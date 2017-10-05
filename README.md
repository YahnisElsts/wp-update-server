WP Update Server
================

A custom update API for WordPress plugins and themes. 

Features
--------
* **Provide updates for plugins and themes.**    

  From the users perspective, the updates work just like they do with plugins and themes listed in the official WordPress.org directory.
* **Easy to set up.**

  Just upload the script directory to your server and drop a plugin or theme ZIP in the `packages` subdirectory. Now you have a working update API at `http://yourserver.com/wp-update-server/?action=get_metadata&slug=your-plugin`.
* **Easy to integrate** with existing plugins and themes.

  All it takes is about 5 lines of code. See the [plugin update checker](http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/) and [theme update checker](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) docs for details, or just scroll down to the "Getting Started" section for the short version.
* **Minimal server requirements.**

  The server component requires PHP 5.3+ and the Zip extension. The client library only requires PHP 5.2 - same as the current version of WordPress.
* **Designed for extensibility.**

  Want to secure your upgrade download links? Or use a custom logger or cache? Maybe your plugin doesn't have a standard `readme.txt` and you'd prefer to load the changelog and other update meta from the database instead? Create your own customized server by extending the `Wpup_UpdateServer` class. See examples below.
  	
Getting Started
---------------

### Setting Up the Server
This part of the setup process is identical for both plugins and themes. For the sake of brevity, I'll describe it from the plugin perspective.

1. Upload the `wp-update-server` directory to your site. You can rename it to something else (e.g. `updates`) if you want. 
2. Make the `cache` and `logs` subdirectories writable by PHP.
3. Create a Zip archive of your plugin's directory. The name of the archive must be the same as the name of the directory + ".zip".
4. Copy the Zip file to the `packages` subdirectory.
5. Verify that the API is working by visiting `/wp-update-server/?action=get_metadata&slug=plugin-directory-name` in your browser. You should see a JSON document containing various information about your plugin (name, version, description and so on).

