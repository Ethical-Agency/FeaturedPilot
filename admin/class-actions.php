<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all AJAX endpoints and the Posts-list bulk action.
 * Every handler: verify nonce → verify capability → sanitize inputs → process.
 */
class Unsplash_Actions {

	/** @var Source_Manager */
	private $source_manager;

	/** @var Image_Handler */
	private $image_handler;

	/** @var Keyword_Generator */
	private $keyword_generator;

	/** @var Activity_Logger */
	private $logger;

	public function __construct(
		Source_Manager $source_manager,
		Image_Handler $image_handler,
		Keyword_Generator $keyword_generator,
		Activity_Logger $logger
	) {
		$this->source_manager    = $source_manager;
		$this->image_handler     = $image_handler;
		$this->keyword_generator = $keyword_generator;
		$this->logger            = $logger;

		// AJAX hooks — logged-in users only (no nopriv variants).
		add_action( 'wp_ajax_unsplash_update_image',    array( $this, 'ajax_update_image' ) );
		add_action( 'wp_ajax_unsplash_search_preview',  array( $this, 'ajax_search_preview' ) );
		add_action( 'wp_ajax_unsplash_get_logs',        array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_fp_set_photo',             array( $this, 'ajax_set_photo' ) );
		add_action( 'wp_ajax_fp_rate_limit_status',     array( $this, 'ajax_rate_limit_status' ) );
		add_action( 'wp_ajax_fp_clear_logs',            array( $this, 'ajax_clear_logs' ) );
		// Queue-based bulk run.
		add_action( 'wp_ajax_unsplash_bulk_init',       array( $this, 'ajax_bulk_init' ) );
		add_action( 'wp_ajax_unsplash_bulk_process',    array( $this, 'ajax_bulk_process' ) );
		add_action( 'wp_ajax_unsplash_bulk_status',     array( $this, 'ajax_bulk_status' ) );
		add_action( 'wp_ajax_unsplash_bulk_cancel',     array( $this, 'ajax_bulk_cancel' ) );

		// Bulk action on Posts list.
		add_filter( 'bulk_actions-edit-post', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-post', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	// -------------------------------------------------------------------------
	// AJAX: update single post
	// -------------------------------------------------------------------------

	public function ajax_update_image() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'edit_posts' );
		$this->verify_user_permissions( 'upload_files' );

		$post_id          = absint( $_POST['post_id'] ?? 0 );
		$replace_existing = ! empty( $_POST['replace_existing'] );
		$custom_keyword   = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$dry_run          = ! empty( $_POST['dry_run'] );

		// API connection test — verify the Unsplash key without downloading an image.
		if ( $dry_run ) {
			$plugin     = Unsplash_Featured_Images::get_instance();
			$key_result = $plugin->api->is_valid_key();
			if ( true === $key_result ) {
				wp_send_json_success( array( 'message' => __( 'API key is valid.', 'unsplash-featured-images' ) ) );
			} else {
				$msg = is_wp_error( $key_result ) ? $key_result->get_error_message() : __( 'Invalid API key. Check your key at unsplash.com/developers.', 'unsplash-featured-images' );
				wp_send_json_error( array( 'message' => $msg ) );
			}
		}

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'unsplash-featured-images' ) ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot edit this post.', 'unsplash-featured-images' ) ) );
		}

		// Resolve keyword.
		if ( ! empty( $custom_keyword ) ) {
			$keyword = $custom_keyword;
		} else {
			$keyword = $this->keyword_generator->get_keyword_for_post( $post_id );
		}

		// Search across configured sources.
		$orientation    = get_option( 'unsplash_image_orientation', '' );
		$content_filter = get_option( 'unsplash_image_content_filter', 'low' );

		$results = $this->source_manager->search_photos( $keyword, 1, 'relevant', $orientation, $content_filter );
		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		if ( empty( $results['results'][0] ) ) {
			wp_send_json_error( array( 'message' => __( 'No photos found for this keyword.', 'unsplash-featured-images' ) ) );
		}

		$photo       = $results['results'][0];
		$photo_id    = sanitize_text_field( $photo['id'] );
		$source_slug = sanitize_key( $photo['source'] ?? 'unsplash' );

