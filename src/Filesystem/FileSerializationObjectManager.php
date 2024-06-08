<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use Doctrine\Common\EventManager;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\Persistence\Mapping\ClassMetadata;
use NoreSources\Container\Container;
use NoreSources\OFM\Configuration;
use NoreSources\OFM\Filesystem\Traits\DirectoryMapperAwareTrait;
use NoreSources\OFM\Filesystem\Traits\FilenameStrategyTrait;
use NoreSources\OFM\Filesystem\Traits\SerializationStrategyTrait;
use NoreSources\Persistence\AbstractObjectManager;
use NoreSources\Persistence\ObjectManagerAwareInterface;
use NoreSources\Persistence\UnitOfWork;
use NoreSources\Persistence\Event\ListenerInvoker;
use NoreSources\Persistence\Event\ListenerInvokerProviderInterface;
use NoreSources\Type\TypeDescription;

class FileSerializationObjectManager extends AbstractObjectManager implements
	ListenerInvokerProviderInterface
{

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
		$evm = $configuration->getEventManager();
		if ($evm)
		{
			$invoker = new ListenerInvoker($evm);
			$this->setListenerInvoker($invoker);
		}

		$this->setMetadataFactory($configuration->getMetadataFactory());

		$this->setBasePath($configuration->getBasePath());
		$this->setDirectoryMapper($configuration->getDirectoryMapper());

		if (($serializer = $configuration->getSerializationManager()))
			$this->setSerializationManager($serializer);
		if (($mediaType = $configuration->getFileMediaType()))
			$this->setFileMediaType($mediaType);

		$this->setFilenameMapper($configuration->getFilenameMapper());
		$this->setFileExtension($configuration->getFileExtension());
	}

	/**
	 *
	 * @return EventManager|NULL
	 */
	public function getEventManager()
	{
		$invoker = $this->getListenerInvoker();
		if ($invoker)
			return $invoker->getEventManager();

		return null;
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
	 * @return ObjectRepository
	 */
	public function getRepository($className)
	{
		if ($this->hasRepository($className))
			return $this->defaultGetRepository($className);
		$factory = $this->getMetadataFactory();
		if ($factory->isTransient($className))
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
	 * @param object $object
	 *        	Object
	 * @param ClassMetadata $metadata
	 *
	 */
	protected function preRemoveTask($object, $metadata)
	{
		$visited = [
			\spl_object_id($object)
		];
		$this->doFinalizePreRemove($visited, $object, $metadata);
	}

	protected function doFinalizePreRemove(&$visited, $object, $metadata)
	{
		$objectId = $metadata->getIdentifierValues($object);
		$objectId = Container::firstValue($objectId);
		$factory = $this->getMetadataFactory();
		$allMetadata = $factory->getAllMetadata();
		$className = $metadata->getName();

		/**
		 *
		 * @var \Doctrine\Persistence\Mapping\ReflectionService $reflectionService
		 */
		$reflectionService = $factory->getReflectionService();

		foreach ($allMetadata as $otherMetadata)
		{
			$associations = $otherMetadata->getAssociationNames();
			if (\count($associations) == 0)
				continue;

			$otherClassName = $otherMetadata->getName();

			$managed = null;
			if ($this->hasUnitOfWork())
				$managed = new ArrayCollection(
					$this->getUnitOfWork()->getObjectsBy(
						$otherClassName,
						[
							UnitOfWork::OPERATION_UPDATE,
							UnitOfWork::OPERATION_INSERT
						]));

			foreach ($associations as $fieldName)
			{
				if ($otherMetadata->getAssociationTargetClass(
					$fieldName) != $className)
					continue;

				$repository = $this->getRepository(
					$otherMetadata->getName());

				$property = $reflectionService->getAccessibleProperty(
					$otherClassName, $fieldName);

				if ($otherMetadata->isSingleValuedAssociation(
					$fieldName))
				{
					$list = $repository->findBy(
						[
							$fieldName => $object
						]);
					if ($managed instanceof ArrayCollection)
					{
						$expression = new Comparison($fieldName,
							Comparison::EQ, $object);
						$criteria = Criteria::create()->andWhere(
							$expression);

						$filtered = $managed->matching($criteria);
						foreach ($filtered as $value)
							if (!\in_array($value, $list))
								$list[] = $value;
					}

					foreach ($list as $o)
					{
						$oid = \spl_object_id($o);
						if (\in_array($oid, $visited))
							continue;
						$visited[] = $oid;

						$property->setValue($o, null);
						$this->persist($o);
					}
				}
				elseif ($otherMetadata->isCollectionValuedAssociation(
					$fieldName) && $repository instanceof Selectable)
				{
					$expression = new Comparison($fieldName,
						Comparison::MEMBER_OF, $object);
					$criteria = Criteria::create()->andWhere(
						$expression);

					/**
					 *
					 * @var Collection $list
					 */
					$list = $repository->matching($criteria);

					if ($managed instanceof ArrayCollection)
					{
						$filtered = $managed->matching($criteria);
						foreach ($filtered as $element)
						{
							if (!$list->contains($element))
								$list->add($element);
						}
					}

					foreach ($list as $o)
					{
						$oid = \spl_object_id($o);
						if (\in_array($oid, $visited))
							continue;
						$visited[] = $oid;

						$collection = $property->getValue($o);
						$collection->removeElement($object);
						$property->setValue($o, $collection);
						$this->persist($o);
					}
				}
			}
		}
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

		$repository = $this->getRepository($className);
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

		return $this->basePath . '/' . $dm->getClassDirectory(
			$className);
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
	 * @var ListenerInvoker
	 */
	private $objectListenerInvoker;
}
