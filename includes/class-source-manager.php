<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates image search across multiple source APIs (Unsplash, Pexels, Pixabay).
 * Tries sources in user-configured priority order, falling through to the next
 * when a source is rate-limited or returns no results.
 */
class Source_Manager {

	const PRIORITY_OPTION   = 'unsplash_source_priority';
	const KNOWN_SOURCES     = array( 'unsplash', 'pexels', 'pixabay', 'freepik' );
	const DEFAULT_PRIORITY  = 'unsplash,pexels,pixabay,freepik';

	/** @var array  Keyed by slug: ['unsplash' => Unsplash_API, ...] */
	private $sources;

	/** @var Activity_Logger */
	private $logger;

	/**
	 * @param array          $sources  Associative: slug => API object.
	 * @param Activity_Logger $logger
	 */
	public function __construct( array $sources, Activity_Logger $logger ) {
		$this->sources = $sources;
		$this->logger  = $logger;
	}

	// -------------------------------------------------------------------------
	// Search (tries sources in priority order)
	// -------------------------------------------------------------------------

	/**
	 * Search photos across sources in priority order.
	 * Returns results from the first source that succeeds.
	 *
	 * @param string $keyword
	 * @param int    $per_page
	 * @param string $order_by
	 * @param string $orientation
	 * @param string $content_filter
	 * @return array|WP_Error  Normalized results array with 'results' key.
	 */
	public function search_photos( $keyword, $per_page = 1, $order_by = 'relevant', $orientation = '', $content_filter = 'low' ) {
		$order = $this->get_priority_order();

		$last_error = new WP_Error( 'no_sources', __( 'No image sources are configured.', 'unsplash-featured-images' ) );

		foreach ( $order as $slug ) {
			$api = $this->get_source( $slug );
			if ( ! $api ) {
				continue;
			}

			if ( $api->is_rate_limited() ) {
				$api->increment_hit_counter();
				continue;
			}

			$result = $api->search_photos( $keyword, $per_page, $order_by, $orientation, $content_filter );

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();
				if ( 'rate_limited' === $code || false !== strpos( $code, '429' ) ) {
					$api->increment_hit_counter();
					$last_error = $result;
					continue;
				}
				$last_error = $result;
				continue;
			}

			if ( empty( $result['results'] ) ) {
				// No photos for this keyword on this source — try next.
				continue;
			}

			return $result;
		}

		return $last_error;
	}

	// -------------------------------------------------------------------------
	// Per-source photo retrieval (used when photo_id + source are already known)
	// -------------------------------------------------------------------------

	/**
	 * Get a single photo from a specific source by ID.
	 *
	 * @param string $photo_id
	 * @param string $source_slug
	 * @return array|WP_Error  Normalized photo array.
	 */
	public function get_photo( $photo_id, $source_slug ) {
		$api = $this->get_source( $source_slug );
		if ( ! $api ) {
			return new WP_Error( 'unknown_source', __( 'Unknown image source.', 'unsplash-featured-images' ) );
		}
		return $api->get_photo( $photo_id );
	}

	/**
	 * Trigger download-tracking (if applicable) and return the file URL.
	 *
	 * @param string $photo_id
	 * @param string $size       'thumb' | 'small' | 'regular'
	 * @param string $source_slug
	 * @return string|WP_Error
	 */
	public function download_photo( $photo_id, $size, $source_slug ) {
		$api = $this->get_source( $source_slug );
		if ( ! $api ) {
			return new WP_Error( 'unknown_source', __( 'Unknown image source.', 'unsplash-featured-images' ) );
		}
		return $api->download_photo( $photo_id, $size );
	}

	// -------------------------------------------------------------------------
	// Status / meta
	// -------------------------------------------------------------------------

	/**
	 * Return the ordered list of source slugs as configured by the user.
	 *
	 * @return string[]
	 */
	public function get_priority_order() {
		$raw   = get_option( self::PRIORITY_OPTION, self::DEFAULT_PRIORITY );
		$slugs = array_map( 'trim', explode( ',', $raw ) );
		$valid = array_filter( $slugs, function( $s ) {
			return in_array( $s, self::KNOWN_SOURCES, true );
		} );
		// Ensure all known sources appear (unconfigured ones go at end).
		$missing = array_diff( self::KNOWN_SOURCES, $valid );
		return array_values( array_merge( $valid, $missing ) );
	}

	/**
	 * Return status info for all sources (for the rate-limit AJAX endpoint).
	 *
	 * @return array  Keyed by slug.
	 */
	public function get_all_status() {
		$status    = array();
		$cron_next = absint( wp_next_scheduled( 'fp_hourly_rate_reset' ) );

		foreach ( self::KNOWN_SOURCES as $slug ) {
			$api = $this->get_source( $slug );
			if ( $api ) {
				$api_next     = method_exists( $api, 'get_next_reset_time' ) ? $api->get_next_reset_time() : 0;
				$next_reset   = $api_next ?: $cron_next;

				$status[ $slug ] = array(
					'remaining'    => $api->get_rate_limit_remaining(),
					'total'        => $api->get_rate_limit_limit(),
					'hits_today'   => absint( get_transient( 'fp_rate_hits_' . $slug ) ),
					'connected'    => $this->is_source_connected( $slug ),
					'next_reset_at' => $next_reset,
				);
			} else {
				$status[ $slug ] = array(
					'remaining'    => 0,
					'total'        => 0,
					'hits_today'   => 0,
					'connected'    => false,
					'next_reset_at' => $cron_next,
				);
			}
		}
		return $status;
	}

	/**
	 * Increment hit counter for a source.
	 *
	 * @param string $source_slug
	 */
	public function increment_hit_counter( $source_slug ) {
		$api = $this->get_source( $source_slug );
		if ( $api ) {
			$api->increment_hit_counter();
		}
	}

	/**
	 * True if the source has an API key configured.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public function is_source_connected( $slug ) {
		switch ( $slug ) {
			case 'unsplash':
				return ( defined( 'UNSPLASH_API_KEY' ) && ! empty( UNSPLASH_API_KEY ) )
					|| '' !== get_option( 'unsplash_api_key', '' );
			case 'pexels':
				return ( defined( 'PEXELS_API_KEY' ) && ! empty( PEXELS_API_KEY ) )
					|| '' !== get_option( 'pexels_api_key', '' );
			case 'pixabay':
				return ( defined( 'PIXABAY_API_KEY' ) && ! empty( PIXABAY_API_KEY ) )
					|| '' !== get_option( 'pixabay_api_key', '' );
			case 'freepik':
				return ( defined( 'FREEPIK_API_KEY' ) && ! empty( FREEPIK_API_KEY ) )
					|| '' !== get_option( 'freepik_api_key', '' );
		}
		return false;
	}

	/**
	 * Get the API object for a given slug, or null if not registered.
	 *
	 * @param string $slug
	 * @return Unsplash_API|Pexels_API|Pixabay_API|null
	 */
	public function get_source( $slug ) {
		return $this->sources[ $slug ] ?? null;
	}

	/**
	 * Reset rate-limit tracking for all sources (called at start of cron continuation).
	 */
	public function reset_all_rate_limits() {
		foreach ( $this->sources as $api ) {
			$api->reset_rate_limit_tracking();
		}
	}
}
