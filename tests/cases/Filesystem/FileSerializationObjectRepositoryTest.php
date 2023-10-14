<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\TestCase\Filesystem;

use Doctrine\Persistence\ObjectRepository;
use NoreSources\Data\Serialization\SerializationManager;
use NoreSources\MediaType\MediaTypeFactory;
use NoreSources\OFM\Filesystem\DefaultFilenameMapper;
use NoreSources\OFM\Filesystem\FileSerializationObjectRepository;
use NoreSources\OFM\TestUtility\TestReflectionClassMetadata;
use NoreSources\Test\DerivedFileTestTrait;

class FileSerializationObjectRepositoryTest extends \PHPUnit\Framework\TestCase
{

	use DerivedFileTestTrait;

	public $testId;

	public $testName = self::class;

	public function setUp(): void
	{
		$this->setUpDerivedFileTestTrait(__DIR__ . '/../..');
	}

	public function tearDown(): void
	{
		$this->tearDownDerivedFileTestTrait();
	}

	public function testMetadata()
	{
		$metadata = new TestReflectionClassMetadata($this);
		$fields = $metadata->getFieldNames();
		$this->assertContains('testName', $fields, 'This class fields.');

		$ids = $metadata->getIdentifierFieldNames();
		$this->assertContains('testId', $fields, 'This class ID fields.');
	}

	public function testConstruct()
	{
		$serializer = new SerializationManager();
		$mediaType = MediaTypeFactory::getInstance()->createFromString(
			'application/json');
		$metadata = new TestReflectionClassMetadata($this);
		$basePath = $this->getDerivedFileDirectory();
		$filenameMapper = new DefaultFilenameMapper();
		$repository = new FileSerializationObjectRepository($metadata,
			$serializer, $basePath, $filenameMapper, $mediaType);
		$this->assertInstanceOf(ObjectRepository::class, $repository);
		return $repository;
	}

	public function testPersistAndRemove()
	{
		$repository = $this->testConstruct();
		$this->testId = __METHOD__;
		$this->testName = 'File serialization persister';
		$repository->persist($this);

		$metadata = new TestReflectionClassMetadata($this);
		$filename = $repository->getObjectFile($this, $metadata);
		$this->assertFileExists($filename, 'This object serialized file');
		$this->appendDerivedFilename($filename, false);

		$thisFromFile = $repository->find($this->testId);
		$this->assertInstanceOf(self::class, $thisFromFile,
			'Retrieve from file');
		foreach ([
			'testId',
			'testName'
		] as $field)
		{
			$this->assertEquals($this->$field, $thisFromFile->$field,
				$field . ' value');
		}
		$repository->remove($this);
	}
}
