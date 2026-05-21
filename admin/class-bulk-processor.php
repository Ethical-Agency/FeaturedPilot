<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the persistent bulk-image queue and batch processing.
 *
 * The queue survives page reloads: when the Unsplash rate limit is hit the job
 * pauses, schedules a WP-Cron event one hour later, then resumes automatically.
 * The JS drives processing while the user is on-page; cron covers everything else.
 */
class Bulk_Processor {

	const QUEUE_OPTION = 'unsplash_bulk_queue';

	// Kept for the Posts-list bulk action (not queue-based).
	const STATUS_TRANSIENT     = 'unsplash_processing_status';
	const STATUS_TRANSIENT_TTL = 300;

	/** @var Image_Handler */
	private $image_handler;

	/** @var Keyword_Generator */
	private $keyword_generator;

	/** @var Activity_Logger */
	private $logger;

	public function __construct(
		Image_Handler $image_handler,
		Keyword_Generator $keyword_generator,
		Activity_Logger $logger
	) {
		$this->image_handler     = $image_handler;
		$this->keyword_generator = $keyword_generator;
		$this->logger            = $logger;

		add_action( 'admin_notices', array( $this, 'maybe_show_bulk_notice' ) );
	}

	// =========================================================================
	// Queue-based bulk job (Settings page "Run Now")
	// =========================================================================

	/**
	 * Initialise a new bulk job from a list of post IDs.
	 * Clears any previously running job and its cron continuation.
	 *
	 * @param int[] $post_ids
	 * @param bool  $replace_existing
	 * @return array  Status array.
	 */
	public function init_job( $post_ids, $replace_existing = false ) {
		$post_ids = array_values( array_map( 'absint', (array) $post_ids ) );
		$total    = count( $post_ids );

		wp_clear_scheduled_hook( 'unsplash_bulk_hourly_continue' );

		$queue = array(
			'post_ids'         => $post_ids,
			'replace_existing' => (bool) $replace_existing,
			'total'            => $total,
			'processed'        => 0,
			'errors'           => 0,
			'skipped'          => 0,
			'status'           => 0 === $total ? 'complete' : 'running',
			'started_at'       => time(),
			'next_run_at'      => 0,
		);
		update_option( self::QUEUE_OPTION, $queue, false );

		return $this->queue_status( $queue );
	}

