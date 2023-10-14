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
 * @persistent-entity table=Test_EntityWithEmbeddedObject; schema=Tests
 * @persistent-lifecycle-callbacks pre-persist=prePersistTask
 * @persistent-entity-listener class="\\NoreSources\\OFM\\TestUtility\\TestEntityListener"
 *
 */
class EntityWithEmbeddedObject
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
	 * Embedded product
	 *
	 * @persistent-field
	 * @var Product
	 */
	public $product;
}
