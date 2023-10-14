<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

/**
 * Object file directory mapping strategy
 */
interface DirectoryMapperInterface
{

	/**
	 * Get the file system directory for the given object class name
	 *
	 * @param unknown $className
	 *        	Directory path
	 */
	function getClassDirectory($className);

	/**
	 * #returnboolean TRUE if paths provided by getClassDirectory() should be considered as absolute
	 * paths.
	 */
	function isAbsolute();
}
