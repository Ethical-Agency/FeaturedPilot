=== Auto Featured Image Tool ===
Contributors: theethicalagency
Tags: featured image, unsplash, automatic, media
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically assigns Unsplash photos as featured images for your WordPress posts.

== Description ==

Auto Featured Image Tool connects to the Unsplash API to find and set relevant
featured images for your WordPress posts automatically.

**Features**

* One-click image assignment from the post edit screen
* Keyword auto-detection from post title, categories and tags
* Scheduled daily or weekly automation
* Bulk action on the Posts list screen
* Activity log with 1000-entry history
* Image filters: orientation, content safety, minimum dimensions
* Full photographer attribution stored and displayed
* Hardened security: nonces, capability checks, sanitisation, output escaping

**Unsplash Attribution**

All images are sourced from Unsplash under the Unsplash License. Photographer
attribution is stored in attachment metadata automatically.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the Plugins screen
3. Go to **Settings > Unsplash Images** and enter your API key
4. Test the connection — you're ready to go

**Getting an API key**

1. Create a free account at unsplash.com/developers
2. Create a new application
3. Copy the "Access Key" — paste it into the plugin settings

**Alternative: define the key in wp-config.php**

    define( 'UNSPLASH_API_KEY', 'your_key_here' );

== Frequently Asked Questions ==

= Is this free? =

Yes. The Unsplash free tier provides 50 API requests per hour, which is
sufficient for most sites.

= Will it overwrite existing featured images? =

Only if you explicitly choose to replace them (via the meta box button or bulk
action). The scheduler defaults to posts that have no featured image.

= How does keyword selection work? =

The plugin checks, in order: custom keyword → post title → category → tag →
default keyword from settings.

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
