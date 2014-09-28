<?php
/**
 * This class represents the metadata from one specific WordPress plugin or theme.
 */
class Wpup_Metadata {

	/**
	 * @var int $cacheTime  How long the package metadata should be cached in seconds.
	 *                      Defaults to 1 week ( 7 * 24 * 60 * 60 ).
	 */
	public static $cacheTime = 604800;

	/**
	 * @var array Plugin PHP header mapping, i.e. which tags to add to the metadata under which array key
	 */
	protected $headerMap = array(
		'Name' => 'name',
		'Version' => 'version',
		'PluginURI' => 'homepage',
		'ThemeURI' => 'homepage',
		'Author' => 'author',
		'AuthorURI' => 'author_homepage',
		'DetailsURI' => 'details_url', //Only for themes.
		'Depends' => 'depends', // plugin-dependencies plugin
		'Provides' => 'provides', // plugin-dependencies plugin
	);

	/**
	 * @var array Plugin readme file mapping, i.e. which tags to add to the metadata
	 */
	protected $readmeMap = array(
		'requires',
		'tested',
	);
	
	/**
	 * @var array Package info as retrieved by the parser
	 */
	protected $packageInfo;

	/**
	 * @var string Path to the Zip archive that contains the plugin or theme.
	 */
	protected $filename;

	/**
	 * @var Wpup_Cache Cache object.
	 */
	protected $cache;

	/**
	 * @var array Package metadata in a format suitable for the update checker.
	 */
	protected $metadata = array();


	/**
	 * Get the metadata from a zip file.
	 *
	 * @param string $filename
	 * @param Wpup_Cache $cache
	 */
	public function __construct($filename, Wpup_Cache $cache = null) {
		$this->filename = $filename;
		$this->cache = $cache;

		$this->setMetadataFromArchive();
	}

	/**
	 * Get metadata.
	 *
	 * @see Wpup_Metadata::extractMetadata()
	 * @return array
	 */
	public function get() {
		return $this->metadata;
	}

	/**
	 * Load metadata information from a Zip archive.
	 *
	 * We'll try to load processed metadata from the cache first (if available), and if that
	 * fails we'll extract plugin/theme details from the specified Zip file.
	 *
	 * @throws Wpup_InvalidPackageException if the input file can't be parsed as a plugin or theme.
	 */
	public function setMetadataFromArchive() {
		$modified = filemtime($this->filename);
		$cacheKey = 'metadata-' . md5($this->filename . '|' . filesize($this->filename) . '|' . $modified);
		$metadata = null;

		//Try the cache first.
		if ( isset($this->cache) ) {
			$metadata = $this->cache->get($cacheKey);
		}

		if ( !isset($metadata) || !is_array($metadata) ) {
			$metadata = $this->extractMetadata();
			if ( $metadata === null ) {
				throw new Wpup_InvalidPackageException( sprintf('The specified file %s does not contain a valid WordPress plugin or theme.', $this->filename));
			}
			$metadata['last_updated'] = gmdate('Y-m-d H:i:s', $modified);
		}

		//Update cache.
		if ( isset($this->cache) ) {
			$this->cache->set($cacheKey, $metadata, self::$cacheTime);
		}

		$this->metadata = $metadata;
	}

	/**
	 * Extract plugin or theme headers and readme contents from a ZIP file and convert them
	 * into a structure compatible with the custom update checker.
	 *
	 * See this page for an overview of the plugin metadata format:
	 * @link https://spreadsheets.google.com/pub?key=0AqP80E74YcUWdEdETXZLcXhjd2w0cHMwX2U1eDlWTHc&authkey=CK7h9toK&hl=en&single=true&gid=0&output=html
	 *
	 * @return array An associative array of metadata fields, or NULL if the input file doesn't appear to be a valid plugin/theme archive.
	 */
	protected function extractMetadata(){
		$this->packageInfo = WshWordPressPackageParser::parsePackage($this->filename, true);
		if ( $this->packageInfo === false ) {
			return null;
		}

		$meta = array();

		if ( isset($this->packageInfo['header']) && !empty($this->packageInfo['header']) ){
			foreach($this->headerMap as $headerField => $metaField){
				if ( array_key_exists($headerField, $this->packageInfo['header']) && !empty($this->packageInfo['header'][$headerField]) ){
					$meta[$metaField] = $this->packageInfo['header'][$headerField];
				}
			}

			//Theme metadata should include a "details_url" that specifies the page to display
			//when the user clicks "View version x.y.z details". If the developer didn't provide
			//it by setting the "Details URI" header, we'll default to the theme homepage ("Theme URI").
			if ( $this->packageInfo['type'] === 'theme' &&  !isset($meta['details_url']) && isset($meta['homepage']) ) {
				$meta['details_url'] = $meta['homepage'];
			}
		}

		if ( !empty($this->packageInfo['readme']) ){
			foreach($this->readmeMap as $readmeField){
				if ( !empty($this->packageInfo['readme'][$readmeField]) ){
					$meta[$readmeField] = $this->packageInfo['readme'][$readmeField];
				}
			}
			if ( !empty($this->packageInfo['readme']['sections']) && is_array($this->packageInfo['readme']['sections']) ){
				foreach($this->packageInfo['readme']['sections'] as $sectionName => $sectionContent){
					$sectionName = str_replace(' ', '_', strtolower($sectionName));
					$meta['sections'][$sectionName] = $sectionContent;
				}
			}

			//Check if we have an upgrade notice for this version
			if ( isset($meta['sections']['upgrade_notice']) && isset($meta['version']) ){
				$regex = "@<h4>\s*" . preg_quote($meta['version']) . "\s*</h4>[^<>]*?<p>(.+?)</p>@i";
				if ( preg_match($regex, $meta['sections']['upgrade_notice'], $matches) ){
					$meta['upgrade_notice'] = trim(strip_tags($matches[1]));
				}
			}
		}

		if ( !isset($meta['last_updated']) ) {
			$meta['last_updated'] = gmdate('Y-m-d H:i:s', filemtime($zipFilename));
		}

		$mainFile = $this->packageInfo['type'] === 'plugin' ? $this->packageInfo['pluginFile'] : $this->packageInfo['stylesheet'];
		$meta['slug'] = basename(dirname(strtolower($mainFile)));
		//Idea: Warn the user if the package doesn't match the expected "/slug/other-files" layout.

		return $meta;
	}
}
