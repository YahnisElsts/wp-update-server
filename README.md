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
$MyUpdateChecker = PucFactory::buildUpdateChecker(
	'http://example.com/wp-update-server/?action=get_metadata&slug=plugin-directory-name', //Metadata URL.
	__FILE__, //Full path to the main plugin file.
	'plugin-directory-name' //Plugin slug. Usually it's the same as the name of the directory.
);
```
4. When you're ready to release an update, just zip the plugin directory as described above and put it in the `packages` subdirectory on the server (overwriting the previous version). 

The library will check for updates twice a day by default. If the update checker discovers that a new version is available, it will display an update notification in the WordPress Dashboard and the user will be able to install it by clicking the "upgrade now" link. It works just like with plugins hosted on WordPress.org from the users' perspective. 

See the [update checker docs](http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/) for detailed usage instructions and and more examples.

**Tip:** Create a `readme.txt` file for your plugin. If you have one, the update server will use it to generate the plugin information page that users see when they click the "View version x.y.z details" link in an update notification. The readme must conform to [the WordPress.org readme standard](http://wordpress.org/extend/plugins/about/readme.txt).

### Integrating with Themes

1. Download the [theme update checker](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) library.
2. Place the `theme-updates` directory in your `includes` or the equivalent.
3. Add this snippet to your `functions.php`:

   ```php
require 'path/to/theme-updates/theme-update-checker.php';
$MyThemeUpdateChecker = new ThemeUpdateChecker(
    'theme-directory-name', //Theme slug. Usually identical to the name of its directory.
    'http://example.com/wp-update-server/?action=get_metadata&slug=theme-directory-name' //Metadata URL.
);
```
4. Add a `Details URI` header to your `style.css`:

  ```Details URI: http://example.com/my-theme-changelog.html```
  
  This header specifies the page that the user will see if they click the "View version x.y.z details" link in an update notification. Set it to the URL of your "What’s New In Version z.y.z" page or the theme homepage.

Like with plugin updates, the theme update checker will query the server for theme details every 12 hours and display an update notification in the WordPress Dashboard if a new version is available.

See the [theme update checker docs](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) for more information.
	
## Advanced Topics

*TODO:* This section is incomplete.

### Logging

The server logs all API requests to the `/logs/request.log` file. Each line represents one request and is formatted like this:

```
[timestamp] IP_address	action	slug	installed_version	wordpress_version	site_url	query_string
```

Missing or inapplicable fields are replaced with a dash "-". The logger extracts the WordPress version and site URL from the "User-Agent" header that WordPress adds to all requests sent via its HTTP API. These fields will not be present if the header is removed or overriden by a plugin (some security plugins do that) or if you access the API through the browser.

### Extending the server
### Securing download links
### Running the server from a plugin
### Changing the server URL
