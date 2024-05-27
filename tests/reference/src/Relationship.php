<?php

/**
 * Copyright © 2024 by Renaud Guillard (dev@nore.fr)
 * Distributed under the terms of the MIT License, see LICENSE
 *
 * @package OFM
 */
namespace NoreSources\OFM\TestData;

/**
 *
 * @persistent-object
 *
 */
class Relationship
{

	public function __construct($id, User $a, User $b, $type = 'link')
	{
		$this->id = $id;
		$this->firstUser = $a;
		$this->secondUser = $b;
		$this->relationType = $type;
	}

	/**
	 * First user
	 *
	 * @persistent-many-to-one
	 * @var User|NULL
	 */
	public $firstUser;

	/**
	 * Second user
	 *
	 * @persistent-many-to-one
	 * @var User|NULL
	 */
	public $secondUser;

	/**
	 * Relation type
	 *
	 * @persistent-property
	 * @var string
	 */
	public $relationType;

	/**
	 *
	 * @persistent-id
	 * @var integer
	 */
	private $id;
}
