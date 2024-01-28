<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\TestCase\Filesystem;

use Doctrine\Common\EventManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use NoreSources\Data\Serialization\SerializationManager;
use NoreSources\MediaType\MediaTypeFactory;
use NoreSources\OFM\OFMSetup;
use NoreSources\OFM\TestData\Bug;
use NoreSources\OFM\TestData\EntityWithEmbeddedObject;
use NoreSources\OFM\TestData\Product;
use NoreSources\OFM\TestData\User;
use NoreSources\Persistence\ObjectManagerAwareInterface;
use NoreSources\Persistence\ObjectManagerProviderInterface;
use NoreSources\Persistence\ObjectManagerRegistry;
use NoreSources\Persistence\PropertyMappingInterface;
use NoreSources\Persistence\Event\ListenerInvoker;
use NoreSources\Persistence\Mapping\ClassMetadataAdapter;
use NoreSources\Persistence\Mapping\ObjectManagerRegistryClassMetadataFactory;
use NoreSources\Persistence\Mapping\Driver\MappingDriverProviderInterface;
use NoreSources\Persistence\TestUtility\TestEntityManagerFactoryTrait;
use NoreSources\Persistence\TestUtility\TestMappingDriverClassMetadataFactory;
use NoreSources\Test\DerivedFileTestTrait;

class FileSerializationObjectManagerTest extends \PHPUnit\Framework\TestCase
{

	use DerivedFileTestTrait;
	use TestEntityManagerFactoryTrait;

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

	public function testIdGeneratorUsingManagerWithReflectionDriver()
	{
		$serializationManager = new SerializationManager();
		$mediaType = MediaTypeFactory::getInstance()->createFromString(
			'application/json');
		$basePath = $this->getDerivedFileDirectory();

		$paths = [
			$this->getReferenceFileDirectory() . '/src'
		];

		$className = Product::class;

		$configuration = OFMSetup::createReflectionDriverConfiguration(
			$paths);
		$configuration->setSerializationManager($serializationManager);
		$configuration->setBasePath($basePath);
		$configuration->setFileMediaType($mediaType);

		$fsManager = OFMSetup::createObjectManager($configuration);

		$this->assertInstanceOf(MappingDriverProviderInterface::class,
			$fsManager->getMetadataFactory(),
			'Metadata factory type in manager');

		$productMetadata = $fsManager->getMetadataFactory()->getMetadataFor(
			Product::class);
		$this->assertEquals(Product::class, $productMetadata->getName(),
			'ClassMetadata::getName() returns qualified class name');
		foreach ([
			Product::class => ClassMetadataAdapter::getFullyQualifiedClassName(
				Product::class, $productMetadata),
			Product::class => ClassMetadataAdapter::getFullyQualifiedClassName(
				'Product', $productMetadata),
			User::class => ClassMetadataAdapter::getFullyQualifiedClassName(
				'User', $productMetadata)
		] as $expected => $actual)
		{
			$this->assertEquals($expected, $actual);
		}

		$product = new Product();
		$product->setName('AProductThatMakeEverything');

		$fsManager->persist($product);
		$fsManager->flush();

		$filename = $fsManager->getObjectFile($product);
		$this->assertFileExists($filename, 'Product persistent file');
		$this->appendDerivedFilename($filename, false);
	}

	public function testObjectManagerRegistry()
	{
		if (!\class_exists(ObjectManagerRegistry::class))
			return $this->assertFalse(false,
				'Early version of noresources/persistence');

		$method = __METHOD__;
		$suffix = null;
		$serializationManager = new SerializationManager();
		$mediaType = MediaTypeFactory::getInstance()->createFromString(
			'application/json');
		$basePath = $this->getDerivedFileDirectory();

		$configuration = OFMSetup::createReflectionDriverConfiguration(
			[
				$this->getReferenceFileDirectory() . '/src'
			]);
		$configuration->setSerializationManager($serializationManager);
		$configuration->setFileMediaType($mediaType);
		$configuration->setBasePath($basePath);
		$firstManager = OFMSetup::createObjectManager($configuration);

		$mappingDriver = new XmlDriver(
			[
				$this->getReferenceFileDirectory() . '/dcm'
			]);
		$factory = new TestMappingDriverClassMetadataFactory();
		$factory->setMappingDriver($mappingDriver);
		$factory->setMetadataClass(
			\Doctrine\ORM\Mapping\ClassMetadata::class);
		$configuration = OFMSetup::createConfiguration();
		$configuration->setMappingDriver($mappingDriver);
		$configuration->setMetadataFactory($factory);
		$configuration->setBasePath($basePath);
		$configuration->setFileMediaType($mediaType);
		$configuration->setSerializationManager($serializationManager);

		$secondManager = OFMSetup::createObjectManager($configuration);

		$registry = new ObjectManagerRegistry();
		$registry->setObjectManager('first', $firstManager);
		$registry->setObjectManager('second', $secondManager);

		$registryMetadataFactory = $registry->getMetadataFactory();
		$this->assertInstanceOf(
			ObjectManagerRegistryClassMetadataFactory::class,
			$registryMetadataFactory);

		$none = $registry->find(User::class, 'who');
		$this->assertNull($none, 'Find a user that does not exists');
	}

