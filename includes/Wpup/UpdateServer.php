<?php
class Wpup_UpdateServer {
	const FILE_PER_DAY = 'Y-m-d';
	const FILE_PER_MONTH = 'Y-m';

	protected $packageDirectory;
	protected $bannerDirectory;
	protected $assetDirectories = array();

	protected $logDirectory;
	protected $logRotationEnabled = false;
	protected $logDateSuffix = null;
	protected $logBackupCount = 0;

	protected $cache;
	protected $serverUrl;
	protected $startTime = 0;
	protected $packageFileLoader = array('Wpup_Package', 'fromArchive');

	protected $ipAnonymizationEnabled = false;
	protected $ip4Mask = '';
	protected $ip6Mask = '';

	public function __construct($serverUrl = null, $serverDirectory = null) {
		if ( $serverDirectory === null ) {
			$serverDirectory = realpath(__DIR__ . '/../..');
		}
		if ( $serverUrl === null ) {
			$serverUrl = self::guessServerUrl();
		}

		$this->serverUrl = $serverUrl;
		$this->packageDirectory = $serverDirectory . '/packages';
		$this->logDirectory = $serverDirectory . '/logs';

		$this->bannerDirectory = $serverDirectory . '/banners';
		$this->assetDirectories = array(
			'banners' => $this->bannerDirectory,
			'icons'   => $serverDirectory . '/icons',
		);

		//Set up the IP anonymization masks.
		//For 32-bit addresses, replace the last 8 bits with zeros.
		$this->ip4Mask = pack('H*', 'ffffff00');
		//For 128-bit addresses, zero out the last 80 bits.
		$this->ip6Mask = pack('H*', 'ffffffffffff00000000000000000000');

		$this->cache = new Wpup_FileCache($serverDirectory . '/cache');
	}

	/**
	 * Guess the Server Url based on the current request.
	 *
	 * Defaults to the current URL minus the query and "index.php".
	 *
	 * @static
	 *
	 * @return string Url
	 */
	public static function guessServerUrl() {
		$serverUrl = ( self::isSsl() ? 'https' : 'http' );
		$serverUrl .= '://' . $_SERVER['HTTP_HOST'];
		$path = $_SERVER['SCRIPT_NAME'];

		if ( basename($path) === 'index.php' ) {
			$path = dirname($path);
			if ( DIRECTORY_SEPARATOR === '/' ) {
				//Normalize Windows paths.
				$path = str_replace('\\', '/', $path);
			}
			//Make sure there's a trailing slash.
			if ( substr($path, -1) !== '/' ) {
				$path .= '/';
			}
		}

		$serverUrl .= $path;
		return $serverUrl;
	}

