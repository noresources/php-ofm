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
 * @persistent-entity mapped-superclass=true
 *
 */
class Person
{

	/**
	 * First name
	 *
	 * @persistent-field
	 * @var string|NULL
	 */
	public $firstName;

	/**
	 * Last name
	 *
	 * @persistent-field
	 * @var string|NULL
	 */
	public $lastName;

	public function setPrivateData($sex)
	{
		$this->sex = $sex;
	}

	public function getSex()
	{
		return $this->sex;
	}

	const SEX_MALE = 1;

	const SEX_FEMALE = 0;

	/**
	 *
	 * Birth sex
	 *
	 * @persistent-field
	 *
	 * @var integer|null
	 */
	private $sex;
}
