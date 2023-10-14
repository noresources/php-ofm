<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

/**
 * Transform an object identifier to a filename
 */
interface FilenameMapperInterface
{

	/**
	 * Get the file base name for the given object identifier
	 *
	 * @param string|array $identifier
	 *        	Identifier to transform to a file system base file name
	 * @return string Filesystem base file name corresponding to the given identifier.
	 */
	function getBasename($identifier);

	/**
	 * Get object identifier from a file system base file name
	 *
	 * @param string $basename
	 *        	Filesystem base name.
	 * @return string|array Object identifier
	 */
	function getIdentifier($basename);
}
