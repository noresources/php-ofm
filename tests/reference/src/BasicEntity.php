<?php

/**
 * Copyright © 2023 - 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\TestData;

/**
 *
 * @persistent-entity table=Test_BasicEntity; schema=Tests
 * @persistent-lifecycle-callbacks pre-persist=prePersistTask
 * @persistent-entity-listener class="\\NoreSources\\OFM\\TestUtility\\TestEntityListener"
 *
 */
class BasicEntity
{

	/**
	 * The entity unique ID
	 *
	 * @persistent-id
	 *
	 * @var integer
	 */
	public $id;

	/**
	 * The entity name
	 *
	 * @persistent-field
	 * @var string
	 */
	public $name;

	/**
	 *
	 * @persistent-field
	 * @var string|NULL
	 */
	public $nickname;

	/**
	 * A property which won't be mapped to DB
	 *
	 * @var unknown
	 */
	public $notMapped;

	public function prePersistTask()
	{
		if (empty($this->nickname))
			$this->nickname = $this->name;
		if (!isset($this->secret))
			$this->secret = new \DateTime('@0');
	}

	public function setSecret(\DateTimeInterface $d)
	{
		$this->secret = $d;
	}

	/**
	 * The date of the beginning of the master plan to rule the world.
	 *
	 * @persistent-field
	 * @var \DateTimeInterface
	 */
	private $secret;
}
