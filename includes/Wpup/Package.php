<?php
/**
 * This class represents the collection of files and metadata that make up
 * a WordPress plugin or theme.
 *
 * Most often a "package" is going to be a "plugin-slug.zip" archive that exists
 * in the /packages subdirectory and contains the current version of a plugin.
 * However, you could also load plugin information from the database or a configuration
 * file and store the actual download elsewhere - or even generate it on the fly.
 */
class Wpup_Package {
	/** @var string Path to the Zip archive that contains the plugin or theme. */
	protected $filename;

	/** @var array Package metadata in a format suitable for the update checker. */
	protected $metadata = array();

	/** @var string Plugin or theme slug. */
	public $slug;

	/**
	 * Create a new package.
	 *
	 * In most cases you will probably want to use self::fromArchive($pluginZip) instead
	 * of instantiating this class directly. Still, you can do it if you want to, for example,
	 * load plugin metadata from the database instead of extracting it from a Zip file.
	 *
	 * @param string $slug
	 * @param string $filename
	 * @param array $metadata
	 */
	public function __construct($slug, $filename = null, $metadata = array()) {
		$this->slug = $slug;
		$this->filename = $filename;
		$this->metadata = $metadata;
	}

	/**
	 * Get the full file path of this package.
	 *
	 * @return string
	 */
	public function getFilename() {
		return $this->filename;
	}

	/**
	 * Get package metadata.
	 *
	 * @see self::extractMetadata()
	 * @return array
	 */
	public function getMetadata() {
		return array_merge($this->metadata, array('slug' => $this->slug));
	}

	/**
	 * Load package information from a Zip archive.
	 *
	 * We'll try to load processed metadata from the cache first (if available), and if that
	 * fails we'll extract plugin/theme details from the specified Zip file.
	 *
	 * @param string $filename Path to a Zip archive that contains a WP plugin or theme.
	 * @param string $slug Optional plugin or theme slug. Will be detected automatically.
	 * @param Wpup_Cache $cache
	 * @throws Wpup_InvalidPackageException if the input file can't be parsed as a plugin or theme.
	 * @return Wpup_Package
	 */
	public static function fromArchive($filename, $slug = null, Wpup_Cache $cache = null) {
		$modified = filemtime($filename);
		$cacheKey = 'metadata-' . $slug . '-' . md5($filename . '|' . filesize($filename) . '|' . $modified);
		$metadata = null;

		//Try the cache first.
		if ( isset($cache) ) {
			$metadata = $cache->get($cacheKey);
		}

		if ( !isset($metadata) || !is_array($metadata) ) {
			$metadata = self::extractMetadata($filename);
			if ( $metadata === null ) {
				throw new Wpup_InvalidPackageException('The specified file does not contain a valid WordPress plugin or theme.');
			}
			$metadata['last_updated'] = gmdate('Y-m-d H:i:s', $modified);
		}

		//Update cache.
		if ( isset($cache) ) {
			$cache->set($cacheKey, $metadata, 7 * 24 * 3600);
		}
		if ( $slug === null ) {
			$slug = $metadata['slug'];
		}

		return new self($slug, $filename, $metadata);
	}

	/**
	 * Extract plugin or theme headers and readme contents from a ZIP file and convert them
	 * into a structure compatible with the custom update checker.
	 *
	 * See this page for an overview of the plugin metadata format:
	 * @link https://spreadsheets.google.com/pub?key=0AqP80E74YcUWdEdETXZLcXhjd2w0cHMwX2U1eDlWTHc&authkey=CK7h9toK&hl=en&single=true&gid=0&output=html
	 *
	 * @param string $zipFilename
	 * @return array An associative array of metadata fields, or NULL if the input file doesn't appear to be a valid plugin/theme archive.
	 */
	public static function extractMetadata($zipFilename){
		$packageInfo = WshWordPressPackageParser::parsePackage($zipFilename, true);
		if ( $packageInfo === false ) {
			return null;
		}

		$meta = array();

		if ( isset($packageInfo['header']) && !empty($packageInfo['header']) ){
			$mapping = array(
				'Name' => 'name',
			    'Version' => 'version',
			    'PluginURI' => 'homepage',
			    'ThemeURI' => 'homepage',
			    'Author' => 'author',
			    'AuthorURI' => 'author_homepage',
			    'DetailsURI' => 'details_url', //Only for themes.
			);
			foreach($mapping as $headerField => $metaField){
				if ( array_key_exists($headerField, $packageInfo['header']) && !empty($packageInfo['header'][$headerField]) ){
					$meta[$metaField] = $packageInfo['header'][$headerField];
				}
			}

			//Theme metadata should include a "details_url" that specifies the page to display
			//when the user clicks "View version x.y.z details". If the developer didn't provide
			//it by setting the "Details URI" header, we'll default to the theme homepage ("Theme URI").
			if ( $packageInfo['type'] === 'theme' &&  !isset($meta['details_url']) && isset($meta['homepage']) ) {
				$meta['details_url'] = $meta['homepage'];
			}
		}

		if ( !empty($packageInfo['readme']) ){
			$mapping = array('requires', 'tested');
			foreach($mapping as $readmeField){
				if ( !empty($packageInfo['readme'][$readmeField]) ){
					$meta[$readmeField] = $packageInfo['readme'][$readmeField];
				}
			}
			if ( !empty($packageInfo['readme']['sections']) && is_array($packageInfo['readme']['sections']) ){
				foreach($packageInfo['readme']['sections'] as $sectionName => $sectionContent){
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

		$mainFile = $packageInfo['type'] === 'plugin' ? $packageInfo['pluginFile'] : $packageInfo['stylesheet'];
		$meta['slug'] = basename(dirname(strtolower($mainFile)));
		//Idea: Warn the user if the package doesn't match the expected "/slug/other-files" layout.

		return $meta;
	}

	/**
	 * Get the size of the package (in bytes).
	 *
	 * @return int
	 */
	public function getFileSize() {
		return filesize($this->filename);
	}

	/**
	 * Get the Unix timestamp of the last time this package was modified.
	 *
	 * @return int
	 */
	public function getLastModified() {
		return filemtime($this->filename);
	}
}