<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\Filesystem;

use NoreSources\SingletonTrait;
use NoreSources\Container\Container;
use NoreSources\Type\TypeConversion;
use NoreSources\Type\TypeDescription;

/**
 * Default file name mapper.
 */
class DefaultFilenameMapper implements FilenameMapperInterface
{

	use SingletonTrait;

	/**
	 * Safe character list.
	 *
	 * If the identifier exclusively contains those character,
	 * the file name will corresponds to the identifier.
	 */
	const SAFE_IDENTIFIER_PATTERN = '/^[a-z0-9-][a-z0-9_\.-]*$/i';

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\OFM\Filesystem\FilenameMapperInterface::getIdentifier()
	 */
	public function getIdentifier($basename)
	{
		if (\substr($basename, 0, 1) != '_')
			return $basename;
		return \base64_decode(\substr($basename, 1));
	}

	/**
	 *
	 * {@inheritdoc}
	 * @see \NoreSources\OFM\Filesystem\FilenameMapperInterface::getBasename()
	 */
	public function getBasename($identifier)
	{
		if (Container::isArray($identifier))
		{
			$c = Container::count($identifier);

			if ($c > 1)
			{
				throw new \RuntimeException(
					'Composite identifier (' .
					\implode(', ', \array_keys($identifier)) .
					') is not supported');
			}
			$identifier = Container::firstValue($identifier);
		}

		if (!TypeDescription::hasStringRepresentation($identifier))
			throw new \InvalidArgumentException(
				'Invalid identifier type. Stringable expected. Got ' .
				TypeDescription::getName($identifier));

		$identifier = TypeConversion::toString($identifier);
		$encode = false;
		if (\substr($identifier, 0, 1) == '_')
			$encode = true;
		elseif (!\preg_match(self::SAFE_IDENTIFIER_PATTERN, $identifier))
			$encode = true;
		if ($encode)
			return '_' . \base64_encode($identifier);
		return $identifier;
	}
}
