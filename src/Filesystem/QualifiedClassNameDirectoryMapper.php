<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

/**
 * Use class name as the name of directory.
 */
class QualifiedClassNameDirectoryMapper implements
	DirectoryMapperInterface
{

	/**
	 * Character that will replace backspace (PHP namespace separator)
	 *
	 * @var string
	 */
	public $namespaceSeperator = '.';

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\OFM\Filesystem\DirectoryMapperInterface::getClassDirectory()
	 */
	public function getClassDirectory($className)
	{
		return \str_replace('\\', $this->namespaceSeperator, $className);
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\OFM\Filesystem\DirectoryMapperInterface::isAbsolute()
	 */
	public function isAbsolute()
	{
		return false;
	}
}
