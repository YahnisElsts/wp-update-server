<?php
/**
 * Simple request class for the update server.
 */
class Wpup_Request {
	/** @var array Query parameters. */
	public $query = array();
	/** @var string The name of the current action. For example, "get_metadata". */
	public $action;
	/** @var string Plugin or theme slug from the current request. */
	public $slug;
	/** @var Wpup_Package The package that matches the current slug, if any. */
	public $package;

	/** @var array Other, arbitrary request properties. */
	protected $props = array();

	public function __construct($query, $action, $slug = null, $package = null) {
		$this->query = $query;
		$this->action = $action;
		$this->slug = $slug;
		$this->package = $package;
	}

	public function __get($name) {
		if ( array_key_exists($name, $this->props) ) {
			return $this->props[$name];
		}
		return null;
	}

	public function __set($name, $value) {
		$this->props[$name] = $value;
	}

	public function __isset($name) {
		return isset($this->props[$name]);
	}

	public function __unset($name) {
		unset($this->props[$name]);
	}
}