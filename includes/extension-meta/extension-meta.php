<?php

if ( !function_exists('Markdown') ) {
	include 'markdown.php'; //Used to convert readme.txt contents to HTML.
}

class WshWordPressPackageParser {
	/**
	 * Extract headers and readme.txt data from a ZIP archive that contains a plugin or theme.
	 *
	 * Returns an associative array with these keys:
	 *  'type'   - Detected package type. This can be either "plugin" or "theme".
	 * 	'header' - An array of plugin or theme headers. See get_plugin_data() or WP_Theme for details.
	 *  'readme' - An array of metadata extracted from readme.txt. @see self::parseReadme()
	 * 	'pluginFile' - The name of the PHP file where the plugin headers were found relative to the root directory of the ZIP archive.
	 * 	'stylesheet' - The relative path to the style.css file that contains theme headers, if any.
	 *
	 * The 'readme' key will only be present if the input archive contains a readme.txt file
	 * formatted according to WordPress.org readme standards. Similarly, 'pluginFile' and
	 * 'stylesheet' will only be present if the archive contains a plugin or a theme, respectively.
	 *
	 * @param string $packageFilename The path to the ZIP package.
	 * @param bool $applyMarkdown Whether to transform markup used in readme.txt to HTML. Defaults to false.
	 * @return array|bool Either an associative array or FALSE if the input file is not a valid ZIP archive or doesn't contain a WP plugin or theme.
	 */
	public static function parsePackage($packageFilename, $applyMarkdown = false){
		if ( !file_exists($packageFilename) || !is_readable($packageFilename) ){
			return false;
		}

		//Open the .zip
		$zip = WshWpp_Archive::open($packageFilename);
		if ( $zip === false ){
			return false;
		}

		//Find and parse the plugin or theme file and (optionally) readme.txt.
		$header = null;
		$readme = null;
		$pluginFile = null;
		$stylesheet = null;
		$type = null;

		$entries = $zip->listEntries();
		for ( $fileIndex = 0; ($fileIndex < count($entries)) && (empty($readme) || empty($header)); $fileIndex++ ){
			$info = $entries[$fileIndex];

			//Normalize filename: convert backslashes to slashes, remove leading slashes.
			$fileName = trim(str_replace('\\', '/', $info['name']), '/');
			$fileName = ltrim($fileName, '/');

			$fileNameParts = explode('.', $fileName);
			$extension = strtolower(end($fileNameParts));
			$depth = substr_count($fileName, '/');

			//Skip empty files, directories and everything that's more than 1 sub-directory deep.
			if ( ($depth > 1) || $info['isFolder'] ) {
				continue;
			}

			//readme.txt (for plugins)?
			if ( empty($readme) && (strtolower(basename($fileName)) == 'readme.txt') ){
				//Try to parse the readme.
				$readme = self::parseReadme($zip->getFileContents($info), $applyMarkdown);
			}

			//Theme stylesheet?
			if ( empty($header) && (strtolower(basename($fileName)) == 'style.css') ) {
				$fileContents = substr($zip->getFileContents($info), 0, 8*1024);
				$header = self::getThemeHeaders($fileContents);
				if ( !empty($header) ){
					$stylesheet = $fileName;
					$type = 'theme';
				}
			}

			//Main plugin file?
			if ( empty($header) && ($extension === 'php') ){
				$fileContents = substr($zip->getFileContents($info), 0, 8*1024);
				$header = self::getPluginHeaders($fileContents);
				if ( !empty($header) ){
					$pluginFile = $fileName;
					$type = 'plugin';
				}
			}
		}

		if ( empty($type) ){
			return false;
		} else {
			return compact('header', 'readme', 'pluginFile', 'stylesheet', 'type');
		}
	}

