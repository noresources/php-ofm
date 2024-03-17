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
use Doctrine\Persistence\Mapping\ClassMetadata;
use NoreSources\Container\Container;
use NoreSources\Data\Serialization\SerializationManager;
use NoreSources\MediaType\MediaTypeFactory;
use NoreSources\OFM\OFMSetup;
use NoreSources\OFM\TestData\Bug;
use NoreSources\OFM\TestData\Customer;
use NoreSources\OFM\TestData\EntityWithEmbeddedObject;
use NoreSources\OFM\TestData\Person;
use NoreSources\OFM\TestData\Product;
use NoreSources\OFM\TestData\User;
use NoreSources\Persistence\ObjectManagerAwareInterface;
use NoreSources\Persistence\ObjectManagerProviderInterface;
use NoreSources\Persistence\ObjectManagerRegistry;
use NoreSources\Persistence\Event\ListenerInvoker;
use NoreSources\Persistence\Mapping\ClassMetadataAdapter;
use NoreSources\Persistence\Mapping\GenericClassMetadataFactory;
use NoreSources\Persistence\Mapping\ObjectManagerRegistryClassMetadataFactory;
use NoreSources\Persistence\Mapping\PropertyMappingInterface;
use NoreSources\Persistence\Mapping\Driver\MappingDriverProviderInterface;
use NoreSources\Persistence\TestUtility\ResultComparisonTrait;
use NoreSources\Persistence\TestUtility\TestEntityManagerFactoryTrait;
use NoreSources\Test\DerivedFileTestTrait;
use NoreSources\Type\TypeDescription;

class FileSerializationObjectManagerTest extends \PHPUnit\Framework\TestCase
{

