<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Unauthorized', 'unsplash-featured-images' ) );
}
// $settings is the Unsplash_Settings instance passed from render_settings_page().
?>
<div class="wrap unsplash-settings">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'unsplash_settings' ); ?>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'unsplash_settings' );
		do_settings_sections( 'unsplash-featured-images' );
		submit_button( __( 'Save Settings', 'unsplash-featured-images' ) );
		?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'API Rate Limit', 'unsplash-featured-images' ); ?></h2>
	<p><?php $settings->render_rate_limit_status(); ?></p>

	<hr />

	<h2><?php esc_html_e( 'Bulk Run', 'unsplash-featured-images' ); ?></h2>
	<p><?php esc_html_e( 'Find and assign Unsplash images to multiple posts at once.', 'unsplash-featured-images' ); ?></p>

	<div class="unsplash-bulk-run" id="unsplash-bulk-run">

		<p>
			<label>
				<input type="checkbox" id="unsplash-bulk-replace" value="1" />
				<?php esc_html_e( 'Replace existing featured images', 'unsplash-featured-images' ); ?>
			</label>
		</p>

		<p>
			<button type="button" id="unsplash-bulk-start" class="button button-primary">
				<?php esc_html_e( 'Run Now', 'unsplash-featured-images' ); ?>
			</button>
			<button type="button" id="unsplash-bulk-cancel" class="button" style="display:none">
				<?php esc_html_e( 'Cancel', 'unsplash-featured-images' ); ?>
			</button>
		</p>

		<div id="unsplash-bulk-status" class="unsplash-bulk-run__status" style="display:none">
			<div class="unsplash-bulk-run__bar-wrap">
				<div class="unsplash-bulk-run__bar" id="unsplash-bulk-bar" style="width:0%" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
			</div>
			<p id="unsplash-bulk-label" class="unsplash-bulk-run__label"></p>
		</div>

	</div>

	<hr />

	<h2><?php esc_html_e( 'Activity Log', 'unsplash-featured-images' ); ?></h2>
	<?php require UNSPLASH_FI_PLUGIN_DIR . 'admin/views/activity-log.php'; ?>
</div>