	/**
	 * Determine if ssl is used.
	 *
	 * @see WP core - wp-includes/functions.php
	 *
	 * @return bool True if SSL, false if not used.
	 */
	public static function isSsl() {
		if ( isset($_SERVER['HTTPS']) ) {
			if ( $_SERVER['HTTPS'] == '1' || strtolower($_SERVER['HTTPS']) === 'on' ) {
				return true;
			}
		} elseif ( isset($_SERVER['SERVER_PORT']) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Process an update API request.
	 *
	 * @param array|null $query Query parameters. Defaults to the current GET request parameters.
	 * @param array|null $headers HTTP headers. Defaults to the headers received for the current request.
	 */
	public function handleRequest($query = null, $headers = null) {
		$this->startTime = microtime(true);

		$request = $this->initRequest($query, $headers);
		$this->logRequest($request);

		$this->loadPackageFor($request);
		$this->validateRequest($request);
		$this->checkAuthorization($request);
		$this->dispatch($request);
		exit;
	}

	/**
	 * Set up a request instance.
	 *
	 * @param array $query
	 * @param array $headers
	 * @return Wpup_Request
	 */
	protected function initRequest($query = null, $headers = null) {
		if ( $query === null ) {
			$query = $_GET;
		}
		if ( $headers === null ) {
			$headers = Wpup_Headers::parseCurrent();
		}
		$clientIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		$httpMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

		return new Wpup_Request($query, $headers, $clientIp, $httpMethod);
	}

	/**
	 * Load the requested package into the request instance.
	 *
	 * @param Wpup_Request $request
	 */
	protected function loadPackageFor($request) {
		if ( empty($request->slug) ) {
			return;
		}

		try {
			$request->package = $this->findPackage($request->slug);
		} catch (Wpup_InvalidPackageException $ex) {
			$this->exitWithError(sprintf(
				'Package "%s" exists, but it is not a valid plugin or theme. ' .
				'Make sure it has the right format (Zip) and directory structure.',
				htmlentities($request->slug)
			));
			exit;
		}
	}

	/**
	 * Basic request validation. Every request must specify an action and a valid package slug.
	 *
	 * @param Wpup_Request $request
	 */
	protected function validateRequest($request) {
		if ( $request->action === '' ) {
			$this->exitWithError('You must specify an action.', 400);
		}
		if ( $request->slug === '' ) {
			$this->exitWithError('You must specify a package slug.', 400);
		}
		if ( $request->package === null ) {
			$this->exitWithError('Package not found', 404);
		}
	}

	/**
	 * Run the requested action.
	 *
	 * @param Wpup_Request $request
	 */
	protected function dispatch($request) {
		if ( $request->action === 'get_metadata' ) {
			$this->actionGetMetadata($request);
		} else if ( $request->action === 'download' ) {
			$this->actionDownload($request);
		} else {
			$this->exitWithError(sprintf('Invalid action "%s".', htmlentities($request->action)), 400);
		}
	}

	/**
	 * Retrieve package metadata as JSON. This is the primary function of the custom update API.
	 *
	 * @param Wpup_Request $request
	 */
	protected function actionGetMetadata(Wpup_Request $request) {
		$meta = $request->package->getMetadata();
		$meta['download_url'] = $this->generateDownloadUrl($request->package);
		$meta['banners'] = $this->getBanners($request->package);
		$meta['icons'] = $this->getIcons($request->package);

		$meta = $this->filterMetadata($meta, $request);

		//For debugging. The update checker ignores unknown fields, so this is safe.
		$meta['request_time_elapsed'] = sprintf('%.3f', microtime(true) - $this->startTime);

		$this->outputAsJson($meta);
		exit;
	}

	/**
	 * Filter plugin metadata before output.
	 *
	 * Override this method to customize update API responses. For example, you could use it
	 * to conditionally exclude the download_url based on query parameters.
	 *
	 * @param array $meta
	 * @param Wpup_Request $request
	 * @return array Filtered metadata.
	 */
	protected function filterMetadata($meta, /** @noinspection PhpUnusedParameterInspection */ $request) {
		//By convention, un-set properties are omitted.
		$meta = array_filter($meta, function ($value) {
			return $value !== null;
		});
		return $meta;
	}

	/**
	 * Process a download request.
	 *
	 * Typically this occurs when a user attempts to install a plugin/theme update
	 * from the WordPress dashboard, but technically they could also download and
	 * install it manually.
	 *
	 * @param Wpup_Request $request
	 */
	protected function actionDownload(Wpup_Request $request) {
		//Required for IE, otherwise Content-Disposition may be ignored.
		if(ini_get('zlib.output_compression')) {
			@ini_set('zlib.output_compression', 'Off');
		}

		$package = $request->package;
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . $package->slug . '.zip"');
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . $package->getFileSize());

		readfile($package->getFilename());
	}

	/**
	 * Find a plugin or theme by slug.
	 *
	 * @param string $slug
	 * @return Wpup_Package A package object or NULL if the plugin/theme was not found.
	 */
	protected function findPackage($slug) {
		//Check if there's a slug.zip file in the package directory.
		$safeSlug = preg_replace('@[^a-z0-9\-_\.,+!]@i', '', $slug);
		$filename = $this->packageDirectory . '/' . $safeSlug . '.zip';
		if ( !is_file($filename) || !is_readable($filename) ) {
			return null;
		}

		return call_user_func($this->packageFileLoader, $filename, $slug, $this->cache);
	}

	/**
	 * Stub. You can override this in a subclass to show update info only to
	 * users with a valid license key (for example).
	 *
	 * @param $request
	 */
	protected function checkAuthorization($request) {
		//Stub.
	}

