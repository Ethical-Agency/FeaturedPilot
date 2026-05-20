<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects Unsplash controls into the native WordPress Featured Image meta box.
 * Uses the admin_post_thumbnail_html filter so our UI appears inside the
 * existing box rather than creating a separate one.
 */
class Unsplash_Meta_Box {

	/** @var Source_Manager */
	private $source_manager;

	/** @var Keyword_Generator */
	private $keyword_generator;

	/** @var Image_Handler */
	private $image_handler;

	public function __construct( Source_Manager $source_manager, Keyword_Generator $keyword_generator, Image_Handler $image_handler ) {
		$this->source_manager    = $source_manager;
		$this->keyword_generator = $keyword_generator;
		$this->image_handler     = $image_handler;

		// Append our controls into the native Featured Image box.
		add_filter( 'admin_post_thumbnail_html', array( $this, 'append_to_thumbnail_box' ), 10, 3 );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Inject into native Featured Image box
	// -------------------------------------------------------------------------

	/**
	 * Appends our keyword field, Find button, and status area to the native
	 * Featured Image meta box HTML.
	 *
	 * @param string   $content      Existing featured image HTML.
	 * @param int      $post_id
	 * @param int|null $thumbnail_id Current attachment ID or null.
	 * @return string
	 */
	public function append_to_thumbnail_box( $content, $post_id, $thumbnail_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return $content;
		}

		$meta_box = $this;
		ob_start();
		require UNSPLASH_FI_PLUGIN_DIR . 'admin/views/meta-box.php';
		return $content . ob_get_clean();
	}

	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style(
			'unsplash-fi-meta-box',
			UNSPLASH_FI_PLUGIN_URL . 'assets/css/meta-box.css',
			array(),
			UNSPLASH_FI_VERSION
		);
		wp_enqueue_script(
			'unsplash-fi-meta-box',
			UNSPLASH_FI_PLUGIN_URL . 'assets/js/meta-box.js',
			array( 'jquery' ),
			UNSPLASH_FI_VERSION,
			true
		);
		global $post;
		$post_id         = $post ? absint( $post->ID ) : 0;
		$preferred_source = $post_id ? sanitize_key( get_post_meta( $post_id, '_fp_preferred_source', true ) ) : '';
		$keyword_mode    = get_option( 'unsplash_keyword_mode', 'title' );

		wp_localize_script(
			'unsplash-fi-meta-box',
			'unsplashMetaBox',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'unsplash_action_nonce' ),
				'postId'          => $post_id,
				'preferredSource' => $preferred_source,
				'keywordMode'     => esc_html( $keyword_mode ),
				'sources'         => array(
					array( 'slug' => '',         'label' => __( 'Auto', 'unsplash-featured-images' ) ),
					array( 'slug' => 'unsplash',  'label' => 'Unsplash' ),
					array( 'slug' => 'pexels',    'label' => 'Pexels' ),
					array( 'slug' => 'pixabay',   'label' => 'Pixabay' ),
				),
				'i18n'            => array(
					'finding'       => __( 'Finding image…', 'unsplash-featured-images' ),
					'success'       => __( 'Featured image set!', 'unsplash-featured-images' ),
					'error'         => __( 'Something went wrong. Please try again.', 'unsplash-featured-images' ),
					'loadingPreviews' => __( 'Loading previews…', 'unsplash-featured-images' ),
					'fetchPreviews' => __( 'Fetch Previews', 'unsplash-featured-images' ),
					'useThis'       => __( 'Use This', 'unsplash-featured-images' ),
					'noResults'     => __( 'No photos found. Try a different keyword.', 'unsplash-featured-images' ),
					'by'            => __( 'by', 'unsplash-featured-images' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Save handler
	// -------------------------------------------------------------------------

	public function save_meta_box( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['unsplash_meta_box_nonce_field'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['unsplash_meta_box_nonce_field'] ) ), 'unsplash_meta_box_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['unsplash_custom_keyword'] ) ) {
			$keyword = sanitize_text_field( wp_unslash( $_POST['unsplash_custom_keyword'] ) );
			$this->keyword_generator->set_custom_keyword( $post_id, $keyword );
			update_post_meta( $post_id, '_unsplash_last_keyword', $keyword );
		}

		if ( isset( $_POST['fp_preferred_source'] ) ) {
			$source = sanitize_key( wp_unslash( $_POST['fp_preferred_source'] ) );
			update_post_meta( $post_id, '_fp_preferred_source', $source );
		}

		$skip = isset( $_POST['unsplash_skip_auto'] ) ? 1 : 0;
		update_post_meta( $post_id, '_unsplash_skip_auto', $skip );
	}

	// -------------------------------------------------------------------------
	// Helpers used by the view
	// -------------------------------------------------------------------------

	public function get_post_keyword( $post_id ) {
		return $this->keyword_generator->get_custom_keyword( $post_id );
	}

	public function get_last_update_info( $post_id ) {
		$post_id = absint( $post_id );
		return array(
			'keyword' => get_post_meta( $post_id, '_unsplash_last_keyword', true ),
			'method'  => get_post_meta( $post_id, '_unsplash_assignment_method', true ),
			'photo'   => get_post_meta( $post_id, '_unsplash_photo_id', true ),
		);
	}
}
