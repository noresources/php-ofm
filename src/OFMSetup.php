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
use NoreSources\Path;
use NoreSources\Container\Container;
use NoreSources\Data\Serialization\SerializationManager;
use NoreSources\MediaType\MediaTypeFactory;
use NoreSources\OFM\Filesystem\DirectoryMapperInterface;
use NoreSources\OFM\Filesystem\FileSerializationObjectManager;
use NoreSources\OFM\Filesystem\FilenameMapperInterface;
use NoreSources\Persistence\Cache\CacheItemPoolAwareInterface;
use NoreSources\Persistence\Mapping\Driver\ReflectionDriver;
use NoreSources\Text\Text;
use NoreSources\Type\TypeDescription;

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
	 * Create a configuration from a structured description.
	 *
	 * @param array $descriptor
	 *        	Configuration descriptor
	 * @param $workingDirectory Reference
	 *        	directory for relative paths
	 * @return Configuration
	 */
	public static function createConfigurationFromDescriptor(
		$descriptor, $workingDirectory = null, $variables = array())
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

		$options = Container::MERGE_RECURSE;
		$descriptor = Container::merge($defaults, $descriptor, $options);

		$flags = 0;
		if (Container::keyValue($descriptor, 'development', false))
			$flags |= self::DEVELOPMENT;
		$configuration = new Configuration($flags);

		$mappingDriverDescriptor = Container::keyValue($descriptor,
			'mapping-driver', []);

		$metadata = Container::keyValue($descriptor, 'class-metadata',
			[]);
		$filesystem = Container::keyValue($descriptor, 'filesystem', []);

		$paths = [];
		if (($v = Container::keyValue($mappingDriverDescriptor, 'paths',
			[])))
		{
			foreach ($v as $text)
				$paths[] = self::processConfigurationVariables($text,
					$variables);
		}

		if (\is_string($workingDirectory))
		{
			$paths = \array_map(
				function ($path) use ($workingDirectory) {
					$p = $path;
					if (!Path::isAbsolute($path))
						$p = $workingDirectory . '/' . $path;
					if (!\is_dir($p))
						throw new \InvalidArgumentException(
							'Invalid mapping driver path "' . $path . '"');
					return \realpath($p);
				}, $paths);
		}

		$object = null;
		$overrides = [
			'paths' => $paths,
			'locator' => $paths
		];

		if (self::retrieveObjectFromDescriptor($object, $descriptor,
			'mapping-driver', $variables, $overrides,
			MappingDriver::class))
		{
			$configuration->setMappingDriver($object);
		}

		$objectKey = 'factory';
		$object = null;
		if (self::retrieveObjectFromDescriptor($object, $metadata,
			$objectKey, $variables, [], ClassMetadataFactory::class))
		{
			$configuration->setMetadataFactory($factory);
		}

		if (($v = Container::keyValue($filesystem, 'base-path')))
		{
			$p = self::processConfigurationVariables($v, $variables);
			if (\is_string($workingDirectory) && !Path::isAbsolute($v))
				$p = $workingDirectory . '/' . $v;

			if (!\is_dir($p))
				throw new \InvalidArgumentException(
					'Base directory "' . $v . '" does not exists');

			$configuration->setBasePath(\realpath($p));
		}

		$objectKey = 'directory-mapper';
		if (self::retrieveObjectFromDescriptor($object, $filesystem,
			$objectKey, $variables, [], DirectoryMapperInterface::class))
		{
			$configuration->setDirectoryMapper($object);
		}

		$objectKey = 'filename-mapper';
		if (self::retrieveObjectFromDescriptor($object, $filesystem,
			$objectKey, $variables, [], FilenameMapperInterface::class))
		{
			$configuration->setFilenameMapper($object);
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
	 * Create configuration from structured description file.
	 *
	 * @param string $filename
	 *        	Descriptor file name
	 * @param string|NULL $workingDirectory
	 *        	Base directory for relative paths. If NULL, use $filename directory as working
	 *        	directory.
	 * @throws \InvalidArgumentException
	 * @return \NoreSources\OFM\Configuration
	 */
	public static function createConfigurationFromDescriptorFile(
		$filename, $workingDirectory = null, $variables = array())
	{
		if (!\file_exists($filename))
			throw new \InvalidArgumentException(
				'Configuration descriptor file not found');
		if ($workingDirectory === null)
			$workingDirectory = \dirname(\realpath($filename));
		$serializer = new SerializationManager();
		$descriptor = $serializer->unserializeFromFile($filename);
		return self::createConfigurationFromDescriptor($descriptor,
			$workingDirectory, $variables);
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

	/**
	 *
	 * @param object $object
	 *        	Output object
	 * @param array $descriptor
	 *        	Descriptor
	 * @param string $objectKey
	 *        	Object key in descriptor
	 * @param string|NULL $expectedInstanceOf
	 * @return boolean TRUE if correctly retrieved
	 */
	protected static function retrieveObjectFromDescriptor(&$object,
		$descriptor, $objectKey, $variables, $overrides = array(),
		$expectedInstanceOf = null)
	{
		if (($descriptor = Container::keyValue($descriptor, $objectKey,
			null)) === null)
			return false;

		if (\is_string($descriptor))
			$descriptor = [
				'class-name' => $descriptor
			];

		if (\is_array($descriptor))
		{
			$key = 'class-name';
			if (!Container::keyExists($descriptor, $key))
				$key = 'class';
			$className = Container::keyValue($descriptor, $key);
			if (!(\is_string($className) && \class_exists($className)))
				return false;
			unset($descriptor[$key]);

			$descriptor = self::constructObjectFromDescriptor(
				$className, $descriptor, $variables, $overrides);
		}

		if (!\is_object($descriptor))
			throw new \InvalidArgumentException(
				'Unexpected object descriptor type ' .
				TypeDescription::getName($descriptor) .
				'. Expect string, array or object.');

		if (\is_string($expectedInstanceOf))
		{
			if (!\is_a($descriptor, $expectedInstanceOf))
				throw new \InvalidArgumentException(
					$expectedInstanceOf . ' expected');
		}

		$object = $descriptor;
		return true;
	}

	protected static function constructObjectFromDescriptor($className,
		$parameters, $variables, $parameterOverrides = array())
	{
		$class = new \ReflectionClass($className);
		$constructor = $class->getConstructor();
		if (!$constructor)
			return $class->newInstance();
		$arguments = [];
		foreach ($constructor->getParameters() as $parameter)
		{
			/**
			 *
			 * @var \ReflectionParameter $parameter
			 */
			$name = $parameter->getName();
			if (Container::keyExists($parameterOverrides, $name))
			{
				$value = Container::keyValue($parameterOverrides, $name);
				$arguments[] = $value;
				continue;
			}
			$kebabName = Text::toKebabCase($name);
			if (!Container::keyExists($parameters, $name) &&
				!Container::keyExists($parameters, $kebabName))
			{
				if ($parameter->isOptional())
					break;
				try
				{
					$value = $parameter->getDefaultValue();
					$arguments[] = $value;
				}
				catch (\Exception $e)
				{
					throw new \RuntimeException(
						'Missing ' . $class->getName() .
						' constructor argument $' . $name);
				}
				continue;
			}

			$value = Container::keyValue($parameters, $name,
				Container::keyValue($parameters, $kebabName));
			$arguments[] = $value;
		}

		return $class->newInstanceArgs($arguments);
	}

	/**
	 *
	 * @param string $text
	 *        	Text
	 * @param array $variables
	 *        	Variable names and values
	 */
	protected static function processConfigurationVariables($text,
		$variables)
	{
		do
		{
			$previous = $text;
			foreach ($variables as $name => $value)
				$text = \str_replace('${' . $name . '}', $value, $text);
		}
		while ($previous != $text);
		return $text;
	}
}
