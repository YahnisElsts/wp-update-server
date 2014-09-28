<?php

/**
 * Auto load class files
 *
 * @param   string  $class  Class name
 * @return  void
 */
function wpup_auto_load($class) {
	static $classes = null;

	if ($classes === null) {
		$classes = array(
			'wpup_package'  => __DIR__ . '/includes/Wpup/Package.php',
			'wpup_invalidpackageexception' => __DIR__ . '/includes/Wpup/InvalidPackageException.php',
			'wpup_request' => __DIR__ . '/includes/Wpup/Request.php',
			'wpup_cache' => __DIR__ . '/includes/Wpup/Cache.php',
			'wpup_filecache' => __DIR__ . '/includes/Wpup/FileCache.php',
			'wpup_updateserver' => __DIR__ . '/includes/Wpup/UpdateServer.php',
		);

		if ( !class_exists('WshWordPressPackageParser') ) {
			$classes['wshwordpresspackageparser'] = __DIR__ . '/includes/extension-meta/extension-meta.php';
		}
	}

	$cn = strtolower($class);

	if ( isset($classes[ $cn ])) {
		require_once($classes[ $cn ]);
	}
}
spl_autoload_register('wpup_auto_load');