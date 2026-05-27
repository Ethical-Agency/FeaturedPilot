<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP-Cron events for automated image assignment.
 */
class Scheduler {

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

		$this->register_cron_events();
	}

	// -------------------------------------------------------------------------
	// Cron registration
	// -------------------------------------------------------------------------

	public function register_cron_events() {
		add_action( 'unsplash_daily_update',         array( $this, 'run_daily_update' ) );
		add_action( 'unsplash_weekly_update',        array( $this, 'run_weekly_update' ) );
		add_action( 'unsplash_bulk_hourly_continue', array( $this, 'run_bulk_continue' ) );
		add_action( 'fp_hourly_rate_reset',          array( $this, 'run_hourly_rate_reset' ) );

		// Re-schedule on every load to keep events alive if settings change.
		add_action( 'init', array( $this, 'maybe_reschedule' ) );
		add_action( 'init', array( $this, 'maybe_schedule_hourly_reset' ) );
	}

	/**
	 * Cron callback: reset all API rate-limit counters every hour so a depleted
	 * quota from a previous session never blocks future requests indefinitely.
	 */
	public function run_hourly_rate_reset() {
		$plugin = Unsplash_Featured_Images::get_instance();
		if ( $plugin && isset( $plugin->source_manager ) ) {
			$plugin->source_manager->reset_all_rate_limits();
		}
	}

	/**
	 * Cron callback: continue a rate-limited bulk job.
	 */
	public function run_bulk_continue() {
		$bulk = new Bulk_Processor( $this->image_handler, $this->keyword_generator, $this->logger );
		$bulk->continue_from_cron();
	}

	/**
	 * Schedule or reschedule cron based on current settings.
	 */
	public function maybe_reschedule() {
		if ( ! $this->is_schedule_enabled() ) {
			$this->unschedule_update();
			return;
		}

		$frequency = $this->get_schedule_frequency();
		$hook      = 'daily' === $frequency ? 'unsplash_daily_update' : 'unsplash_weekly_update';
		$interval  = 'daily' === $frequency ? 'daily' : 'weekly';

		// Remove the other hook to avoid double-running.
		$other_hook = 'daily' === $frequency ? 'unsplash_weekly_update' : 'unsplash_daily_update';
		if ( wp_next_scheduled( $other_hook ) ) {
			wp_clear_scheduled_hook( $other_hook );
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $interval, $hook );
		}
	}

	/**
	 * Ensure the hourly rate-reset cron is always scheduled, independently of
	 * whether the user has enabled the automated image-assignment schedule.
	 */
	public function maybe_schedule_hourly_reset() {
		if ( ! wp_next_scheduled( 'fp_hourly_rate_reset' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'fp_hourly_rate_reset' );
		}
	}

	public function schedule_update() {
		$this->maybe_reschedule();
	}

	public function unschedule_update() {
		wp_clear_scheduled_hook( 'unsplash_daily_update' );
		wp_clear_scheduled_hook( 'unsplash_weekly_update' );
	}

	// -------------------------------------------------------------------------
	// Cron callbacks
	// -------------------------------------------------------------------------

	public function run_daily_update() {
		$this->logger->log_action( 'scheduled_run_start', 0, array( 'trigger' => 'daily' ) );
		$this->run_update();
	}

	public function run_weekly_update() {
		$this->logger->log_action( 'scheduled_run_start', 0, array( 'trigger' => 'weekly' ) );
		$this->run_update();
	}

	// -------------------------------------------------------------------------
	// Core processing
	// -------------------------------------------------------------------------

	private function run_update() {
		$posts = $this->get_posts_to_process();

		foreach ( $posts as $post_id ) {
			$this->process_post( $post_id );
		}

		$this->logger->log_action( 'scheduled_run_complete', 0, array( 'post_count' => count( $posts ) ) );
	}

	/**
	 * Return the post IDs to process based on the schedule target setting.
	 *
	 * @return int[]
	 */
	private function get_posts_to_process() {
		$target = $this->get_schedule_target();

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 50,  // Batch size — keeps rate limit manageable.
			'fields'         => 'ids',
			'orderby'        => 'rand',
		);

		if ( 'no_featured_image' === $target ) {
			$args['meta_query'] = array(  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query = new WP_Query( $args );
		return array_map( 'absint', $query->posts );
	}

	/**
	 * Process a single post — skip if auto-update is disabled for it.
	 *
	 * @param int $post_id
	 */
	private function process_post( $post_id ) {
		$post_id = absint( $post_id );

		// Respect per-post skip flag.
		if ( get_post_meta( $post_id, '_unsplash_skip_auto', true ) ) {
			return;
		}

		$keyword = $this->keyword_generator->get_keyword_for_post( $post_id );
		$target  = $this->get_schedule_target();

		// For 'all_posts' target, replace existing featured images.
		$replace = ( 'all_posts' === $target );

		$orientation    = get_option( 'unsplash_image_orientation', '' );
		$content_filter = get_option( 'unsplash_image_content_filter', 'low' );

		$plugin  = Unsplash_Featured_Images::get_instance();
		$results = $plugin->source_manager->search_photos( $keyword, 10, 'relevant', $orientation, $content_filter );

		if ( is_wp_error( $results ) || empty( $results['results'] ) ) {
			$this->logger->log_error(
				is_wp_error( $results ) ? $results->get_error_message() : 'No results found.',
				$post_id,
				array( 'keyword' => $keyword )
			);
			return;
		}

		// Prefer unused photo; fall back to top result if the pool is exhausted.
		$unused = $this->image_handler->filter_unused_photos( $results['results'] );
		$photo  = ! empty( $unused ) ? $unused[0] : $results['results'][0];
		$photo_id    = sanitize_text_field( $photo['id'] );
		$source_slug = sanitize_key( $photo['source'] ?? 'unsplash' );

		$attachment_id = $this->image_handler->download_and_upload_image( $post_id, $photo_id, $replace, $source_slug );

		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->log_error( $attachment_id->get_error_message(), $post_id, array( 'keyword' => $keyword ) );
			return;
		}

		update_post_meta( $post_id, '_unsplash_last_keyword', sanitize_text_field( $keyword ) );
		update_post_meta( $post_id, '_unsplash_assignment_method', 'scheduled' );
		update_post_meta( $post_id, '_fp_photo_source', $source_slug );
	}

	// -------------------------------------------------------------------------
	// Setting getters
	// -------------------------------------------------------------------------

	private function is_schedule_enabled() {
		return (bool) get_option( 'unsplash_schedule_enabled', 0 );
	}

	private function get_schedule_frequency() {
		$freq = get_option( 'unsplash_schedule_frequency', 'daily' );
		return in_array( $freq, array( 'daily', 'weekly' ), true ) ? $freq : 'daily';
	}

	private function get_schedule_target() {
		$target = get_option( 'unsplash_schedule_target', 'no_featured_image' );
		return in_array( $target, array( 'no_featured_image', 'all_posts' ), true ) ? $target : 'no_featured_image';
	}

	/**
	 * Remove logs older than the configured retention period.
	 *
	 * @param int $days
	 */
	public function clear_old_logs( $days = 30 ) {
		$retention = absint( get_option( 'unsplash_log_retention_days', 30 ) );
		$this->logger->clear_logs( $days ?: $retention );
	}
}
