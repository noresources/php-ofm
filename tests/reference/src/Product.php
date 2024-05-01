<?php
namespace NoreSources\OFM\TestData;

/**
 *
 * @persistent-object table=products
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
	 * @persistent-property
	 */
	protected $name;

	public function getId()
	{
		return $this->id;
	}

	public function forceIdValue($id)
	{
		$this->id = $id;
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