	/**
	 * Create a download URL for a plugin.
	 *
	 * @param Wpup_Package $package
	 * @return string URL
	 */
	protected function generateDownloadUrl(Wpup_Package $package) {
		$query = array(
			'action' => 'download',
			'slug' => $package->slug,
		);
		return self::addQueryArg($query, $this->serverUrl);
	}

	/**
	 * Find plugin banners.
	 *
	 * See WordPress repository docs for more information on banners:
	 * https://wordpress.org/plugins/about/faq/#banners
	 *
	 * @param Wpup_Package $package
	 * @return array|null
	 */
	protected function getBanners(Wpup_Package $package) {
		//Find the normal banner first. The file name should be slug-772x250.ext.
		$smallBanner = $this->findFirstAsset($package, 'banners', '-772x250');
		if ( !empty($smallBanner) ) {
			$banners = array('low' => $smallBanner);

			//Then find the high-DPI banner.
			$bigBanner = $this->findFirstAsset($package, 'banners', '-1544x500');
			if ( !empty($bigBanner) ) {
				$banners['high'] = $bigBanner;
			}

			return $banners;
		}

		return null;
	}

	/**
	 * Get a publicly accessible URL for a plugin banner.
	 *
	 * @deprecated Use generateAssetUrl() instead.
	 * @param string $relativeFileName Banner file name relative to the "banners" subdirectory.
	 * @return string
	 */
	protected function generateBannerUrl($relativeFileName) {
		return $this->generateAssetUrl('banners', $relativeFileName);
	}

	/**
	 * Find plugin icons.
	 *
	 * @param Wpup_Package $package
	 * @return array|null
	 */
	protected function getIcons(Wpup_Package $package) {
		$icons = array(
			'1x'  => $this->findFirstAsset($package, 'icons', '-128x128'),
			'2x'  => $this->findFirstAsset($package, 'icons', '-256x256'),
			'svg' => $this->findFirstAsset($package, 'icons', '', 'svg'),
		);

		$icons = array_filter($icons);
		if ( !empty($icons) ) {
			return $icons;
		}
		return null;
	}

	/**
	 * Get the first asset that has the specified suffix and file name extension.
	 *
	 * @param Wpup_Package $package
	 * @param string $assetType Either 'icons' or 'banners'.
	 * @param string $suffix Optional file name suffix. For example, "-128x128" for plugin icons.
	 * @param array|string $extensions Optional. Defaults to common image file formats.
	 * @return null|string Asset URL, or NULL if there are no matching assets.
	 */
	protected function findFirstAsset(
		Wpup_Package $package,
		$assetType = 'banners',
		$suffix = '',
		$extensions = array('png', 'jpg', 'jpeg')
	) {
		$pattern = $this->assetDirectories[$assetType] . '/' . $package->slug . $suffix;

		if ( is_array($extensions) ) {
			$extensionPattern = '{' . implode(',', $extensions) . '}';
		} else {
			$extensionPattern = $extensions;
		}

		$assets = glob($pattern . '.' . $extensionPattern, GLOB_BRACE | GLOB_NOESCAPE);
		if ( !empty($assets) ) {
			$firstFile = basename(reset($assets));
			return $this->generateAssetUrl($assetType, $firstFile);
		}
		return null;
	}

	/**
	 * Get a publicly accessible URL for a plugin asset.
	 *
	 * @param string $assetType Either 'icons' or 'banners'.
	 * @param string $relativeFileName File name relative to the asset directory.
	 * @return string
	 */
	protected function generateAssetUrl($assetType, $relativeFileName) {
		//The current implementation is trivially simple, but you could override this method
		//to (for example) create URLs that don't rely on the directory being public.
		$subDirectory = basename($this->assetDirectories[$assetType]);
		return $this->serverUrl . $subDirectory . '/' . $relativeFileName;
	}

