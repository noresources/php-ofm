<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

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
use NoreSources\Persistence\ClosureExpressionVisitorObjectSorter;
use NoreSources\Persistence\ObjectIdentifier;
use NoreSources\Persistence\ObjectManagerAwareInterface;
use NoreSources\Persistence\ObjectManagerProviderInterface;
use NoreSources\Persistence\ObjectSorterInterface;
use NoreSources\Persistence\Traits\ObjectManagerReferenceTrait;

/**
 * File-based object repository base implementation
 */
abstract class AbstractFilesystemObjectRepository implements
	ObjectRepository, Selectable, ObjectManagerProviderInterface,
	ObjectManagerAwareInterface
{
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
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \Doctrine\Persistence\ObjectRepository::find()
	 */
	public function find($id)
	{
		$metadata = $this->getClassMetadata();
		$id = ObjectIdentifier::normalize($id, $metadata);

		$object = $this->getCachedObject($id, true);
		if ($object)
			return $object;

		$filename = $this->getObjectIdentifierFile($id);
		if (!\file_exists($filename))
			return null;

		$flags = self::FETCH_USE_CACHED | self::FETCH_CACHE_OBJECT;
		return $this->fetchObjectFromFile($filename, $flags);
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

		$filter = null;
		$list = [];
		$orderBy = $this->normalizeSortedBy($orderBy);
		$files = $this->getObjectFiles();
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
				$flags = self::FETCH_USE_CACHED;
				foreach ($files as $key => $filename)
				{
					$object = $this->fetchObjectFromFile($filename,
						$flags);

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

			return \array_values(
				\array_map(
					function ($filename) {
						return $this->fetchObjectFromFile($filename,
							self::FETCH_USE_CACHED);
					}, $files));
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
		$flags = self::FETCH_USE_CACHED | self::FETCH_CACHE_OBJECT;
		foreach ($files as $filename)
			$list[] = $this->fetchObjectFromFile($filename, $flags);
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
			$visitor = new ClosureExpressionVisitor();
			$filter = $visitor->dispatch($expr);
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
		return $filtered;
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

	/*
	 * public function getClassName();
	 */

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
			$this->objectSorter = new ClosureExpressionVisitorObjectSorter();
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

	public function getCachedObject($id, $normalized = false)
	{
		$metadata = $this->getClassMetadata();
		if (!$normalized)
			$id = ObjectIdentifier::normalize($id, $metadata);
		$hash = $this->getFilenameMapper()->getBasename($id);
		return Container::keyValue($this->objectCache, $hash);
	}

	public function cacheObject($object)
	{
		$metadata = $this->getClassMetadata();
		$id = ObjectIdentifier::normalize($object, $metadata);
		$hash = $this->getFilenameMapper()->getBasename($id);
		$this->objectCache[$hash] = $object;
	}

	public function uncacheObject($objectOrId)
	{
		$metadata = $this->getClassMetadata();
		$id = ObjectIdentifier::normalize($objectOrId, $metadata);
		$hash = $this->getFilenameMapper()->getBasename($id);
		unset($this->objectCache[$hash]);
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
			$this->closureExpressionVisitor = new ClosureExpressionVisitor();
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
	private $objectCache = [];
}
