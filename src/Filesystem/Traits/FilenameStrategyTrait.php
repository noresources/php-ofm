<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem\Traits;

use NoreSources\OFM\Filesystem\FilenameMapperInterface;

/**
 * Trait for objects that define an object filename mapping strategy
 */
trait FilenameStrategyTrait
{

	/**
	 *
	 * @return FilenameMapperInterface
	 */
	public function getFilenameMapper()
	{
		return $this->filenameMapper;
	}

	/**
	 *
	 * @param FilenameMapperInterface $filenameMapper
	 */
	public function setFilenameMapper(
		FilenameMapperInterface $filenameMapper = null)
	{
		$this->filenameMapper = $filenameMapper;
	}

	/**
	 * string
	 */
	public function getFileExtension()
	{
		return $this->extension;
	}

	/**
	 *
	 * @param string $extension
	 *        	Filename extension
	 */
	public function setFileExtension($extension)
	{
		$this->extension = $extension;
	}

	/**
	 *
	 * @var FilenameMapperInterface
	 */
	protected $filenameMapper;

	/**
	 *
	 * @var string|NULL
	 */
	protected $extension;
}