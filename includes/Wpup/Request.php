<?php
class Wpup_Request {
	/** @var array */
	public $query = array();
	/** @var string */
	public $action;
	/** @var string */
	public $slug;
	/** @var Wpup_Package */
	public $package;

	public function __construct($query, $action, $slug = null, $package = null) {
		$this->query = $query;
		$this->action = $action;
		$this->slug = $slug;
		$this->package = $package;
	}
}