	public function testUserPersistenceUsingManagerWithXmlDriverAndOrmClassMetadata()
	{
		$method = __METHOD__;
		$suffix = null;
		$serializationManager = new SerializationManager();
		$mediaType = MediaTypeFactory::getInstance()->createFromString(
			'application/json');
		$basePath = $this->getDerivedFileDirectory();

		$mappingDriver = new XmlDriver(
			[
				$this->getReferenceFileDirectory() . '/dcm'
			]);
		$factory = new TestMappingDriverClassMetadataFactory();
		$factory->setMappingDriver($mappingDriver);
		$factory->setMetadataClass(
			\Doctrine\ORM\Mapping\ClassMetadata::class);

		$invoker = new ListenerInvoker(new EventManager());

		$configuration = OFMSetup::createConfiguration();
		$configuration->setMappingDriver($mappingDriver);
		$configuration->setSerializationManager($serializationManager);
		$configuration->setMetadataFactory($factory);
		$configuration->setBasePath($basePath);
		$configuration->setFileMediaType($mediaType);

		$fsManager = OFMSetup::createObjectManager($configuration);
		$fsManager->setListenerInvoker($invoker);

		$className = User::class;

		$metadata = $fsManager->getClassMetadata($className);
		$idFields = $metadata->getIdentifierFieldNames();
		$this->assertEquals([
			'id'
		], $idFields, 'User id fields');

		$repository = $fsManager->getRepository($className);

		$this->assertInstanceOf(ObjectRepository::class, $repository,
			'Repository for ' . $className);

		$this->assertInstanceOf(ObjectManagerProviderInterface::class,
			$repository);
		$this->assertEquals($fsManager, $repository->getObjectManager(),
			'Repository has a object manager reference');

		$mapper = $repository->getPropertyMapper();
		$this->assertInstanceOf(PropertyMappingInterface::class, $mapper);
		$this->assertInstanceOf(ObjectManagerAwareInterface::class,
			$mapper);
		$this->assertInstanceOf(ObjectManagerProviderInterface::class,
			$mapper);
		$this->assertEquals($fsManager, $mapper->getObjectManager());
		// EntityManager
		$isDevMode = true;
		$configuration = ORMSetup::createConfiguration($isDevMode);
		$configuration->setMetadataDriverImpl($mappingDriver);
		$databasePath = $this->getDerivedFilename($method, $suffix,
			'sqlite');
		$this->assertCreateFileDirectoryPath($databasePath,
			'Database path');
		$em = $this->createEntityManager($configuration, $databasePath,
			[
				$className
			]);

		$this->appendDerivedFilename($databasePath, false);

		$a = new User('alice');
		$this->assertEquals(0, $a->persistCount,
			'User.persistCount initial value');
		$this->assertEquals(0, $a->updateCount,
			'User.updateCount initial value');

		if ($fsManager->find($className, 'alice'))
		{
			$fsManager->remove($a);
			$fsManager->flush();
		}

		if ($em->find($className, $a))
		{
			$em->remove($a);
			$em->flush();
		}

		$this->assertNull($fsManager->find($className, 'alice'),
			'Alice does not exist in FS repository');

		$this->assertNull($em->find($className, 'alice'),
			'Alice does not exist in EM repository');

		$this->assertFalse($fsManager->contains($a),
			'FS Unit of work does not contain alice');
		$this->assertFalse($em->contains($a),
			'EM Unit of work does not contain alice');

		$fsManager->persist($a);
		$this->assertEquals(1, $a->persistCount,
			'prePersist callback called just after first FS persist');

		$em->persist($a);
		$this->assertEquals(2, $a->persistCount,
			'prePersist callback called just after first EM persist');

		$this->assertEquals(0, $a->updateCount,
			'preUpdate callback not called on first persist');

		$this->assertTrue($fsManager->contains($a),
			'FS Unit of work contains alice');
		$this->assertTrue($em->contains($a),
			'EM Unit of work contains alice');

		$fsManager->flush();

		$found = $fsManager->find($className, 'alice');
		$this->assertEquals($a, $found, 'Alice was found');

		$filename = $fsManager->getObjectFile($a);
		$this->assertFileExists($filename, '$a object file');
		$this->appendDerivedFilename($filename, false);
	}

