<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the Pixabay REST API. Returns normalized photo arrays compatible
 * with the shared Source_Manager interface.
 *
 * Note: Pixabay requires the API key as a query parameter (not a header).
 * The key is never logged or exposed in responses.
 */
class Pixabay_API {

	const API_BASE          = 'https://pixabay.com/api/';
	const CACHE_TTL         = HOUR_IN_SECONDS;
	const RATE_LIMIT_OPTION = 'pixabay_rate_limit_remaining';
	const RATE_TOTAL_OPTION = 'pixabay_rate_limit_total';
	const SOURCE_SLUG       = 'pixabay';
	const DEFAULT_LIMIT     = 5000;

	/** @var Activity_Logger */
	private $logger;

	public function __construct( Activity_Logger $logger ) {
		$this->logger = $logger;
	}

	// -------------------------------------------------------------------------
	// Public API methods
	// -------------------------------------------------------------------------

	/**
	 * Search Pixabay photos by keyword. Returns normalized shape.
	 *
	 * @param string $keyword
	 * @param int    $per_page       1–200
	 * @param string $order_by       'relevant' | 'latest'
	 * @param string $orientation    '' | 'landscape' | 'portrait' | 'squarish'
	 * @param string $content_filter unused (Pixabay uses safesearch param instead)
	 * @return array|WP_Error
	 */
	public function search_photos( $keyword, $per_page = 1, $order_by = 'relevant', $orientation = '', $content_filter = 'low' ) {
		$keyword = sanitize_text_field( $keyword );
		if ( empty( $keyword ) ) {
			return new WP_Error( 'empty_keyword', __( 'Search keyword cannot be empty.', 'unsplash-featured-images' ) );
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Pixabay API key is not configured.', 'unsplash-featured-images' ) );
		}

		$params = array(
			'key'        => $api_key,
			'q'          => $keyword,
			'image_type' => 'photo',
			'per_page'   => min( 200, max( 3, absint( $per_page ) ) ),
			'safesearch' => 'true',
		);

		// Pixabay orientation: horizontal, vertical, all.
		$orientation_map = array(
			'landscape' => 'horizontal',
			'portrait'  => 'vertical',
			'squarish'  => 'all',
		);
		$params['orientation'] = isset( $orientation_map[ $orientation ] ) ? $orientation_map[ $orientation ] : 'all';

		if ( 'latest' === $order_by ) {
			$params['order'] = 'latest';
		}

		// Cache key must NOT include the API key for security.
		$safe_params = $params;
		unset( $safe_params['key'] );
		$cache_key = 'fp_pixabay_' . md5( wp_json_encode( $safe_params ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( $this->is_rate_limited() ) {
			return new WP_Error( 'rate_limited', __( 'Pixabay API rate limit reached. Please try again later.', 'unsplash-featured-images' ) );
		}

		$start    = microtime( true );
		$url      = add_query_arg( array_map( 'strval', $params ), self::API_BASE );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		$elapsed  = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			$this->logger->log_error( $response->get_error_message(), 0, array( 'keyword' => $keyword, 'source' => self::SOURCE_SLUG ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 429 === (int) $code ) {
			update_option( self::RATE_LIMIT_OPTION, 0, false );
			$this->increment_hit_counter();
			return new WP_Error( 'rate_limited', __( 'Pixabay API rate limit reached. Please try again later.', 'unsplash-featured-images' ) );
		}
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'api_error_' . $code,
				/* translators: %d: HTTP status code */
				sprintf( __( 'Pixabay API returned HTTP %d.', 'unsplash-featured-images' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['hits'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Unexpected Pixabay API response.', 'unsplash-featured-images' ) );
		}

		$normalized = $this->normalize_results( $body['hits'] );
		$result     = array(
			'results' => $normalized,
			'total'   => absint( $body['totalHits'] ?? count( $normalized ) ),
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

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Pixabay API key is not configured.', 'unsplash-featured-images' ) );
		}

		$url = add_query_arg(
			array(
				'key' => $api_key,
				'id'  => $photo_id,
			),
			self::API_BASE
		);

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'api_error_' . $code,
				sprintf( __( 'Pixabay API returned HTTP %d.', 'unsplash-featured-images' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['hits'][0] ) ) {
			return new WP_Error( 'photo_not_found', __( 'Photo not found on Pixabay.', 'unsplash-featured-images' ) );
		}

		return $this->normalize_photo( $body['hits'][0] );
	}

	/**
	 * Return the direct download URL for a photo.
	 * Pixabay requires no download-tracking ping.
	 *
	 * @param string $photo_id
	 * @param string $size
	 * @return string|WP_Error
	 */
	public function download_photo( $photo_id, $size = 'regular' ) {
		$photo = $this->get_photo( $photo_id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		$url = $photo['urls']['regular'] ?? $photo['urls']['small'] ?? '';
		if ( empty( $url ) ) {
			return new WP_Error( 'no_url', __( 'Could not retrieve Pixabay photo URL.', 'unsplash-featured-images' ) );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Verify the API key returns a valid response.
	 *
	 * @return true|WP_Error
	 */
	public function is_valid_key() {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Pixabay API key is not configured.', 'unsplash-featured-images' ) );
		}

		$url = add_query_arg(
			array(
				'key'      => $api_key,
				'q'        => 'nature',
				'per_page' => '3',
			),
			self::API_BASE
		);

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'invalid_key',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Pixabay API key invalid (HTTP %d).', 'unsplash-featured-images' ), $code )
			);
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
		$key  = 'fp_rate_hits_pixabay';
		$hits = absint( get_transient( $key ) );
		set_transient( $key, $hits + 1, DAY_IN_SECONDS );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function get_api_key() {
		if ( defined( 'PIXABAY_API_KEY' ) && ! empty( PIXABAY_API_KEY ) ) {
			return PIXABAY_API_KEY;
		}
		return get_option( 'pixabay_api_key', '' );
	}

	/**
	 * Normalize a single raw Pixabay hit to the shared photo shape.
	 *
	 * @param array $hit
	 * @return array
	 */
	private function normalize_photo( $hit ) {
		if ( empty( $hit['id'] ) ) {
			return array();
		}
		return array(
			'id'             => (string) $hit['id'],
			'source'         => self::SOURCE_SLUG,
			'urls'           => array(
				'thumb'   => esc_url_raw( $hit['previewURL']   ?? '' ),
				'small'   => esc_url_raw( $hit['webformatURL'] ?? '' ),
				'regular' => esc_url_raw( $hit['largeImageURL'] ?? $hit['webformatURL'] ?? '' ),
			),
			'links'          => array(
				'html'              => esc_url_raw( $hit['pageURL'] ?? '' ),
				'download_location' => '',
			),
			'user'           => array(
				'name'  => sanitize_text_field( $hit['user'] ?? '' ),
				'links' => array(
					'html' => esc_url_raw( 'https://pixabay.com/users/' . rawurlencode( $hit['user'] ?? '' ) . '-' . absint( $hit['user_id'] ?? 0 ) . '/' ),
				),
			),
			'alt_description' => sanitize_text_field( $hit['tags'] ?? '' ),
		);
	}

	/**
	 * Normalize an array of raw Pixabay hits.
	 *
	 * @param array $hits
	 * @return array
	 */
	private function normalize_results( $hits ) {
		$results = array();
		foreach ( $hits as $hit ) {
			$n = $this->normalize_photo( $hit );
			if ( ! empty( $n ) ) {
				$results[] = $n;
			}
		}
		return $results;
	}
}
