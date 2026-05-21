<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into the WordPress plugin update mechanism to check GitHub releases
 * for a newer version and surface the standard "Update available" notice in WP admin.
 *
 * How it works:
 *   1. Filters `pre_set_site_transient_update_plugins` to inject release info when
 *      the latest GitHub release tag is higher than the installed version.
 *   2. Filters `plugins_api` to populate the "View version details" popup.
 *   3. Filters `upgrader_post_install` to rename the extracted GitHub zip folder
 *      to the correct plugin directory name after installation.
 *
 * Requirements:
 *   - GitHub repo must have at least one published release with a version tag
 *     (e.g. `v1.2.1` or `1.2.1`).
 *   - Attaching a pre-built plugin zip as a release asset is recommended — it
 *     ensures the directory structure inside the zip is correct and skips the
 *     rename step. Without an asset the auto-generated zipball is used and the
 *     rename step handles the GitHub-generated folder name.
 *
 * Private repos: pass a personal access token to the constructor; it is sent
 * as `Authorization: token {token}` on every GitHub API request.
 */
class FeaturedPilot_Updater {

	/** @var string  plugin_basename() value, e.g. "FeaturedPilot/unsplash-featured-images.php" */
	private $plugin_basename;

	/** @var string  GitHub account or organisation slug */
	private $github_user;

	/** @var string  GitHub repository slug */
	private $github_repo;

	/** @var string  Currently installed version string */
	private $current_version;

	/** @var string|null  Optional personal access token for private repos */
	private $access_token;

	/**
	 * @param string      $plugin_file      Absolute path to the main plugin file (__FILE__).
	 * @param string      $github_user      GitHub account or org slug.
	 * @param string      $github_repo      GitHub repository slug.
	 * @param string      $current_version  Currently installed version.
	 * @param string|null $access_token     Optional PAT for private repos.
	 */
	public function __construct( $plugin_file, $github_user, $github_repo, $current_version, $access_token = null ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->github_user     = $github_user;
		$this->github_repo     = $github_repo;
		$this->current_version = $current_version;
		$this->access_token    = $access_token;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install',                 array( $this, 'rename_after_install' ), 10, 3 );
	}

	// -------------------------------------------------------------------------
	// WordPress filter callbacks
	// -------------------------------------------------------------------------

