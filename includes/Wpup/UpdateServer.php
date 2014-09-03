<?php
class Wpup_UpdateServer {
	protected $packageDirectory;
	protected $logDirectory;
	protected $cache;
	protected $serverUrl;
	protected $startTime = 0;

	public function __construct($serverUrl = null, $serverDirectory = null) {
		if ( $serverDirectory === null ) {
			$serverDirectory = realpath(__DIR__ . '/../..');
		}
		if ( $serverUrl === null ) {
			$serverUrl = self::determineServerUrl();
		}

		$this->serverUrl = $serverUrl;
		$this->packageDirectory = $serverDirectory . '/packages';
		$this->logDirectory = $serverDirectory . '/logs';
		$this->cache = new Wpup_FileCache($serverDirectory . '/cache');
	}

	/**
	 * Determine the Server Url based on the current request.
	 *
	 * Defaults to the current URL minus the query and "index.php".
	 *
	 * @static
	 *
	 * @return string Url
	 */
	public static function determineServerUrl() {
		$serverUrl = ( self::isSsl() ? 'https' : 'http' );
		$serverUrl .= '://' . $_SERVER['HTTP_HOST'];
		$path = $_SERVER['SCRIPT_NAME'];

		if ( basename($path) === 'index.php' ) {
			if ( DIRECTORY_SEPARATOR === '/' ) {
				$path = dirname($path) . '/';
			}
			else {
				// Fix Windows
				if ( dirname($path) === '\\' ) {
					$path = '/';
				} else {
					$path = str_replace('\\', '/', dirname($path)) . '/';
				}
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
		}
		elseif ( isset($_SERVER['SERVER_PORT']) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Process an update API request.
	 *
	 * @param array|null $query Query parameters. Defaults to the current request parameters (GET + POST).
	 */
	public function handleRequest($query = null) {
		$this->startTime = microtime(true);
		if ( $query === null ) {
			$query = $_GET;
		}
		$this->logRequest($query);

		$request = $this->initRequest($query);
		$this->checkAuthorization($request);
		$this->dispatch($request);
		exit;
	}

	/**
	 * Parse an API request and perform some basic validation.
	 *
	 * @param array $query
	 * @return Wpup_Request
	 */
	protected function initRequest($query) {
		$action = isset($query['action']) ? strval($query['action']) : '';
		if ( $action === '' ) {
			$this->exitWithError('You must specify an action.', 400);
		}
		$slug = isset($query['slug']) ? strval($query['slug']) : '';
		if ( $slug === '' ) {
			$this->exitWithError('You must specify a package slug.', 400);
		}

		try {
			$package = $this->findPackage($slug);
		} catch (Wpup_InvalidPackageException $ex) {
			$this->exitWithError(sprintf(
				'Package "%s" exists, but it is not a valid plugin or theme. ' .
				'Make sure it has the right format (Zip) and directory structure.',
				htmlentities($slug)
			));
			exit;
		}
		if ( $package === null ) {
			$this->exitWithError(sprintf('Package "%s" not found', htmlentities($slug)), 404);
		}

		return new Wpup_Request($query, $action, $slug, $package);
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
	protected function filterMetadata($meta, $request) {
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

		return Wpup_Package::fromArchive($filename, $slug, $this->cache);
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
	 * Log an API request.
	 *
	 * @param array $query Query arguments.
	 */
	protected function logRequest($query) {
		$logFile = $this->logDirectory . '/request.log';
		$handle = fopen($logFile, 'a');
		if ( $handle && flock($handle, LOCK_EX) ) {

			//If the request was made via the WordPress HTTP API we can usually
			//get WordPress version and site URL from the user agent.
			$wpVersion = $wpSiteUrl = null;
			$regex = '@WordPress/(?P<version>\d[^;]*?);\s+(?P<url>https?://.+?)(?:\s|;|$)@i';
			if ( isset($_SERVER['HTTP_USER_AGENT']) && preg_match($regex, $_SERVER['HTTP_USER_AGENT'], $matches) ) {
				$wpVersion = $matches['version'];
				$wpSiteUrl = $matches['url'];
			}

			$columns = array(
				isset($_SERVER['REMOTE_ADDR']) ? str_pad($_SERVER['REMOTE_ADDR'], 15, ' ') : '-',
				isset($query['action'])            ? $query['action']            : '-',
				isset($query['slug'])              ? $query['slug']              : '-',
				isset($query['installed_version']) ? $query['installed_version'] : '-',
				isset($wpVersion) ? $wpVersion : '-',
				isset($wpSiteUrl) ? $wpSiteUrl : '-',
				http_build_query($query, '', '&')
			);

			//Set the time zone to whatever the default is to avoid PHP notices.
			//Will default to UTC if it's not set properly in php.ini.
			date_default_timezone_set(@date_default_timezone_get());

			$line = date('[Y-m-d H:i:s O]') . ' ' . implode("\t", $columns) . "\n";

			fwrite($handle, $line);
			flock($handle, LOCK_UN);
		}
		if ( $handle ) {
			fclose($handle);
		}
	}

	/**
	 * Output something as JSON.
	 *
	 * @param mixed $response
	 */
	protected function outputAsJson($response) {
		header('Content-Type: application/json');
		$output = json_encode($response);
		if ( function_exists('wsh_pretty_json') ) {
			$output = wsh_pretty_json($output);
		}
		echo $output;
	}

	/**
	 * Stop script execution with an error message.
	 *
	 * @param string $message Error message.
	 * @param int $httpStatus Optional HTTP status code. Defaults to 500 (Internal Server Error).
	 */
	protected function exitWithError($message, $httpStatus = 500) {
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

		//Output a HTTP status header.
		if ( isset($statusMessages[$httpStatus]) ) {
			header('HTTP/1.1 ' . $statusMessages[$httpStatus]);
			$title = $statusMessages[$httpStatus];
		} else {
			header('X-Ws-Update-Server-Error: ' . $httpStatus, true, $httpStatus);
			$title = 'HTTP ' . $httpStatus;
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
			$url = self::determineServerUrl();
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