	/**
	 * Process the next batch of posts from the persistent queue.
	 *
	 * @param int $batch_size  Max real posts to attempt. 0 = unlimited (cron mode).
	 * @return array  Status array.
	 */
	public function process_queue_batch( $batch_size = 5 ) {
		$queue = get_option( self::QUEUE_OPTION, null );

		if ( ! is_array( $queue ) ) {
			return array( 'status' => 'idle' );
		}

		if ( in_array( $queue['status'], array( 'cancelled', 'complete' ), true ) ) {
			return $this->queue_status( $queue );
		}

		if ( empty( $queue['post_ids'] ) ) {
			$queue['status'] = 'complete';
			update_option( self::QUEUE_OPTION, $queue, false );
			return $this->queue_status( $queue );
		}

		$queue['status']   = 'running';
		$replace_existing  = ! empty( $queue['replace_existing'] );
		$plugin            = Unsplash_Featured_Images::get_instance();
		$attempted         = 0;   // Real (non-skipped) posts attempted this call.
		$rate_limited      = false;
		$start_time        = microtime( true );

		// In cron mode extend PHP execution time so we can run until rate-limited.
		if ( 0 === $batch_size && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		while ( ! empty( $queue['post_ids'] ) ) {

			// Batch-size cap (JS-driven mode).
			if ( $batch_size > 0 && $attempted >= $batch_size ) {
				break;
			}

			// Time guard (cron mode) — stop at 270 s to stay inside PHP limits.
			if ( 0 === $batch_size && ( microtime( true ) - $start_time ) > 270 ) {
				break;
			}

			$post_id = array_shift( $queue['post_ids'] );

			// Free skip — does not count towards the batch limit.
			if ( ! get_post( $post_id ) || get_post_meta( $post_id, '_unsplash_skip_auto', true ) ) {
				$queue['skipped']++;
				continue;
			}

			$attempted++;

			$keyword        = $this->keyword_generator->get_keyword_for_post( $post_id );
			$orientation    = get_option( 'unsplash_image_orientation', '' );
			$content_filter = get_option( 'unsplash_image_content_filter', 'low' );

			$results = $plugin->source_manager->search_photos( $keyword, 10, 'relevant', $orientation, $content_filter );

			if ( is_wp_error( $results ) ) {
				$err = $results->get_error_code();
				if ( 'rate_limited' === $err || false !== strpos( $err, '429' ) || 'no_sources' === $err ) {
					// All sources exhausted — put the post back and pause.
					array_unshift( $queue['post_ids'], $post_id );
					$rate_limited = true;
					break;
				}
				$queue['errors']++;
				$this->logger->log_error( $results->get_error_message(), $post_id, array( 'keyword' => $keyword, 'source' => 'bulk' ) );
				update_option( self::QUEUE_OPTION, $queue, false );
				continue;
			}

			if ( empty( $results['results'] ) ) {
				$queue['errors']++;
				$this->logger->log_error( __( 'No results found.', 'unsplash-featured-images' ), $post_id, array( 'keyword' => $keyword, 'source' => 'bulk' ) );
				update_option( self::QUEUE_OPTION, $queue, false );
				continue;
			}

			// Prefer unused photo; fall back to top result if pool is exhausted.
			$unused = $this->image_handler->filter_unused_photos( $results['results'] );
			$photo  = ! empty( $unused ) ? $unused[0] : $results['results'][0];
			$photo_id    = sanitize_text_field( $photo['id'] );
			$source_slug = sanitize_key( $photo['source'] ?? 'unsplash' );

			$attachment_id = $this->image_handler->download_and_upload_image( $post_id, $photo_id, $replace_existing, $source_slug );

			if ( is_wp_error( $attachment_id ) ) {
				$queue['errors']++;
				$this->logger->log_error( $attachment_id->get_error_message(), $post_id, array( 'source' => 'bulk' ) );
			} else {
				$queue['processed']++;
				update_post_meta( $post_id, '_unsplash_last_keyword', sanitize_text_field( $keyword ) );
				update_post_meta( $post_id, '_unsplash_assignment_method', 'bulk' );
				update_post_meta( $post_id, '_fp_photo_source', $source_slug );
				$this->logger->log_action( 'image_assigned', $post_id, array( 'keyword' => $keyword, 'source' => $source_slug ), 'success' );
			}

			update_option( self::QUEUE_OPTION, $queue, false );
		}

		// Determine final status for this call.
		if ( $rate_limited ) {
			// Rate limit hit — schedule cron to continue in 1 hour.
			$next_run             = time() + HOUR_IN_SECONDS;
			$queue['status']      = 'paused';
			$queue['next_run_at'] = $next_run;
			update_option( self::QUEUE_OPTION, $queue, false );
			wp_clear_scheduled_hook( 'unsplash_bulk_hourly_continue' );
			wp_schedule_single_event( $next_run, 'unsplash_bulk_hourly_continue' );

		} elseif ( empty( $queue['post_ids'] ) ) {
			// All done.
			$queue['status'] = 'complete';
			update_option( self::QUEUE_OPTION, $queue, false );
			wp_clear_scheduled_hook( 'unsplash_bulk_hourly_continue' );

		} elseif ( 0 === $batch_size ) {
			// Cron time-limit hit with posts remaining — retry after 1 minute.
			$next_run             = time() + MINUTE_IN_SECONDS;
			$queue['status']      = 'paused';
			$queue['next_run_at'] = $next_run;
			update_option( self::QUEUE_OPTION, $queue, false );
			wp_clear_scheduled_hook( 'unsplash_bulk_hourly_continue' );
			wp_schedule_single_event( $next_run, 'unsplash_bulk_hourly_continue' );

		} else {
			// JS batch-size limit hit; still running — just save progress.
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		return $this->queue_status( $queue );
	}

	/**
	 * Return the current job status without modifying the queue.
	 *
	 * @return array
	 */
	public function get_job_status() {
		$queue = get_option( self::QUEUE_OPTION, null );
		if ( ! is_array( $queue ) ) {
			return array( 'status' => 'idle' );
		}
		return $this->queue_status( $queue );
	}

	/**
	 * Cancel the running job and clear the scheduled continuation.
	 */
	public function cancel_job() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( is_array( $queue ) ) {
			$queue['status'] = 'cancelled';
			update_option( self::QUEUE_OPTION, $queue, false );
		}
		wp_clear_scheduled_hook( 'unsplash_bulk_hourly_continue' );
	}

	/**
	 * WP-Cron callback — continues a paused job.
	 * Resets the stored rate-limit so the new hourly window is used.
	 */
	public function continue_from_cron() {
		$queue = get_option( self::QUEUE_OPTION, null );
		if ( ! is_array( $queue ) || 'paused' !== $queue['status'] ) {
			return;
		}

		// Clear the cached remaining-count so the first request of this window
		// isn't pre-blocked and actually gets fresh headers from the API.
		$plugin = Unsplash_Featured_Images::get_instance();
		$plugin->source_manager->reset_all_rate_limits();

		$this->process_queue_batch( 0 );
	}

	// =========================================================================
	// Posts-list bulk action (non-queue, direct list)
	// =========================================================================

	/**
	 * Process a fixed list of post IDs (used by the Posts-list bulk action).
	 *
	 * @param int[]         $post_ids
	 * @param bool          $replace_existing
	 * @param callable|null $batch_callback
	 * @return array  { processed, errors, skipped, total }
	 */
	public function process_posts( $post_ids, $replace_existing = false, $batch_callback = null ) {
		$post_ids  = array_map( 'absint', (array) $post_ids );
		$total     = count( $post_ids );
		$processed = 0;
		$errors    = 0;
		$skipped   = 0;

		$this->set_status( array(
			'total'     => $total,
			'processed' => 0,
			'errors'    => 0,
			'skipped'   => 0,
			'cancelled' => false,
		) );

		foreach ( $post_ids as $post_id ) {
			$status = $this->get_processing_status();
			if ( is_array( $status ) && ! empty( $status['cancelled'] ) ) {
				break;
			}

			if ( ! get_post( $post_id ) ) {
				$skipped++;
				continue;
			}

			if ( get_post_meta( $post_id, '_unsplash_skip_auto', true ) ) {
				$skipped++;
				continue;
			}

			$keyword        = $this->keyword_generator->get_keyword_for_post( $post_id );
			$orientation    = get_option( 'unsplash_image_orientation', '' );
			$content_filter = get_option( 'unsplash_image_content_filter', 'low' );
			$plugin         = Unsplash_Featured_Images::get_instance();

			$results = $plugin->source_manager->search_photos( $keyword, 10, 'relevant', $orientation, $content_filter );

			if ( is_wp_error( $results ) ) {
				$err = $results->get_error_code();
				if ( 'rate_limited' === $err || false !== strpos( $err, '429' ) || 'no_sources' === $err ) {
					break;
				}
				$errors++;
				$this->logger->log_error( $results->get_error_message(), $post_id, array( 'keyword' => $keyword, 'source' => 'list_bulk' ) );
				continue;
			}

			if ( empty( $results['results'] ) ) {
				$errors++;
				$this->logger->log_error( __( 'No results found.', 'unsplash-featured-images' ), $post_id, array( 'keyword' => $keyword, 'source' => 'list_bulk' ) );
				continue;
			}

			// Prefer unused photo; fall back to top result if pool is exhausted.
			$unused = $this->image_handler->filter_unused_photos( $results['results'] );
			$photo  = ! empty( $unused ) ? $unused[0] : $results['results'][0];
			$photo_id    = sanitize_text_field( $photo['id'] );
			$source_slug = sanitize_key( $photo['source'] ?? 'unsplash' );

			$attachment_id = $this->image_handler->download_and_upload_image( $post_id, $photo_id, $replace_existing, $source_slug );

			if ( is_wp_error( $attachment_id ) ) {
				$errors++;
				$this->logger->log_error( $attachment_id->get_error_message(), $post_id, array( 'source' => 'list_bulk' ) );
			} else {
				$processed++;
				update_post_meta( $post_id, '_unsplash_last_keyword', sanitize_text_field( $keyword ) );
				update_post_meta( $post_id, '_unsplash_assignment_method', 'bulk' );
				update_post_meta( $post_id, '_fp_photo_source', $source_slug );
				$this->logger->log_action( 'image_assigned', $post_id, array( 'keyword' => $keyword, 'source' => $source_slug ), 'success' );
			}

			$this->set_status( array(
				'total'     => $total,
				'processed' => $processed,
				'errors'    => $errors,
				'skipped'   => $skipped,
				'cancelled' => false,
			) );

			if ( is_callable( $batch_callback ) ) {
				call_user_func( $batch_callback, $processed, $total );
			}
		}

		delete_transient( self::STATUS_TRANSIENT );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
			'skipped'   => $skipped,
			'total'     => $total,
		);
	}

