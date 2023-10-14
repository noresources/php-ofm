<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM;

use NoreSources\OFM\Filesystem\DefaultFilenameMapper;
use NoreSources\OFM\Filesystem\DirectoryMapperInterface;
use NoreSources\OFM\Filesystem\FilenameMapperInterface;
use NoreSources\OFM\Filesystem\QualifiedClassNameDirectoryMapper;
use NoreSources\OFM\Filesystem\Traits\DirectoryMapperAwareTrait;
use NoreSources\OFM\Filesystem\Traits\FilenameStrategyTrait;
use NoreSources\OFM\Filesystem\Traits\SerializationStrategyTrait;

/**
 * File-based object manager configuration
 */
class Configuration extends \NoreSources\Persistence\Configuration
{
	use FilenameStrategyTrait;
	use DirectoryMapperAwareTrait;
	use SerializationStrategyTrait;

	/**
	 *
	 * @return DirectoryMapperInterface User defined directory mapper or
	 *         QualifiedClassNameDirectoryMapper otherwise.
	 */
	public function getDirectoryMapper()
	{
		if (!isset($this->directoryMapper))
			$this->directoryMapper = new QualifiedClassNameDirectoryMapper();
		return $this->directoryMapper;
	}

	/**
	 *
	 * @return FilenameMapperInterface User-defined object file name mapper or DefaultFilenameMapper
	 *         otherwise
	 */
	public function getFilenameMapper()
	{
		if (!isset($this->filenameMapper))
			$this->filenameMapper = new DefaultFilenameMapper();
		return $this->filenameMapper;
	}
}