	public function testEntityWithEmbeddedObjectUsingReflectionDriver()
	{
		$method = __METHOD__;
		$suffix = null;
		$serializationManager = new SerializationManager();
		$mediaType = MediaTypeFactory::getInstance()->createFromString(
			'application/json');

		$basePath = $this->getDerivedFileDirectory();
		$classPaths = [
			$this->getReferenceFileDirectory() . '/src'
		];

		$configuration = OFMSetup::createReflectionDriverConfiguration(
			$classPaths);
		$configuration->setBasePath($basePath);
		$configuration->setFileMediaType($mediaType);
		$configuration->setSerializationManager($serializationManager);
		$manager = OFMSetup::createObjectManager($configuration);

		$metadata = $manager->getClassMetadata(
			EntityWithEmbeddedObject::class);
		$this->assertEquals(Product::class,
			$metadata->getTypeOfField('product'),
			'$product type in metadata');

		$product = new Product();
		$productName = 'A wonderful product';
		$product->setName($productName);

		$e = new EntityWithEmbeddedObject();
		$e->id = 1;
		$e->product = $product;

		$this->assertEquals($productName, $product->getName(),
			'Product name');

		$manager->persist($e);
		$manager->flush();
	}

	public function testAssociations()
	{
		$method = __METHOD__;
		$suffix = '';
		$isDevMode = true;
		$classNames = [
			User::class,
			Bug::class,
			Bug::class,
			Product::class
		];
		$dcmPaaths = [
			$this->getReferenceFileDirectory() . '/dcm'
		];

		$configuration = ORMSetup::createXMLMetadataConfiguration(
			$dcmPaaths, $isDevMode);
		$databasePath = $this->getDerivedFilename($method, $suffix,
			'sqlite');
		$this->assertCreateFileDirectoryPath($databasePath,
			'Database path');
		$em = $this->createEntityManager($configuration, $databasePath,
			$classNames);
		$this->appendDerivedFilename($databasePath, $isDevMode);

		$classPaths = [
			$this->getReferenceFileDirectory() . '/src'
		];
		$serializer = new SerializationManager();
		$mediaType = MediaTypeFactory::getInstance()->createFromString(
			'application/json');
		$ofmConfiguration = OFMSetup::createReflectionDriverConfiguration(
			$classPaths);
		$ofmConfiguration->setBasePath($this->getDerivedFileDirectory());
		$ofmConfiguration->setSerializationManager($serializer);
		$ofmConfiguration->setFileMediaType($mediaType);
		$fm = OFMSetup::createObjectManager($ofmConfiguration);

		foreach ([
			$em,
			$fm
		] as $manager)
		{
			$this->runTestAssociations($manager);
		}
	}

	public function runTestAssociations(ObjectManager $objectManager)
	{
		$engineer = new User('engineer');
		$this->assertEquals('engineer', $engineer->getId(),
			'User constructor');
		$reporter = new User('reporter');

		$product = new Product();
		$product->setName('Wonder Stuff');

		$bug = new Bug();
		$bug->setCreated(new \DateTime('now'));
		$bug->setDescription('Ooops');
		$bug->setReporter($reporter);
		$bug->setEngineer($engineer);
		$bug->assignToProduct($product);

		$objectManager->persist($bug);
		$objectManager->persist($engineer);
		$objectManager->persist($reporter);
		$objectManager->persist($product);
		$objectManager->flush();

		$productId = $product->getId();
		$bugId = $bug->getId();

		/**
		 *
		 * @var Product $product
		 */
		$product = $objectManager->find(Product::class, $productId);
		$this->assertInstanceOf(Product::class, $product);
		$this->assertEquals($productId, $product->getId(), 'Product ID');

		$bugMetadata = $objectManager->getClassMetadata(Bug::class);

		/**
		 *
		 * @var Bug $bug
		 */
		$bug = $objectManager->find(Bug::class, $bugId);
		$this->assertInstanceOf(Bug::class, $bug);

		$bugProducts = $bug->getProducts();
		$this->assertCount(1, $bugProducts,
			'Number of products concerned by the bug');
		$firstBugProduct = $bugProducts[0];
		$this->assertInstanceOf(Product::class, $firstBugProduct,
			'Bug products are Product');
		$this->assertEquals($productId, $firstBugProduct->getId(),
			'Bug product ID');
	}
}
