<?php

/**
 * Copyright © 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use Doctrine\Persistence\ObjectManager;
use NoreSources\Container\Container;
use NoreSources\Data\Primitifier;
use NoreSources\Persistence\Mapping\ClassMetadataAdapter;
use NoreSources\Persistence\Mapping\ClassMetadataReflectionPropertyMapper;
use NoreSources\Type\TypeConversion;

/**
 * Extension of the ClassMetadataReflectionPropertyMapper that handle object associations and
 * embedded obect properties
 */
class FileSerializationPropertyMapper extends ClassMetadataReflectionPropertyMapper
{

	/**
	 * This method override transforms association property values to association identifiers
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\Persistence\Mapping\ClassMetadataReflectionPropertyMapper::fetchObjectAssociationProperty()
	 *
	 */
	protected function fetchObjectAssociationProperty($object,
		$fieldName)
	{
		$field = $this->getReflectionService()->getAccessibleProperty(
			$this->getClassMetadata()
				->getName(), $fieldName);
		$value = $field->getValue($object);
		$metadata = $this->getClassMetadata();

		$associationClassName = $metadata->getAssociationTargetClass(
			$fieldName);
		$objectManager = $this->getObjectManager();
		if (!$objectManager)
			throw new \RuntimeException(
				ObjectManager::class . ' is not set.');
		if (!($associationClassName &&
			($associationClassName = ClassMetadataAdapter::getFullyQualifiedClassName(
				$associationClassName, $metadata))))
			return $value;

		$associationMetadata = $objectManager->getClassMetadata(
			$associationClassName);

		if ($metadata->isSingleValuedAssociation($fieldName))
		{
			if (\is_object($value) &&
				\is_a($value, $associationClassName))
			{
				$id = $associationMetadata->getIdentifierValues($value);
				if (Container::count($id) == 1)
					return Container::firstValue($id);
			}

			return $value;
		}

		$ids = [];
		if (Container::isTraversable($value))
			foreach ($value as $o)
			{
				$id = $o;
				if (\is_object($o) && \is_a($o, $associationClassName))
				{
					$id = $associationMetadata->getIdentifierValues($o);
					if (Container::count($id) == 1)
						$id = Container::firstValue($id);
				}
				$ids[] = $id;
			}

		return $ids;
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\Persistence\Mapping\ClassMetadataReflectionPropertyMapper::serializeObjectEmbeddedObjectProperty()
	 */
	protected function serializeObjectEmbeddedObjectProperty($value,
		$fieldName, $type)
	{
		if (!(\is_object($value) && \is_a($value, $type)))
			return $value;

		$primitifier = $this->getPrimitifier();
		return $primitifier($value);
	}

	/**
	 * This method override instanciate an object of the type of the field and set its properties
	 * using reflection service.
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\Persistence\Mapping\ClassMetadataReflectionPropertyMapper::unserializeEmbeddedObject()
	 */
	protected function unserializeEmbeddedObject($value, $fieldName,
		$expectedClassName)
	{
		if (\is_string($value))
		{
			try
			{
				$v = @\unserialize($value);
				if (\is_a($v, $expectedClassName))
					return $v;
			}
			catch (\Exception $e)
			{}
		}
		try
		{
			return TypeConversion::to($expectedClassName, $value,
				[
					TypeConversion::OPTION_FLAGS => TypeConversion::OPTION_FLAG_OBJECT_FACTORY
				]);
		}
		catch (\Exception $e)
		{}

		if (!Container::isTraversable($value))
			return $value;
		$rs = $this->getReflectionService();
		$instantiator = $this->getInstantiator();
		$instance = $instantiator->instantiate($expectedClassName);
		foreach ($value as $k => $v)
		{
			$field = $rs->getAccessibleProperty($expectedClassName, $k);
			$field->setValue($instance, $value);
		}
		return $instance;
	}

	/**
	 *
	 * @return \NoreSources\Data\Primitifier
	 */
	public function getPrimitifier()
	{
		if (!isset($this->primitifier))
			$this->primitifier = new Primitifier();
		return $this->primitifier;
	}

	/**
	 *
	 * @var Primitifier
	 */
	private $primitifier;
}