**Tip:** Use the JSONView extension ([Firefox](https://addons.mozilla.org/en-US/firefox/addon/10869/),  [Chrome](https://chrome.google.com/webstore/detail/jsonview/chklaanhfefbnpoihckbnefhakgolnmc)) to pretty-print JSON in the browser.

When creating the Zip file, make sure the plugin files are inside a directory and not at the archive root. For example, lets say you have a plugin called "My Cool Plugin" and it lives inside `/wp-content/plugins/my-cool-plugin`. The ZIP file should be named `my-cool-plugin.zip` and it should contain the following:

```
/my-cool-plugin
    /css
    /js
    /another-directory
    my-cool-plugin.php
    readme.txt
    ...
```

If you put everything at the root, update notifications may show up just fine, but you will run into inexplicable problems when you try to install an update because WordPress expects plugin files to be inside a subdirectory.

### Integrating with Plugins

Now that you have the server ready to go, the next step is to make your plugin query it for updates. We'll use the [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) library to achieve that.

1. Download the update checker.
2. Move the `plugin-update-checker` directory to your plugin's directory.
3. Add the following code to your main plugin file:

	```php
	require 'path/to/plugin-update-checker/plugin-update-checker.php';
	$MyUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
		'http://example.com/wp-update-server/?action=get_metadata&slug=plugin-directory-name', //Metadata URL.
		__FILE__, //Full path to the main plugin file.
		'plugin-directory-name' //Plugin slug. Usually it's the same as the name of the directory.
	);
	```
4. When you're ready to release an update, just zip the plugin directory as described above and put it in the `packages` subdirectory on the server (overwriting the previous version). 

The library will check for updates twice a day by default. If the update checker discovers that a new version is available, it will display an update notification in the WordPress Dashboard and the user will be able to install it by clicking the "upgrade now" link. It works just like with plugins hosted on WordPress.org from the users' perspective. 

See the [update checker docs](http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/) for detailed usage instructions and and more examples.

**Tip:** Create a `readme.txt` file for your plugin. If you have one, the update server will use it to generate the plugin information page that users see when they click the "View version x.y.z details" link in an update notification. The readme must conform to [the WordPress.org readme standard](http://wordpress.org/extend/plugins/about/readme.txt).

**Note:** Your plugin or theme must be active for updates to work. One consequence of this is that on a multisite installation updates will only show up if your plugin is active on the main site. This is because only plugins that are enabled on the main site are loaded in the network admin. For reference, the main site is the one that has the path "/" in the *All Sites* list. 

### Integrating with Themes

1. Download the [theme update checker](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) library.
2. Place the `theme-updates` directory in your `includes` or the equivalent.
3. Add this snippet to your `functions.php`:

	```php
	require 'path/to/theme-updates/theme-update-checker.php';
	$MyThemeUpdateChecker = new ThemeUpdateChecker(
		'theme-directory-name', //Theme slug. Usually the same as the name of its directory.
		'http://example.com/wp-update-server/?action=get_metadata&slug=theme-directory-name' //Metadata URL.
	);
	```
4. Add a `Details URI` header to your `style.css`:

	`Details URI: http://example.com/my-theme-changelog.html`
  
	This header specifies the page that the user will see if they click the "View version x.y.z details" link in an update notification. Set it to the URL of your "What’s New In Version z.y.z" page or the theme homepage.

Like with plugin updates, the theme update checker will query the server for theme details every 12 hours and display an update notification in the WordPress Dashboard if a new version is available.

See the [theme update checker docs](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) for more information.

**Update:** The [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) library now also supports theme updates. The old theme update checker is no longer actively maintained.

## Advanced Topics

### Logging

The server logs all API requests to the `/logs/request.log` file. Each line represents one request and is formatted like this:

```
[timestamp] IP_address	action	slug	installed_version	wordpress_version	site_url	query_string
```

Missing or inapplicable fields are replaced with a dash "-". The logger extracts the WordPress version and site URL from the "User-Agent" header that WordPress adds to all requests sent via its HTTP API. These fields will not be present if you make an API request via the browser or if the header is removed or overriden by a plugin (some security plugins do that).

### Extending the server

To customize the way the update server works, create your own server class that extends [Wpup_UpdateServer](includes/Wpup/UpdateServer.php) and edit the init script (that's `index.php` if you're running the server as a standalone app) to load and use the new class.

For example, lets make a simple modification that disables downloads and removes the download URL from the plugin details returned by the update API. This could serve as a foundation for a custom server that requires authorization to download an update.

Add a new file `MyCustomServer.php` to `wp-update-server`:

```php
class MyCustomServer extends Wpup_UpdateServer {
	protected function filterMetadata($meta, $request) {
		$meta = parent::filterMetadata($meta, $request);
		unset($meta['download_url']);
		return $meta;
	}
	
	protected function actionDownload(Wpup_Request $request) {
		$this->exitWithError('Downloads are disabled.', 403);
	}
}
```

Edit `index.php` to use the new class:

```php
require __DIR__ . '/loader.php';
require __DIR__ . '/MyCustomServer.php';
$server = new MyCustomServer();
$server->handleRequest();
```

### Running the server from another script

While the easiest way to use the update server is to run it as a standalone application, that's not the only way to do it. If you need to, you can also load it as a third-party library and create your own server instance. This lets you  filter and modify query arguments before passing them to the server, run it from a WordPress plugin, use your own server class, and so on.

To run the server from your own application you need to do three things:

1. Include `/wp-update-server/loader.php`.
2. Create an instance of `Wpup_UpdateServer` or a class that extends it.
3. Call the `handleRequest($queryParams)` method.

Here's a basic example plugin that runs the update server from inside WordPress:
```php
<?php
/*
Plugin Name: Plugin Update Server
Description: An example plugin that runs the update API.
Version: 1.0
Author: Yahnis Elsts
Author URI: http://w-shadow.com/
*/

require_once __DIR__ . '/path/to/wp-update-server/loader.php';

class ExamplePlugin {
	protected $updateServer;

	public function __construct() {
		$this->updateServer = new MyCustomServer(home_url('/'));
		
		//The "action" and "slug" query parameters are often used by the WordPress core
		//or other plugins, so lets use different parameter names to avoid conflict.
		add_filter('query_vars', array($this, 'addQueryVariables'));
		add_action('template_redirect', array($this, 'handleUpdateApiRequest'));
	}
	
	public function addQueryVariables($queryVariables) {
		$queryVariables = array_merge($queryVariables, array(
			'update_action',
			'update_slug',
		));
		return $queryVariables;
	}
	
	public function handleUpdateApiRequest() {
		if ( get_query_var('update_action') ) {
			$this->updateServer->handleRequest(array_merge($_GET, array(
				'action' => get_query_var('update_action'),
				'slug'   => get_query_var('update_slug'),
			)));
		}
	}
}

class MyCustomServer extends Wpup_UpdateServer {
    protected function generateDownloadUrl(Wpup_Package $package) {
        $query = array(
            'update_action' => 'download',
            'update_slug' => $package->slug,
        );
        return self::addQueryArg($query, $this->serverUrl);
    }
}

$examplePlugin = new ExamplePlugin();
```

**Note:** If you intend to use something like the above in practice, you'll probably want to override `Wpup_UpdateServer::generateDownloadUrl()` to customize the URLs or change the query parameters.

### Securing download links

See [this blog post](http://w-shadow.com/blog/2013/03/19/plugin-updates-securing-download-links/) for a high-level overview and some brief examples.

### Analytics

You can use the [wp-update-server-stats](https://github.com/YahnisElsts/wp-update-server-stats) tool to parse server logs and display statistics like the number of active installs, active versions, and so on.
