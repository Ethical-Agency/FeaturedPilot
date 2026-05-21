<div align="center">

<img src="https://raw.githubusercontent.com/Ethical-Agency/FeaturedPilot/main/assets/img/banner.png" alt="FeaturedPilot" width="100%" />

# FeaturedPilot

**Automatically assign stunning featured images to your WordPress posts — from Unsplash, Pexels, Pixabay, or Freepik — with smart fallbacks, optional Magnific AI upscaling, live rate gauges, and a preview-before-you-set grid.**

[![Version](https://img.shields.io/badge/version-1.2.0-blue.svg)](https://github.com/Ethical-Agency/FeaturedPilot/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

[Features](#features) · [Installation](#installation) · [Configuration](#configuration) · [FAQ](#faq) · [Changelog](#changelog)

</div>

---

## Overview

FeaturedPilot connects to three stock-photo APIs and intelligently picks the best available image for each post. Configure your preferred sources, drag them into priority order, and let the plugin do the rest — or hand-pick from a live 3-image preview grid right inside the post editor.

---

## Features

### Multi-Source Image Search
| Source | Free Quota | Auth Method |
|--------|-----------|-------------|
| [Unsplash](https://unsplash.com/developers) | 50 req / hr | `Authorization: Client-ID {key}` |
| [Pexels](https://www.pexels.com/api/) | 200 req / hr | `Authorization: {key}` |
| [Pixabay](https://pixabay.com/api/docs/) | ~5,000 req / hr | `key` query parameter |
| [Freepik](https://docs.freepik.com) | 100 req / day | `X-Freepik-API-Key: {key}` |

- **Priority-order fallback** — if your top source hits its rate limit, the next one takes over automatically
- **Drag-to-reorder** source priority from the Sources settings tab
- **Per-source live rate gauge** — colour-coded bar (green / amber / red) updates every 60 seconds

### Magnific AI Upscaling (optional)
- Toggle on in the **Images** settings tab
- Each fetched image is sent to Magnific (Freepik's AI upscaling engine) before being saved
- Choose **2×** or **4×** scale — higher scale uses more API credits and takes longer
- Uses the same API key as Freepik
- Graceful fallback: if upscaling fails or times out, the original image is used with an activity log entry

### Smart Keyword Detection
Three keyword modes, selectable from a card-style UI:

| Mode | How it works |
|------|-------------|
| **Post content** | Title → Category → Tag → Default keyword |
| **Global keyword only** | Always use the configured default keyword |
| **Combined** | Default keyword + post title terms merged |

### Post Editor — Preview Grid
- Click **Fetch Previews** to load 3 candidate images from your chosen source
- Each card shows the thumbnail, photographer credit, and a source badge
- Click **Use This** to set that exact image as the featured image — no page reload

### Scheduled Automation
- Daily or weekly cron runs
- Targets posts without a featured image, or all published posts
- Automatically pauses when a rate limit is hit and resumes after the window resets

### Bulk Assignment
- Select posts from the Posts list → **Assign with FeaturedPilot**
- Or use the **Bulk Run** tab for a batch job with a real-time progress bar and cancel button

### Activity Log
- Logs every API call and image assignment with status and timing
- Filterable, paginated log table in the **Activity Log** settings tab
- One-click **Clear All Logs** button

### Security
- Nonce-verified AJAX on every endpoint
- Capability checks (`manage_options`, `edit_posts`, `upload_files`) before any action
- All input sanitised; all output escaped
- API keys never logged or echoed raw (masked to last 4 characters in the UI)
- Pixabay cache keys exclude the API key

---

## Installation

### From a zip file

1. Download the latest `FeaturedPilot-x.x.x.zip` from [Releases](https://github.com/Ethical-Agency/FeaturedPilot/releases)
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Choose the zip and click **Install Now**, then **Activate**

### From source

```bash
cd wp-content/plugins
git clone https://github.com/Ethical-Agency/FeaturedPilot.git
```

Then activate the plugin from the Plugins screen.

---

## Configuration

Go to **Settings → FeaturedPilot** after activation.

### Sources tab

#### Unsplash
1. Create a free account at [unsplash.com/developers](https://unsplash.com/developers)
2. Create a new application and copy the **Access Key**
3. Paste it into the Unsplash API Key field and click **Test**

#### Pexels
1. Sign up at [pexels.com/api](https://www.pexels.com/api/)
2. Copy your API key from the dashboard
3. Paste it into the Pexels API Key field and click **Test**

#### Pixabay
1. Register at [pixabay.com](https://pixabay.com) and visit [pixabay.com/api/docs](https://pixabay.com/api/docs/)
2. Copy your API key
3. Paste it into the Pixabay API Key field and click **Test**

#### Freepik
1. Sign up at [freepik.com](https://www.freepik.com) and visit [freepik.com/api](https://www.freepik.com/api)
2. Create an API application and copy your key
3. Paste it into the Freepik API Key field and click **Test**
4. Free plan gives 100 requests/day — only free-licensed photos are returned

#### Magnific AI Upscaling
Magnific is Freepik's AI image enhancement engine and uses the **same API key as Freepik**.

1. Enter your Freepik API key in the Magnific API Key field on the **Images** tab
2. Tick **Upscale every assigned image with Magnific AI**
3. Choose a scale factor (2× recommended to balance quality vs. speed and credits)
4. Save — every image assigned from this point forward will be upscaled before upload

#### Source priority
Drag the source rows into your preferred order. The first connected source is always tried first; the others serve as automatic fallbacks.

### Defining keys in wp-config.php

You can hard-code API keys as PHP constants (useful for multi-environment setups). Constants take precedence over stored options.

```php
define( 'UNSPLASH_API_KEY', 'your_unsplash_access_key' );
define( 'PEXELS_API_KEY',   'your_pexels_api_key' );
define( 'PIXABAY_API_KEY',  'your_pixabay_api_key' );
define( 'FREEPIK_API_KEY',  'your_freepik_api_key' );
define( 'MAGNIFIC_API_KEY', 'your_freepik_api_key' ); // same key as Freepik
```

### Automation tab

| Setting | Description |
|---------|-------------|
| **Keyword Mode** | How the search keyword is derived per post |
| **Default Keyword** | Fallback when no keyword can be inferred |
| **Schedule** | Enable daily / weekly auto-assignment |
| **Target Posts** | Posts without an image, or all published posts |

### Images tab

| Setting | Options |
|---------|---------|
| **Orientation** | Any · Landscape · Portrait · Square |
| **Content Filter** | Standard · Strict (family-safe only) |
| **Min Width / Height** | Reject images below these pixel dimensions |

---

## FAQ

**Is this free to use?**
Yes. All three APIs have generous free tiers. Unsplash gives 50 req/hr, Pexels 200 req/hr, and Pixabay ~5,000 req/hr. In practice, running all three sources gives you thousands of requests per hour before any limit is hit.

**Will it overwrite existing featured images?**
Only if you explicitly opt in — via the "Replace existing" checkbox in the Bulk Run tab, or the replace option in the post meta box. Scheduled runs default to posts that have no featured image.

**Does it comply with Unsplash attribution requirements?**
Yes. The plugin fires the required Unsplash download-tracking endpoint whenever an Unsplash image is saved, and stores photographer name and profile URL in attachment metadata.

**Can I force a specific source for a single post?**
Yes. The meta box source pill-toggle (Auto / Unsplash / Pexels / Pixabay) lets you pin a per-post source preference. It's saved to post meta and honoured by manual assignment from the meta box.

**What happens if all sources are rate-limited?**
A `WP_Error` is returned and logged. The scheduler pauses and automatically retries after the reset window. The rate gauge turns red so you can see it at a glance.

**Can I use a constant for the API key instead of the settings UI?**
Yes — see [Defining keys in wp-config.php](#defining-keys-in-wp-configphp).

---

## Changelog

### 1.2.0 — 2026-05-21
- New: Freepik stock photo support as a 4th priority source (100 req/day free, only free-licensed content returned)
- New: Magnific AI upscaling — optional post-processing step that runs every assigned image through Magnific before upload
- New: Magnific settings card in the Images tab (API key, enable toggle, 2×/4× scale factor)
- New: `FREEPIK_API_KEY` and `MAGNIFIC_API_KEY` PHP constant overrides
- New: Freepik and Magnific options cleaned up on plugin delete via `uninstall.php`
- The Magnific Test button validates the Freepik/Magnific key against the Freepik user endpoint (no upscale credits consumed)

### 1.1.1 — 2026-05-21
- Fix: version bump to bust browser cache on `admin.js` after the per-source test-connection refactor in 1.1.0

### 1.1.0 — 2026-05-18
- New: Pexels and Pixabay support with priority-order fallback via `Source_Manager`
- New: 5-tab settings page (Sources, Automation, Images, Bulk Run, Activity Log)
- New: per-source API cards with live rate gauge, daily hit counter, and independent Test button
- New: drag-to-reorder source priority (jQuery UI Sortable)
- New: option-card UI for Keyword Mode, Orientation, and Content Filter
- New: meta box 3-image preview grid with source badge and Use This button
- New: per-post source pill-toggle (Auto / Unsplash / Pexels / Pixabay)
- New: `fp_test_source` AJAX endpoint tests each API independently with the key typed in the field
- New: `uninstall.php` removes all options, post meta, transients, and cron on plugin delete
- Improved: keyword generator supports three modes (post content, global keyword, combined)
- Improved: `Activity Logger` gains `clear_all_logs()`
- Fix: `Unsplash_API` missing `increment_hit_counter()` caused fatal error when rate limit was stored as 0
- Fix: `ajax_search_preview` now returns nested normalized photo shape matching the JS `renderGrid()` reader

### 1.0.0
- Initial release — Unsplash-only, single settings page, basic meta box

---

## Credits

Built by [The Ethical Agency](https://theethicalagency.co.za).

Images sourced from [Unsplash](https://unsplash.com), [Pexels](https://www.pexels.com), and [Pixabay](https://pixabay.com). Please review each platform's license terms before using images in commercial contexts.

---

## License

[GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
