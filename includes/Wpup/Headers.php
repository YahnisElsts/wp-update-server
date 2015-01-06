<?php
class Wpup_Headers implements ArrayAccess, IteratorAggregate, Countable {
	protected $headers = array();

	/**
	 * HTTP headers stored in the $_SERVER array are usually prefixed with "HTTP_" or "X_".
	 * These special headers don't have that prefix, so we need an explicit list to identify them.
	 *
	 * @var array
	 */
	protected static $unprefixedNames = array(
		'CONTENT_TYPE',
		'CONTENT_LENGTH',
		'PHP_AUTH_USER',
		'PHP_AUTH_PW',
		'PHP_AUTH_DIGEST',
		'AUTH_TYPE'
	);

	public function __construct($headers = array()) {
		foreach ($headers as $name => $value) {
			$this->set($name, $value);
		}
	}

	/**
	 * Extract HTTP headers from an array of data (usually $_SERVER).
	 *
	 * @param array $environment
	 * @return array
	 */
	public static function parse($environment) {
		$results = array();
		foreach ($environment as $key => $value) {
			$key = strtoupper($key);
			if ( self::isHeaderName($key) ) {
				//Remove the "HTTP_" prefix that PHP adds to headers stored in $_SERVER.
				$key = preg_replace('/^HTTP[_-]/', '', $key);
				$results[$key] = $value;
			}
		}
		return $results;
	}

	/**
	 * Check if a $_SERVER key looks like a HTTP header name.
	 *
	 * @param string $key
	 * @return bool
	 */
	protected static function isHeaderName($key) {
		return self::startsWith($key, 'X_')
		|| self::startsWith($key, 'HTTP_')
		|| in_array($key, static::$unprefixedNames);
	}

	/**
	 * Parse headers for the current HTTP request.
	 * Will automatically choose the best way to get the headers from PHP.
	 *
	 * @return array
	 */
	public static function parseCurrent() {
		//getallheaders() is the easiest solution, but it's not available in some server configurations.
		if ( function_exists('getallheaders') ) {
			$headers = getallheaders();
			if ( $headers !== false ) {
				return $headers;
			}
		}
		return self::parse($_SERVER);
	}

	/**
	 * Convert a header name to "Title-Case-With-Dashes".
	 *
	 * @param string $name
	 * @return string
	 */
	protected function normalizeName($name) {
		$name = strtolower($name);

		$name = str_replace(array('_', '-'), ' ', $name);
		$name = ucwords($name);
		$name = str_replace(' ', '-', $name);

		return $name;
	}

	/**
	 * Check if a string starts with the given prefix.
	 *
	 * @param string $string
	 * @param string $prefix
	 * @return bool
	 */
	protected static function startsWith($string, $prefix) {
		return (substr($string, 0, strlen($prefix)) === $prefix);
	}

	/**
	 * Get the value of a HTTP header.
	 *
	 * @param string $name Header name.
	 * @param mixed $default The default value to return if the header doesn't exist.
	 * @return string|null
	 */
	public function get($name, $default = null) {
		$name = $this->normalizeName($name);
		if ( isset($this->headers[$name]) ) {
			return $this->headers[$name];
		}
		return $default;
	}

	/**
	 * Set a header to value.
	 *
	 * @param string $name
	 * @param string $value
	 */
	public function set($name, $value) {
		$name = $this->normalizeName($name);
		$this->headers[$name] = $value;
	}

	/* ArrayAccess interface */

	public function offsetExists($offset) {
		return array_key_exists($offset, $this->headers);
	}

	public function offsetGet($offset) {
		return $this->get($offset);
	}

	public function offsetSet($offset, $value) {
		$this->set($offset, $value);
	}

	public function offsetUnset($offset) {
		$name = $this->normalizeName($offset);
		unset($this->headers[$name]);
	}

	/* Countable interface */

	public function count() {
		return count($this->headers);
	}

	/* IteratorAggregate interface  */

	public function getIterator() {
		return new ArrayIterator($this->headers);
	}

}