The `banners` directory is the place to store plugin banners. When the user clicks the "View version x.y.z details" link, the appropriate banner image will be displayed in the header of the information pop-up. Banners are optional.

To add a banner, create an 772x250 pixel image and save it as `$slug-772x250.png` or `$slug-772x250.jpg`. For example, if your plugin slug is `my-great-plugin`, name the file `my-great-plugin-772x250.png`. 

To support high-DPI displays, you can include an additional 1544x500 px banner named `$slug-1544x500.png` or `$slug-1544x500.jpg`.

See these links for more information:
- https://wordpress.org/plugins/about/faq/#banners
- https://make.wordpress.org/core/2012/07/04/fun-with-high-dpi-displays/


(Themes have a different update information screen that doesn't include a banner.)