	/**
	 * Parse a plugin's readme.txt to extract various plugin metadata.
	 *
	 * Returns an array with the following fields:
	 * 	'name' - Name of the plugin.
	 * 	'contributors' - An array of wordpress.org usernames.
	 * 	'donate' - The plugin's donation link.
	 * 	'tags' - An array of the plugin's tags.
	 * 	'requires' - The minimum version of WordPress that the plugin will run on.
	 * 	'tested' - The latest version of WordPress that the plugin has been tested on.
	 * 	'stable' - The SVN tag of the latest stable release, or 'trunk'.
	 * 	'short_description' - The plugin's "short description".
	 * 	'sections' - An associative array of sections present in the readme.txt.
	 *               Case and formatting of section headers will be preserved.
	 *
	 * Be warned that this function does *not* perfectly emulate the way that WordPress.org
	 * parses plugin readme's. In particular, it may mangle certain HTML markup that wp.org
	 * handles correctly.
	 *
	 * @see http://wordpress.org/extend/plugins/about/readme.txt
	 *
	 * @param string $readmeTxtContents The contents of a plugin's readme.txt file.
	 * @param bool $applyMarkdown Whether to transform Markdown used in readme.txt sections to HTML. Defaults to false.
	 * @return array|null Associative array, or NULL if the input isn't a valid readme.txt file.
	 */
	public static function parseReadme($readmeTxtContents, $applyMarkdown = false){
		$readmeTxtContents = trim($readmeTxtContents, " \t\n\r");
		$readme = array(
			'name' => '',
			'contributors' => array(),
			'donate' => '',
			'tags' => array(),
			'requires' => '',
			'tested' => '',
			'stable' => '',
			'short_description' => '',
			'sections' => array(),
		);

		//The readme.txt header has a fairly fixed structure, so we can parse it line-by-line
		$lines = explode("\n", $readmeTxtContents);
		//Plugin name is at the very top, e.g. === My Plugin ===
		if ( preg_match('@===\s*(.+?)\s*===@', array_shift($lines), $matches) ){
			$readme['name'] = $matches[1];
		} else {
			return null;
		}

		//Then there's a bunch of meta fields formatted as "Field: value"
		$headers = array();
		$headerMap = array(
			'Contributors' => 'contributors',
			'Donate link' => 'donate',
			'Tags' => 'tags',
			'Requires at least' => 'requires',
			'Tested up to' => 'tested',
			'Stable tag' => 'stable',
		);
		do { //Parse each readme.txt header
			$pieces = explode(':', array_shift($lines), 2);
			if ( array_key_exists($pieces[0], $headerMap) ){
				if ( isset($pieces[1]) ){
					$headers[ $headerMap[$pieces[0]] ] = trim($pieces[1]);
				} else {
					$headers[ $headerMap[$pieces[0]] ] = '';
				}
			}
		} while ( trim($pieces[0]) != '' ); //Until an empty line is encountered

		//"Contributors" is a comma-separated list. Convert it to an array.
		if ( !empty($headers['contributors']) ){
			$headers['contributors'] = array_map('trim', explode(',', $headers['contributors']));
		}

		//Likewise for "Tags"
		if ( !empty($headers['tags']) ){
			$headers['tags'] = array_map('trim', explode(',', $headers['tags']));
		}

		$readme = array_merge($readme, $headers);

		//After the headers comes the short description
		$readme['short_description'] = array_shift($lines);

		//Finally, a valid readme.txt also contains one or more "sections" identified by "== Section Name =="
		$sections = array();
		$contentBuffer = array();
		$currentSection = '';
		foreach($lines as $line){
			//Is this a section header?
			if ( preg_match('@^\s*==\s+(.+?)\s+==\s*$@m', $line, $matches) ){
				//Flush the content buffer for the previous section, if any
				if ( !empty($currentSection) ){
					$sectionContent = trim(implode("\n", $contentBuffer));
					$sections[$currentSection] = $sectionContent;
				}
				//Start reading a new section
				$currentSection = $matches[1];
				$contentBuffer = array();
			} else {
				//Buffer all section content
				$contentBuffer[] = $line;
			}
		}
		//Flush the buffer for the last section
		if ( !empty($currentSection) ){
			$sections[$currentSection] = trim(implode("\n", $contentBuffer));
		}

		//Apply Markdown to sections
		if ( $applyMarkdown ){
			$sections = array_map(__CLASS__ . '::applyMarkdown', $sections);
		}

		$readme['sections'] = $sections;

		return $readme;
	}