	/**
	 * Inject update data into the WP update transient when a newer release exists.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->fetch_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'vV' );

		if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'         => $this->plugin_slug(),
				'plugin'       => $this->plugin_basename,
				'new_version'  => $remote_version,
				'url'          => esc_url_raw( $release['html_url'] ?? '' ),
				'package'      => $this->get_download_url( $release ),
				'requires'     => '5.0',
				'requires_php' => '7.4',
				'tested'       => '',
				'icons'        => array(),
				'banners'      => array(),
			);
		} else {
			// Tell WP the plugin is current so it doesn't re-check on every page load.
			if ( ! isset( $transient->no_update[ $this->plugin_basename ] ) ) {
				$transient->no_update[ $this->plugin_basename ] = (object) array(
					'slug'         => $this->plugin_slug(),
					'plugin'       => $this->plugin_basename,
					'new_version'  => $this->current_version,
					'url'          => '',
					'package'      => '',
					'icons'        => array(),
					'banners'      => array(),
					'tested'       => '',
					'requires_php' => '7.4',
				);
			}
			unset( $transient->response[ $this->plugin_basename ] );
		}

		return $transient;
	}

	/**
	 * Populate the "View version details" popup in the Plugins screen.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( $this->plugin_slug() !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release['tag_name'], 'vV' );
		$body           = $release['body'] ?? '';

		return (object) array(
			'name'          => 'FeaturedPilot',
			'slug'          => $this->plugin_slug(),
			'version'       => $remote_version,
			'author'        => '<a href="https://theethicalagency.co.za">The Ethical Agency</a>',
			'author_profile' => 'https://theethicalagency.co.za',
			'homepage'      => esc_url_raw( 'https://github.com/' . $this->github_user . '/' . $this->github_repo ),
			'requires'      => '5.0',
			'requires_php'  => '7.4',
			'tested'        => '6.7',
			'download_link' => $this->get_download_url( $release ),
			'sections'      => array(
				'description' => 'Automatically assign featured images from Unsplash, Pexels, Pixabay, or Freepik with smart fallbacks, Magnific AI upscaling, live rate gauges, and a preview-before-you-set grid.',
				'changelog'   => ! empty( $body )
					? '<pre>' . esc_html( $body ) . '</pre>'
					: '<p>See <a href="' . esc_url( 'https://github.com/' . $this->github_user . '/' . $this->github_repo . '/releases' ) . '">GitHub releases</a> for the full changelog.</p>',
			),
		);
	}

	/**
	 * After the GitHub zip is installed, rename the extracted folder to the
	 * correct plugin directory name.
	 *
	 * GitHub's auto-generated zipballs unpack to a folder named
	 * "{user}-{repo}-{short-sha}/" which does not match "FeaturedPilot/".
	 * If the user attaches a correctly structured zip as a release asset this
	 * step is a no-op because the destination will already be correct.
	 *
	 * @param bool  $response
	 * @param array $hook_extra
	 * @param array $result
	 * @return array
	 */
	public function rename_after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		// Only act on updates to this specific plugin.
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $response;
		}

		$correct_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->plugin_slug();

		// Nothing to do — WordPress already installed to the right directory.
		if ( untrailingslashit( $result['destination'] ) === untrailingslashit( $correct_dir ) ) {
			activate_plugin( $this->plugin_basename );
			return $result;
		}

		// Remove stale copy at the correct path before moving the new one in.
		if ( $wp_filesystem->is_dir( $correct_dir ) ) {
			$wp_filesystem->delete( $correct_dir, true );
		}

		$wp_filesystem->move( $result['destination'], $correct_dir );
		$result['destination']        = $correct_dir;
		$result['remote_destination'] = $correct_dir;

		activate_plugin( $this->plugin_basename );

		return $result;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * The plugin's directory slug — the folder name inside wp-content/plugins/.
	 *
	 * @return string  e.g. "FeaturedPilot"
	 */
	private function plugin_slug() {
		return dirname( $this->plugin_basename );
	}

	/**
	 * Choose the best download URL from a release.
	 *
	 * Prefers a manually attached `.zip` release asset (which the developer
	 * controls and can name correctly). Falls back to the auto-generated
	 * zipball URL — the `rename_after_install` hook will fix the folder name.
	 *
	 * @param array $release  Decoded GitHub release object.
	 * @return string
	 */
	private function get_download_url( array $release ) {
		foreach ( $release['assets'] ?? array() as $asset ) {
			$mime = $asset['content_type'] ?? '';
			$url  = $asset['browser_download_url'] ?? '';
			if ( 'application/zip' === $mime && ! empty( $url ) ) {
				return esc_url_raw( $url );
			}
		}

		return esc_url_raw( $release['zipball_url'] ?? '' );
	}

	/**
	 * Fetch the latest GitHub release via the REST API.
	 * Response is cached in a transient for 12 hours to stay inside rate limits.
	 *
	 * @return array|false  Decoded release array on success, false on any failure.
	 */
	private function fetch_latest_release() {
		$cache_key = 'fp_gh_release_' . substr( md5( $this->github_user . $this->github_repo ), 0, 12 );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->github_user ),
			rawurlencode( $this->github_repo )
		);

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'FeaturedPilot/' . $this->current_version . '; WordPress/' . get_bloginfo( 'version' ),
		);

		if ( ! empty( $this->access_token ) ) {
			$headers['Authorization'] = 'token ' . $this->access_token;
		}

		$response = wp_remote_get( $api_url, array(
			'headers' => $headers,
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['tag_name'] ) ) {
			return false;
		}

		set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );

		return $data;
	}
}
