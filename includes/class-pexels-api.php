<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the Pexels REST API v1. Returns normalized photo arrays compatible
 * with the shared Source_Manager interface.
 */
class Pexels_API {

	const API_BASE          = 'https://api.pexels.com/v1';
	const CACHE_TTL         = HOUR_IN_SECONDS;
	const RATE_LIMIT_OPTION = 'pexels_rate_limit_remaining';
	const RATE_TOTAL_OPTION = 'pexels_rate_limit_total';
	const SOURCE_SLUG       = 'pexels';

	/** @var Activity_Logger */
	private $logger;

	public function __construct( Activity_Logger $logger ) {
		$this->logger = $logger;
	}

	// -------------------------------------------------------------------------
	// Public API methods
	// -------------------------------------------------------------------------

	/**
	 * Search Pexels photos by keyword. Returns normalized shape.
	 *
	 * @param string $keyword
	 * @param int    $per_page       1–80
	 * @param string $order_by       'relevant' | 'latest' (mapped to Pexels 'popular'|'latest')
	 * @param string $orientation    '' | 'landscape' | 'portrait' | 'squarish'
	 * @param string $content_filter unused (Pexels has no content filter param)
	 * @return array|WP_Error
	 */
	public function search_photos( $keyword, $per_page = 1, $order_by = 'relevant', $orientation = '', $content_filter = 'low' ) {
		$keyword = sanitize_text_field( $keyword );
		if ( empty( $keyword ) ) {
			return new WP_Error( 'empty_keyword', __( 'Search keyword cannot be empty.', 'unsplash-featured-images' ) );
		}

		$params = array(
			'query'    => $keyword,
			'per_page' => min( 80, max( 1, absint( $per_page ) ) ),
		);

		// Pexels orientation: landscape, portrait, square (not squarish).
		$orientation_map = array(
			'landscape' => 'landscape',
			'portrait'  => 'portrait',
			'squarish'  => 'square',
		);
		if ( ! empty( $orientation ) && isset( $orientation_map[ $orientation ] ) ) {
			$params['orientation'] = $orientation_map[ $orientation ];
		}

		$cache_key = 'fp_pexels_' . md5( wp_json_encode( $params ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$start    = microtime( true );
		$response = $this->make_request( '/search', $params );
		$elapsed  = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			$this->logger->log_error( $response->get_error_message(), 0, array( 'keyword' => $keyword, 'source' => self::SOURCE_SLUG ) );
			return $response;
		}

		if ( ! isset( $response['photos'] ) || ! is_array( $response['photos'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Unexpected Pexels API response.', 'unsplash-featured-images' ) );
		}

		$normalized = $this->normalize_results( $response['photos'] );
		$result     = array(
			'results' => $normalized,
			'total'   => absint( $response['total_results'] ?? count( $normalized ) ),
		);

		$this->logger->log_api_call( $keyword, count( $normalized ), $elapsed );
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Get a single photo by ID.
	 *
	 * @param string $photo_id
	 * @return array|WP_Error  Normalized photo array.
	 */
	public function get_photo( $photo_id ) {
		$photo_id = sanitize_text_field( $photo_id );
		if ( empty( $photo_id ) ) {
			return new WP_Error( 'empty_photo_id', __( 'Photo ID cannot be empty.', 'unsplash-featured-images' ) );
		}

		$response = $this->make_request( '/photos/' . rawurlencode( $photo_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$normalized = $this->normalize_photo( $response );
		if ( empty( $normalized ) ) {
			return new WP_Error( 'invalid_photo', __( 'Invalid photo data from Pexels.', 'unsplash-featured-images' ) );
		}

		return $normalized;
	}

	/**
	 * Return the direct download URL for a photo.
	 * Pexels requires no download-tracking ping.
	 *
	 * @param string $photo_id
	 * @param string $size  'thumb' | 'small' | 'regular' | 'full'
	 * @return string|WP_Error
	 */
	public function download_photo( $photo_id, $size = 'regular' ) {
		$photo = $this->get_photo( $photo_id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		$size_map = array(
			'thumb'   => 'thumb',
			'small'   => 'small',
			'regular' => 'regular',
			'full'    => 'regular', // Pexels 'large2x' is very large; use regular as safe default.
		);
		$size = $size_map[ $size ] ?? 'regular';

		$url = $photo['urls'][ $size ] ?? $photo['urls']['regular'] ?? '';
		if ( empty( $url ) ) {
			return new WP_Error( 'no_url', __( 'Could not retrieve Pexels photo URL.', 'unsplash-featured-images' ) );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Verify the API key returns a valid response.
	 *
	 * @return true|WP_Error
	 */
	public function is_valid_key() {
		$response = $this->make_request( '/curated', array( 'per_page' => 1 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Test a specific API key without saving it.
	 *
	 * @param string $api_key  Key to test.
	 * @return true|WP_Error
	 */
	public function test_connection( $api_key ) {
		$api_key = sanitize_text_field( $api_key );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Please enter an API key to test.', 'unsplash-featured-images' ) );
		}

		$url = add_query_arg( array( 'per_page' => '1' ), self::API_BASE . '/curated' );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => $api_key,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			if ( 401 === (int) $code || 403 === (int) $code ) {
				return new WP_Error( 'invalid_key', __( 'Invalid API key. Check your credentials at pexels.com/api.', 'unsplash-featured-images' ) );
			}
			return new WP_Error( 'api_error_' . $code, sprintf( __( 'Pexels API returned HTTP %d.', 'unsplash-featured-images' ), $code ) );
		}

		return true;
	}

	public function get_rate_limit_remaining() {
		$set_at = absint( get_option( self::RATE_LIMIT_OPTION . '_set_at', 0 ) );
		if ( $set_at > 0 && ( time() - $set_at ) >= HOUR_IN_SECONDS ) {
			$this->reset_rate_limit_tracking();
		} elseif ( 0 === $set_at ) {
			$stored = get_option( self::RATE_LIMIT_OPTION );
			if ( false !== $stored && 0 === absint( $stored ) ) {
				$this->reset_rate_limit_tracking();
			}
		}
		return absint( get_option( self::RATE_LIMIT_OPTION, 200 ) );
	}

	public function get_next_reset_time() {
		$set_at = absint( get_option( self::RATE_LIMIT_OPTION . '_set_at', 0 ) );
		return $set_at > 0 ? $set_at + HOUR_IN_SECONDS : 0;
	}

	public function get_rate_limit_limit() {
		return absint( get_option( self::RATE_TOTAL_OPTION, 200 ) );
	}

	public function is_rate_limited() {
		return $this->get_rate_limit_remaining() <= 0;
	}

	public function reset_rate_limit_tracking() {
		delete_option( self::RATE_LIMIT_OPTION );
		delete_option( self::RATE_LIMIT_OPTION . '_set_at' );
	}

	public function get_source_slug() {
		return self::SOURCE_SLUG;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function make_request( $endpoint, $params = array() ) {
		if ( $this->is_rate_limited() ) {
			return new WP_Error( 'rate_limited', __( 'Pexels API rate limit reached. Please try again later.', 'unsplash-featured-images' ) );
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Pexels API key is not configured.', 'unsplash-featured-images' ) );
		}

		$url = self::API_BASE . $endpoint;
		if ( ! empty( $params ) ) {
			$url = add_query_arg( array_map( 'strval', $params ), $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => $api_key,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->parse_rate_limit_headers( $response );

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			if ( 429 === (int) $code ) {
				update_option( self::RATE_LIMIT_OPTION, 0, false );
				update_option( self::RATE_LIMIT_OPTION . '_set_at', time(), false );
				$this->increment_hit_counter();
			}
			return new WP_Error(
				'api_error_' . $code,
				/* translators: %d: HTTP status code */
				sprintf( __( 'Pexels API returned HTTP %d.', 'unsplash-featured-images' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'json_parse_error', __( 'Could not parse Pexels API response.', 'unsplash-featured-images' ) );
		}

		return $body;
	}

	private function parse_rate_limit_headers( $response ) {
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$limit     = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );

		if ( '' !== $remaining ) {
			$remaining_int = absint( $remaining );
			update_option( self::RATE_LIMIT_OPTION, $remaining_int, false );
			update_option( self::RATE_LIMIT_OPTION . '_set_at', time(), false );
			if ( 0 === $remaining_int ) {
				$this->increment_hit_counter();
			}
		}
		if ( '' !== $limit ) {
			update_option( self::RATE_TOTAL_OPTION, absint( $limit ), false );
		}
	}

	public function increment_hit_counter() {
		$key  = 'fp_rate_hits_pexels_' . gmdate( 'Y-m-d' );
		$hits = absint( get_transient( $key ) );
		set_transient( $key, $hits + 1, 2 * DAY_IN_SECONDS );
	}

	private function get_api_key() {
		if ( defined( 'PEXELS_API_KEY' ) && ! empty( PEXELS_API_KEY ) ) {
			return PEXELS_API_KEY;
		}
		return get_option( 'pexels_api_key', '' );
	}

	/**
	 * Normalize a Pexels photo array to the shared shape.
	 *
	 * @param array $photo  Raw Pexels photo object.
	 * @return array
	 */
	private function normalize_photo( $photo ) {
		if ( empty( $photo['id'] ) ) {
			return array();
		}
		return array(
			'id'             => (string) $photo['id'],
			'source'         => self::SOURCE_SLUG,
			'urls'           => array(
				'thumb'   => esc_url_raw( $photo['src']['tiny']   ?? $photo['src']['small'] ?? '' ),
				'small'   => esc_url_raw( $photo['src']['medium'] ?? '' ),
				'regular' => esc_url_raw( $photo['src']['large']  ?? $photo['src']['medium'] ?? '' ),
			),
			'links'          => array(
				'html'              => esc_url_raw( $photo['url'] ?? '' ),
				'download_location' => '',
			),
			'user'           => array(
				'name'  => sanitize_text_field( $photo['photographer'] ?? '' ),
				'links' => array(
					'html' => esc_url_raw( $photo['photographer_url'] ?? '' ),
				),
			),
			'alt_description' => sanitize_text_field( $photo['alt'] ?? '' ),
		);
	}

	/**
	 * Normalize an array of raw Pexels photo objects.
	 *
	 * @param array $photos
	 * @return array
	 */
	private function normalize_results( $photos ) {
		$results = array();
		foreach ( $photos as $photo ) {
			$n = $this->normalize_photo( $photo );
			if ( ! empty( $n ) ) {
				$results[] = $n;
			}
		}
		return $results;
	}
}
