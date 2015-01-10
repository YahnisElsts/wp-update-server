<?php
/**
 * This class represents the metadata from one specific WordPress plugin or theme.
 */
class Wpup_ZipMetadataParser {

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
	 * @var string Plugin or theme slug.
	 */
	protected $slug;

	/**
	 * @var Wpup_Cache Cache object.
	 */
	protected $cache;

	/**
	 * @var array Package metadata in a format suitable for the update checker.
	 */
	protected $metadata;


	/**
	 * Get the metadata from a zip file.
	 *
	 * @param string $slug
	 * @param string $filename
	 * @param Wpup_Cache $cache
	 */
	public function __construct($slug, $filename, Wpup_Cache $cache = null){
		$this->slug = $slug;
		$this->filename = $filename;
		$this->cache = $cache;

		$this->setMetadata();
	}

	/**
	 * Get metadata.
	 *
	 * @return array
	 */
	public function get(){
		return $this->metadata;
	}

	/**
	 * Load metadata information from a cache or create it.
	 *
	 * We'll try to load processed metadata from the cache first (if available), and if that
	 * fails we'll extract plugin/theme details from the specified Zip file.
	 */
	protected function setMetadata(){
		$cacheKey = $this->generateCacheKey();

		//Try the cache first.
		if ( isset($this->cache) ){
			$this->metadata = $this->cache->get($cacheKey);
		}

		// Otherwise read out the metadata and create a cache
		if ( !isset($this->metadata) || !is_array($this->metadata) ){
			$this->extractMetadata();

			//Update cache.
			if ( isset($this->cache) ){
				$this->cache->set($cacheKey, $this->metadata, self::$cacheTime);
			}
		}
	}

	/**
	 * Extract plugin or theme headers and readme contents from a ZIP file and convert them
	 * into a structure compatible with the custom update checker.
	 *
	 * See this page for an overview of the plugin metadata format:
	 * @link https://spreadsheets.google.com/pub?key=0AqP80E74YcUWdEdETXZLcXhjd2w0cHMwX2U1eDlWTHc&authkey=CK7h9toK&hl=en&single=true&gid=0&output=html
	 *
	 * @throws Wpup_InvalidPackageException if the input file can't be parsed as a plugin or theme.
	 */
	protected function extractMetadata(){
		$this->packageInfo = WshWordPressPackageParser::parsePackage($this->filename, true);
		if ( is_array($this->packageInfo) && $this->packageInfo !== array() ){
			$this->setInfoFromHeader();
			$this->setInfoFromReadme();
			$this->setLastUpdateDate();
			$this->setSlug();
		} else {
			throw new Wpup_InvalidPackageException( sprintf('The specified file %s does not contain a valid WordPress plugin or theme.', $this->filename));
		}
	}

	/**
	 * Extract relevant metadata from the plugin/theme header information
	 */
	protected function setInfoFromHeader(){
		if ( isset($this->packageInfo['header']) && !empty($this->packageInfo['header']) ){
			$this->setMappedFields($this->packageInfo['header'], $this->headerMap);
			$this->setThemeDetailsUrl();
		}
	}

	/**
	 * Extract relevant metadata from the plugin/theme readme
	 */
	protected function setInfoFromReadme(){
		if ( !empty($this->packageInfo['readme']) ){
			$readmeMap = array_combine(array_values($this->readmeMap), $this->readmeMap);
			$this->setMappedFields($this->packageInfo['readme'], $readmeMap);
			$this->setReadmeSections();
			$this->setReadmeUpgradeNotice();
		}
	}

	/**
	 * Extract selected metadata from the retrieved package info
	 *
	 * @see http://codex.wordpress.org/File_Header
	 * @see https://wordpress.org/plugins/about/readme.txt
	 *
	 * @param array $input The package info sub-array to use to retrieve the info from
	 * @param array $map   The key mapping for that sub-array where the key is the key as used in the
	 *                     input array and the value is the key to use for the output array
	 */
	protected function setMappedFields($input, $map){
		foreach($map as $fieldKey => $metaKey){
			if ( !empty($input[$fieldKey]) ){
				$this->metadata[$metaKey] = $input[$fieldKey];
			}
		}
	}

	/**
	 * Determine the details url for themes
	 *
	 * Theme metadata should include a "details_url" that specifies the page to display
	 * when the user clicks "View version x.y.z details". If the developer didn't provide
	 * it by setting the "Details URI" header, we'll default to the theme homepage ("Theme URI").
	 */
	protected function setThemeDetailsUrl() {
		if ( $this->packageInfo['type'] === 'theme' &&  !isset($this->metadata['details_url']) && isset($this->metadata['homepage']) ){
			$this->metadata['details_url'] = $this->metadata['homepage'];
		}
	}

	/**
	 * Extract the texual information sections from a readme file
	 *
	 * @see https://wordpress.org/plugins/about/readme.txt
	 */
	protected function setReadmeSections(){
		if ( is_array($this->packageInfo['readme']['sections']) && $this->packageInfo['readme']['sections'] !== array()){
			foreach($this->packageInfo['readme']['sections'] as $sectionName => $sectionContent){
				$sectionName = str_replace(' ', '_', strtolower($sectionName));
				$this->metadata['sections'][$sectionName] = $sectionContent;
			}
		}
	}

	/**
	 * Extract the upgrade notice for the current version from a readme file
	 *
	 * @see https://wordpress.org/plugins/about/readme.txt
	 */
	protected function setReadmeUpgradeNotice(){
		//Check if we have an upgrade notice for this version
		if ( isset($this->metadata['sections']['upgrade_notice']) && isset($this->metadata['version']) ){
			$regex = '@<h4>\s*' . preg_quote($this->metadata['version']) . '\s*</h4>[^<>]*?<p>(.+?)</p>@i';
			if ( preg_match($regex, $this->metadata['sections']['upgrade_notice'], $matches) ){
				$this->metadata['upgrade_notice'] = trim(strip_tags($matches[1]));
			}
		}
	}

	/**
	 * Add last update date to the metadata
	 */
	protected function setLastUpdateDate(){
		if ( !isset($this->metadata['last_updated']) ){
			$this->metadata['last_updated'] = gmdate('Y-m-d H:i:s', filemtime($this->filename));
		}
	}

	/**
	 * Determine the slug based on the directory name for the theme/plugin
	 */
	protected function setSlug(){
		$mainFile = $this->packageInfo['type'] === 'plugin' ? $this->packageInfo['pluginFile'] : $this->packageInfo['stylesheet'];
		$this->metadata['slug'] = basename(dirname(strtolower($mainFile)));
		//Idea: Warn the user if the package doesn't match the expected "/slug/other-files" layout.
	}

	/**
	 * Generate the cache key (cache filename) for a file
	 */
	protected function generateCacheKey(){
		return 'metadata-b64-' . $this->slug . '-' . md5($this->filename . '|' . filesize($this->filename) . '|' . filemtime($this->filename));
	}

}
