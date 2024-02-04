<?php
namespace NoreSources\OFM\TestData;

/**
 *
 * @persistent-entity table=products
 */
class Product
{

	/**
	 *
	 * @persistent-id generator=AUTO
	 * @var integer
	 */
	protected $id;

	/**
	 *
	 * @persistent-field
	 */
	protected $name;

	public function getId()
	{
		return $this->id;
	}

	public function getName()
	{
		return $this->name;
	}

	public function setName($name)
	{
		$this->name = $name;
	}
}
