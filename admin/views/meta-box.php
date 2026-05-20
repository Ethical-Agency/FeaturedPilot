<?php
/**
 * Appended inside the native Featured Image meta box.
 * Available variables: $post_id (int), $meta_box (Unsplash_Meta_Box)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$keyword          = $meta_box->get_post_keyword( $post_id );
$last_info        = $meta_box->get_last_update_info( $post_id );
$skip_auto        = (bool) get_post_meta( $post_id, '_unsplash_skip_auto', true );
$preferred_source = sanitize_key( get_post_meta( $post_id, '_fp_preferred_source', true ) );
$keyword_mode     = get_option( 'unsplash_keyword_mode', 'title' );

$placeholder_map = array(
	'title'    => __( 'auto-detected from title (leave blank)', 'unsplash-featured-images' ),
	'keyword'  => __( 'override global keyword (leave blank to use default)', 'unsplash-featured-images' ),
	'combined' => __( 'override global + title keyword (leave blank to combine)', 'unsplash-featured-images' ),
);
$placeholder = $placeholder_map[ $keyword_mode ] ?? $placeholder_map['title'];

wp_nonce_field( 'unsplash_meta_box_nonce', 'unsplash_meta_box_nonce_field' );
?>
<div class="unsplash-fi" id="unsplash-fi-<?php echo esc_attr( $post_id ); ?>">

	<hr class="unsplash-fi__divider" />

	<p class="unsplash-fi__heading">
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="14" height="14" fill="currentColor" aria-hidden="true" style="vertical-align:middle;margin-right:4px"><path d="M10 9V0h12v9H10zm12 5h10v18H0V14h10v9h12v-9z"/></svg>
		<?php esc_html_e( 'FeaturedPilot', 'unsplash-featured-images' ); ?>
	</p>

	<input type="text"
		   name="unsplash_custom_keyword"
		   id="unsplash-keyword-<?php echo esc_attr( $post_id ); ?>"
		   value="<?php echo esc_attr( $keyword ); ?>"
		   placeholder="<?php echo esc_attr( $placeholder ); ?>"
		   class="widefat unsplash-fi__keyword" />

	<!-- Source selector -->
	<div class="unsplash-fi__source-pills" role="group" aria-label="<?php esc_attr_e( 'Image source', 'unsplash-featured-images' ); ?>">
		<?php
		$sources = array(
			''         => __( 'Auto', 'unsplash-featured-images' ),
			'unsplash' => 'Unsplash',
			'pexels'   => 'Pexels',
			'pixabay'  => 'Pixabay',
		);
		foreach ( $sources as $slug => $label ) :
			$active = ( $slug === $preferred_source ) ? ' fp-pill--active' : '';
		?>
		<button type="button"
				class="fp-pill<?php echo esc_attr( $active ); ?>"
				data-source="<?php echo esc_attr( $slug ); ?>"
				aria-pressed="<?php echo $slug === $preferred_source ? 'true' : 'false'; ?>">
			<?php echo esc_html( $label ); ?>
		</button>
		<?php endforeach; ?>
		<input type="hidden" name="fp_preferred_source" id="fp-preferred-source" value="<?php echo esc_attr( $preferred_source ); ?>" />
	</div>

	<!-- Fetch Previews button -->
	<div class="unsplash-fi__actions">
		<button type="button"
				class="button unsplash-fi__btn"
				id="unsplash-fetch-previews"
				data-post-id="<?php echo esc_attr( $post_id ); ?>">
			<?php esc_html_e( 'Fetch Previews', 'unsplash-featured-images' ); ?>
		</button>
		<span class="spinner unsplash-fi__spinner"></span>
	</div>

	<!-- Preview grid (populated by JS) -->
	<div id="unsplash-preview-grid"
		 class="unsplash-fi__preview-grid"
		 aria-live="polite"
		 aria-label="<?php esc_attr_e( 'Photo preview grid', 'unsplash-featured-images' ); ?>">
	</div>

	<!-- Status message -->
	<div id="unsplash-status"
		 class="unsplash-fi__status"
		 role="status"
		 aria-live="polite"
		 style="display:none"></div>

	<?php if ( ! empty( $last_info['keyword'] ) ) : ?>
	<p class="unsplash-fi__meta">
		<?php
		printf(
			/* translators: 1: keyword used, 2: method (manual/scheduled/bulk) */
			esc_html__( 'Last set via %2$s — "%1$s"', 'unsplash-featured-images' ),
			esc_html( $last_info['keyword'] ),
			esc_html( $last_info['method'] ?: 'manual' )
		);
		?>
	</p>
	<?php endif; ?>

	<label class="unsplash-fi__skip">
		<input type="checkbox"
			   name="unsplash_skip_auto"
			   value="1"
			   <?php checked( $skip_auto ); ?> />
		<?php esc_html_e( 'Skip automatic updates', 'unsplash-featured-images' ); ?>
	</label>

</div>
