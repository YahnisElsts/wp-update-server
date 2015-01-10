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
	 * Load package information.
	 *
	 * @param string $filename Path to a Zip archive that contains a WP plugin or theme.
	 * @param string $slug Optional plugin or theme slug. Will be detected automatically.
	 * @param Wpup_Cache $cache
	 * @return Wpup_Package
	 */
	public static function fromArchive($filename, $slug = null, Wpup_Cache $cache = null) {
		$metaObj = new Wpup_ZipMetadataParser($slug, $filename, $cache);
		$metadata = $metaObj->get();

		if ( $slug === null && isset($metadata['slug']) ) {
			$slug = $metadata['slug'];
		}

		return new self($slug, $filename, $metadata);
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