	/**
	 * Transform Markdown markup to HTML.
	 *
	 * Tries (in vain) to emulate the transformation that WordPress.org applies to readme.txt files.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function applyMarkdown($text){
		//The WP standard for readme files uses some custom markup, like "= H4 headers ="
		$text = preg_replace('@^\s*=\s*(.+?)\s*=\s*$@m', "<h4>$1</h4>\n", $text);
		return Markdown($text);
	}

	/**
	 * Parse the plugin contents to retrieve plugin's metadata headers.
	 *
	 * Adapted from the get_plugin_data() function used by WordPress.
	 * Returns an array that contains the following:
	 *		'Name' - Name of the plugin.
	 *		'Title' - Title of the plugin and the link to the plugin's web site.
	 *		'Description' - Description of what the plugin does and/or notes from the author.
	 *		'Author' - The author's name.
	 *		'AuthorURI' - The author's web site address.
	 *		'Version' - The plugin version number.
	 *		'PluginURI' - Plugin web site address.
	 *		'TextDomain' - Plugin's text domain for localization.
	 *		'DomainPath' - Plugin's relative directory path to .mo files.
	 *		'Network' - Boolean. Whether the plugin can only be activated network wide.
	 *
	 * If the input string doesn't appear to contain a valid plugin header, the function
	 * will return NULL.
	 *
	 * @param string $fileContents Contents of the plugin file
	 * @return array|null See above for description.
	 */
	public static function getPluginHeaders($fileContents) {
		//[Internal name => Name used in the plugin file]
		$pluginHeaderNames = array(
			'Name' => 'Plugin Name',
			'PluginURI' => 'Plugin URI',
			'Version' => 'Version',
			'Description' => 'Description',
			'Author' => 'Author',
			'AuthorURI' => 'Author URI',
			'TextDomain' => 'Text Domain',
			'DomainPath' => 'Domain Path',
			'Network' => 'Network',
			'Depends' => 'Depends',
			'Provides' => 'Provides',

			//Site Wide Only is deprecated in favor of Network.
			'_sitewide' => 'Site Wide Only',
		);

		$headers = self::getFileHeaders($fileContents, $pluginHeaderNames);

		//Site Wide Only is the old header for Network.
		if ( empty($headers['Network']) && !empty($headers['_sitewide']) ) {
			$headers['Network'] = $headers['_sitewide'];
		}
		unset($headers['_sitewide']);
		$headers['Network'] = (strtolower($headers['Network']) === 'true');

		//For backward compatibility by default Title is the same as Name.
		$headers['Title'] = $headers['Name'];

		//"Depends" is a comma-separated list. Convert it to an array.
		if ( !empty($headers['Depends']) ){
			$headers['Depends'] = array_map('trim', explode(',', $headers['Depends']));
		}

		//Same for "Provides"
		if ( !empty($headers['Provides']) ){
			$headers['Provides'] = array_map('trim', explode(',', $headers['Provides']));
		}

		//If it doesn't have a name, it's probably not a plugin.
		if ( empty($headers['Name']) ){
			return null;
		} else {
			return $headers;
		}
	}

	/**
	 * Parse the theme stylesheet to retrieve its metadata headers.
	 *
	 * Adapted from the get_theme_data() function and the WP_Theme class in WordPress.
	 * Returns an array that contains the following:
	 *		'Name' - Name of the theme.
	 *		'Description' - Theme description.
	 *		'Author' - The author's name
	 *		'AuthorURI' - The authors web site address.
	 *		'Version' - The theme version number.
	 *		'ThemeURI' - Theme web site address.
	 *		'Template' - The slug of the parent theme. Only applies to child themes.
	 *		'Status' - Unknown. Included for completeness.
	 *		'Tags' - An array of tags.
	 *		'TextDomain' - Theme's text domain for localization.
	 *		'DomainPath' - Theme's relative directory path to .mo files.
	 *
	 * If the input string doesn't appear to contain a valid theme header, the function
	 * will return NULL.
	 *
	 * @param string $fileContents Contents of the theme stylesheet.
	 * @return array|null See above for description.
	 */
	public static function getThemeHeaders($fileContents) {
		$themeHeaderNames = array(
			'Name'        => 'Theme Name',
			'ThemeURI'    => 'Theme URI',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'Version'     => 'Version',
			'Template'    => 'Template',
			'Status'      => 'Status',
			'Tags'        => 'Tags',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'DetailsURI'   => 'Details URI',
		);
		$headers = self::getFileHeaders($fileContents, $themeHeaderNames);

		$headers['Tags'] = array_filter(array_map('trim', explode(',', strip_tags( $headers['Tags']))));

		//If it doesn't have a name, it's probably not a valid theme.
		if ( empty($headers['Name']) ){
			return null;
		} else {
			return $headers;
		}
	}

	/**
	 * Parse the file contents to retrieve its metadata.
	 *
	 * Searches for metadata for a file, such as a plugin or theme.  Each piece of
	 * metadata must be on its own line. For a field spanning multiple lines, it
	 * must not have any newlines or only parts of it will be displayed.
	 *
	 * @param string $fileContents File contents. Can be safely truncated to 8kiB as that's all WP itself scans.
	 * @param array $headerMap The list of headers to search for in the file.
	 * @return array
	 */
	public static function getFileHeaders($fileContents, $headerMap ) {
		$headers = array();

		//Support systems that use CR as a line ending.
		$fileContents = str_replace("\r", "\n", $fileContents);

		foreach ($headerMap as $field => $prettyName) {
			$found = preg_match('/^[ \t\/*#@]*' . preg_quote($prettyName, '/') . ':(.*)$/mi', $fileContents, $matches);
			if ( ($found > 0) && !empty($matches[1]) ) {
				//Strip comment markers and closing PHP tags.
				$value = trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $matches[1]));
				$headers[$field] = $value;
			} else {
				$headers[$field] = '';
			}
		}

		return $headers;
	}
}

