<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use Doctrine\Common\EventManager;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use NoreSources\OFM\Configuration;
use NoreSources\OFM\Filesystem\Traits\DirectoryMapperAwareTrait;
use NoreSources\OFM\Filesystem\Traits\FilenameStrategyTrait;
use NoreSources\OFM\Filesystem\Traits\SerializationStrategyTrait;
use NoreSources\Persistence\ObjectManagerAwareInterface;
use NoreSources\Persistence\Event\EventManagerAwareInterface;
use NoreSources\Persistence\Event\ListenerInvoker;
use NoreSources\Persistence\Event\ListenerInvokerProviderInterface;
use NoreSources\Persistence\Traits\ObjectManagerTrait;
use NoreSources\Type\TypeDescription;

/**
 * ObjectManager implementation using FileSerializationObjectRepository
 */
class FileSerializationObjectManager implements ObjectManager,
	EventManagerAwareInterface, ListenerInvokerProviderInterface
{
	use ObjectManagerTrait;
	use DirectoryMapperAwareTrait;
	use FilenameStrategyTrait;
	use SerializationStrategyTrait;

	public function __construct()
	{}

	/**
	 * Initialize object manager from a OFM configuration
	 *
	 * @param Configuration $configuration
	 *        	Configuration
	 */
	public function configure(Configuration $configuration)
	{
		$this->setEventManager($configuration->getEventManager());
		$this->setMetadataFactory($configuration->getMetadataFactory());

		$this->setBasePath($configuration->getBasePath());
		$this->setDirectoryMapper($configuration->getDirectoryMapper());

		$this->setSerializationManager(
			$configuration->getSerializationManager());
		$this->setFileMediaType($configuration->getFileMediaType());

		$this->setFilenameMapper($configuration->getFilenameMapper());
		$this->setFileExtension($configuration->getFileExtension());
	}

	/**
	 *
	 * @return EventManager
	 */
	public function getEventManager()
	{
		if (!isset($this->eventManager))
			$this->eventManager = new EventManager();
		return $this->eventManager;
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\OFM\Event\EventManagerAwareInterface::setEventManager()
	 */
	public function setEventManager(EventManager $evm)
	{
		$this->eventManager = $evm;
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\Persistence\Event\ListenerInvokerProviderInterface::getListenerInvoker()
	 */
	public function getListenerInvoker()
	{
		return $this->objectListenerInvoker;
	}

	/**
	 *
	 * @param object $invoker
	 *        	An object that will invoke lifecycle callbacks, object listener and event manager
	 *        	events.
	 */
	public function setListenerInvoker($invoker)
	{
		$this->objectListenerInvoker = $invoker;
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \Doctrine\Persistence\ObjectManager::getRepository()
	 */
	public function getRepository($className)
	{
		if ($this->hasRepository($className))
			return $this->defaultGetRepository($className);
		$factory = $this->getMetadataFactory();
		if (!$factory->hasMetadataFor($className))
			throw new \InvalidArgumentException(
				$className . ' is not supported by this ObjectManager');
		if (!isset($this->serializationManager))
			throw new \RuntimeException(
				'No serializer defined for automatic creation of ObjectRepository');

		if (!isset($this->basePath))
			throw new \RuntimeException(
				'No base path defined for automatic creation of ObjectRepository');
		$repository = $this->createObjectRepository($className);

		if ($repository instanceof ObjectManagerAwareInterface)
			$repository->setObjectManager($this);

		$this->setRepository($className, $repository);
		return $repository;
	}

	/**
	 *
	 * @param string $className
	 *        	Class name
	 * @return mixed
	 */
	/**
	 * Get object persister interface for the given object lcass.
	 *
	 * @param string $className
	 *        	Class name
	 * @return NULL|mixed|array|\ArrayAccess|\Psr\Container\ContainerInterface|\Traversable|\Doctrine\Persistence\ObjectRepository
	 */
	public function getPersister($className)
	{
		if ($this->hasPersister($className))
			return $this->defaultGetPersister($className);

		if ($this->hasRepository($className))
			$repository = $this->defaultGetRepository($className);
		else
		{
			$repository = $this->createObjectRepository($className);
			$this->setRepository($className, $repository);
		}

		$this->setPersister($className, $repository);
		return $repository;
	}

	public function getObjectFile($object)
	{
		$className = \get_class($object);

		$repository = $this->defaultGetRepository($className);
		if ($repository instanceof AbstractFilesystemObjectRepository)
		{
			$metadata = $this->getClassMetadata($className);
			return $repository->getObjectFile($object, $metadata);
		}

		throw new \RuntimeException(
			'Unable to determine file path for ' . $className . ' object');
		;
	}

	/**
	 * Get object file storage for the given class.
	 *
	 * @param string $className
	 *        	Class name
	 * @return NULL|string
	 */
	public function getObjectBasePath($className)
	{
		$dm = $this->getDirectoryMapper();
		if ($dm->isAbsolute())
			return $dm->getClassDirectory($className);
		if (!$this->basePath)
			throw new \RuntimeException(
				'General base path is mandatory to use non-absolute path from ' .
				TypeDescription::getName($dm));

		return $this->basePath . '/' .
			$dm->getClassDirectory($className);
	}

	/**
	 *
	 * @param string $className
	 *        	Class name
	 * @return ObjectRepository
	 */
	protected function createObjectRepository($className)
	{
		$classMetadata = $this->getClassMetadata($className);
		$serializationManager = $this->serializationManager;
		$basePath = $this->getObjectBasePath($className);
		$repository = new FileSerializationObjectRepository(
			$classMetadata, $serializationManager, $basePath,
			$this->getFilenameMapper(), $this->getFileMediaType(),
			$this->getFileExtension());

		return $repository;
	}

	/**
	 *
	 * @var EventManager
	 */
	private $eventManager;

	/**
	 *
	 * @var ListenerInvoker
	 */
	private $objectListenerInvoker;
}
