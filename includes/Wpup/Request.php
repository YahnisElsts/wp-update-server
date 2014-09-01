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
	/** @var string WP Version of the site making the request */
	public $wpVersion;
	/** @var string Url of the site making the request */
	public $wpSiteUrl;

	/** @var array Other, arbitrary request properties. */
	protected $props = array();

	public function __construct($query, $action, $slug = null, $package = null) {
		$this->query = $query;
		$this->action = $action;
		$this->slug = $slug;
		$this->package = $package;
		$this->wpVersion = self::getWpVersion();
		$this->wpSiteUrl = self::getWpSiteUrl();
	}

	/**
	 * Get the value of a query parameter.
	 *
	 * @param string $name Parameter name.
	 * @param mixed $default The value to return if the parameter doesn't exist. Defaults to null.
	 * @return mixed
	 */
	public function param($name, $default = null) {
		if ( array_key_exists($name, $this->query) ) {
			return $this->query[$name];
		} else {
			return $default;
		}
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
	
	public static function getWpVersion() {
		return self::_whoIsAsking('version');
	}

	public static function getWpSiteUrl() {
		return self::_whoIsAsking('siteurl');
	}

	protected static function _whoIsAsking( $info ) {
		static $wpVersion = null;
		static $wpSiteUrl = null;
		static $parsed    = false;
		
		if ( $parsed === false ) {
			//If the request was made via the WordPress HTTP API we can usually
			//get WordPress version and site URL from the user agent.
			$regex = '@WordPress/(?P<version>\d[^;]*?);\s+(?P<url>https?://.+?)(?:\s|;|$)@i';
			if ( isset($_SERVER['HTTP_USER_AGENT']) && preg_match($regex, $_SERVER['HTTP_USER_AGENT'], $matches) ) {
				$wpVersion = $matches['version'];
				$wpSiteUrl = $matches['url'];
			}
			$parsed = true;
		}
		
		if ( $info==='version' ) {
			return $wpVersion;
		}
		elseif ( $info==='siteurl' ) {
			return $wpSiteUrl;
		}
		else {
			return null;
		}
	}
}