/**
 * Extract plugin metadata from a plugin's ZIP file and transform it into a structure
 * compatible with the custom update checker.
 *
 * Deprecated. Included for backwards-compatibility.
 *
 * This is an utility function that scans the input file (assumed to be a ZIP archive)
 * to find and parse the plugin's main PHP file and readme.txt file. Plugin metadata from
 * both files is assembled into an associative array. The structure if this array is
 * compatible with the format of the metadata file used by the custom plugin update checker
 * library available at the below URL.
 *
 * @see http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/
 * @see https://spreadsheets.google.com/pub?key=0AqP80E74YcUWdEdETXZLcXhjd2w0cHMwX2U1eDlWTHc&authkey=CK7h9toK&hl=en&single=true&gid=0&output=html
 *
 * Requires the ZIP extension for PHP.
 * @see http://php.net/manual/en/book.zip.php
 *
 * @param string|array $packageInfo Either path to a ZIP file containing a WP plugin, or the return value of analysePluginPackage().
 * @return array Associative array
 */
function getPluginPackageMeta($packageInfo){
	if ( is_string($packageInfo) && file_exists($packageInfo) ){
		$packageInfo = WshWordPressPackageParser::parsePackage($packageInfo, true);
	}

	$meta = array();

	if ( isset($packageInfo['header']) && !empty($packageInfo['header']) ){
		$mapping = array(
			'Name' => 'name',
		 	'Version' => 'version',
		 	'PluginURI' => 'homepage',
		 	'Author' => 'author',
		 	'AuthorURI' => 'author_homepage',
		);
		foreach($mapping as $headerField => $metaField){
			if ( array_key_exists($headerField, $packageInfo['header']) && !empty($packageInfo['header'][$headerField]) ){
				$meta[$metaField] = $packageInfo['header'][$headerField];
			}
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

	if ( !empty($packageInfo['pluginFile']) ){
		$meta['slug'] = strtolower(basename(dirname($packageInfo['pluginFile'])));
	}

	return $meta;
}

abstract class WshWpp_Archive {
	/**
	 * Open a Zip archive.
	 *
	 * @param string $zipFileName
	 * @return bool|WshWpp_Archive
	 */
	public static function open($zipFileName) {
		if ( class_exists('ZipArchive', false) ) {
			return WshWpp_ZipArchive::open($zipFileName);
		} else {
			return WshWpp_PclZipArchive::open($zipFileName);
		}
	}

	/**
	 * Get the list of files and directories in the archive.
	 *
	 * @return array
	 */
	abstract public function listEntries();

	/**
	 * Get the contents of a specific file.
	 *
	 * @param array $file
	 * @return string|false
	 */
	abstract public function getFileContents($file);
}

class WshWpp_ZipArchive extends WshWpp_Archive {
	/**
	 * @var ZipArchive
	 */
	protected $archive;

	protected function __construct($zipArchive) {
		$this->archive = $zipArchive;
	}

	public static function open($zipFileName) {
		$zip = new ZipArchive();
		if ( $zip->open($zipFileName) !== true ) {
			return false;
		}
		return new self($zip);
	}

	public function listEntries() {
		$list = array();
		$zip = $this->archive;

		for ($index = 0; $index < $zip->numFiles; $index++) {
			$info = $zip->statIndex($index);
			if ( is_array($info) ) {
				$list[] = array(
					'name'     => $info['name'],
					'size'     => $info['size'],
					'isFolder' => ($info['size'] == 0),
					'index'    => $index,
				);
			}
		}

		return $list;
	}

	public function getFileContents($fileInfo) {
		return $this->archive->getFromIndex($fileInfo['index']);
	}
}

class WshWpp_PclZipArchive extends WshWpp_Archive {
	/**
	 * @var PclZip
	 */
	protected $archive;

	protected function __construct($zipFileName) {
		$this->archive = new PclZip($zipFileName);
	}

	public static function open($zipFileName) {
		if ( !class_exists('PclZip', false) ) {
			require_once dirname(__FILE__) . '/pclzip.php';
		}
		return new self($zipFileName);
	}

	public function listEntries() {
		$contents = $this->archive->listContent();
		if ( $contents === 0 ) {
			return array();
		}

		$list = array();
		foreach ($contents as $info) {
			$list[] = array(
				'name'     => $info['filename'],
				'size'     => $info['size'],
				'isFolder' => $info['folder'],
				'index'    => $info['index'],
			);
		}

		return $list;
	}

	public function getFileContents($fileInfo) {
		$result = $this->archive->extract(PCLZIP_OPT_BY_INDEX, $fileInfo['index'], PCLZIP_OPT_EXTRACT_AS_STRING);

		if ( ($result === 0) || (!isset($result[0], $result[0]['content'])) ) {
			return false;
		}

		return $result[0]['content'];
	}
}
