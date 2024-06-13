<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\ExpressionBuilder;
use Doctrine\Common\Collections\Selectable;
use Doctrine\Common\Collections\Expr\ClosureExpressionVisitor;
use Doctrine\Common\Collections\Expr\Expression;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\Persistence\Mapping\ClassMetadata;
use NoreSources\Container\Container;
use NoreSources\MediaType\MediaTypeFileExtensionRegistry;
use NoreSources\OFM\Filesystem\Traits\FilenameStrategyTrait;
use NoreSources\Persistence\Index;
use NoreSources\Persistence\NotManagedException;
use NoreSources\Persistence\ObjectContainerInterface;
use NoreSources\Persistence\ObjectFieldIndexRepositoryInterface;
use NoreSources\Persistence\ObjectManagerAwareInterface;
use NoreSources\Persistence\ObjectManagerProviderInterface;
use NoreSources\Persistence\Event\Traits\EventManagerAwareTrait;
use NoreSources\Persistence\Expr\ClassMetadataClosureExpressionVisitor;
use NoreSources\Persistence\Id\DefaultObjectRuntimeIdGenerator;
use NoreSources\Persistence\Id\ObjectIdentifier;
use NoreSources\Persistence\Id\ObjectRuntimeIdGeneratorInterface;
use NoreSources\Persistence\Mapping\ClassMetadataAdapter;
use NoreSources\Persistence\Sorting\ObjectSorterInterface;
use NoreSources\Persistence\Traits\ObjectManagerReferenceTrait;

/**
 * File-based object repository base implementation
 */
