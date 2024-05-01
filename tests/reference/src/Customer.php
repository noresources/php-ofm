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
class Customer extends Person
{

	public function getId()
	{
		return $this->id;
	}

	public function __construct($id)
	{
		$this->id = $id;
	}

	/**
	 *
	 * @persistent-property
	 * @var \DateTimeInterface
	 */
	public $birthDate;

	/**
	 *
	 * @persistent-id
	 * @var integer
	 */
	private $id;
}