		$attachment_id = $this->image_handler->download_and_upload_image( $post_id, $photo_id, $replace_existing, $source_slug );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}

		update_post_meta( $post_id, '_unsplash_last_keyword', sanitize_text_field( $keyword ) );
		update_post_meta( $post_id, '_unsplash_assignment_method', 'manual' );
		update_post_meta( $post_id, '_fp_photo_source', $source_slug );

		wp_send_json_success( array(
			'message'          => __( 'Featured image set successfully!', 'unsplash-featured-images' ),
			'attachment_id'    => $attachment_id,
			'thumbnail_url'    => esc_url( wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ),
			'photographer'     => esc_html( $photo['user']['name'] ?? '' ),
			'photographer_url' => esc_url( $photo['user']['links']['html'] ?? '' ),
			'photo_url'        => esc_url( $photo['links']['html'] ?? '' ),
			'source'           => esc_html( $source_slug ),
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: search preview (returns up to 3 photo thumbnails)
	// -------------------------------------------------------------------------

	public function ajax_search_preview() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'edit_posts' );

		$keyword     = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$post_id     = absint( $_POST['post_id'] ?? 0 );
		$source_pref = sanitize_key( $_POST['source'] ?? '' ); // '' = auto (priority order)

		if ( empty( $keyword ) && $post_id ) {
			$keyword = $this->keyword_generator->get_keyword_for_post( $post_id );
		}

		if ( empty( $keyword ) ) {
			wp_send_json_error( array( 'message' => __( 'No keyword provided.', 'unsplash-featured-images' ) ) );
		}

		$orientation    = get_option( 'unsplash_image_orientation', '' );
		$content_filter = get_option( 'unsplash_image_content_filter', 'low' );

		// If a specific source is requested, use it directly; otherwise use priority order.
		if ( ! empty( $source_pref ) ) {
			$api = $this->source_manager->get_source( $source_pref );
			if ( $api ) {
				$results = $api->search_photos( $keyword, 3, 'relevant', $orientation, $content_filter );
			} else {
				$results = new WP_Error( 'unknown_source', __( 'Unknown image source.', 'unsplash-featured-images' ) );
			}
		} else {
			$results = $this->source_manager->search_photos( $keyword, 3, 'relevant', $orientation, $content_filter );
		}

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		$photos = array();
		foreach ( array_slice( $results['results'] ?? array(), 0, 3 ) as $photo ) {
			$photos[] = array(
				'id'               => sanitize_text_field( $photo['id'] ),
				'source'           => esc_html( $photo['source'] ?? 'unsplash' ),
				'thumb'            => esc_url( $photo['urls']['thumb'] ?? '' ),
				'small'            => esc_url( $photo['urls']['small'] ?? '' ),
				'photographer'     => esc_html( $photo['user']['name'] ?? '' ),
				'photographer_url' => esc_url( $photo['user']['links']['html'] ?? '' ),
				'photo_url'        => esc_url( $photo['links']['html'] ?? '' ),
				'alt'              => esc_attr( $photo['alt_description'] ?? '' ),
			);
		}

		wp_send_json_success( array(
			'keyword' => esc_html( $keyword ),
			'photos'  => $photos,
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: get activity logs
	// -------------------------------------------------------------------------

	public function ajax_get_logs() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'manage_options' );

		$limit   = min( 100, absint( $_POST['limit'] ?? 50 ) );
		$offset  = absint( $_POST['offset'] ?? 0 );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : null;

		$logs = $this->logger->get_logs( $limit, $offset, $post_id );

		// Escape all output values.
		$safe_logs = array_map( function( $entry ) {
			return array(
				'id'      => esc_html( $entry['id'] ?? '' ),
				'action'  => esc_html( $entry['action'] ?? '' ),
				'post_id' => absint( $entry['post_id'] ?? 0 ),
				'status'  => esc_html( $entry['status'] ?? '' ),
				'time'    => esc_html( $entry['time'] ?? '' ),
				'details' => array_map( 'sanitize_text_field', (array) ( $entry['details'] ?? array() ) ),
			);
		}, $logs );

		wp_send_json_success( array( 'logs' => $safe_logs ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: set a specific photo (preview "Use This" flow)
	// -------------------------------------------------------------------------

	public function ajax_set_photo() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'edit_posts' );
		$this->verify_user_permissions( 'upload_files' );

		$post_id     = absint( $_POST['post_id'] ?? 0 );
		$photo_id    = sanitize_text_field( wp_unslash( $_POST['photo_id'] ?? '' ) );
		$source_slug = sanitize_key( $_POST['source'] ?? 'unsplash' );
		$replace     = ! empty( $_POST['replace_existing'] );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'unsplash-featured-images' ) ) );
		}

		if ( empty( $photo_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid photo ID.', 'unsplash-featured-images' ) ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot edit this post.', 'unsplash-featured-images' ) ) );
		}

		$photo_data = $this->source_manager->get_photo( $photo_id, $source_slug );
		if ( is_wp_error( $photo_data ) ) {
			wp_send_json_error( array( 'message' => $photo_data->get_error_message() ) );
		}

		$attachment_id = $this->image_handler->download_and_upload_image( $post_id, $photo_id, $replace, $source_slug );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}

		update_post_meta( $post_id, '_unsplash_assignment_method', 'manual' );
		update_post_meta( $post_id, '_fp_photo_source', $source_slug );

		wp_send_json_success( array(
			'message'          => __( 'Featured image set successfully!', 'unsplash-featured-images' ),
			'attachment_id'    => $attachment_id,
			'thumbnail_url'    => esc_url( wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ),
			'photographer'     => esc_html( $photo_data['user']['name'] ?? '' ),
			'photographer_url' => esc_url( $photo_data['user']['links']['html'] ?? '' ),
			'photo_url'        => esc_url( $photo_data['links']['html'] ?? '' ),
			'source'           => esc_html( $source_slug ),
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: rate limit status for all sources
	// -------------------------------------------------------------------------

	public function ajax_rate_limit_status() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'manage_options' );

		wp_send_json_success( $this->source_manager->get_all_status() );
	}

	// -------------------------------------------------------------------------
	// AJAX: clear all activity logs
	// -------------------------------------------------------------------------

	public function ajax_clear_logs() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'manage_options' );

		$this->logger->clear_all_logs();
		wp_send_json_success( array( 'message' => __( 'All logs cleared.', 'unsplash-featured-images' ) ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: queue-based bulk run
	// -------------------------------------------------------------------------

	/**
	 * Initialise a new bulk job: build the eligible post list and save the queue.
	 * The JS calls this once on "Run Now", then drives batches via ajax_bulk_process.
	 */
	public function ajax_bulk_init() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'edit_posts' );
		$this->verify_user_permissions( 'upload_files' );

		$replace_existing = ! empty( $_POST['replace_existing'] );

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! $replace_existing ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query    = new WP_Query( $args );
		$post_ids = array_map( 'absint', $query->posts );

		$bulk_processor = new Bulk_Processor( $this->image_handler, $this->keyword_generator, $this->logger );
		wp_send_json_success( $bulk_processor->init_job( $post_ids, $replace_existing ) );
	}

	/**
	 * Process the next batch from the persistent queue (JS drives this in a loop).
	 */
	public function ajax_bulk_process() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'edit_posts' );
		$this->verify_user_permissions( 'upload_files' );

		$bulk_processor = new Bulk_Processor( $this->image_handler, $this->keyword_generator, $this->logger );
		wp_send_json_success( $bulk_processor->process_queue_batch( 5 ) );
	}

	/**
	 * Return the current job status — used for polling when paused and on page load.
	 */
	public function ajax_bulk_status() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'edit_posts' );

		$bulk_processor = new Bulk_Processor( $this->image_handler, $this->keyword_generator, $this->logger );
		wp_send_json_success( $bulk_processor->get_job_status() );
	}

	/**
	 * Cancel the running job and clear scheduled continuation.
	 */
	public function ajax_bulk_cancel() {
		$this->verify_ajax_nonce();
		$this->verify_user_permissions( 'edit_posts' );

		$bulk_processor = new Bulk_Processor( $this->image_handler, $this->keyword_generator, $this->logger );
		$bulk_processor->cancel_job();
		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Posts list bulk action
	// -------------------------------------------------------------------------

	public function register_bulk_action( $bulk_actions ) {
		$bulk_actions['unsplash_assign_images'] = __( 'Assign with FeaturedPilot', 'unsplash-featured-images' );
		return $bulk_actions;
	}

	public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( 'unsplash_assign_images' !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			return $redirect_to;
		}

		$post_ids       = array_map( 'absint', (array) $post_ids );
		$bulk_processor = new Bulk_Processor( $this->image_handler, $this->keyword_generator, $this->logger );
		$result         = $bulk_processor->process_posts( $post_ids, false );

		return add_query_arg(
			array(
				'unsplash_processed' => $result['processed'],
				'unsplash_errors'    => $result['errors'],
			),
			$redirect_to
		);
	}

	// -------------------------------------------------------------------------
	// Private security helpers
	// -------------------------------------------------------------------------

	private function verify_ajax_nonce() {
		check_ajax_referer( 'unsplash_action_nonce', 'nonce' );
	}

	private function verify_user_permissions( $capability = 'edit_posts' ) {
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'unsplash-featured-images' ) ), 403 );
			wp_die();
		}
	}
}
