<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use Doctrine\Persistence\Mapping\ClassMetadata;
use NoreSources\Container\Container;
use NoreSources\Data\Serialization\FileSerializerInterface;
use NoreSources\Data\Serialization\FileUnserializerInterface;
use NoreSources\Helper\FunctionInvoker;
use NoreSources\MediaType\MediaTypeInterface;
use NoreSources\Persistence\Index;
use NoreSources\Persistence\ObjectComparer;
use NoreSources\Persistence\ObjectFieldIndexPersisterInterface;
use NoreSources\Persistence\ObjectPersisterInterface;
use NoreSources\Persistence\Event\EventManagerAwareInterface;
use NoreSources\Persistence\Mapping\ReflectionService;

/**
 * Object repository that persists object to a file useng noresources/data serialization.
 */
class FileSerializationObjectRepository extends ReadOnlyFileSerializationObjectRepository implements
	ObjectPersisterInterface, ObjectFieldIndexPersisterInterface,
	EventManagerAwareInterface
{

	/**
	 *
	 * @param ClassMetadata $classMetadata
	 *        	Class metadata
	 * @param FileSerializerInterface|FileUnserializerInterface $serializationManager
	 *        	File serializer and unserializer
	 * @param string $basePath
	 *        	Storage base path
	 * @param string $extension
	 *        	File extension
	 * @param unknown $filenameMapper
	 */
	public function __construct(ClassMetadata $classMetadata,
		FileSerializerInterface $serializationManager, $basePath,
		FilenameMapperInterface $filenameMapper = null,
		MediaTypeInterface $mediaType = null, $extension = null)
	{
		parent::__construct($classMetadata, $serializationManager,
			$basePath, $filenameMapper, $mediaType, $extension);
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\Persistence\ObjectPersisterInterface::persist()
	 */
	public function persist($object)
	{
		if (!\is_dir($this->getBasePath()))
			FunctionInvoker::mkdir($this->getBasePath(), 0755, true);

		$metadata = $this->getClassMetadata();
		$objectId = $metadata->getIdentifierValues($object);
		$filename = $this->getObjectIdentifierFile($objectId);

		$serializer = $this->getSerializer();
		$data = [];
		$this->getPropertyMapper()->fetchObjectProperties($data, $object);
		/**
		 *
		 * @var FileSerializerInterface $serializer
		 */
		$serializer->serializeToFile($filename, $data);

		$original = $this->getObjectOriginalCopy($object);

		$this->cacheObject($object);

		$indexedFieldNames = $this->getIndexedFieldNames();
		if ($original)
		{
			$reflectionService = ReflectionService::getInstance();
			$changes = ObjectComparer::computeChangeSet($metadata,
				$reflectionService, $original, $object);
			foreach ($changes as $fieldName => $change)
			{
				if (!\in_array($fieldName, $indexedFieldNames))
					continue;
				$index = $this->getFieldIndex($fieldName);
				$index->move($change[0], $change[1], $objectId);
				$this->persistFieldIndex($fieldName, $index);
			}
		}
		else
		{
			foreach ($indexedFieldNames as $fieldName)
			{
				if (!Container::keyExists($data, $fieldName))
					continue;
				$index = $this->getFieldIndex($fieldName);
				$index->append($data[$fieldName], $objectId);
				$this->persistFieldIndex($fieldName, $index);
			}
		}
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\Persistence\ObjectPersisterInterface::remove()
	 */
	public function remove($object)
	{
		$o = $this->getObjectOriginalCopy($object);
		if (!$o)
			$o = $object;
		$this->uncacheObject($object);

		$filename = $this->getObjectFile($o);
		if (\file_exists($filename))
			FunctionInvoker::unlink($filename);
		$indexedFieldNames = $this->getIndexedFieldNames();
		if (\count($indexedFieldNames) == 0)
			return;
		$objectId = $this->getClassMetadata()->getIdentifierValues($o);
		$data = [];
		$this->getPropertyMapper()->fetchObjectProperties($data, $o);
		foreach ($indexedFieldNames as $fieldName)
		{
			if (!Container::keyExists($data, $fieldName))
				continue;
			$this->removeObjectFromFieldIndex($fieldName,
				$data[$fieldName], $objectId);
		}
	}

	protected function removeObjectFromFieldIndex($fieldName,
		$indexValue, $objectId)
	{
		$filename = $this->getFieldIndexFile($fieldName);
		if (!\is_file($filename))
			return;
		$index = $this->getFieldIndex($fieldName);
		$index->remove($indexValue, $objectId);
		$this->doPersistFieldIndex($index, $filename);
	}

	public function persistFieldIndex($fieldName, Index $data)
	{
		$filename = $this->getFieldIndexFile($fieldName);
		$this->doPersistFieldIndex($data, $filename);
	}

	protected function doPersistFieldIndex(Index $index, $filename)
	{
		$this->getSerializationManager()->serializeToFile($filename,
			$index->getArrayCopy(), $this->getFileMediaType());
	}
}
