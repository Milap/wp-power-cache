=== WP Power Cache ===
Contributors: Milap, Imneerav 
Tags: cache, static cache, performance, speed, optimization
Requires at least: 3.0.1
Tested up to: 6.9
Stable tag: 1.0
Requires PHP: 8.0
Donate link: https://www.paypal.me/MilapPatel
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A high-performance static page caching engine that generates HTML snapshots of your WordPress site for near-instant loading times.

== Description ==

WP Power Cache is designed for developers and site owners who need extreme speed without the bloat of traditional caching plugins. It converts your dynamic WordPress pages into static HTML files, serving them directly from the filesystem to bypass heavy PHP and MySQL processing.

**Key Features:**

* **Static HTML Generation:** Automatically creates and stores minified-style HTML snapshots.
* **Smart Cache Clearing:** Intelligently clears only the specific post cache and the homepage when content is updated, keeping the rest of your cache intact.
* **Admin Bar Integration:** Clear the entire site cache from any page via the top WordPress Admin Bar.
* **System Diagnostics:** Built-in health checks for directory permissions, disk space, and permalink compatibility.
* **Developer Friendly:** Includes a dedicated Developer Mode for real-time performance monitoring.
* **Secure Storage:** Uses hashed directory names to prevent unauthorized directory browsing of your cached files.

== Installation ==

1. Upload the `wp-power-cache` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Power Cache** in the admin sidebar to configure settings.
4. Ensure your Permalinks are set to anything other than "Plain" (Settings > Permalinks).

== Frequently Asked Questions ==

= Does it clear the homepage when I update a post? =
Yes. To ensure your "Latest Posts" sections stay updated, the plugin automatically purges the homepage (index.html) whenever a single post or page is saved.

= Where are the cache files stored? =
Files are stored in a protected sub-folder within the plugin directory using an MD5 hashed name for enhanced security.

= Can I clear the cache manually? =
Absolutely. You can use the "Empty Entire Website Cache" button in the plugin settings or the "Power Cache" menu in the WordPress Admin Bar.

== Screenshots ==

1. General Settings - Toggle caching and developer mode.
2. Cache Info - Real-time storage overview and file paths.
3. Diagnostics - System health check for server compatibility.

== Changelog ==

= 1.0 =
* Initial release.
* Added Static HTML generation.
* Added Smart Auto-Clear logic for individual posts and homepages.
* Added Admin Bar shortcuts.
* Added Diagnostics tab and health checks.