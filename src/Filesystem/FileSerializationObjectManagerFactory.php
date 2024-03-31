<?php

/**
 * Copyright © 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use NoreSources\Container\Container;
use NoreSources\OFM\OFMSetup;
use NoreSources\Persistence\ObjectManagerFactoryInterface;

/**
 * Creates a FileSerializationObjectManager from a descriptor
 */
class FileSerializationObjectManagerFactory implements
	ObjectManagerFactoryInterface
{

	/**
	 * Default parameters.
	 *
	 * These parameters are pre-merged to parameters passed to createObjectManagerFromDescriptor()
	 *
	 * @var array
	 */
	public $defaults = [];

	/**
	 * Working directory to use when handling relative paths
	 *
	 * @var string|NULL
	 */
	public $workingDirectory = null;

	/**
	 * Environment variables
	 *
	 * @var array
	 */
	public $environment = [];

	/**
	 *
	 * @param callable $configurationFinalizer
	 *        	A function to call to post process ObjectManager configuration. The callable will
	 *        	receive the configuration as unique argument.
	 * @throws \InvalidArgumentException
	 */
	public function setConfigurationFinalizer($configurationFinalizer)
	{
		if (!\is_callable($configurationFinalizer))
			throw new \InvalidArgumentException('Not callable');
		$this->configurationFinalizer = $configurationFinalizer;
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\Persistence\ObjectManagerFactoryInterface::createObjectManager()
	 * @return FileSerializationObjectManager
	 */
	public function createObjectManager($parameters)
	{
		if (Container::isTraversable($parameters))
			$parameters = Container::merge($this->defaults, $parameters,
				Container::MERGE_RECURSE);
		$configuration = OFMSetup::createConfigurationFromDescriptor(
			$parameters, $this->workingDirectory, $this->environment);
		if (\is_callable($this->configurationFinalizer))
			\call_user_func($this->configurationFinalizer,
				$configuration);
		return OFMSetup::createObjectManager($configuration);
	}

	/**
	 *
	 * @var callable
	 */
	private $configurationFinalizer;
}