	use DerivedFileTestTrait;
	use TestEntityManagerFactoryTrait;
	use ResultComparisonTrait;

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
			[
				Product::class,
				ClassMetadataAdapter::getFullyQualifiedClassName(
					Product::class, $productMetadata)
			],
			[
				Product::class,
				ClassMetadataAdapter::getFullyQualifiedClassName(
					'Product', $productMetadata)
			],
			[
				User::class,
				ClassMetadataAdapter::getFullyQualifiedClassName('User',
					$productMetadata)
			]
		] as $test)
		{
			$expected = $test[0];
			$actual = $test[1];
			$this->assertEquals($expected, $actual);
		}

		$productA = new Product();
		$productA->setName('AProductThatMakeEverything');

		$this->assertNull($productA->getId(), 'ID is initially NULL');

		$productB = new Product();
		$foredId = '_SoMeThiNgThat-wiLl-NoT-bE-aGeneraTed-ID_!_';
		$productB->forceIdValue($foredId);

		$this->assertEquals($foredId, $productB->getId(),
			'ProductB ID before persist');

		$fsManager->persist($productA);
		$this->assertNotNull($productA->getId(),
			'ProductA ID set after persist');

		$fsManager->persist($productB);
		$this->assertNotEquals($foredId, $productB->getId(),
			'Product B ID re-assigned after persist new.');

		$fsManager->flush();

		$filename = $fsManager->getObjectFile($productA);
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

		$mappingDriver = $configuration->getMappingDriver();
		$factory = $configuration->getMetadataFactory();
		$this->assertTrue(!$factory->isTransient(User::class),
			TypeDescription::getLocalName($factory) . ' using ' .
			TypeDescription::getLocalName($mappingDriver) .
			' has metadata for User');

		$configuration->setSerializationManager($serializationManager);
		$configuration->setFileMediaType($mediaType);
		$configuration->setBasePath($basePath);
		$firstManager = OFMSetup::createObjectManager($configuration);

		$mappingDriver = new XmlDriver(
			[
				$this->getReferenceFileDirectory() . '/dcm'
			]);
		$factory = new GenericClassMetadataFactory();
		$factory->setMappingDriver($mappingDriver);
		$factory->setMetadataClass(
			\Doctrine\ORM\Mapping\ClassMetadata::class);

		$this->assertTrue(!$factory->isTransient(User::class),
			TypeDescription::getLocalName($factory) . ' using ' .
			TypeDescription::getLocalName($mappingDriver) .
			' has metadata for User');

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
		$factory = new GenericClassMetadataFactory();
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
		$isDevMode = false;
		$em = $this->createEntityManagerForTest($method, [
			$className
		]);

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
			'$productA type in metadata');

		$productA = new Product();
		$productName = 'A wonderful product';
		$productA->setName($productName);

		$e = new EntityWithEmbeddedObject();
		$e->id = 1;
		$e->product = $productA;

		$this->assertEquals($productName, $productA->getName(),
			'Product name');

		$manager->persist($e);
		$manager->flush();
	}

	public function testInheritance()
	{
		$method = __METHOD__;
		$suffix = '';
		$isDevMode = false;
		$classNames = [
			Person::class,
			Customer::class
		];

		$em = $this->createEntityManagerForTest($method, $classNames,
			$isDevMode);

		$ofm = $this->createFileObjectManagerForTest($method);

		$emFactory = $em->getMetadataFactory();
		$ofmFactory = $ofm->getMetadataFactory();

		$this->compareImplementation(
			[
				'isTransient' => [
					Person::class
				],
				'isTransient' => [
					Customer::class
				],
				'isTransient' => [
					self::class
				]
			], $emFactory, $ofmFactory, 'Metadata factory');

		$className = Customer::class;

		$emCustommerMetadata = $em->getClassMetadata($className);
		$ofmCustommerMetadata = $ofm->getClassMetadata($className);

		$this->compareImplementation(
			[
				'getFieldNames',
				'GetAssociationNames',
				'isIdentifier' => [
					'id'
				],
				'hasField' => [
					'firstName'
				],
				'getTypeOfField' => [
					'sex'
				]
			], $emCustommerMetadata, $ofmCustommerMetadata,
			'Customer class');

		foreach ([
			$em,
			$ofm
		] as $manager)
		{
			$this->runCustomerTest($manager);
		}
	}

	public function runCustomerTest(ObjectManager $manager)
	{
		$ns = TypeDescription::getNamespaces($manager);
		$label = $ns[0] . ' ' . TypeDescription::getLocalName($manager) .
			': ';
		$one = new Customer(1);
		$one->firstName = 'The';
		$one->lastName = 'One';
		$one->setPrivateData('M');
		$one->birthDate = new \DateTime('2024-02-11T14:47:05+0100');

		$className = Customer::class;
		if (($existing = $manager->find($className, 1)))
		{
			$manager->remove($existing);
			$manager->flush();
		}

		$this->assertNull($manager->find($className, 1),
			$label . '"One" Initially not existing');
		$manager->persist($one);
		$manager->flush();
		$this->assertNotNull($manager->find($className, 1),
			$label . '"One" Now exist');
	}

	public function testAssociations()
	{
		$method = __METHOD__;
		$suffix = '';
		$isDevMode = false;
		$classNames = [
			User::class,
			Bug::class,
			Bug::class,
			Product::class,
			Customer::class
		];

		$em = $this->createEntityManagerForTest($method, $classNames);
		$ofm = $this->createFileObjectManagerForTest($method);

		foreach ([
			$em,
			$ofm
		] as $manager)
		{
			$this->runTestAssociations($manager);
		}
	}

	public function runTestAssociations(ObjectManager $objectManager)
	{
		$prefix = TypeDescription::getLocalName($objectManager) . ' | ';
		$engineer = new User('engineer');
		$this->assertEquals('engineer', $engineer->getId(),
			$prefix . 'User constructor');
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
		$this->assertEquals($productId, $product->getId(),
			$prefix . 'Product ID');

		/**
		 *
		 * @var ClassMetadata $bugMetadata
		 */
		$bugMetadata = $objectManager->getClassMetadata(Bug::class);

		$this->assertTrue($bugMetadata->hasAssociation('reporter'),
			$prefix . 'Bog ass reporter association');
		$reporterTargetClass = $bugMetadata->getAssociationTargetClass(
			'reporter');

		$this->assertEquals(User::class, $reporterTargetClass,
			$prefix . '$reporter target class');

		/**
		 *
		 * @var Bug $bug
		 */
		$bug = $objectManager->find(Bug::class, $bugId);
		$this->assertInstanceOf(Bug::class, $bug);

		$bugProducts = $bug->getProducts();
		$this->assertCount(1, $bugProducts,
			$prefix . 'Number of products concerned by the bug');
		$firstBugProduct = $bugProducts[0];
		$this->assertInstanceOf(Product::class, $firstBugProduct,
			$prefix . 'Bug products are Product');
		$this->assertEquals($productId, $firstBugProduct->getId(),
			$prefix . 'Bug product ID');
	}

	/**
	 * Check if our ObjectManager reacts the same way as Doctrine ORM EntityManager
	 */
	public function testObjectManagerBehavior()
	{
		$method = __METHOD__;
		$isDevMode = true;
		$ofm = $this->createFileObjectManagerForTest($method);
		$em = $this->createEntityManagerForTest($method,
			[
				Product::class,
				Bug::class,
				User::class
			], $isDevMode);

		$objectManagers = [
			TypeDescription::getLocalName($em) => $em,
			TypeDescription::getLocalName($ofm) => $ofm
		];

		$className = Product::class;

		$product = new Product();
		$product->setName('P1');

		$this->runContainsComparison($em, $ofm, $product,
			'Initial state');
		$this->assertNull($product->getId(),
			'Product ID is initially null');

		$ofm->persist($product);
		$productIdFromOFM = $product->getId();
		$this->assertNotNull($productIdFromOFM,
			'FM metadata factory has an immediate ID generator');
		$em->persist($product);
		$this->assertEquals($productIdFromOFM, $product->getId(),
			'EM did not modify P1 ID since EM ID generator is a post flush generator');

		$this->runContainsComparison($em, $ofm, $product,
			'ObjectManager (after persist)');

		foreach ($objectManagers as $name => $om)
			$om->flush();

		$productIdFromEM = $product->getId();
		$this->assertNotEquals($productIdFromEM, $productIdFromOFM,
			'EM has has modified ID after flush');

		$this->runContainsComparison($em, $ofm, $product,
			'ObjectManager (flushed)');

		$modifiedId = $product->getId() * 2;
		$modifiedName = $product->getName() . ' (modified ID - ' .
			$modifiedId . ')';
		$product->setName($modifiedName);
		$product->forceIdValue($modifiedId);

		$this->compareImplementation(
			[
				'find' => [
					$className,
					$modifiedId
				]
			], $em, $ofm, 'find() with a modified ID');

		foreach ($objectManagers as $name => $om)
		{
			$om->persist($product);
			$om->flush();
		}

		$this->assertEquals($modifiedId, $product->getId(),
			'Modified ID not altered after persist/flush');

		foreach ($objectManagers as $name => $om)
			$om->clear();

		$this->runContainsComparison($em, $ofm, $product,
			'ObjectManager (clear)');

		$this->assertFalse($em->contains($product),
			'EM does not contains P1 after clear');

		$productFromEM = $em->find($className, $productIdFromEM);
		$this->assertNotNull($productFromEM,
			'Found P1 in EM by original ID');
		$productFromOFM = $ofm->find($className, $productIdFromOFM);
		$this->assertNotNull($productFromOFM,
			'P1 found in FM by original ID');

		$this->compareImplementation([
			'getName'
		], $productFromEM, $productFromOFM, 'find by original ID');

		$this->assertTrue($em->contains($productFromEM),
			'Product retrieved by EM find() is managed');
		$this->assertTrue($ofm->contains($productFromOFM),
			'Product found by OFM find() is managed');

		$sameProductFromEM = $em->find($className, $productIdFromEM);
		$this->assertEquals($productFromEM, $sameProductFromEM);
		$a = \spl_object_hash($productFromEM);
		$b = \spl_object_hash($sameProductFromEM);
		$this->assertEquals($a, $b,
			'Same call to EM::find(id) returns two different PHP objects.');

		$sameProductFromOFM = $ofm->find($className, $productIdFromOFM);
		$this->assertEquals($productFromOFM, $sameProductFromOFM);
		$a = \spl_object_hash($productFromOFM);
		$b = \spl_object_hash($sameProductFromOFM);
		$this->assertEquals($a, $b,
			'Same call to OFM::find(id) returns two different PHP objects.');

		$productFromEMRepository = $em->getRepository($className)->findBy(
			[
				'name' => $modifiedName
			]);
		$this->assertIsArray($productFromEMRepository,
			'EM::findBy(name) result');
		$productFromEMRepository = Container::lastValue(
			$productFromEMRepository);
		$this->assertNotNull($productFromEMRepository,
			'EM::findBy(name) found a product');
		$this->assertTrue($em->contains($productFromEMRepository),
			'Product found by EM::findBy(name) is managed');

		$productFromOFMRepository = $ofm->getRepository($className)->findBy(
			[
				'name' => $modifiedName
			]);
		$this->assertIsArray($productFromOFMRepository,
			'OFM::findBy(name) result');
		$productFromOFMRepository = Container::lastValue(
			$productFromOFMRepository);
		$this->assertNotNull($productFromOFMRepository,
			'OFM::findBy(name) found something');
		$this->assertTrue($ofm->contains($productFromOFMRepository),
			'Product found by OFM::findBy(name) is managed');

		$badName = 'Bleeh';
		foreach ([
			[
				$em,
				$productFromEM,
				$productIdFromEM
			],
			[
				$ofm,
				$productFromOFM,
				$productIdFromOFM
			]
		] as $test)
		{
			$manager = $test[0];
			$managerName = TypeDescription::getLocalName($manager);
			$object = $test[1];
			$object->setName($badName);
			$id = $test[2];

			$this->assertTrue($manager->contains($object),
				$managerName . '::contains() before refresh()');

			$manager->refresh($object);

			$this->assertTrue($manager->contains($object),
				$managerName . '::contains() after refresh()');

			$this->assertEquals($modifiedName, $object->getName(),
				$managerName . '::refresh() has restored $name');

			$manager->detach($object);
			$this->assertFalse($manager->contains($object),
				$managerName . '::contains() after detach()');

			$o = $manager->find($className, $id);
			$this->assertNotNull($o,
				$managerName . ' object ' . $id .
				' is still foundable after detach');

			$h1 = \spl_object_hash($object);
			$h2 = \spl_object_hash($o);
			$this->assertNotEmpty($a, $b,
				$managerName .
				' Finding a detached object must return a different object');

			$exception = null;
			try
			{
				$manager->remove($object);
			}
			catch (\Exception $e)
			{
				$exception = $e;
			}
			$this->assertInstanceOf(\Exception::class, $exception,
				$managerName . ' cannot remove a detached object.');

			$object = $o;
			$this->assertTrue($manager->contains($object),
				$managerName .
				'::contains() after persist() (before remove)');

			$manager->remove($object);

			$this->assertFalse($manager->contains($object),
				$managerName . '::contains() after remove()');

			$manager->flush();

			$o = $manager->find($className, $id);
			$this->assertNull($o,
				$managerName . ' object ' . $id .
				' is removed after flush');
		}
	}

	protected function runContainsComparison(ObjectManager $a,
		ObjectManager $b, $object, $testName)
	{
		$tests = [
			'contains' => [
				$object
			]
		];
		$this->compareImplementation($tests, $a, $b, $testName);
	}

	public function createFileObjectManagerForTest($method)
	{
		$a = TypeDescription::getLocalName($this);
		$b = \preg_replace('/(?:.*::)?(?:test)?(.*)/', '$1', $method);
		$classPaths = [
			$this->getReferenceFileDirectory() . '/src'
		];
		$serializer = new SerializationManager();
		$mediaType = MediaTypeFactory::getInstance()->createFromString(
			'application/json');
		$ofmConfiguration = OFMSetup::createReflectionDriverConfiguration(
			$classPaths);
		$ofmConfiguration->setBasePath(
			$this->getDerivedFileDirectory() . '/' . $a . '/' . $b);
		$ofmConfiguration->setSerializationManager($serializer);
		$ofmConfiguration->setFileMediaType($mediaType);
		$ofm = OFMSetup::createObjectManager($ofmConfiguration);

		return $ofm;
	}

	public function createEntityManagerForTest($method,
		$classNames = array(), $isDevMode = false)
	{
		$a = TypeDescription::getLocalName($this);
		$b = \preg_replace('/(?:.*::)?(?:test)?(.*)/', '$1', $method);
		$extension = 'sqlite';
		$suffix = '';
		$dcmPaths = [
			$this->getReferenceFileDirectory() . '/dcm'
		];
		$configuration = ORMSetup::createXMLMetadataConfiguration(
			$dcmPaths, $isDevMode);
		$databasePath = $this->getDerivedFilename($method, $suffix,
			$extension);
		$this->assertCreateFileDirectoryPath($databasePath,
			'Database path');
		$em = $this->createEntityManager($configuration, $databasePath,
			$classNames);
		$this->appendDerivedFilename($databasePath, $isDevMode);
		return $em;
	}
}
