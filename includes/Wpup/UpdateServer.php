<?php
class Wpup_UpdateServer {
	protected $packageDirectory;
	protected $cache;
	protected $serverUrl;
	protected $startTime = 0;

	public function __construct($serverUrl = null, $serverDirectory = null) {
		if ( $serverDirectory === null ) {
			$serverDirectory = realpath(__DIR__ . '/../..');
		}
		if ( $serverUrl === null ) {
			//Default to the current URL (minus the query).
			$serverUrl = 'http://' . $_SERVER['HTTP_HOST'];
			$path = $_SERVER['SCRIPT_NAME'];
			if ( basename($path) === 'index.php' ) {
				$path = dirname($path) . '/';
			}
			$serverUrl .= $path;
		}

		$this->serverUrl = $serverUrl;
		$this->packageDirectory = $serverDirectory . '/packages';
		$this->cache = new Wpup_FileCache($serverDirectory . '/cache');
	}

	public function handleRequest($query = null) {
		$this->startTime = microtime(true);
		if ( $query === null ) {
			$query = array_merge($_GET, $_POST);
		}

		$request = $this->initRequest($query);
		$this->checkAuthorization($request);
		$this->dispatch($request);
		exit;
	}

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

	protected function dispatch($request) {
		if ( $request->action === 'get_metadata' ) {
			$this->actionGetMetadata($request);
		} else if ( $request->action === 'download' ) {
			$this->actionDownload($request);
		} else {
			$this->exitWithError(sprintf('Invalid action "%s".', htmlentities($request->action)), 400);
		}
	}

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
	 * @return array
	 */
	protected function filterMetadata($meta, $request) {
		//By convention, un-set properties are omitted.
		$meta = array_filter($meta, function ($value) {
			return $value !== null;
		});
		return $meta;
	}

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
	 * Stub. You can override this in a subclass.
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
		return $this->serverUrl . '?' . http_build_query($query, '', '&');
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
		header('X-Ws-Update-Server-Error: ' . $httpStatus, true, $httpStatus);
		echo $message;
		exit;
	}
}
