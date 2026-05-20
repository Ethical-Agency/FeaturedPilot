<?php
/**
 * Fired when the plugin is deleted via Plugins > Delete.
 * Removes all options, post meta, transients, and cron events.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ---------------------------------------------------------------
// Options
// ---------------------------------------------------------------
$options = array(
	// Unsplash
	'unsplash_api_key',
	'unsplash_rate_limit_remaining',
	'unsplash_rate_limit_total',
	'unsplash_rate_limit_reset',
	'unsplash_orientation',
	'unsplash_content_filter',
	'unsplash_default_keyword',
	'unsplash_keyword_mode',
	'unsplash_schedule_frequency',
	'unsplash_post_types',
	'unsplash_min_width',
	'unsplash_min_height',
	'unsplash_source_priority',
	'unsplash_bulk_queue',
	'unsplash_bulk_job',
	// Pexels
	'pexels_api_key',
	'pexels_rate_limit_remaining',
	'pexels_rate_limit_total',
	// Pixabay
	'pixabay_api_key',
	'pixabay_rate_limit_remaining',
	'pixabay_rate_limit_total',
	// DB version
	'unsplash_fi_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ---------------------------------------------------------------
// Transients
// ---------------------------------------------------------------
$transients = array(
	'fp_rate_hits_unsplash',
	'fp_rate_hits_pexels',
	'fp_rate_hits_pixabay',
);

foreach ( $transients as $transient ) {
	delete_transient( $transient );
}

// ---------------------------------------------------------------
// Cron events
// ---------------------------------------------------------------
$timestamp = wp_next_scheduled( 'unsplash_fi_scheduled_run' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'unsplash_fi_scheduled_run' );
}
wp_clear_scheduled_hook( 'unsplash_fi_scheduled_run' );

// ---------------------------------------------------------------
// Post meta — runs in batches to avoid memory exhaustion on large sites
// ---------------------------------------------------------------
global $wpdb;

$meta_keys = array(
	'_unsplash_photo_id',
	'_unsplash_photo_url',
	'_unsplash_photo_source',
	'_unsplash_photographer',
	'_unsplash_photographer_url',
	'_unsplash_keyword_used',
	'_unsplash_last_updated',
	'_unsplash_last_method',
	'_unsplash_skip_auto',
	'_fp_preferred_source',
	'_fp_photo_source',
);

foreach ( $meta_keys as $meta_key ) {
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => $meta_key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		array( '%s' )
	);
}

// ---------------------------------------------------------------
// Activity log option (stored as a serialized option)
// ---------------------------------------------------------------
delete_option( 'unsplash_activity_log' );
