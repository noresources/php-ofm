<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem\Traits;

use NoreSources\OFM\Filesystem\DirectoryMapperInterface;

/**
 * Trait for objects that define a object directory mapping strategy
 */
trait DirectoryMapperAwareTrait
{

	/**
	 *
	 * @return string
	 */
	public function getBasePath()
	{
		return $this->basePath;
	}

	/**
	 *
	 * @param string $path
	 *        	Base path
	 */
	public function setBasePath($path)
	{
		$this->basePath = $path;
	}

	/**
	 *
	 * @return DirectoryMapperInterface
	 */
	public function getDirectoryMapper()
	{
		return $this->directoryMapper;
	}

	/**
	 *
	 * @param DirectoryMapperInterface $mapper
	 *        	Object storage directory mapping strategy
	 */
	public function setDirectoryMapper(DirectoryMapperInterface $mapper)
	{
		$this->directoryMapper = $mapper;
	}

	/**
	 *
	 * @var DirectoryMapperInterface
	 */
	private $directoryMapper;

	/**
	 *
	 * @var string Directory path
	 */
	private $basePath;
}
