<?php
class Wpup_Package {
	/** @var string */
	protected $filename;

	/** @var array */
	protected $metadata = array();

	/** @var Wpup_FileCache */
	protected $cache;

	/** @var string */
	public $slug;

	public function __construct($slug, $filename = null, $metadata = array()) {
		$this->slug = $slug;
		$this->filename = $filename;
		$this->metadata = $metadata;
	}

	public function getFilename() {
		return $this->filename;
	}

	public function getMetadata() {
		return array_merge($this->metadata, array('slug' => $this->slug));
	}

	/**
	 * Load package information from a Zip archive.
     *
     * We'll try to load processed metadata from the cache first (if available), and if that
	 * fails we'll extract plugin/theme details from the ZIP file itself.
	 *
	 * @param string $filename
	 * @param string $slug
	 * @param Wpup_FileCache $cache
	 * @return Wpup_Package
	 */
	public static function fromArchive($filename, $slug = null, Wpup_FileCache $cache = null) {
		$modified = filemtime($filename);
		$cacheKey = 'metadata-' . md5($filename . '|' . filesize($filename) . '|' . $modified);
		$metadata = null;

		//Try the cache first.
		if ( isset($cache) ) {
			$metadata = $cache->get($cacheKey);
		}

		if ( !isset($metadata) || !is_array($metadata) ) {
			$metadata = self::extractMetadata($filename);
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
	 * Extract plugin or theme headers and readme contents from a 's ZIP file and convert them
	 * into a structure compatible with the custom update checker.
	 *
	 * See this page for an overview of the plugin metadata format:
	 * @link https://spreadsheets.google.com/pub?key=0AqP80E74YcUWdEdETXZLcXhjd2w0cHMwX2U1eDlWTHc&authkey=CK7h9toK&hl=en&single=true&gid=0&output=html
	 *
	 * @param string $zipFilename
	 * @return array
	 */
	public static function extractMetadata($zipFilename){
		$packageInfo = WshWordPressPackageParser::parsePackage($zipFilename, true);
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

	public function getFileSize() {
		return filesize($this->filename);
	}

	public function getLastModified() {
		return filemtime($this->filename);
	}
}