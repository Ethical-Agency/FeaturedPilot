<?php
/**
 * Plugin Name:       FeaturedPilot
 * Plugin URI:        https://github.com/Ethical-Agency/FeaturedPilot
 * Description:       Automatically assigns featured images from Unsplash, Pexels, or Pixabay with priority-order fallback, tabbed settings, live rate gauges, and a meta-box preview grid.
 * Version:           1.1.1
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            The Ethical Agency
 * Author URI:        https://theethicalagency.co.za
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       unsplash-featured-images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'UNSPLASH_FI_VERSION', '1.1.1' );
define( 'UNSPLASH_FI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UNSPLASH_FI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UNSPLASH_FI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class (singleton).
 */
final class Unsplash_Featured_Images {

	/** @var Unsplash_Featured_Images|null */
	private static $instance = null;

	/** @var Unsplash_API */
	public $api;

	/** @var Source_Manager */
	public $source_manager;

	/** @var Image_Handler */
	public $image_handler;

	/** @var Keyword_Generator */
	public $keyword_generator;

	/** @var Activity_Logger */
	public $logger;

	/** @var Image_Filters */
	public $image_filters;

	/** @var Scheduler */
	public $scheduler;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_core();

		// Instantiate admin classes early enough for their own hooks (admin_init, admin_menu, etc.) to fire.
		if ( is_admin() ) {
			$this->init_admin();
		}
	}

	private function load_dependencies() {
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-activity-logger.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-unsplash-api.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-pexels-api.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-pixabay-api.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-source-manager.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-keyword-generator.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-image-handler.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-image-filters.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'includes/class-scheduler.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'admin/class-settings.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'admin/class-meta-box.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'admin/class-actions.php';
		require_once UNSPLASH_FI_PLUGIN_DIR . 'admin/class-bulk-processor.php';
	}

	private function init_core() {
		$this->logger  = new Activity_Logger();
		$this->api     = new Unsplash_API( $this->logger );
		$pexels_api    = new Pexels_API( $this->logger );
		$pixabay_api   = new Pixabay_API( $this->logger );
		$this->source_manager = new Source_Manager(
			array(
				'unsplash' => $this->api,
				'pexels'   => $pexels_api,
				'pixabay'  => $pixabay_api,
			),
			$this->logger
		);
		$this->keyword_generator = new Keyword_Generator();
		$this->image_filters     = new Image_Filters();
		$this->image_handler     = new Image_Handler( $this->source_manager, $this->logger );
		$this->scheduler         = new Scheduler( $this->image_handler, $this->keyword_generator, $this->logger );
	}

	public function init_admin() {
		new Unsplash_Settings( $this->source_manager );
		new Unsplash_Meta_Box( $this->source_manager, $this->keyword_generator, $this->image_handler );
		new Unsplash_Actions( $this->source_manager, $this->image_handler, $this->keyword_generator, $this->logger );
		new Bulk_Processor( $this->image_handler, $this->keyword_generator, $this->logger );
	}

	public static function activate() {
		// Create default options on first activation.
		$defaults = array(
			'unsplash_schedule_enabled'      => '0',
			'unsplash_schedule_frequency'    => 'daily',
			'unsplash_schedule_target'       => 'no_featured_image',
			'unsplash_default_keyword'       => 'nature',
			'unsplash_log_enabled'           => '1',
			'unsplash_log_retention_days'    => 30,
			'unsplash_image_orientation'     => '',
			'unsplash_image_content_filter'  => 'low',
			'unsplash_image_min_width'       => 0,
			'unsplash_image_min_height'      => 0,
			// v1.1.0 multi-source.
			'pexels_api_key'                 => '',
			'pixabay_api_key'                => '',
			'unsplash_source_priority'       => 'unsplash,pexels,pixabay',
			'pexels_rate_limit_remaining'    => 200,
			'pexels_rate_limit_total'        => 200,
			'pixabay_rate_limit_remaining'   => 5000,
			'pixabay_rate_limit_total'       => 5000,
			'unsplash_keyword_mode'          => 'title',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}

	public static function deactivate() {
		// Clear scheduled cron events.
		wp_clear_scheduled_hook( 'unsplash_daily_update' );
		wp_clear_scheduled_hook( 'unsplash_weekly_update' );
	}
}

// Activation / deactivation hooks must be registered before plugins_loaded.
register_activation_hook( __FILE__, array( 'Unsplash_Featured_Images', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Unsplash_Featured_Images', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Unsplash_Featured_Images', 'get_instance' ) );
