<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use Doctrine\Persistence\Mapping\ClassMetadata;
use NoreSources\Data\Serialization\FileSerializerInterface;
use NoreSources\Data\Serialization\FileUnserializerInterface;
use NoreSources\Helper\FunctionInvoker;
use NoreSources\MediaType\MediaTypeInterface;
use NoreSources\Persistence\ObjectPersisterInterface;
use NoreSources\Persistence\Event\EventManagerAwareInterface;
use NoreSources\Persistence\Event\Traits\EventManagerAwareTrait;

/**
 * Object repository that persists object to a file useng noresources/data serialization.
 */
class FileSerializationObjectRepository extends ReadOnlyFileSerializationObjectRepository implements
	ObjectPersisterInterface, EventManagerAwareInterface
{
	use EventManagerAwareTrait;

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
		$filename = $this->getObjectFile($object);

		$serializer = $this->getSerializer();
		$data = [];
		$this->getPropertyMapper()->fetchObjectProperties($data, $object);
		/**
		 *
		 * @var FileSerializerInterface $serializer
		 */
		$serializer->serializeToFile($filename, $data);

		$this->cacheObject($object);
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\Persistence\ObjectPersisterInterface::remove()
	 */
	public function remove($object)
	{
		$this->uncacheObject($object);
		$filename = $this->getObjectFile($object);
		if (!\file_exists($filename))
			return;
		FunctionInvoker::unlink($filename);
	}
}
