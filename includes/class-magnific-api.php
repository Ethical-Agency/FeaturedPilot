<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the Magnific AI upscaling API (Freepik-owned).
 *
 * Magnific takes an existing image URL and returns an AI-upscaled version.
 * This is an optional post-processing step in the image pipeline — if
 * upscaling fails or times out, Image_Handler falls back to the original URL.
 *
 * Auth:  X-Freepik-API-Key header (same key as Freepik content API)
 * Docs:  https://docs.freepik.com/ai-suite/magnific
 * Endpoint verified against Freepik AI API v1 — update UPSCALE_ENDPOINT
 * if Freepik changes the path in a future API version.
 */
class Magnific_API {

	// POST to this endpoint with JSON body {"image_url": "...", "scale_factor": N}.
	const UPSCALE_ENDPOINT = 'https://api.freepik.com/v1/ai/magnific/upscale';

	// Endpoint to poll for async job status.
	const STATUS_ENDPOINT  = 'https://api.freepik.com/v1/ai/magnific/upscale/{id}';

	// Max seconds to wait for upscaling to complete before falling back.
	// Kept short so AJAX requests don't hit the PHP max_execution_time limit.
	// Bulk/cron callers get more time because set_time_limit(0) is called before polling.
	const POLL_TIMEOUT     = 20;
	const POLL_INTERVAL    = 3; // Seconds between status polls.

	const OPTION_KEY       = 'magnific_api_key';
	const ENABLED_OPTION   = 'magnific_upscale_enabled';
	const SCALE_OPTION     = 'magnific_scale_factor';

	/** @var Activity_Logger */
	private $logger;

	public function __construct( Activity_Logger $logger ) {
		$this->logger = $logger;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Upscale an image URL. Returns the upscaled URL on success, or a WP_Error.
	 * Image_Handler should fall back to the original URL on error.
	 *
	 * @param string $image_url    Original image URL.
	 * @param int    $scale_factor 2 | 4.
	 * @return string|WP_Error  Upscaled image URL.
	 */
	public function upscale_image( $image_url, $scale_factor = 2 ) {
		$image_url    = esc_url_raw( $image_url );
		$scale_factor = in_array( (int) $scale_factor, array( 2, 4 ), true ) ? (int) $scale_factor : 2;

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Magnific API key is not configured.', 'unsplash-featured-images' ) );
		}

		// Submit the upscale job.
		$response = wp_remote_post(
			self::UPSCALE_ENDPOINT,
			array(
				'headers' => array(
					'X-Freepik-API-Key' => $api_key,
					'Content-Type'      => 'application/json',
					'Accept'            => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'image_url'    => $image_url,
					'scale_factor' => $scale_factor,
				) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code && 201 !== (int) $code && 202 !== (int) $code ) {
			$msg = $body['message'] ?? $body['error'] ?? '';
			return new WP_Error(
				'magnific_error_' . $code,
				$msg ?: sprintf( __( 'Magnific API returned HTTP %d.', 'unsplash-featured-images' ), $code )
			);
		}

		// Synchronous response: API returned the output URL directly.
		if ( ! empty( $body['output']['url'] ) ) {
			return esc_url_raw( $body['output']['url'] );
		}

		// Asynchronous response: poll until complete.
		if ( ! empty( $body['id'] ) ) {
			return $this->poll_for_result( $body['id'], $api_key );
		}

		return new WP_Error( 'magnific_no_result', __( 'Magnific returned an unexpected response.', 'unsplash-featured-images' ) );
	}

	/**
	 * Whether upscaling is enabled and the API key is configured.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return '1' === get_option( self::ENABLED_OPTION, '0' )
			&& ! empty( $this->get_api_key() );
	}

	/**
	 * Return the configured scale factor.
	 *
	 * @return int  2 or 4.
	 */
	public function get_scale_factor() {
		$val = (int) get_option( self::SCALE_OPTION, 2 );
		return in_array( $val, array( 2, 4 ), true ) ? $val : 2;
	}

	/**
	 * Test a specific API key without saving it.
	 *
	 * @param string $api_key
	 * @return true|WP_Error
	 */
	public function test_connection( $api_key ) {
		$api_key = sanitize_text_field( $api_key );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Please enter an API key to test.', 'unsplash-featured-images' ) );
		}

		// A lightweight check: hit the Freepik user/profile endpoint which is
		// accessible with a valid key and doesn't cost an upscale credit.
		$response = wp_remote_get(
			'https://api.freepik.com/v1/user',
			array(
				'headers' => array(
					'X-Freepik-API-Key' => $api_key,
					'Accept'            => 'application/json',
				),
				'timeout' => 10,
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
			return new WP_Error( 'api_error_' . $code, sprintf( __( 'Magnific API returned HTTP %d.', 'unsplash-featured-images' ), $code ) );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Poll the job status endpoint until the upscale is complete or the
	 * timeout is reached.
	 *
	 * @param string $job_id
	 * @param string $api_key
	 * @return string|WP_Error  Output URL on success.
	 */
	private function poll_for_result( $job_id, $api_key ) {
		$status_url = str_replace( '{id}', rawurlencode( $job_id ), self::STATUS_ENDPOINT );
		$deadline   = time() + self::POLL_TIMEOUT;

		// Extend PHP execution time so this loop doesn't kill long-running cron jobs.
		// In AJAX context the timeout is still bounded by POLL_TIMEOUT above.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( 60, self::POLL_TIMEOUT + 10 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		while ( time() < $deadline ) {
			sleep( self::POLL_INTERVAL );

			$response = wp_remote_get(
				$status_url,
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
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== (int) $code ) {
				return new WP_Error( 'poll_error_' . $code, sprintf( __( 'Magnific status check returned HTTP %d.', 'unsplash-featured-images' ), $code ) );
			}

			$status = strtoupper( $body['status'] ?? '' );

			if ( 'COMPLETED' === $status || 'DONE' === $status || 'SUCCESS' === $status ) {
				$url = $body['output']['url'] ?? $body['result']['url'] ?? '';
				if ( ! empty( $url ) ) {
					return esc_url_raw( $url );
				}
				return new WP_Error( 'no_output_url', __( 'Magnific job completed but returned no output URL.', 'unsplash-featured-images' ) );
			}

			if ( 'FAILED' === $status || 'ERROR' === $status ) {
				return new WP_Error( 'magnific_failed', __( 'Magnific upscaling job failed.', 'unsplash-featured-images' ) );
			}

			// PENDING / PROCESSING — continue polling.
		}

		return new WP_Error( 'magnific_timeout', __( 'Magnific upscaling timed out. Using original image.', 'unsplash-featured-images' ) );
	}

	private function get_api_key() {
		if ( defined( 'MAGNIFIC_API_KEY' ) && ! empty( MAGNIFIC_API_KEY ) ) {
			return MAGNIFIC_API_KEY;
		}
		return get_option( self::OPTION_KEY, '' );
	}
}