	/**
	 * Log an API request.
	 *
	 * @param Wpup_Request $request
	 */
	protected function logRequest($request) {
		$logFile = $this->getLogFileName();

		//If the log file is new, we should rotate old logs.
		$mustRotate = $this->logRotationEnabled && !file_exists($logFile);

		$handle = fopen($logFile, 'a');
		if ( $handle && flock($handle, LOCK_EX) ) {

			$loggedIp = $request->clientIp;
			if ( $this->ipAnonymizationEnabled ) {
				$loggedIp = $this->anonymizeIp($loggedIp);
			}

			$columns = array(
				'ip'                => str_pad($loggedIp, 15, ' '),
				'http_method'       => str_pad($request->httpMethod, 4, ' '),
				'action'            => $request->param('action', '-'),
				'slug'              => $request->param('slug', '-'),
				'installed_version' => $request->param('installed_version', '-'),
				'wp_version'        => isset($request->wpVersion) ? $request->wpVersion : '-',
				'site_url'          => isset($request->wpSiteUrl) ? $request->wpSiteUrl : '-',
				'query'             => http_build_query($request->query, '', '&'),
			);
			$columns = $this->filterLogInfo($columns, $request);

			//Set the time zone to whatever the default is to avoid PHP notices.
			//Will default to UTC if it's not set properly in php.ini.
			date_default_timezone_set(@date_default_timezone_get());

			$line = date('[Y-m-d H:i:s O]') . ' ' . implode("\t", $columns) . "\n";

			fwrite($handle, $line);

			if ( $mustRotate ) {
				$this->rotateLogs();
			}
			flock($handle, LOCK_UN);
		}
		if ( $handle ) {
			fclose($handle);
		}
	}

	/**
	 * @return string
	 */
	protected function getLogFileName() {
		$path = $this->logDirectory . '/request';
		if ( $this->logRotationEnabled ) {
			$path .= '-' . date($this->logDateSuffix);
		}
		return $path . '.log';
	}

	/**
	 * Adjust information that will be logged.
	 * Intended to be overridden in child classes.
	 *
	 * @param array $columns List of columns in the log entry.
	 * @param Wpup_Request|null $request
	 * @return array
	 */
	protected function filterLogInfo($columns, /** @noinspection PhpUnusedParameterInspection */$request = null) {
		return $columns;
	}

	/**
	 * Enable basic log rotation.
	 * Defaults to monthly rotation.
	 *
	 * @param string|null $rotationPeriod Either Wpup_UpdateServer::FILE_PER_DAY or Wpup_UpdateServer::FILE_PER_MONTH.
	 * @param int $filesToKeep The max number of log files to keep. Zero = unlimited.
	 */
	public function enableLogRotation($rotationPeriod = null, $filesToKeep = 10) {
		if ( !isset($rotationPeriod) ) {
			$rotationPeriod = self::FILE_PER_MONTH;
		}

		$this->logDateSuffix = $rotationPeriod;
		$this->logBackupCount = $filesToKeep;
		$this->logRotationEnabled = true;
	}

	/**
	 * Delete old log files.
	 */
	protected function rotateLogs() {
		//Skip GC of old files if the backup count is unlimited.
		if ( $this->logBackupCount === 0 ) {
			return;
		}

		//Find log files.
		$logFiles = glob($this->logDirectory . '/request*.log', GLOB_NOESCAPE);
		if ( count($logFiles) <= $this->logBackupCount ) {
			return;
		}

		//Sort the files by name. Due to the date suffix format, this also sorts them by date.
		usort($logFiles, 'strcmp');
		//Put them in descending order.
		$logFiles = array_reverse($logFiles);

		//Keep the most recent $logBackupCount files, delete the rest.
		foreach(array_slice($logFiles, $this->logBackupCount) as $fileName) {
			@unlink($fileName);
		}
	}

	/**
	 * Enable basic IP address anonymization.
	 */
	public function enableIpAnonymization() {
		$this->ipAnonymizationEnabled = true;
	}

	/**
	 * Anonymize an IP address by replacing the last byte(s) with zeros.
	 *
	 * @param string $ip A valid IP address such as "12.45.67.89" or "2001:db8:85a3::8a2e:370:7334".
	 * @return string
	 */
	protected function anonymizeIp($ip) {
		$binaryIp = @inet_pton($ip);
		if ( strlen($binaryIp) === 4 ) {
			//IPv4
			$anonBinaryIp = $binaryIp & $this->ip4Mask;
		} else if ( strlen($binaryIp) === 16 ) {
			//IPv6
			$anonBinaryIp = $binaryIp & $this->ip6Mask;
		} else {
			//The input is not a valid IPv4 or IPv6 address. Return it unmodified.
			return $ip;
		}
		return inet_ntop($anonBinaryIp);
	}

