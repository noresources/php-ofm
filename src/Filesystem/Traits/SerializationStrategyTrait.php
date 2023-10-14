<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem\Traits;

use NoreSources\Data\Serialization\FileSerializerInterface;
use NoreSources\Data\Serialization\FileUnserializerInterface;
use NoreSources\MediaType\MediaTypeInterface;

/**
 * Trait for objects that holds a file serialization system
 */
trait SerializationStrategyTrait
{

	/**
	 *
	 * @return FileSerializerInterface|FileUnserializerInterface|NULL
	 */
	public function getSerializationManager()
	{
		return $this->serializationManager;
	}

	/**
	 *
	 * @param FileSerializerInterface|FileUnserializerInterface|NULL $serializationManager
	 *        	Serializer
	 * @throws \InvalidArgumentException
	 */
	public function setSerializationManager($serializationManager)
	{
		if (!\is_null($serializationManager) &&
			!(($serializationManager instanceof FileSerializerInterface) ||
			($serializationManager instanceof FileUnserializerInterface)))
		{
			$message = 'Argument must be NULL or implements at least one of ' .
				FileSerializerInterface::class . ' or ' .
				FileUnserializerInterface::class;
			throw new \InvalidArgumentException($message);
		}

		$this->serializationManager = $serializationManager;
	}

	/**
	 *
	 * @return \NoreSources\MediaType\MediaTypeInterface|NULL
	 */
	public function getFileMediaType()
	{
		return $this->mediaType;
	}

	/**
	 *
	 * @param MediaTypeInterface $mediaType
	 *        	Media type of the generated file
	 */
	public function setFileMediaType(MediaTypeInterface $mediaType)
	{
		$this->mediaType = $mediaType;
	}

	/**
	 *
	 * @var FileSerializerInterface|FileUnserializerInterface
	 */
	private $serializationManager;

	/**
	 *
	 * @var MediaTypeInterface|NULL
	 */
	protected $mediaType;
}