abstract class AbstractFilesystemObjectRepository implements
	ObjectRepository, ObjectFieldIndexRepositoryInterface, Selectable,
	ObjectManagerProviderInterface, ObjectManagerAwareInterface,
	ObjectContainerInterface
{
	use EventManagerAwareTrait;
	use FilenameStrategyTrait;
	use ObjectManagerReferenceTrait;

	const FETCH_USE_CACHED = 0x01;

	const FETCH_CACHE_OBJECT = 0x02;

	/**
	 *
	 * @param ClassMetadata $classMetadata
	 *        	Class metadata
	 * @param string $basePath
	 *        	Object file storage base directory
	 * @param string $extension
	 *        	Object file extension
	 * @param FilenameMapperInterface $filenameMapper
	 *        	Object identifier <-> filename transformation strategy
	 */
	public function __construct(ClassMetadata $classMetadata, $basePath,
		FilenameMapperInterface $filenameMapper = null,
		$extension = null)
	{
		$this->classMetadata = $classMetadata;
		$this->setFilesystemStrategy($basePath, $filenameMapper,
			$extension);
		$this->objectRuntimeIdentifierGenerator = new DefaultObjectRuntimeIdGenerator();
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \Doctrine\Persistence\ObjectRepository::find()
	 */
	public function find($id)
	{
		if (!\is_dir($this->basePath))
			return NULL;

		$metadata = $this->getClassMetadata();
		$id = ObjectIdentifier::normalize($id, $metadata);

		$object = $this->getCachedObject($id, true);
		if ($object)
			return $object;

		$filename = $this->getObjectIdentifierFile($id);
		if (!\is_string($filename))
			throw new \RuntimeException(
				'Could not determine filename for objet ID ' .
				var_export($id, true));
		if (!\file_exists($filename))
			return null;

		return $this->fetchObjectFromFile($filename,
			self::FETCH_CACHE_OBJECT);
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \Doctrine\Persistence\ObjectRepository::findBy()
	 */
	public function findBy(array $criteria, ?array $orderBy = null,
		$limit = null, $offset = null)
	{
		if ($limit === 0)
			return [];
		if (!\is_dir($this->basePath))
			return [];

		$indexedFieldNames = $this->getIndexedFieldNames();
		$indexedCriteria = Container::filterKeys($criteria,
			function ($name) use ($indexedFieldNames) {
				return \in_array($name, $indexedFieldNames);
			});

		$filter = null;
		$list = [];
		$orderBy = $this->normalizeSortedBy($orderBy);

		if (\count($indexedCriteria))
		{
			$files = [];
			foreach ($indexedCriteria as $fieldName => $indexValue)
			{
				unset($criteria[$fieldName]);
				$index = $this->getFieldIndex($fieldName);
				if (!$index->has($indexValue))
					return [];
				$ids = $index->get($indexValue);
				foreach ($ids as $id)
				{
					$hash = $this->getFilenameMapper()->getBasename($id);
					if (Container::keyExists($list, $hash))
						continue;
					$files[$hash] = $this->getObjectIdentifierFile($id);
				}
			}
		}
		else
			$files = $this->getObjectFiles();

		if (\count($files) == 0)
			return [];

		\ksort($files);

		if (\count($criteria) > 0)
		{
			$c = Criteria::create();
			$eb = new ExpressionBuilder();
			foreach ($criteria as $field => $value)
				$c->andWhere($eb->eq($field, $value));
			$filter = $this->createFilter($c->getWhereExpression());
		}

		$naturalSort = $this->isNaturalSort($orderBy);

		if ($naturalSort)
		{
			if ($filter)
			{
				$offset = \is_integer($offset) ? $offset : 0;
				$limit = (\is_integer($limit) && $limit > 0) ? $limit : -1;
				foreach ($files as $hash => $filename)
				{
					if (isset($this->hashToObject[$hash]))
						$object = $this->hashToObject[$hash];
					else
						$object = $this->fetchObjectFromFile($filename,
							self::FETCH_CACHE_OBJECT);

					if (!$filter($object))
						continue;
					if ($offset > 0)
					{
						$o--;
						continue;
					}

					$list[] = $object;
					$this->cacheObject($object);

					if ($limit > 0)
					{
						$limit--;
						if ($limit == 0)
							break;
					}
				}
				return $list;
			}

			/*
			 * Just load a slice of the file set
			 */

			$offset = \is_integer($offset) ? $offset : 0;
			if ($offset || $limit)
				$files = \array_slice($files, $offset, $limit);

			$list = [];
			foreach ($files as $hash => $filename)
			{
				if (isset($this->hashToObject[$hash]))
					$list[] = $this->hashToObject[$hash];
				else
					$list[] = $this->fetchObjectFromFile($filename,
						self::FETCH_CACHE_OBJECT);
			}
			return $list;
		} // Natural sort

		$list = $this->findAll();
		if ($filter)
			$list = Container::filterValue($list, $filter);

		if (\count($orderBy))
			$this->getObjectSorter()->sortObjects($list, $orderBy);

		if (\is_integer($limit))
			$list = \array_slice($list,
				\is_integer($offset) ? $offset : 0, $limit);
		elseif (\is_integer($offset) && $offset > 0)
			$list = \array_slice($limit, $offset);

		foreach ($limit as $object)
			$this->cacheObject($object);
		return $list;
	}

	public function findOneBy(array $criteria)
	{
		return Container::firstValue(
			$this->findBy($criteria, null, 1, 0), null);
	}

	public function findAll()
	{
		$files = $this->getObjectFiles();
		\ksort($files);
		$list = [];
		foreach ($files as $hash => $filename)
		{
			if (isset($this->hashToObject[$hash]))
				$list[] = $this->hashToObject[$hash];
			else
				$list[] = $this->fetchObjectFromFile($filename,
					self::FETCH_CACHE_OBJECT);
		}

		return $list;
	}

	public function matching(Criteria $criteria)
	{
		$expr = $criteria->getWhereExpression();
		$orderBy = $criteria->getOrderings();
		$offset = $criteria->getFirstResult();
		$limit = $criteria->getMaxResults();
		if (!$expr && $this->isNaturalSort($orderBy))
			return $this->findBy([], $orderBy, $limit, $offset);

		$filtered = $this->findAll();
		if ($expr)
		{

			$filter = $this->createFilter($expr);
			$filtered = array_filter($filtered, $filter);
		}

		if ($orderBy)
		{
			$next = null;
			foreach (array_reverse($orderBy) as $field => $ordering)
			{
				$next = ClosureExpressionVisitor::sortByField($field,
					$ordering === Criteria::DESC ? -1 : 1, $next);
			}

			\uasort($filtered, $next);
		}

		if ($offset || $limit)
		{
			$filtered = array_slice($filtered, (int) $offset, $limit);
		}
		return new ArrayCollection(\array_values($filtered));
	}

	public function contains(object $object)
	{
		$oid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
		return isset($this->objects[$oid]);
	}

	public function attach(object $object)
	{
		$oid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
		if (isset($this->objects[$oid]))
			return;
		$metadata = $this->getClassMetadata();
		$id = $metadata->getIdentifierValues($object);
		$hash = $this->getFilenameMapper()->getBasename($id);
		$this->objects[$oid] = [
			self::OBJECT_HASH => $hash,
			self::OBJECT_ORIGINAL => clone $object
		];

		$this->hashToObject[$hash] = $object;
	}

	public function getObjectIdentity(object $object)
	{
		$oid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
		if (!isset($this->objects[$oid]))
			return NULL;
		$hash = $this->objects[$oid][self::OBJECT_HASH];
		$id = $this->getFilenameMapper()->getIdentifier($hash);
		$metadata = $this->getClassMetadata();
		$name = Container::firstValue(
			$metadata->getIdentifierFieldNames());
		return [
			$name => $id
		];
	}

	public function getObjectOriginalCopy(object $object)
	{
		$oid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
		if (!isset($this->objects[$oid]))
			return NULL;
		if (!isset($this->objects[$oid][self::OBJECT_ORIGINAL]))
			return NULL;
		return $this->objects[$oid][self::OBJECT_ORIGINAL];
	}

	public function setObjectOriginalCopy(object $object,
		object $original)
	{
		$oid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
		if (!isset($this->objects[$oid]))
			throw new NotManagedException($object);
		$this->objects[$oid][self::OBJECT_ORIGINAL] = $original;
	}

	protected function getObjectOID($object)
	{
		return $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
	}

	public function detach($object)
	{
		$oid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
		if (!isset($this->objects[$oid]))
			throw new NotManagedException($object);
		$hash = $this->objects[$oid][self::OBJECT_HASH];

		unset($this->objects[$oid]);
		unset($this->hashToObject[$hash]);
	}

	public function clear()
	{
		$this->objects = [];
		$this->hashToObject = [];
	}

	/**
	 * Tell if ORDER BY rules corresponds to the natural sorting order of entries returned by
	 * getObjectFiles()
	 *
	 * @param array $orderBy
	 * @return boolean
	 */
	public function isNaturalSort($orderBy)
	{
		return \count($orderBy) == 0;
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \Doctrine\Persistence\ObjectRepository::getClassName()
	 */
	public function getClassName()
	{
		return $this->classMetadata->getName();
	}

	/**
	 *
	 * @return \Doctrine\Persistence\Mapping\ClassMetadata
	 */
	public function getClassMetadata()
	{
		return $this->classMetadata;
	}

	/**
	 *
	 * @return string[] List of object files indexed by identifiers
	 */
	public function getObjectFiles()
	{
		$basePath = \realpath($this->basePath);
		if (!\is_dir($basePath))
			return [];
		$iterator = new \RecursiveDirectoryIterator($basePath);
		$prefix = $basePath . '/';
		$prefixLength = \strlen($prefix);
		$map = [];
		$suffix = '.' . $this->getFileExtension();
		$suffixLength = \strlen($suffix);
		foreach ($iterator as $item)
		{
			/**
			 *
			 * @var \SplFileInfo $item
			 */
			if (!$item->isFile())
				continue;
			$x = $item->getExtension();
			if ($this->getFileExtension() &&
				!($x == $this->getFileExtension()))
				continue;

			$basename = \substr($item->getRealPath(), $prefixLength);
			$basename = \substr($basename, 0, -$suffixLength);

			$identifier = $this->getFilenameMapper()->getIdentifier(
				$basename);
			if (!$identifier)
				continue;

			$map[$identifier] = $item->getRealPath();
		}

		return $map;
	}

	/**
	 *
	 * @param string $filename
	 *        	File system file path.
	 * @param integer $flags
	 *        	Option flags
	 * @return object Object
	 */
	public abstract function fetchObjectFromFile($filename, $flags = 0);

	/**
	 * Assign the strategy method to transform identifiers to filenames.
	 *
	 * @param FilenameMapperInterface $filenameMapper
	 *        	Identifier transformation strategy
	 */

	/**
	 * Get object sorter.
	 *
	 * A default one is created if none was set before.
	 *
	 * @return \NoreSources\Persistence\ObjectSorterInterface
	 */
	public function getObjectSorter()
	{
		if (!isset($this->objectSorter))
			$this->objectSorter = new ClassMetadataClosureExpressionVisitor(
				$this->getClassMetadata());
		return $this->objectSorter;
	}

	/**
	 *
	 * @param ObjectSorterInterface $sorter
	 *        	The sorter
	 */
	public function setObjectSorter(ObjectSorterInterface $sorter)
	{
		$this->objectSorter = $sorter;
	}

	public function getObjectFile($object)
	{
		$metadata = $this->getClassMetadata();
		$id = $metadata->getIdentifierValues($object);
		return $this->getObjectIdentifierFile($id);
	}

	public function getObjectIdentifierFile($id)
	{
		$basePath = \realpath($this->basePath);
		if (!$basePath)
			return null;
		$basename = $this->getFilenameMapper()->getBasename($id);
		return $basePath . '/' . $basename . '.' .
			$this->getFileExtension();
	}

	/**
	 * Get the field index data filename for a given field
	 *
	 * @param unknown $fieldName
	 *        	Indexed field name
	 * @return NULL|string Field index data file name
	 */
	public function getFieldIndexFile($fieldName)
	{
		$basePath = \realpath($this->basePath);
		if (!$basePath)
			return null;
		$basename = $this->getFilenameMapper()->getBasename($fieldName);
		return $basePath . '.' . $basename . '.index.' .
			$this->getFileExtension();
	}

	/**
	 * Get all field index file names
	 *
	 * @return array<string, string
	 */
	public function getFieldIndexFiles()
	{
		$list = [];
		foreach ($this->getIndexedFieldNames() as $fieldName)
			$list[$fieldName] = $this->getFieldIndexFile($fieldName);
		return $list;
	}

	/**
	 *
	 * @param string $fieldName
	 *        	Field name
	 * @return Index
	 */
	public function getFieldIndex($fieldName)
	{
		if (isset($this->indexes[$fieldName]))
			return $this->indexes[$fieldName];
		$filename = $this->getFieldIndexFile($fieldName);
		if (\is_file($filename))
		{
			$data = $this->fetchIndexDataFromFile($filename);
			$index = new Index($data);
		}
		else
			$index = new Index();
		$this->indexes[$fieldName] = $index;
		return $index;
	}

	public function refreshFieldIndexes()
	{
		$indexedFieldNames = $this->getIndexedFieldNames();
		$indexes = [];

		$metadata = $this->getClassMetadata();
		$className = $metadata->getName();
		$properties = [];
		$reflectionService = \NoreSources\Persistence\Mapping\ReflectionService::getInstance();

		foreach ($indexedFieldNames as $fieldName)
		{
			$properties[$fieldName] = $reflectionService->getAccessibleProperty(
				$className, $fieldName);
			$indexes[$fieldName] = $this->getFieldIndex($fieldName);
			$indexes[$fieldName]->clear();
		}

		$files = $this->getObjectFiles();
		$idFields = $metadata->getIdentifierFieldNames();
		$mediaType = $this->getFileMediaType();

		foreach ($files as $hash => $filename)
		{
			$object = $this->fetchObjectFromFile($filename, 0);
			$objectId = $metadata->getIdentifierValues($object);

			$data = [];
			$this->getPropertyMapper()->fetchObjectProperties($data,
				$object);
			foreach ($properties as $fieldName => $property)
			{
				$indexValue = $property->getValue($object);
				$indexes[$fieldName]->append($indexValue, $objectId);
			}
		}
	}

	/**
	 *
	 * @return string Storage base path
	 */
	public function getBasePath()
	{
		return $this->basePath;
	}

	/**
	 *
	 * @param string $basePath
	 *        	Storage base path
	 * @param FilenameMapperInterface $filenameMapper
	 * @param string $extension
	 *        	File extension
	 */
	public function setFilesystemStrategy($basePath,
		FilenameMapperInterface $filenameMapper = null,
		$extension = null)
	{
		$this->basePath = $basePath;
		$this->filenameMapper = $filenameMapper;
		$this->extension = $extension;
		if (!isset($this->mediaType) && $extension)
			$this->mediaType = MediaTypeFileExtensionRegistry::getInstance()->getExtensionMediaType(
				$extension);
	}

	public function getIndexedFieldNames()
	{
		if (isset($this->indexedFieldNames))
			return $this->indexedFieldNames;
		$this->indexedFieldNames = [];
		$table = null;
		$metadata = $this->getClassMetadata();
		$arguments = [];
		ClassMetadataAdapter::retrieveMetadataElement($table, $metadata,
			'table', ...$arguments);
		if (ClassMetadataAdapter::retrieveMetadataElement($table,
			$metadata, 'table', ...$arguments) &&
			Container::isArray($table) &&
			($indexes = Container::keyValue($table, 'indexes')))
		{
			foreach ($indexes as $index)
			{
				if (isset($index['fields']) &&
					\count($index['fields']) == 1)
					$this->indexedFieldNames[] = $index['fields'][0];
			}
		}

		$this->indexedFieldNames = \array_unique(
			$this->indexedFieldNames);
		return $this->indexedFieldNames;
	}

	public function getCachedObject($id, $normalized = false)
	{
		$metadata = $this->getClassMetadata();
		if (!$normalized)
			$id = ObjectIdentifier::normalize($id, $metadata);
		$hash = $this->getFilenameMapper()->getBasename($id);
		return Container::keyValue($this->hashToObject, $hash);
	}

	public function cacheObject($object)
	{
		$oid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
		$metadata = $this->getClassMetadata();
		$id = ObjectIdentifier::normalize($object, $metadata);
		$hash = $this->getFilenameMapper()->getBasename($id);

		if (isset($this->objects[$oid]))
		{
			if ($this->objects[$oid][self::OBJECT_HASH] !== $hash)
			{
				throw new \RuntimeException(
					$metadata->getName() . ' ' . $hash . ' #' . $oid .
					' is already cached with ID ' .
					$this->objects[$oid][self::OBJECT_HASH]);
			}
		}

		if (isset($this->hashToObject[$hash]))
		{
			if ($this->hashToObject[$hash] !== $object)
			{
				$ooid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
					$this->hashToObject[$hash]);
				throw new \RuntimeException(
					$metadata->getName() . ' ' . $hash . ' #' . $oid .
					' is already cached with a different object #' .
					$ooid);
			}
		}

		$this->objects[$oid] = [
			self::OBJECT_HASH => $hash,
			self::OBJECT_ORIGINAL => clone $object
		];
		$this->hashToObject[$hash] = $object;
	}

	public function uncacheObject($object)
	{
		if (!\is_object($object))
			throw new \InvalidArgumentException();
		$oid = $this->objectRuntimeIdentifierGenerator->getObjectRuntimeIdentifier(
			$object);
		if (!isset($this->objects[$oid]))
			return;
		$hash = $this->objects[$oid][self::OBJECT_HASH];
		unset($this->hashToObject[$hash]);
	}

	/**
	 *
	 * @param string|\Traversable $orderBy
	 *        	Parameter to normalize
	 * @return array
	 */
	protected function normalizeSortedBy($orderBy)
	{
		if (\is_string($orderBy))
			return [
				$orderBy => ObjectSorterInterface::ASC
			];
		if (!Container::isTraversable($orderBy))
			return [];
		$normalized = [];
		foreach ($orderBy as $k => $v)
		{
			if (\is_integer($k) && \is_string($v))
				$normalized[$v] = ObjectSorterInterface::ASC;
			elseif (\is_bool($v))
				$normalized[$k] = ($v) ? ObjectSorterInterface::ASC : ObjectSorterInterface::DESC;
			else
				$normalized[$k] = (\strcasecmp($v,
					ObjectSorterInterface::DESC) == 0) ? ObjectSorterInterface::DESC : ObjectSorterInterface::ASC;
		}
		return $normalized;
	}

	/**
	 *
	 * @param Expression $expression
	 * @return callable
	 */
	protected function createFilter(Expression $expression)
	{
		if (!isset($this->closureExpressionVisitor))
			$this->closureExpressionVisitor = new ClassMetadataClosureExpressionVisitor(
				$this->getClassMetadata());
		return $this->closureExpressionVisitor->dispatch($expression);
	}

	/**
	 *
	 * @var ClassMetadata
	 */
	private $classMetadata;

	/**
	 *
	 * @var ObjectSorterInterface
	 */
	private $objectSorter;

	/**
	 *
	 * @var ClosureExpressionVisitor
	 */
	private $closureExpressionVisitor;

	/**
	 *
	 * @var string
	 */
	protected $basePath;

	/**
	 *
	 * @var array<string, object>
	 */
	private $hashToObject = [];

	const OBJECT_HASH = 0;

	const OBJECT_ORIGINAL = 1;

	/**
	 * Object runtime identifier -> File identifier map
	 *
	 * @var array <mixed, array>
	 */
	private $objects = [];

	/**
	 *
	 * @var ObjectRuntimeIdGeneratorInterface
	 */
	private $objectRuntimeIdentifierGenerator;

	/**
	 *
	 * @var array<string>
	 */
	private $indexedFieldNames;

	/**
	 *
	 * @var Index[]
	 */
	private $indexes;
}
