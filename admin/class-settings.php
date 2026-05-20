<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the FeaturedPilot settings page (tabbed layout).
 */
class Unsplash_Settings {

	/** @var Source_Manager */
	private $source_manager;

	public function __construct( Source_Manager $source_manager ) {
		$this->source_manager = $source_manager;

		add_action( 'admin_menu',             array( $this, 'register_menu' ) );
		add_action( 'admin_init',             array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Menu & page
	// -------------------------------------------------------------------------

	public function register_menu() {
		add_options_page(
			__( 'FeaturedPilot', 'unsplash-featured-images' ),
			__( 'FeaturedPilot', 'unsplash-featured-images' ),
			'manage_options',
			'unsplash-featured-images',
			array( $this, 'render_settings_page' )
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unsplash-featured-images' ) );
		}
		$settings       = $this;
		$source_manager = $this->source_manager;
		require_once UNSPLASH_FI_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_unsplash-featured-images' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'unsplash-fi-admin',
			UNSPLASH_FI_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			UNSPLASH_FI_VERSION
		);
		wp_enqueue_script(
			'unsplash-fi-admin',
			UNSPLASH_FI_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			UNSPLASH_FI_VERSION,
			true
		);

		$status = $this->source_manager->get_all_status();

		wp_localize_script(
			'unsplash-fi-admin',
			'unsplashAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'unsplash_action_nonce' ),
				'rateStatus' => $status,
				'i18n'       => array(
					'testing'        => __( 'Testing…', 'unsplash-featured-images' ),
					'testSuccess'    => __( 'Connected!', 'unsplash-featured-images' ),
					'testFail'       => __( 'Connection failed.', 'unsplash-featured-images' ),
					'bulkStarting'   => __( 'Starting…', 'unsplash-featured-images' ),
					'bulkProgress'   => __( '{processed} of {total} assigned — {errors} error(s)', 'unsplash-featured-images' ),
					'bulkPaused'     => __( 'Rate limit reached. Resuming in {m}:{s} …', 'unsplash-featured-images' ),
					'bulkResuming'   => __( 'Rate limit reset — resuming shortly…', 'unsplash-featured-images' ),
					'bulkDone'       => __( 'Complete.', 'unsplash-featured-images' ),
					'bulkCancelling' => __( 'Cancelling…', 'unsplash-featured-images' ),
					'bulkCancelled'  => __( 'Cancelled.', 'unsplash-featured-images' ),
					'bulkSummary'    => __( '{processed} assigned, {errors} error(s).', 'unsplash-featured-images' ),
					'bulkError'      => __( 'Could not start — check your API key and settings.', 'unsplash-featured-images' ),
					'clearLogsConfirm' => __( 'Delete all activity logs? This cannot be undone.', 'unsplash-featured-images' ),
					'clearLogsOk'    => __( 'All logs cleared.', 'unsplash-featured-images' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Settings registration — grouped by tab
	// -------------------------------------------------------------------------

	public function register_settings() {

		// ------ SOURCES TAB ------
		add_settings_section( 'fp_sources', '', null, 'fp-settings-sources' );

		// Unsplash API key.
		register_setting( 'fp_settings_sources', 'unsplash_api_key',  array( 'sanitize_callback' => array( $this, 'sanitize_api_key' ), 'default' => '' ) );
		// Pexels API key.
		register_setting( 'fp_settings_sources', 'pexels_api_key',    array( 'sanitize_callback' => array( $this, 'sanitize_api_key' ), 'default' => '' ) );
		// Pixabay API key.
		register_setting( 'fp_settings_sources', 'pixabay_api_key',   array( 'sanitize_callback' => array( $this, 'sanitize_api_key' ), 'default' => '' ) );
		// Source priority.
		register_setting( 'fp_settings_sources', 'unsplash_source_priority', array( 'sanitize_callback' => array( $this, 'sanitize_source_priority' ), 'default' => 'unsplash,pexels,pixabay' ) );

		// ------ AUTOMATION TAB ------
		add_settings_section( 'fp_automation', '', null, 'fp-settings-automation' );

		register_setting( 'fp_settings_automation', 'unsplash_schedule_enabled',   array( 'sanitize_callback' => 'absint',                                      'default' => 0 ) );
		register_setting( 'fp_settings_automation', 'unsplash_schedule_frequency', array( 'sanitize_callback' => array( $this, 'sanitize_frequency' ),           'default' => 'daily' ) );
		register_setting( 'fp_settings_automation', 'unsplash_schedule_target',    array( 'sanitize_callback' => array( $this, 'sanitize_target' ),              'default' => 'no_featured_image' ) );
		register_setting( 'fp_settings_automation', 'unsplash_default_keyword',    array( 'sanitize_callback' => 'sanitize_text_field',                          'default' => 'nature' ) );
		register_setting( 'fp_settings_automation', 'unsplash_keyword_mode',       array( 'sanitize_callback' => array( $this, 'sanitize_keyword_mode' ),        'default' => 'title' ) );

		// ------ IMAGES TAB ------
		add_settings_section( 'fp_images', '', null, 'fp-settings-images' );

		register_setting( 'fp_settings_images', 'unsplash_image_orientation',    array( 'sanitize_callback' => array( $this, 'sanitize_orientation' ),    'default' => '' ) );
		register_setting( 'fp_settings_images', 'unsplash_image_content_filter', array( 'sanitize_callback' => array( $this, 'sanitize_content_filter' ), 'default' => 'low' ) );
		register_setting( 'fp_settings_images', 'unsplash_image_min_width',      array( 'sanitize_callback' => 'absint',                                  'default' => 0 ) );
		register_setting( 'fp_settings_images', 'unsplash_image_min_height',     array( 'sanitize_callback' => 'absint',                                  'default' => 0 ) );

		// ------ LOGS TAB ------
		add_settings_section( 'fp_logs', '', null, 'fp-settings-logs' );

		register_setting( 'fp_settings_logs', 'unsplash_log_enabled',       array( 'sanitize_callback' => 'absint', 'default' => 1 ) );
		register_setting( 'fp_settings_logs', 'unsplash_log_retention_days', array( 'sanitize_callback' => 'absint', 'default' => 30 ) );
	}

	// -------------------------------------------------------------------------
	// Sanitize callbacks
	// -------------------------------------------------------------------------

	public function sanitize_api_key( $value ) {
		return sanitize_text_field( trim( $value ) );
	}

	public function sanitize_frequency( $value ) {
		return in_array( $value, array( 'daily', 'weekly' ), true ) ? $value : 'daily';
	}

	public function sanitize_target( $value ) {
		return in_array( $value, array( 'no_featured_image', 'all_posts' ), true ) ? $value : 'no_featured_image';
	}

	public function sanitize_orientation( $value ) {
		return in_array( $value, array( '', 'landscape', 'portrait', 'squarish' ), true ) ? $value : '';
	}

	public function sanitize_content_filter( $value ) {
		return in_array( $value, array( 'low', 'high' ), true ) ? $value : 'low';
	}

	public function sanitize_keyword_mode( $value ) {
		return in_array( $value, array( 'title', 'keyword', 'combined' ), true ) ? $value : 'title';
	}

	public function sanitize_source_priority( $value ) {
		$known  = array( 'unsplash', 'pexels', 'pixabay' );
		$slugs  = array_map( 'trim', explode( ',', (string) $value ) );
		$valid  = array_filter( $slugs, function( $s ) use ( $known ) {
			return in_array( $s, $known, true );
		} );
		// Ensure all known sources present (append missing ones at end).
		$missing = array_diff( $known, $valid );
		return implode( ',', array_values( array_merge( $valid, $missing ) ) );
	}

	// -------------------------------------------------------------------------
	// Helpers used by views
	// -------------------------------------------------------------------------

	/**
	 * Mask an API key for display (show last 4 chars only).
	 *
	 * @param string $key
	 * @return string
	 */
	public function mask_key( $key ) {
		if ( empty( $key ) ) {
			return '';
		}
		return str_repeat( '•', max( 0, strlen( $key ) - 4 ) ) . substr( $key, -4 );
	}

	/**
	 * Return the Source_Manager so the view can read per-source status.
	 *
	 * @return Source_Manager
	 */
	public function get_source_manager() {
		return $this->source_manager;
	}
}
