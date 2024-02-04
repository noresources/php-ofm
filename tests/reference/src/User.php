<?php
namespace NoreSources\OFM\TestData;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 *
 * @Entity @Table(name="users")
 * @persistent-entity table=users
 */
class User
{

	/**
	 * User ID
	 *
	 * @persistent-id generator=AUTO
	 * @var string
	 */
	protected $id;

	/**
	 *
	 * @persistent-field
	 * @var string
	 */
	protected $name;

	/**
	 *
	 * @OneToMany(targetEntity="Bug", mappedBy="reporter")
	 * @persistent-one-to-many mapped-by=reporter
	 * @var Bug[]
	 */
	protected $reportedBugs = null;

	/**
	 *
	 * @OneToMany(targetEntity="Bug", mappedBy="engineer")
	 * @persistent-one-to-many mapped-by=engineer
	 * @var Bug[]
	 */
	protected $assignedBugs = null;

	/**
	 *
	 * @persistent-field
	 * @var integer
	 */
	public $persistCount = 0;

	/**
	 *
	 * @persistent-field
	 * @var integer
	 */
	public $updateCount = 0;

	public function __construct($id = null)
	{
		$this->id = $id;
		$this->reportedBugs = new ArrayCollection();
		$this->assignedBugs = new ArrayCollection();
	}

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

	public function addReportedBug($bug)
	{
		$this->reportedBugs[] = $bug;
	}

	public function assignedToBug($bug)
	{
		$this->assignedBugs[] = $bug;
	}

	/**
	 *
	 * @param LifecycleEventArgs $event
	 */
	public static function prePersistTask($event)
	{
		$object = $event->getObject();
		if (empty($object->name))
			$object->name = \NoreSources\Text\Text::toHumanCase(
				$object->id);
		$object->persistCount++;
	}

	public static function preUpdateTask($event)
	{
		$object = $event->getObject();
		$object->updateCount++;
	}
}
