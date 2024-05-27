<?php
namespace NoreSources\OFM\TestData;

// repositories/BugRepository.php
use Doctrine\Common\Collections\ArrayCollection;
use DateTime;

/**
 *
 * @persistent-object table=bugs
 */
class Bug
{

	/**
	 *
	 * @persistent-id generator=AUTO
	 *
	 * @var string
	 */
	protected $id;

	/**
	 *
	 * @persistent-property type=text
	 */
	protected $description;

	/**
	 *
	 * @persistent-property
	 * @var \DateTimeInterface
	 */
	protected $created;

	/**
	 *
	 * @persistent-property
	 */
	protected $status = 'undefined';

	/**
	 *
	 * @persistent-many-to-one inversed-by=assignedBugs
	 * @var User|NULL
	 */
	protected $engineer;

	/**
	 *
	 * @ManyToOne(targetEntity="User", inversedBy="reportedBugs")
	 * @persistent-many-to-one inversed-by=reportedBugs
	 * @var User|NULL
	 */
	protected $reporter;

	/**
	 *
	 * @ManyToMany(targetEntity="Product")
	 * @persistent-many-to-many
	 * @var Product[]
	 */
	protected $products;

	/**
	 * Mostly for tests
	 *
	 * @param unknown $id
	 */
	public function setId($id)
	{
		$this->id = $id;
	}

	public function __construct()
	{
		$this->products = new ArrayCollection();
	}

	public function getId()
	{
		return $this->id;
	}

	public function getDescription()
	{
		return $this->description;
	}

	public function setDescription($description)
	{
		$this->description = $description;
	}

	public function setCreated(DateTime $created)
	{
		$this->created = $created;
	}

	public function getCreated()
	{
		return $this->created;
	}

	public function setStatus($status)
	{
		$this->status = $status;
	}

	public function getStatus()
	{
		return $this->status;
	}

	public function setEngineer($engineer)
	{
		$engineer->assignedToBug($this);
		$this->engineer = $engineer;
	}

	public function setReporter($reporter)
	{
		$reporter->addReportedBug($this);
		$this->reporter = $reporter;
	}

	public function getEngineer()
	{
		return $this->engineer;
	}

	public function getReporter()
	{
		return $this->reporter;
	}

	public function assignToProduct($product)
	{
		$this->products[] = $product;
	}

	public function getProducts()
	{
		return $this->products;
	}

	public function close()
	{
		$this->status = "CLOSE";
	}
}