	public function get_processing_status() {
		return get_transient( self::STATUS_TRANSIENT );
	}

	public function cancel_processing() {
		$status = $this->get_processing_status();
		if ( is_array( $status ) ) {
			$status['cancelled'] = true;
			$this->set_status( $status );
		}
	}

	/**
	 * Show an admin notice after the Posts-list bulk action redirect.
	 */
	public function maybe_show_bulk_notice() {
		$processed = isset( $_GET['unsplash_processed'] ) ? absint( $_GET['unsplash_processed'] ) : null;
		$errors    = isset( $_GET['unsplash_errors'] ) ? absint( $_GET['unsplash_errors'] ) : null;

		if ( null === $processed ) {
			return;
		}

		$class   = $errors > 0 ? 'notice-warning' : 'notice-success';
		$message = sprintf(
			/* translators: 1: processed count, 2: error count */
			esc_html__( 'FeaturedPilot: %1$d image(s) assigned, %2$d error(s).', 'unsplash-featured-images' ),
			$processed,
			$errors
		);

		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Normalise the queue array into the status shape returned to callers.
	 */
	private function queue_status( $queue ) {
		return array(
			'status'      => $queue['status'] ?? 'idle',
			'total'       => $queue['total'] ?? 0,
			'processed'   => $queue['processed'] ?? 0,
			'errors'      => $queue['errors'] ?? 0,
			'remaining'   => count( $queue['post_ids'] ?? array() ),
			'next_run_at' => $queue['next_run_at'] ?? 0,
		);
	}

	private function set_status( $status ) {
		set_transient( self::STATUS_TRANSIENT, $status, self::STATUS_TRANSIENT_TTL );
	}
}
