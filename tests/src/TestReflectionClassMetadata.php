<?php

/**
 * Copyright © 2023 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\TestUtility;

use Doctrine\Persistence\Mapping\ClassMetadata;

class TestReflectionClassMetadata implements ClassMetadata
{

	public function __construct($classOrObject)
	{
		if (\is_object($classOrObject))
			$this->reflection = new \ReflectionObject($classOrObject);
		else
			$this->reflection = new \ReflectionClass($classOrObject);
	}

	public function isIdentifier($fieldName)
	{
		return \in_array($fieldName, $this->getIdentifierFieldNames());
	}

	public function getName()
	{
		return $this->reflection->getName();
	}

	public function getTypeOfField($fieldName)
	{
		return 'string';
	}

	public function getAssociationMappedByTargetField($assocName)
	{
		return null;
	}

	public function getFieldNames()
	{
		$properties = $this->reflection->getProperties();
		return \array_map(function ($p) {
			return $p->getName();
		}, $properties);
	}

	public function getIdentifierFieldNames()
	{
		$names = $this->getFieldNames();
		return \array_filter($names,
			function ($n) {
				return $n == 'id' || \preg_match('/.+Id$/', $n);
			});
	}

	public function getAssociationNames()
	{
		return [];
	}

	public function getIdentifier()
	{
		return $this->getIdentifierFieldNames();
	}

	public function getIdentifierValues($object)
	{
		$names = $this->getIdentifierFieldNames();
		$values = [];
		foreach ($names as $name)
		{
			$values[$name] = $this->reflection->getProperty($name)->getValue(
				$object);
		}
		return $values;
	}

	public function hasAssociation($fieldName)
	{
		return false;
	}

	public function isCollectionValuedAssociation($fieldName)
	{
		return false;
	}

	public function getReflectionClass()
	{
		return $this->reflection;
	}

	public function hasField($fieldName)
	{
		return $this->reflection->hasProperty($fieldName);
	}

	public function isSingleValuedAssociation($fieldName)
	{
		return false;
	}

	public function getAssociationTargetClass($assocName)
	{
		return null;
	}

	public function isAssociationInverseSide($assocName)
	{
		return false;
	}

	/**
	 *
	 * @var \ReflectionClass|\ReflectionObject
	 */
	private $reflection;
}
