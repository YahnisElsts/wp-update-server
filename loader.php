<?php
require_once __DIR__ . '/includes/Wpup/Package.php';
require_once __DIR__ . '/includes/Wpup/ZipMetadataParser.php';
require_once __DIR__ . '/includes/Wpup/InvalidPackageException.php';
require_once __DIR__ . '/includes/Wpup/Request.php';
require_once __DIR__ . '/includes/Wpup/Headers.php';
require_once __DIR__ . '/includes/Wpup/Cache.php';
require_once __DIR__ . '/includes/Wpup/FileCache.php';
require_once __DIR__ . '/includes/Wpup/UpdateServer.php';

if ( !class_exists('WshWordPressPackageParser') ) {
	require_once __DIR__ . '/includes/extension-meta/extension-meta.php';
}