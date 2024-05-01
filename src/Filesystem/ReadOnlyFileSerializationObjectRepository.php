<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use Doctrine\Instantiator\Instantiator;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\Mapping\ClassMetadata;
use NoreSources\Container\Container;
use NoreSources\Data\Serialization\FileUnserializerInterface;
use NoreSources\Data\Utility\FileExtensionListInterface;
use NoreSources\Data\Utility\MediaTypeListInterface;
use NoreSources\MediaType\MediaTypeFileExtensionRegistry;
use NoreSources\MediaType\MediaTypeInterface;
use NoreSources\OFM\Filesystem\Traits\SerializationStrategyTrait;
use NoreSources\Persistence\ObjectManagerAwareInterface;
use NoreSources\Persistence\Mapping\PropertyMappingInterface;
use NoreSources\Persistence\Mapping\PropertyMappingProviderInterface;
use NoreSources\Persistence\Sorting\ObjectSorterInterface;

/**
 * Object repository that load objects from structured text files.
 */
class ReadOnlyFileSerializationObjectRepository extends AbstractFilesystemObjectRepository implements
	PropertyMappingProviderInterface
{

	use SerializationStrategyTrait;

	/**
	 *
	 * @param ClassMetadata $classMetadata
	 *        	Class metadata
	 * @param FileUnserializerInterface $serializer
	 *        	File serializer
	 * @param string $basePath
	 *        	File repository base path
	 *        	Object identifier file name transformation strategy
	 * @param MediaTypeInterface $mediaType
	 *        	File media type
	 * @param unknown $extension
	 *        	File extension. Deduced from serializer and/or media type if not set.
	 * @throws \InvalidArgumentException
	 */
	public function __construct(ClassMetadata $classMetadata,
		FileUnserializerInterface $serializer, $basePath,
		FilenameMapperInterface $filenameMapper = null,
		MediaTypeInterface $mediaType = null, $extension = null)
	{
		if (!$mediaType)
		{
			if ($serializer instanceof MediaTypeListInterface)
				$mediaType = Container::firstValue(
					$serializer->getMediaTypes());
		}

		if (!$extension && $mediaType)
		{
			$list = MediaTypeFileExtensionRegistry::getInstance()->getMediaTypeExtensions(
				$mediaType);
			$extension = Container::firstValue($list);
		}

		if (!$extension)
		{
			$extensions = [];
			if ($serializer instanceof FileExtensionListInterface)
				$extensions = $serializer->getFileExtensions();
			if ($mediaType)
			{
				try
				{
					$extensions = \array_merge($extensions,
						MediaTypeFileExtensionRegistry::getInstance()->getMediaTypeExtensions(
							$mediaType));
				}
				catch (\ErrorException $e)
				{}
			}

			$extension = Container::firstValue($extensions, null);

			if (!$mediaType)
				throw new \InvalidArgumentException(
					'Unable to deduce file extension.');
		}

		parent::__construct($classMetadata, $basePath, $filenameMapper,
			$extension);

		$this->setFileMediaType($mediaType);

		$this->setFileMediaType($mediaType);
		$this->setSerializationManager($serializer);
	}

	public function fetchObjectFromFile($filename, $flags = 0)
	{
		$data = $this->getSerializationManager()->unserializeFromFile(
			$filename, $this->mediaType);

		if (($flags & self::FETCH_USE_CACHED) & self::FETCH_USE_CACHED)
		{
			$object = $this->getCachedObject($data);
			if ($object)
				return $object;
		}

		$object = $this->getInstantiator()->instantiate(
			$this->getClassName());

		if (($flags & self::FETCH_CACHE_OBJECT) &
			self::FETCH_CACHE_OBJECT)
		{
			$metadata = $this->getClassMetadata();
			$associationNames = $metadata->getAssociationNames();
			$fieldData = [];
			$associationData = [];
			foreach ($data as $k => $v)
			{
				if (\in_array($k, $associationNames))
					$associationData[$k] = $v;
				else
					$fieldData[$k] = $v;
			}
			$mapper = $this->getPropertyMapper();
			$mapper->assignObjectProperties($object, $fieldData);
			$this->cacheObject($object);
			$mapper->assignObjectProperties($object, $associationData);
		}
		else
			$this->getPropertyMapper()->assignObjectProperties($object,
				$data);

		return $object;
	}

	public function fetchIndexDataFromFile($filename)
	{
		return $this->getSerializationManager()->unserializeFromFile(
			$filename, $this->mediaType);
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

		foreach ($files as $filename)
		{
			$flags = 0;
			$object = $this->fetchObjectFromFile($filename, $flags);

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
	 * @return FileUnserializerInterface
	 */
	public function getSerializer()
	{
		return $this->getSerializationManager();
	}

	public function isNaturalSort($orderBy)
	{
		$c = \count($orderBy);
		if ($c == 0)
			return true;
		if ($c > 1)
			return false;
		$identifiers = $this->getClassMetadata()->getIdentifierFieldNames();
		if (\count($identifiers) != 1)
			return false;
		$identifier = $identifiers[0];
		list ($field, $orientation) = Container::first($orderBy);

		return ($field == $identifier) &&
			($orientation != ObjectSorterInterface::DESC);
	}

	/**
	 *
	 * @return \Doctrine\Instantiator\Instantiator
	 */
	public function getInstantiator()
	{
		if (!isset($this->instantiator))
			$this->instantiator = new Instantiator();
		return $this->instantiator;
	}

	/**
	 *
	 * @return PropertyMappingInterface
	 */
	public function getPropertyMapper()
	{
		if (!isset($this->propertyMapper))
		{
			$this->propertyMapper = new FileSerializationPropertyMapper(
				$this->getClassMetadata());
			if ($this->propertyMapper instanceof ObjectManagerAwareInterface &&
				($manager = $this->getObjectManager()))
				$this->propertyMapper->setObjectManager($manager);
		}
		return $this->propertyMapper;
	}

	public function setObjectManager(ObjectManager $objectManager)
	{
		if (isset($this->propertyMapper) &&
			$this->propertyMapper instanceof ObjectManagerAwareInterface)
			$this->propertyMapper->setObjectManager($objectManager);
		parent::setObjectManager($objectManager);
	}

	/**
	 *
	 * @var Instantiator
	 */
	private $instantiator;

	/**
	 *
	 * @var PropertyMappingInterface
	 */
	private $propertyMapper;
}
