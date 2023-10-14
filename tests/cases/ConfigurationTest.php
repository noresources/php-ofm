<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\TestCase;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use NoreSources\OFM\OFMSetup;
use NoreSources\Persistence\Mapping\Driver\ReflectionDriver;

class ConfigurationTest extends \PHPUnit\Framework\TestCase
{

	public function testDefaultDescriptor()
	{
		$configuration = OFMSetup::createConfigurationFromDescriptor([]);

		$actual = $configuration->getMappingDriver();
		$this->assertInstanceOf(ReflectionDriver::class, $actual);

		$actual = $configuration->getMetadataFactory();
		$this->assertInstanceOf(ClassMetadataFactory::class, $actual);
	}

	public function testDescriptor()
	{
		$descriptor = [
			'mapping-driver' => [
				'paths' => [
					__DIR__ . '/../referecnes/dcm'
				],
				'class' => XmlDriver::class
			],
			'class-metadata' => [
				'class' => ClassMetadata::class
				// 'factory' => ClassMetadataFactory::class
			],
			'filesystem' => [ // 'base-path' => '.',
				// 'directory-mapper' => QualifiedClassNameDirectoryMapper::class,
				// 'filename-mapper' => DefaultFilenameMapper::class,
				'media-type' => 'text/csv',
				'extension' => 'csv'
			]
		];

		$configuration = OFMSetup::createConfigurationFromDescriptor(
			$descriptor);

		$this->assertInstanceOf(XmlDriver::class,
			$configuration->getMappingDriver(),
			'User-defined maping driver');

		$mediaType = \strval($configuration->getFileMediaType());
		$this->assertEquals('text/csv', $mediaType,
			'User-defined media type');

		$this->assertEquals('csv', $configuration->getFileExtension(),
			'User-defined file extension');
	}
}