	/**
	 * Output something as JSON.
	 *
	 * @param mixed $response
	 */
	protected function outputAsJson($response) {
		header('Content-Type: application/json; charset=utf-8');
		if ( defined('JSON_PRETTY_PRINT') ) {
			$output = json_encode($response, JSON_PRETTY_PRINT);
		} elseif ( function_exists('wsh_pretty_json') ) {
			$output = wsh_pretty_json(json_encode($response));
		} else {
			$output = json_encode($response);
		}
		echo $output;
	}

	/**
	 * Stop script execution with an error message.
	 *
	 * @param string $message Error message.
	 * @param int $httpStatus Optional HTTP status code. Defaults to 500 (Internal Server Error).
	 */
	protected function exitWithError($message = '', $httpStatus = 500) {
		$statusMessages = array(
			// This is not a full list of HTTP status messages. We only need the errors.
			// [Client Error 4xx]
			400 => '400 Bad Request',
			401 => '401 Unauthorized',
			402 => '402 Payment Required',
			403 => '403 Forbidden',
			404 => '404 Not Found',
			405 => '405 Method Not Allowed',
			406 => '406 Not Acceptable',
			407 => '407 Proxy Authentication Required',
			408 => '408 Request Timeout',
			409 => '409 Conflict',
			410 => '410 Gone',
			411 => '411 Length Required',
			412 => '412 Precondition Failed',
			413 => '413 Request Entity Too Large',
			414 => '414 Request-URI Too Long',
			415 => '415 Unsupported Media Type',
			416 => '416 Requested Range Not Satisfiable',
			417 => '417 Expectation Failed',
			// [Server Error 5xx]
			500 => '500 Internal Server Error',
			501 => '501 Not Implemented',
			502 => '502 Bad Gateway',
			503 => '503 Service Unavailable',
			504 => '504 Gateway Timeout',
			505 => '505 HTTP Version Not Supported'
		);

		if ( !isset($_SERVER['SERVER_PROTOCOL']) || $_SERVER['SERVER_PROTOCOL'] === '' ) {
			$protocol = 'HTTP/1.1';
		} else {
			$protocol = $_SERVER['SERVER_PROTOCOL'];
		}

		//Output a HTTP status header.
		if ( isset($statusMessages[$httpStatus]) ) {
			header($protocol . ' ' . $statusMessages[$httpStatus]);
			$title = $statusMessages[$httpStatus];
		} else {
			header('X-Ws-Update-Server-Error: ' . $httpStatus, true, $httpStatus);
			$title = 'HTTP ' . $httpStatus;
		}

		if ( $message === '' ) {
			$message = $title;
		}

		//And a basic HTML error message.
		printf(
			'<html>
				<head> <title>%1$s</title> </head>
				<body> <h1>%1$s</h1> <p>%2$s</p> </body>
			 </html>',
			$title, $message
		);
		exit;
	}

	/**
	 * Add one or more query arguments to a URL.
	 * You can also set an argument to NULL to remove it.
	 *
	 * @param array $args An associative array of query arguments.
	 * @param string $url The old URL. Optional, defaults to the request url without query arguments.
	 * @return string New URL.
	 */
	protected static function addQueryArg($args, $url = null ) {
		if ( !isset($url) ) {
			$url = self::guessServerUrl();
		}
		if ( strpos($url, '?') !== false ) {
			$parts = explode('?', $url, 2);
			$base = $parts[0] . '?';
			parse_str($parts[1], $query);
		} else {
			$base = $url . '?';
			$query = array();
		}

		$query = array_merge($query, $args);

		//Remove null/false arguments.
		$query = array_filter($query, function($value) {
			return ($value !== null) && ($value !== false);
		});

		return $base . http_build_query($query, '', '&');
	}
}
