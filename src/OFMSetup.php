<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM;

use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use NoreSources\Container\Container;
use NoreSources\MediaType\MediaTypeFactory;
use NoreSources\OFM\Filesystem\DirectoryMapperInterface;
use NoreSources\OFM\Filesystem\FileSerializationObjectManager;
use NoreSources\OFM\Filesystem\FilenameMapperInterface;
use NoreSources\Persistence\Cache\CacheItemPoolAwareInterface;
use NoreSources\Persistence\Mapping\ClassMetadataAwareInterface;
use NoreSources\Persistence\Mapping\Driver\ReflectionDriver;

/**
 * Utility class to create OFM-related objects
 */
class OFMSetup
{

	/**
	 * Development context
	 *
	 * @var number
	 */
	const DEVELOPMENT = 0x01;

	/**
	 *
	 * @param integer $lfags
	 *        	Option flags
	 * @return Configuration
	 */
	public static function createConfiguration($flags = 0)
	{
		$configuration = new Configuration();
		$cache = $configuration->getCache();
		$factory = $configuration->getMetadataFactory();
		if (!$cache && ($factory instanceof CacheItemPoolAwareInterface) &&
			(($flags & self::DEVELOPMENT) == 0) &&
			($cache = Configuration::createCache()))
		{
			$factory->setCache($cache);
		}
		return $configuration;
	}

	/**
	 *
	 * @param array $paths
	 *        	Object class source paths
	 * @param integer $flags
	 *        	Option flags
	 * @return Configuration
	 */
	public static function createReflectionDriverConfiguration($paths,
		$flags = 0)
	{
		$configuration = self::createConfiguration($flags);
		$mappingDriver = new ReflectionDriver($paths);
		$configuration->setMappingDriver($mappingDriver);
		return $configuration;
	}

	/**
	 *
	 * @param array $descriptor
	 *        	Configuration descriptor
	 * @return COnfiguration
	 */
	public static function createConfigurationFromDescriptor(
		$descriptor)
	{
		$defaults = [
			'development' => false,
			'mapping-driver' => [ // 'paths' => []
				'class' => ReflectionDriver::class
			],
			'class-metadata' => [ // 'class' => \BasicClass::class,
			// 'factory' => ClassMetadataFactory::class
			],
			'filesystem' => [ // 'base-path' => '.',
			// 'directory-mapper' => QualifiedClassNameDirectoryMapper::class,
			// 'filename-mapper' => DefaultFilenameMapper::class,
			// 'media-type' => 'application/json',
			// 'extension' => 'json'
			]
		];
		$flags = 0;
		if (Container::keyValue($descriptor, 'development', false))
			$flags |= self::DEVELOPMENT;
		$configuration = new Configuration($flags);

		$driver = Container::keyValue($descriptor, 'mapping-driver',
			Container::keyValue($defaults, 'mapping-driver', []));

		$metadata = Container::keyValue($descriptor, 'class-metadata',
			Container::keyValue($defaults, 'metadata', []));

		$filesystem = Container::keyValue($descriptor, 'filesystem',
			Container::keyValue($defaults, 'filesystem', []));

		$paths = [];
		if (($v = Container::keyValue($driver, 'paths', [])))
		{
			$paths = $v;
		}

		if (($v = Container::keyValue($driver, 'class',
			Container::keyValue($defaults['mapping-driver'], 'class'))))
		{
			if (\is_string($v) && \class_exists($v) &&
				\is_a($v, MappingDriver::class, true))
			{
				$v = new $v($paths);
			}

			$configuration->setMappingDriver($v);
		}

		if (($v = Container::keyValue($metadata, 'factory')))
		{
			if (\is_string($v) && \class_exists($v) &&
				\is_a($v, ClassMetadataFactory::class, true))
				$v = new $v();

			if ($v instanceof ClassMetadataAwareInterface &&
				($c = Container::keyValue($metadata, 'class')))
				$v->setMetadataClass($c);

			$configuration->setMetadataFactory($v);
		}

		if (($v = Container::keyValue($filesystem, 'base-path')))
			$configuration->setBasePath($v);
		if (($v = Container::keyValue($filesystem, 'directormapper')))
		{
			if (\is_string($v) && \class_exists($v) &&
				\is_a($v, DirectoryMapperInterface::class, true))
				$v = new $v();
			$configuration->setDirectoryMapper($v);
		}
		if (($v = Container::keyValue($filesystem, 'filename-mapper')))
		{
			if (\is_string($v) && \class_exists($v) &&
				\is_a($v, FilenameMapperInterface::class, true))
				$v = new $v();
			$configuration->setFilenameMapper($v);
		}
		if (($v = Container::keyValue($filesystem, 'media-type')))
		{
			if (\is_string($v))
				$v = MediaTypeFactory::getInstance()->createFromString(
					$v);
			$configuration->setFileMediaType($v);
		}

		if (($v = Container::keyValue($filesystem, 'extension')))
			$configuration->setFileExtension($v);

		return $configuration;
	}

	/**
	 * Create ObjectManager using files to store each object.
	 *
	 * @param Configuration $configuration
	 *        	Object manager configuration
	 * @return FileSerializationObjectManager
	 */
	public static function createObjectManager(
		Configuration $configuration)
	{
		$manager = new FileSerializationObjectManager();
		$manager->configure($configuration);
		return $manager;
	}
}
