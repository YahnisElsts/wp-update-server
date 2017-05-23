<?php
/**
 * Simple request class for the update server.
 */
class Wpup_Request {
	/** @var array Query parameters. */
	public $query = array();
	/** @var string Client's IP address. */
	public $clientIp;
	/** @var string The HTTP method, e.g. "POST" or "GET". */
	public $httpMethod;
	/** @var string The name of the current action. For example, "get_metadata". */
	public $action;
	/** @var string Plugin or theme slug from the current request. */
	public $slug;
	/** @var Wpup_Package The package that matches the current slug, if any. */
	public $package = null;

	/** @var string WordPress version number as extracted from the User-Agent header. */
	public $wpVersion = null;
	/** @var string WordPress site URL, also from the User-Agent. */
	public $wpSiteUrl = null;

	/** @var array Other, arbitrary request properties. */
	protected $props = array();

	public function __construct($query, $headers, $clientIp = '0.0.0.0', $httpMethod = 'GET') {
		$this->query = $query;
		$this->headers = new Wpup_Headers($headers);
		$this->clientIp = $clientIp;
		$this->httpMethod = strtoupper($httpMethod);

		$this->action = preg_replace('@[^a-z0-9\-_]@i', '', $this->param('action', ''));
		$this->slug = preg_replace('@[:?/\\\]@i', '', $this->param('slug', ''));

		//If the request was made via the WordPress HTTP API we can usually
		//get WordPress version and site URL from the user agent.
		$regex = '@WordPress/(?P<version>\d[^;]*?);\s+(?P<url>https?://.+?)(?:\s|;|$)@i';
		if ( preg_match($regex, $this->headers->get('User-Agent', ''), $matches) ) {
			$this->wpVersion = $matches['version'];
			$this->wpSiteUrl = $matches['url'];
		}
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
}