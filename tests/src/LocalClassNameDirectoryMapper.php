<?php

/**
 * Copyright © 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\TestUtility;

use NoreSources\OFM\Filesystem\DirectoryMapperInterface;
use NoreSources\Type\TypeDescription;

class LocalClassNameDirectoryMapper implements DirectoryMapperInterface
{

	public $namespaceDepth = 0;

	public function __construct($namespaceDepth = 0)
	{
		$this->namespaceDepth = $namespaceDepth;
	}

	public function getClassDirectory($className)
	{
		$localName = TypeDescription::getLocalName($className, true);
		if ($this->namespaceDepth <= 0)
			return $localName;
		$a = [
			$localName
		];
		$namespace = TypeDescription::getNamespaces($className, true);
		for ($i = 0; $i < $this->namespaceDepth; $i++)
			array_unshift($a, \array_pop($namespace));
		return \implode('/', $a);
	}

	public function isAbsolute()
	{
		return false;
	}
}
