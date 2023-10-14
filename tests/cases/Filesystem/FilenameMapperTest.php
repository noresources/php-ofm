<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\TestCase;

use NoreSources\OFM\Filesystem\DefaultFilenameMapper;

class FilenameMapperTest extends \PHPUnit\Framework\TestCase
{

	public function testDefaultMapping()
	{
		$mapper = new DefaultFilenameMapper();
		foreach ([
			'id' => true,
			'.hidden' => false,
			'' => false,
			'_underscore' => false,
			'underscore_after' => true,
			'a.b' => true,
			'123' => true,
			'--foo' => true
		] as $identifier => $basename)
		{
			if ($basename === true)
				$basename = $identifier;
			if ($basename === false)
				$basename = '_' . \base64_encode($identifier);
			$actual = $mapper->getBasename($identifier);
			$this->assertEquals($basename, $actual,
				'Identifier <' . $identifier . '> to basenmae');
			$actual = $mapper->getIdentifier($basename);
			$this->assertEquals($identifier, $actual,
				'Basenmae <' . $basename . '> to identifier');
		}
	}
}
