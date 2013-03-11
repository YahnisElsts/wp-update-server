WP Update Server
================

A custom update API for WordPress plugins and themes. 

*Note: This documentation is incomplete.*

Features
--------
* **Provide updates for plugins and themes.**    
* **Easy to set up.**

  Just upload the script directory to your server and drop a plugin or theme ZIP in the `packages` subdirectory. Now you have a working update API at `http://yourserver.com/wp-update-server/?action=get_metadata&slug=your-plugin`.
* **Easy to integrate** with existing plugins and themes.

  All it takes is about 5 lines of code. See the [plugin update checker](http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/) and [theme update checker](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) docs for details, or jus scroll down to the "Getting Started" section for the short version.
* **Minimal server requirements.**

  Requires PHP 5.3+ and the Zip extension. No other dependencies.
* **Designed for extensibility.**

  Want to secure your upgrade download links? Or use a custom logger or cache? Maybe your plugin doesn't have a standard `readme.txt` and you'd prefer to load the changelog and other update meta from the database instead? Create your own customized server by extending the `Wpup_UpdateServer` class. See examples below.
  	
Getting Started
---------------

### Setting Up the Server
This part of the setup process is identical for both plugins and themes. For the sake of brevity, I'll describe it from the plugin perspective.

1. Upload the `wp-update-server` directory to your site. You can rename it to something else (e.g. simply `updates`) if you like.
2. Create a Zip archive of your plugin directory. The name of the archive must be the same as the name of the directory + ".zip".
3. Copy the Zip file to the `/packages` subdirectory.
4. Verify that the API is working by visiting `/wp-update-server/?action=get_metadata&slug=plugin-directory-name` in your browser. You should see a JSON document containing various details of your plugin (name, version and so on).

**Tip:** Use the JSONView extension ([Firefox](https://addons.mozilla.org/en-US/firefox/addon/10869/),  [Chrome](https://chrome.google.com/webstore/detail/jsonview/chklaanhfefbnpoihckbnefhakgolnmc)) to pretty-print JSON in your browser.

When creating the Zip file, make sure the plugin files are inside a directory and not at the archive root. For example, lets say you have a plugin called "My Cool Plugin" and it lives inside `/wp-content/plugins/my-cool-plugin`. The ZIP file should be named `my-cool-plugin.zip` and it should contain the following:

```
/my-cool-plugin
    /css
    /js
    my-cool-plugin.php
    readme.txt
    ...
```

If you put everything at the root, update notifications may show up just fine, but you will run into inexplicable problems when you try to actually install an update because WP expects plugin files to be inside a subdirectory.

### Integrating with Plugins

Now that you have the server ready to go, the next step is to make your plugin periodically query it for updates and display them in the WP Dashboard. We'll use the [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) library to achieve that.

1. Download the update checker.
2. Place the `plugin-update-checker` directory inside your `includes` directory or the equivalent.
3. Add the following code to your main plugin file:

```php
require 'path/to/plugin-update-checker/plugin-update-checker.php';
$MyUpdateChecker = PucFactory::buildUpdateChecker(
	'http://example.com/wp-update-server/?action=get_metadata&slug=plugin-directory-name',
	__FILE__,
	'plugin-directory-name'
);
```

The library will check for updates twice per day by default. You can also trigger an immediate check by going to the "Plugins" page and clicking thw "Check for updates" link below the plugin's description. If the update checker discovers that a new version is available, it will display an update notification in the WordPress Dashboard and your users will be able to install it by clicking the "upgrade now" link. It works just like with plugins hosted on WordPress.org from the users' perspective. 

See the [update checker docs](http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/) for more detailed instructions and and examples.

When you're ready to release an update, just zip the plugin directory as described above and put it in the `/packages` subdirectory on the server, overwriting the previous version. 

**Tip:** Create a `readme.txt` file for your plugin. If you have one, the update server will use it to generate the plugin information page that users see when they click the "View version x.y.z details" link in an update notification. The readme must conform to [the WordPress.org readme standard](http://wordpress.org/extend/plugins/about/readme.txt).

### Integrating with Themes

1. Download the [theme update checker](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) library.
2. Place the `theme-updates` directory in your `includes` or the equivalent.
3. Add this snippet to your `functions.php`:

   ```php
require 'path/to/theme-updates/theme-update-checker.php';
$MyThemeUpdateChecker = new ThemeUpdateChecker(
    'theme-directory-name',
    'http://example.com/wp-update-server/?action=get_metadata&slug=theme-directory-name'
);
```
4. Add a `Details URI` header to your `style.css`:

  ```Details URI: http://example.com/my-theme-changelog.html```
  
  This header specifies the page that the user will see if they click the “View version x.y.z details” link in an update notification. Set it to the URL of your “What’s New In Version 1.2.3″ page or the theme homepage.

Like with plugin updates, the theme update checker will query the server for theme details every 12 hours and display an update notification in the WordPress Dashboard if it finds a new version.

See the [theme update checker docs](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) for more information.
	
## Advanced Topics
*TODO:* Describe how to do some or all of the following:
### Extending the server
### Securing download links
### Running the server from a plugin
### Changing the server URL
### Logging
