<?php
/**
 * A very basic cache interface.
 */
interface Wpup_Cache {
	/**
	 * Get cached value.
	 *
	 * @param string $key
	 * @return mixed|null
	 */
	function get($key);

	/**
	 * Update the cache.
	 *
	 * @param string $key Cache key.
	 * @param mixed $value The value to store in the cache.
	 * @param int $expiration Time until expiration, in seconds. Optional.
	 * @return void
	 */
	function set($key, $value, $expiration = 0);

	/**
	 * Clear a cache
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	function clear($key);
}
