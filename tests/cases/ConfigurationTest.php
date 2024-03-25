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
use NoreSources\OFM\TestUtility\LocalClassNameDirectoryMapper;
use NoreSources\Persistence\Mapping\Driver\ReflectionDriver;
use NoreSources\Test\DerivedFileTestTrait;

class ConfigurationTest extends \PHPUnit\Framework\TestCase
{

	use DerivedFileTestTrait;

	public function setUp(): void
	{
		$this->setUpDerivedFileTestTrait(__DIR__ . '/..');
	}

	public function tearDown(): void
	{
		$this->tearDownDerivedFileTestTrait();
	}

	public function testDefaultDescriptor()
	{
		$configuration = OFMSetup::createConfigurationFromDescriptor([]);

		$actual = $configuration->getMappingDriver();
		$this->assertInstanceOf(ReflectionDriver::class, $actual);

		$actual = $configuration->getMetadataFactory();
		$this->assertInstanceOf(ClassMetadataFactory::class, $actual);
	}

	public function testLocalClassNameDirectoryMapper()
	{
		$namespaceDepth = 1;
		$className = self::class;
		$mapper = new LocalClassNameDirectoryMapper($namespaceDepth);
		$actual = $mapper->getClassDirectory($className);
		$expected = 'TestCase/ConfigurationTest';
		$this->assertEquals($expected, $actual,
			'Custom directory mapping');

		$tests = [
			'Class name only' => [
				'descriptor' => [
					'filesystem' => [
						'directory-mapper' => LocalClassNameDirectoryMapper::class
					]
				],
				'expected' => 'ConfigurationTest'
			],
			'With constructor parameters' => [
				'descriptor' => [
					'filesystem' => [
						'directory-mapper' => [
							'class-name' => LocalClassNameDirectoryMapper::class,
							'namespace-depth' => 1
						]
					]
				],
				'expected' => 'TestCase/ConfigurationTest'
			],
			'With object' => [
				'descriptor' => [
					'filesystem' => [
						'directory-mapper' => $mapper
					]
				],
				'expected' => 'TestCase/ConfigurationTest'
			]
		];

		foreach ($tests as $label => $test)
		{
			$descriptor = $test['descriptor'];
			$expected = $test['expected'];
			$configuration = OFMSetup::createConfigurationFromDescriptor(
				$descriptor);
			$mapper = $configuration->getDirectoryMapper();
			$this->assertInstanceOf(
				LocalClassNameDirectoryMapper::class, $mapper, $label);
			$actual = $mapper->getClassDirectory($className);
			$this->assertEquals($expected, $actual, $label);
		}
	}

	public function testFile()
	{
		if (!\extension_loaded('json'))
			return $this->assertFalse(\extension_loaded('json'),
				'No JSON extension');

		$derivedPath = $this->getDerivedFileDirectory();

		$this->assertCreateFileDirectoryPath($derivedPath . '/a-file',
			'Derived path');
		$derivedPath = \realpath($derivedPath);
		$variables = [
			'project' => \realpath(__DIR__ . '/../..')
		];
		$filename = __DIR__ . '/../data/configuration.json';
		$configuration = OFMSetup::createConfigurationFromDescriptorFile(
			$filename, null, $variables);
		$driver = $configuration->getMappingDriver();

		$this->assertEquals($derivedPath, $configuration->getBasePath(),
			'Absolute base path');
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
