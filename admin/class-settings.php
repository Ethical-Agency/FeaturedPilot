<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin settings page under Settings > Unsplash Featured Images.
 */
class Unsplash_Settings {

	/** @var Unsplash_API */
	private $api;

	public function __construct( Unsplash_API $api ) {
		$this->api = $api;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
		$settings = $this;
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
			array( 'jquery' ),
			UNSPLASH_FI_VERSION,
			true
		);
		wp_localize_script(
			'unsplash-fi-admin',
			'unsplashAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'unsplash_action_nonce' ),
				'i18n'    => array(
					'testing'        => __( 'Testing…', 'unsplash-featured-images' ),
					'testSuccess'    => __( 'Connection successful!', 'unsplash-featured-images' ),
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
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public function register_settings() {
		// Section: API.
		add_settings_section( 'unsplash_api', __( 'API Configuration', 'unsplash-featured-images' ), null, 'unsplash-featured-images' );

		register_setting( 'unsplash_settings', 'unsplash_api_key', array(
			'sanitize_callback' => array( $this, 'sanitize_api_key' ),
			'default'           => '',
		) );
		add_settings_field( 'unsplash_api_key', __( 'API Key', 'unsplash-featured-images' ), array( $this, 'field_api_key' ), 'unsplash-featured-images', 'unsplash_api' );

		// Section: Automation.
		add_settings_section( 'unsplash_automation', __( 'Automation', 'unsplash-featured-images' ), null, 'unsplash-featured-images' );

		register_setting( 'unsplash_settings', 'unsplash_schedule_enabled', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		add_settings_field( 'unsplash_schedule_enabled', __( 'Enable Scheduling', 'unsplash-featured-images' ), array( $this, 'field_schedule_enabled' ), 'unsplash-featured-images', 'unsplash_automation' );

		register_setting( 'unsplash_settings', 'unsplash_schedule_frequency', array( 'sanitize_callback' => array( $this, 'sanitize_frequency' ), 'default' => 'daily' ) );
		add_settings_field( 'unsplash_schedule_frequency', __( 'Frequency', 'unsplash-featured-images' ), array( $this, 'field_schedule_frequency' ), 'unsplash-featured-images', 'unsplash_automation' );

		register_setting( 'unsplash_settings', 'unsplash_schedule_target', array( 'sanitize_callback' => array( $this, 'sanitize_target' ), 'default' => 'no_featured_image' ) );
		add_settings_field( 'unsplash_schedule_target', __( 'Target Posts', 'unsplash-featured-images' ), array( $this, 'field_schedule_target' ), 'unsplash-featured-images', 'unsplash_automation' );

		// Section: Image Preferences.
		add_settings_section( 'unsplash_image', __( 'Image Preferences', 'unsplash-featured-images' ), null, 'unsplash-featured-images' );

		register_setting( 'unsplash_settings', 'unsplash_image_orientation', array( 'sanitize_callback' => array( $this, 'sanitize_orientation' ), 'default' => '' ) );
		add_settings_field( 'unsplash_image_orientation', __( 'Orientation', 'unsplash-featured-images' ), array( $this, 'field_orientation' ), 'unsplash-featured-images', 'unsplash_image' );

		register_setting( 'unsplash_settings', 'unsplash_image_content_filter', array( 'sanitize_callback' => array( $this, 'sanitize_content_filter' ), 'default' => 'low' ) );
		add_settings_field( 'unsplash_image_content_filter', __( 'Content Filter', 'unsplash-featured-images' ), array( $this, 'field_content_filter' ), 'unsplash-featured-images', 'unsplash_image' );

		register_setting( 'unsplash_settings', 'unsplash_image_min_width', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		add_settings_field( 'unsplash_image_min_width', __( 'Min Width (px)', 'unsplash-featured-images' ), array( $this, 'field_min_width' ), 'unsplash-featured-images', 'unsplash_image' );

		register_setting( 'unsplash_settings', 'unsplash_image_min_height', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		add_settings_field( 'unsplash_image_min_height', __( 'Min Height (px)', 'unsplash-featured-images' ), array( $this, 'field_min_height' ), 'unsplash-featured-images', 'unsplash_image' );

		// Section: Logging.
		add_settings_section( 'unsplash_logging', __( 'Logging', 'unsplash-featured-images' ), null, 'unsplash-featured-images' );

		register_setting( 'unsplash_settings', 'unsplash_log_enabled', array( 'sanitize_callback' => 'absint', 'default' => 1 ) );
		add_settings_field( 'unsplash_log_enabled', __( 'Enable Logging', 'unsplash-featured-images' ), array( $this, 'field_log_enabled' ), 'unsplash-featured-images', 'unsplash_logging' );

		register_setting( 'unsplash_settings', 'unsplash_log_retention_days', array( 'sanitize_callback' => 'absint', 'default' => 30 ) );
		add_settings_field( 'unsplash_log_retention_days', __( 'Retention (days)', 'unsplash-featured-images' ), array( $this, 'field_log_retention' ), 'unsplash-featured-images', 'unsplash_logging' );

		// Section: Advanced.
		add_settings_section( 'unsplash_advanced', __( 'Advanced', 'unsplash-featured-images' ), null, 'unsplash-featured-images' );

		register_setting( 'unsplash_settings', 'unsplash_default_keyword', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'nature' ) );
		add_settings_field( 'unsplash_default_keyword', __( 'Default Keyword', 'unsplash-featured-images' ), array( $this, 'field_default_keyword' ), 'unsplash-featured-images', 'unsplash_advanced' );
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	public function field_api_key() {
		$key     = get_option( 'unsplash_api_key', '' );
		$display = ! empty( $key ) ? str_repeat( '*', max( 0, strlen( $key ) - 4 ) ) . substr( $key, -4 ) : '';
		?>
		<input type="password" name="unsplash_api_key" id="unsplash_api_key"
			value="<?php echo esc_attr( $key ); ?>"
			class="regular-text"
			autocomplete="new-password" />
		<?php if ( ! empty( $display ) ) : ?>
			<p class="description"><?php echo esc_html( sprintf( __( 'Current key: %s', 'unsplash-featured-images' ), $display ) ); ?></p>
		<?php endif; ?>
		<button type="button" id="unsplash-test-api" class="button button-secondary">
			<?php esc_html_e( 'Test Connection', 'unsplash-featured-images' ); ?>
		</button>
		<span id="unsplash-api-test-result" class="unsplash-inline-result"></span>
		<p class="description">
			<?php esc_html_e( 'Get your free API key at unsplash.com/developers. Or define UNSPLASH_API_KEY constant in wp-config.php.', 'unsplash-featured-images' ); ?>
		</p>
		<?php
	}

	public function field_schedule_enabled() {
		$val = get_option( 'unsplash_schedule_enabled', 0 );
		?>
		<input type="checkbox" name="unsplash_schedule_enabled" id="unsplash_schedule_enabled" value="1" <?php checked( 1, $val ); ?> />
		<label for="unsplash_schedule_enabled"><?php esc_html_e( 'Automatically assign featured images on a schedule', 'unsplash-featured-images' ); ?></label>
		<?php
	}

	public function field_schedule_frequency() {
		$val = get_option( 'unsplash_schedule_frequency', 'daily' );
		?>
		<select name="unsplash_schedule_frequency" id="unsplash_schedule_frequency">
			<option value="daily" <?php selected( 'daily', $val ); ?>><?php esc_html_e( 'Daily', 'unsplash-featured-images' ); ?></option>
			<option value="weekly" <?php selected( 'weekly', $val ); ?>><?php esc_html_e( 'Weekly', 'unsplash-featured-images' ); ?></option>
		</select>
		<?php
	}

	public function field_schedule_target() {
		$val = get_option( 'unsplash_schedule_target', 'no_featured_image' );
		?>
		<select name="unsplash_schedule_target" id="unsplash_schedule_target">
			<option value="no_featured_image" <?php selected( 'no_featured_image', $val ); ?>><?php esc_html_e( 'Posts without a featured image', 'unsplash-featured-images' ); ?></option>
			<option value="all_posts" <?php selected( 'all_posts', $val ); ?>><?php esc_html_e( 'All published posts', 'unsplash-featured-images' ); ?></option>
		</select>
		<?php
	}

	public function field_orientation() {
		$val = get_option( 'unsplash_image_orientation', '' );
		$options = array(
			''          => __( 'Any', 'unsplash-featured-images' ),
			'landscape' => __( 'Landscape', 'unsplash-featured-images' ),
			'portrait'  => __( 'Portrait', 'unsplash-featured-images' ),
			'squarish'  => __( 'Square', 'unsplash-featured-images' ),
		);
		echo '<select name="unsplash_image_orientation">';
		foreach ( $options as $k => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $k ),
				selected( $k, $val, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function field_content_filter() {
		$val = get_option( 'unsplash_image_content_filter', 'low' );
		?>
		<select name="unsplash_image_content_filter">
			<option value="low" <?php selected( 'low', $val ); ?>><?php esc_html_e( 'Standard', 'unsplash-featured-images' ); ?></option>
			<option value="high" <?php selected( 'high', $val ); ?>><?php esc_html_e( 'Strict (family-safe)', 'unsplash-featured-images' ); ?></option>
		</select>
		<?php
	}

	public function field_min_width() {
		$val = absint( get_option( 'unsplash_image_min_width', 0 ) );
		echo '<input type="number" name="unsplash_image_min_width" value="' . esc_attr( $val ) . '" min="0" class="small-text" /> px';
	}

	public function field_min_height() {
		$val = absint( get_option( 'unsplash_image_min_height', 0 ) );
		echo '<input type="number" name="unsplash_image_min_height" value="' . esc_attr( $val ) . '" min="0" class="small-text" /> px';
	}

	public function field_log_enabled() {
		$val = get_option( 'unsplash_log_enabled', 1 );
		?>
		<input type="checkbox" name="unsplash_log_enabled" id="unsplash_log_enabled" value="1" <?php checked( 1, $val ); ?> />
		<label for="unsplash_log_enabled"><?php esc_html_e( 'Log plugin activity', 'unsplash-featured-images' ); ?></label>
		<?php
	}

	public function field_log_retention() {
		$val = absint( get_option( 'unsplash_log_retention_days', 30 ) );
		echo '<input type="number" name="unsplash_log_retention_days" value="' . esc_attr( $val ) . '" min="1" max="365" class="small-text" /> ';
		esc_html_e( 'days', 'unsplash-featured-images' );
	}

	public function field_default_keyword() {
		$val = get_option( 'unsplash_default_keyword', 'nature' );
		echo '<input type="text" name="unsplash_default_keyword" value="' . esc_attr( $val ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Fallback keyword when no other keyword can be derived.', 'unsplash-featured-images' ) . '</p>';
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

	// -------------------------------------------------------------------------
	// Helpers for the view
	// -------------------------------------------------------------------------

	public function render_rate_limit_status() {
		$remaining = absint( get_option( 'unsplash_rate_limit_remaining', '?' ) );
		$total     = absint( get_option( 'unsplash_rate_limit_total', 50 ) );
		printf(
			'<span class="unsplash-rate-limit">%s</span>',
			esc_html( sprintf(
				/* translators: 1: remaining requests, 2: total requests per hour */
				__( '%1$d / %2$d requests remaining this hour', 'unsplash-featured-images' ),
				$remaining,
				$total
			) )
		);
	}
}
