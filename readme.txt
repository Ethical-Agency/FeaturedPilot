=== FeaturedPilot ===
Contributors: theethicalagency
Tags: featured image, unsplash, pexels, pixabay, freepik, magnific, automatic, media, stock photos, ai upscaling
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically assign featured images from Unsplash, Pexels, or Pixabay with
priority-order fallback, live rate gauges, and a preview-before-you-set grid.

== Description ==

FeaturedPilot connects to three stock-photo APIs and intelligently assigns the
best available featured image for each post. Configure your sources, drag them
into priority order, and let the plugin handle the rest — or hand-pick from a
live 3-image preview grid right inside the post editor.

**Three image sources, one smart fallback**

* **Unsplash** — 50 req/hr (free tier) · Client-ID header auth
* **Pexels** — 200 req/hr (free tier) · Authorization header auth
* **Pixabay** — ~5,000 req/hr (free tier) · API key query param
* **Freepik** — 100 req/day (free tier) · X-Freepik-API-Key header · free-licensed content only

When your top source hits its rate limit the next source in your priority order
takes over automatically. Drag the source rows into your preferred order from
the Sources settings tab.

**Smart keyword detection**

Three keyword modes: derive the search term from the post title / category / tag
chain, use a fixed global keyword, or merge both. Select the mode from a
card-style UI — no drop-downs.

**Post editor preview grid**

Click Fetch Previews in the post meta box to load 3 candidate thumbnails from
your chosen source. Each card shows the photographer credit and a source badge.
Click Use This to set that image instantly — no page reload required.

**Scheduled automation**

Run daily or weekly. Targets posts without a featured image by default, or all
published posts. Auto-pauses when a rate limit is hit and resumes after the
window resets.

**Bulk assignment**

Run a batch job for all posts from the Bulk Run settings tab, with a real-time
progress bar and cancel button. Or select posts from the Posts list and use the
Assign with FeaturedPilot bulk action.

**Live rate gauges**

Per-source colour-coded bars (green / amber / red) show remaining requests at a
glance. Values auto-refresh every 60 seconds.

**Activity log**

Every API call and image assignment is logged with status and timing. Clear all
logs with one click from the Activity Log tab.

**Security hardened**

Nonce verification and capability checks on every AJAX endpoint. All input is
sanitised; all output is escaped. API keys are never logged or echoed raw.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the Plugins screen
3. Go to **Settings > FeaturedPilot** and enter your API keys
4. Click **Test** next to each key to verify the connection
5. Drag sources into your preferred priority order and save

**Getting API keys**

* Unsplash — create a free app at unsplash.com/developers and copy the Access Key
* Pexels — sign up at pexels.com/api and copy your key from the dashboard
* Pixabay — register at pixabay.com and find your key at pixabay.com/api/docs

**Define keys in wp-config.php (optional)**

    define( 'UNSPLASH_API_KEY', 'your_key' );
    define( 'PEXELS_API_KEY',   'your_key' );
    define( 'PIXABAY_API_KEY',  'your_key' );

Constants take precedence over stored options.

== Frequently Asked Questions ==

= Is this free? =

Yes. All three APIs have free tiers. Unsplash provides 50 req/hr, Pexels 200
req/hr, and Pixabay ~5,000 req/hr. Running multiple sources gives you thousands
of requests per hour before any limit is hit.

= Will it overwrite existing featured images? =

Only if you explicitly opt in — via the Replace checkbox in the Bulk Run tab or
the replace option in the post meta box. Scheduled runs default to posts that
have no featured image.

= Does it comply with Unsplash attribution requirements? =

Yes. The required Unsplash download-tracking endpoint is fired whenever an image
is saved, and photographer name and profile URL are stored in attachment metadata.

= Can I pin a specific source for one post? =

Yes. The meta box source pill-toggle (Auto / Unsplash / Pexels / Pixabay) lets
you set a per-post source preference.

= What happens if all sources are rate-limited? =

The error is logged and the scheduler pauses. It automatically retries after the
reset window. The rate gauge turns red so you can see it at a glance.

== Changelog ==

= 1.2.1 =
* New: No-repeat image enforcement — each photo ID + source pair is tracked in
  post meta; all automated and manual assignments now skip photos already used
  on another post, falling back to the top result only when the pool is exhausted.
  The preview grid in the post editor also shows unused photos first.

= 1.2.0 =
* New: Freepik stock photos as a 4th search source (100 req/day free plan,
  free-licensed content only via filters[license][free]=1)
* New: Magnific AI upscaling — optional step that enhances images before
  upload; falls back to original on failure
* New: Magnific settings card (API key, enable toggle, 2×/4× scale factor)
* New: FREEPIK_API_KEY and MAGNIFIC_API_KEY wp-config.php constants

= 1.1.1 =
* Fix: version bump to bust browser cache on admin.js after the per-source
  test-connection fix in 1.1.0

= 1.1.0 =
* New: Pexels and Pixabay support with priority-order fallback
* New: 5-tab settings page (Sources, Automation, Images, Bulk Run, Activity Log)
* New: per-source API cards with live rate gauge and independent Test button
* New: drag-to-reorder source priority
* New: option-card UI for Keyword Mode, Orientation, Content Filter
* New: meta box 3-image preview grid with Use This button
* New: per-post source selector (Auto / Unsplash / Pexels / Pixabay)
* New: uninstall.php removes all data on plugin delete
* Improved: keyword generator supports post-content, global, and combined modes
* Fix: fatal error when Unsplash rate limit option was stored as 0
* Fix: search preview response now matches the JS grid reader's expected shape

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.1.1 =
Upload over 1.1.0 to get the test-connection button fix for all three sources.

= 1.1.0 =
Major update — adds Pexels and Pixabay support, a fully redesigned settings
page, and a preview grid in the post editor. Deactivate and reactivate to
register default options for the new sources.
