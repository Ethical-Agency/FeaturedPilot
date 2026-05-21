<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the Freepik Content API v1. Returns normalized photo arrays compatible
 * with the shared Source_Manager interface.
 *
 * Auth:  X-Freepik-API-Key header
 * Docs:  https://docs.freepik.com/content-api/overview
 * Quota: 100 req/day (free plan) — tracked via X-Ratelimit-* response headers.
 */
class Freepik_API {

	const API_BASE          = 'https://api.freepik.com/v1';
	const CACHE_TTL         = HOUR_IN_SECONDS;
	const RATE_LIMIT_OPTION = 'freepik_rate_limit_remaining';
	const RATE_TOTAL_OPTION = 'freepik_rate_limit_total';
	const SOURCE_SLUG       = 'freepik';
	const DEFAULT_LIMIT     = 100; // Free plan: 100 requests per day.

	/** @var Activity_Logger */
	private $logger;

	public function __construct( Activity_Logger $logger ) {
		$this->logger = $logger;
	}

	// -------------------------------------------------------------------------
	// Public API methods
	// -------------------------------------------------------------------------

	/**
	 * Search Freepik photos by keyword. Returns normalized shape.
	 *
	 * @param string $keyword
	 * @param int    $per_page       1–100
	 * @param string $order_by       'relevant' | 'latest'
	 * @param string $orientation    '' | 'landscape' | 'portrait' | 'squarish'
	 * @param string $content_filter unused (Freepik filters by free license instead)
	 * @return array|WP_Error
	 */
	public function search_photos( $keyword, $per_page = 1, $order_by = 'relevant', $orientation = '', $content_filter = 'low' ) {
		$keyword = sanitize_text_field( $keyword );
		if ( empty( $keyword ) ) {
			return new WP_Error( 'empty_keyword', __( 'Search keyword cannot be empty.', 'unsplash-featured-images' ) );
		}

		if ( $this->is_rate_limited() ) {
			return new WP_Error( 'rate_limited', __( 'Freepik API rate limit reached. Please try again later.', 'unsplash-featured-images' ) );
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Freepik API key is not configured.', 'unsplash-featured-images' ) );
		}

		// Build nested params — Freepik uses filters[content_type][photo]=1 style.
		$params = array(
			'term'   => $keyword,
			'page'   => 1,
			'limit'  => min( 100, max( 1, absint( $per_page ) ) ),
			'locale' => 'en-US',
			'filters' => array(
				'content_type' => array( 'photo' => 1 ),
				'license'      => array( 'free' => 1 ), // Free content only.
			),
		);

		// Orientation mapping: Freepik uses landscape / portrait / square.
		$orientation_map = array(
			'landscape' => 'landscape',
			'portrait'  => 'portrait',
			'squarish'  => 'square',
		);
		if ( ! empty( $orientation ) && isset( $orientation_map[ $orientation ] ) ) {
			$params['filters']['orientation'] = array( $orientation_map[ $orientation ] => 1 );
		}

		// Cache key must not include the API key.
		$cache_key = 'fp_freepik_' . md5( wp_json_encode( $params ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = self::API_BASE . '/resources?' . http_build_query( $params );
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'X-Freepik-API-Key' => $api_key,
					'Accept'            => 'application/json',
				),
				'timeout' => 15,
			)
		);
		$elapsed = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			$this->logger->log_error( $response->get_error_message(), 0, array( 'keyword' => $keyword, 'source' => self::SOURCE_SLUG ) );
			return $response;
		}

		$this->parse_rate_limit_headers( $response );

		$code = wp_remote_retrieve_response_code( $response );
		if ( 429 === (int) $code ) {
			update_option( self::RATE_LIMIT_OPTION, 0, false );
			$this->increment_hit_counter();
			return new WP_Error( 'rate_limited', __( 'Freepik API rate limit reached. Please try again later.', 'unsplash-featured-images' ) );
		}
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'api_error_' . $code,
				/* translators: %d: HTTP status code */
				sprintf( __( 'Freepik API returned HTTP %d.', 'unsplash-featured-images' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['data'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Unexpected Freepik API response.', 'unsplash-featured-images' ) );
		}

		$normalized = $this->normalize_results( $body['data'] );
		$result     = array(
			'results' => $normalized,
			'total'   => absint( $body['meta']['pagination']['total'] ?? count( $normalized ) ),
		);

		$this->logger->log_api_call( $keyword, count( $normalized ), $elapsed );
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Get a single resource by ID.
	 *
	 * @param string $photo_id
	 * @return array|WP_Error  Normalized photo array.
	 */
	public function get_photo( $photo_id ) {
		$photo_id = sanitize_text_field( $photo_id );
		if ( empty( $photo_id ) ) {
			return new WP_Error( 'empty_photo_id', __( 'Photo ID cannot be empty.', 'unsplash-featured-images' ) );
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Freepik API key is not configured.', 'unsplash-featured-images' ) );
		}

		$response = wp_remote_get(
			self::API_BASE . '/resources/' . rawurlencode( $photo_id ),
			array(
				'headers' => array(
					'X-Freepik-API-Key' => $api_key,
					'Accept'            => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'api_error_' . $code,
				sprintf( __( 'Freepik API returned HTTP %d.', 'unsplash-featured-images' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['data'] ) ) {
			return new WP_Error( 'photo_not_found', __( 'Photo not found on Freepik.', 'unsplash-featured-images' ) );
		}

		$normalized = $this->normalize_photo( $body['data'] );
		if ( empty( $normalized ) ) {
			return new WP_Error( 'invalid_photo', __( 'Invalid photo data from Freepik.', 'unsplash-featured-images' ) );
		}

		return $normalized;
	}

	/**
	 * Return the direct download URL. Freepik requires no download-tracking ping.
	 *
	 * @param string $photo_id
	 * @param string $size  'thumb' | 'small' | 'regular'
	 * @return string|WP_Error
	 */
	public function download_photo( $photo_id, $size = 'regular' ) {
		$photo = $this->get_photo( $photo_id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		$url = $photo['urls']['regular'] ?? $photo['urls']['small'] ?? '';
		if ( empty( $url ) ) {
			return new WP_Error( 'no_url', __( 'Could not retrieve Freepik photo URL.', 'unsplash-featured-images' ) );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Verify the stored API key.
	 *
	 * @return true|WP_Error
	 */
	public function is_valid_key() {
		return $this->test_connection( $this->get_api_key() );
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

		$url = self::API_BASE . '/resources?' . http_build_query( array(
			'term'    => 'nature',
			'limit'   => '1',
			'locale'  => 'en-US',
			'filters' => array( 'content_type' => array( 'photo' => 1 ) ),
		) );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'X-Freepik-API-Key' => $api_key,
					'Accept'            => 'application/json',
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
				return new WP_Error( 'invalid_key', __( 'Invalid API key. Check your credentials at freepik.com/api.', 'unsplash-featured-images' ) );
			}
			return new WP_Error( 'api_error_' . $code, sprintf( __( 'Freepik API returned HTTP %d.', 'unsplash-featured-images' ), $code ) );
		}

		return true;
	}

	public function get_rate_limit_remaining() {
		return absint( get_option( self::RATE_LIMIT_OPTION, self::DEFAULT_LIMIT ) );
	}

	public function get_rate_limit_limit() {
		return absint( get_option( self::RATE_TOTAL_OPTION, self::DEFAULT_LIMIT ) );
	}

	public function is_rate_limited() {
		return $this->get_rate_limit_remaining() <= 0;
	}

	public function reset_rate_limit_tracking() {
		delete_option( self::RATE_LIMIT_OPTION );
	}

	public function get_source_slug() {
		return self::SOURCE_SLUG;
	}

	public function increment_hit_counter() {
		$key  = 'fp_rate_hits_freepik';
		$hits = absint( get_transient( $key ) );
		set_transient( $key, $hits + 1, DAY_IN_SECONDS );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function get_api_key() {
		if ( defined( 'FREEPIK_API_KEY' ) && ! empty( FREEPIK_API_KEY ) ) {
			return FREEPIK_API_KEY;
		}
		return get_option( 'freepik_api_key', '' );
	}

	private function parse_rate_limit_headers( $response ) {
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$limit     = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );
		if ( '' !== $remaining ) {
			update_option( self::RATE_LIMIT_OPTION, absint( $remaining ), false );
		}
		if ( '' !== $limit ) {
			update_option( self::RATE_TOTAL_OPTION, absint( $limit ), false );
		}
	}

	/**
	 * Normalize a single Freepik resource to the shared photo shape.
	 *
	 * @param array $resource  Raw Freepik resource object.
	 * @return array
	 */
	private function normalize_photo( $resource ) {
		if ( empty( $resource['id'] ) ) {
			return array();
		}

		// Thumbnails array: first item is the preview image (~626 px wide on free plan).
		$thumbnails  = $resource['thumbnails'] ?? array();
		$preview_url = ! empty( $thumbnails[0]['url'] ) ? $thumbnails[0]['url'] : '';

		// Full-size source URL for download.
		$source_url = $resource['image']['source']['url'] ?? $preview_url;

		return array(
			'id'              => (string) $resource['id'],
			'source'          => self::SOURCE_SLUG,
			'urls'            => array(
				'thumb'   => esc_url_raw( $preview_url ),
				'small'   => esc_url_raw( $preview_url ),
				'regular' => esc_url_raw( $source_url ),
			),
			'links'           => array(
				'html'              => esc_url_raw( $resource['url'] ?? '' ),
				'download_location' => '',
			),
			'user'            => array(
				'name'  => sanitize_text_field( $resource['author']['name'] ?? '' ),
				'links' => array(
					'html' => esc_url_raw(
						'https://www.freepik.com/author/' . rawurlencode( $resource['author']['slug'] ?? '' )
					),
				),
			),
			'alt_description' => sanitize_text_field( $resource['title'] ?? '' ),
		);
	}

	/**
	 * Normalize an array of raw Freepik resources.
	 *
	 * @param array $resources
	 * @return array
	 */
	private function normalize_results( $resources ) {
		$results = array();
		foreach ( $resources as $resource ) {
			$n = $this->normalize_photo( $resource );
			if ( ! empty( $n ) ) {
				$results[] = $n;
			}
		}
		return $results;
	}
}
