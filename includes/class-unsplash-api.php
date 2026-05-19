<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps all Unsplash REST API calls. Uses wp_remote_get() exclusively.
 */
class Unsplash_API {

	const API_BASE         = 'https://api.unsplash.com';
	const CACHE_TTL        = HOUR_IN_SECONDS;
	const RATE_LIMIT_OPTION = 'unsplash_rate_limit_remaining';

	/** @var Activity_Logger */
	private $logger;

	public function __construct( Activity_Logger $logger ) {
		$this->logger = $logger;
	}

	// -------------------------------------------------------------------------
	// Public API methods
	// -------------------------------------------------------------------------

	/**
	 * Search Unsplash photos by keyword.
	 *
	 * @param string $keyword
	 * @param int    $per_page       1-30
	 * @param string $order_by       'relevant' | 'latest'
	 * @param string $orientation    '' | 'landscape' | 'portrait' | 'squarish'
	 * @param string $content_filter 'low' | 'high'
	 * @return array|WP_Error  Array with 'results' key on success.
	 */
	public function search_photos( $keyword, $per_page = 1, $order_by = 'relevant', $orientation = '', $content_filter = 'low' ) {
		$keyword = sanitize_text_field( $keyword );
		if ( empty( $keyword ) ) {
			return new WP_Error( 'empty_keyword', __( 'Search keyword cannot be empty.', 'unsplash-featured-images' ) );
		}

		$params = array(
			'query'          => $keyword,
			'per_page'       => min( 30, max( 1, absint( $per_page ) ) ),
			'order_by'       => in_array( $order_by, array( 'relevant', 'latest' ), true ) ? $order_by : 'relevant',
			'content_filter' => in_array( $content_filter, array( 'low', 'high' ), true ) ? $content_filter : 'low',
		);

		if ( ! empty( $orientation ) && in_array( $orientation, array( 'landscape', 'portrait', 'squarish' ), true ) ) {
			$params['orientation'] = $orientation;
		}

		$cache_key    = 'unsplash_search_' . md5( wp_json_encode( $params ) );
		$cached       = $this->get_cached_search( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$start    = microtime( true );
		$response = $this->make_request( '/search/photos', $params );
		$elapsed  = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			$this->logger->log_error( $response->get_error_message(), 0, array( 'keyword' => $keyword ) );
			return $response;
		}

		$body = $this->sanitize_api_response( $response );
		if ( ! isset( $body['results'] ) || ! is_array( $body['results'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Unexpected API response structure.', 'unsplash-featured-images' ) );
		}

		$this->logger->log_api_call( $keyword, count( $body['results'] ), $elapsed );
		$this->cache_search_result( $cache_key, $body );

		return $body;
	}

	/**
	 * Get a single photo by ID.
	 *
	 * @param string $photo_id
	 * @return array|WP_Error
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

		return $this->sanitize_api_response( $response );
	}

	/**
	 * Trigger the Unsplash download event and return the file URL.
	 * This is required by Unsplash guidelines whenever an image is downloaded.
	 *
	 * @param string $photo_id
	 * @param string $size  'raw' | 'full' | 'regular' | 'small' | 'thumb'
	 * @return string|WP_Error  Direct image URL.
	 */
	public function download_photo( $photo_id, $size = 'regular' ) {
		$photo_id = sanitize_text_field( $photo_id );
		$size     = in_array( $size, array( 'raw', 'full', 'regular', 'small', 'thumb' ), true ) ? $size : 'regular';

		// First get photo details for the download_location URL.
		$photo = $this->get_photo( $photo_id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		// Trigger the required download endpoint.
		if ( ! empty( $photo['links']['download_location'] ) ) {
			$this->make_request_by_url( esc_url_raw( $photo['links']['download_location'] ) );
		}

		$url = $photo['urls'][ $size ] ?? $photo['urls']['regular'] ?? '';
		if ( empty( $url ) ) {
			return new WP_Error( 'no_url', __( 'Could not retrieve photo URL.', 'unsplash-featured-images' ) );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Verify the stored API key returns a valid response.
	 *
	 * @return true|WP_Error
	 */
	public function is_valid_key() {
		$response = $this->make_request( '/photos', array( 'per_page' => 1 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Return cached rate-limit remaining count.
	 *
	 * @return int
	 */
	public function get_rate_limit_remaining() {
		return absint( get_option( self::RATE_LIMIT_OPTION, 50 ) );
	}

	/**
	 * Return the total rate limit (free tier default: 50/hr).
	 *
	 * @return int
	 */
	public function get_rate_limit_limit() {
		return absint( get_option( 'unsplash_rate_limit_total', 50 ) );
	}

	/**
	 * True when no requests remain in the current window.
	 *
	 * @return bool
	 */
	public function is_rate_limited() {
		return $this->get_rate_limit_remaining() <= 0;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build and execute an API request.
	 *
	 * @param string $endpoint  Path relative to API_BASE.
	 * @param array  $params    Query parameters.
	 * @return array|WP_Error   Decoded response body on success.
	 */
	private function make_request( $endpoint, $params = array() ) {
		if ( $this->is_rate_limited() ) {
			return new WP_Error( 'rate_limited', __( 'Unsplash API rate limit reached. Please try again later.', 'unsplash-featured-images' ) );
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Unsplash API key is not configured.', 'unsplash-featured-images' ) );
		}

		$url = self::API_BASE . $endpoint;
		if ( ! empty( $params ) ) {
			$url = add_query_arg( array_map( 'strval', $params ), $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Client-ID ' . $api_key,
					'Accept-Version' => 'v1',
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
			return new WP_Error(
				'api_error_' . $code,
				/* translators: %d: HTTP status code */
				sprintf( __( 'Unsplash API returned HTTP %d.', 'unsplash-featured-images' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'json_parse_error', __( 'Could not parse API response.', 'unsplash-featured-images' ) );
		}

		return $body;
	}

	/**
	 * Fire a URL (e.g., download_location) without caring about the body.
	 *
	 * @param string $url
	 */
	private function make_request_by_url( $url ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization'  => 'Client-ID ' . $api_key,
					'Accept-Version' => 'v1',
				),
				'timeout' => 10,
			)
		);
	}

	/**
	 * Read rate-limit headers and persist them to options.
	 *
	 * @param array $response WP HTTP response.
	 */
	private function parse_rate_limit_headers( $response ) {
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$limit     = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );

		if ( '' !== $remaining ) {
			update_option( self::RATE_LIMIT_OPTION, absint( $remaining ), false );
		}
		if ( '' !== $limit ) {
			update_option( 'unsplash_rate_limit_total', absint( $limit ), false );
		}
	}

	/**
	 * Clear the stored rate-limit counter so the next request is always attempted.
	 * Call this at the start of a cron continuation after the hourly window has reset.
	 */
	public function reset_rate_limit_tracking() {
		delete_option( self::RATE_LIMIT_OPTION );
	}

	/**
	 * Return the active API key: constant override > stored option.
	 *
	 * @return string
	 */
	private function get_api_key() {
		if ( defined( 'UNSPLASH_API_KEY' ) && ! empty( UNSPLASH_API_KEY ) ) {
			return UNSPLASH_API_KEY;
		}
		return get_option( 'unsplash_api_key', '' );
	}

	/**
	 * Retrieve a cached search result.
	 *
	 * @param string $cache_key
	 * @return array|false
	 */
	private function get_cached_search( $cache_key ) {
		return get_transient( $cache_key );
	}

	/**
	 * Store a search result in transient cache.
	 *
	 * @param string $cache_key
	 * @param array  $data
	 */
	private function cache_search_result( $cache_key, $data ) {
		set_transient( $cache_key, $data, self::CACHE_TTL );
	}

	/**
	 * Strip keys that are not needed / could carry unexpected data.
	 * Returns the full body but ensures top-level is an array.
	 *
	 * @param array $body
	 * @return array
	 */
	private function sanitize_api_response( $body ) {
		return is_array( $body ) ? $body : array();
	}
}
