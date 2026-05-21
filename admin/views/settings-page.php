<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Unauthorized', 'unsplash-featured-images' ) );
}
// $settings is Unsplash_Settings; $source_manager is Source_Manager — both injected from render_settings_page().

$all_status    = $source_manager->get_all_status();
$priority      = $source_manager->get_priority_order();
$keyword_mode  = get_option( 'unsplash_keyword_mode', 'title' );
$orientation   = get_option( 'unsplash_image_orientation', '' );
$content_filter = get_option( 'unsplash_image_content_filter', 'low' );

$source_labels = array(
	'unsplash' => 'Unsplash',
	'pexels'   => 'Pexels',
	'pixabay'  => 'Pixabay',
);
$source_descriptions = array(
	'unsplash' => __( '50 req/hr (demo) · Client-ID header', 'unsplash-featured-images' ),
	'pexels'   => __( '200 req/hr · Authorization header', 'unsplash-featured-images' ),
	'pixabay'  => __( '~5 000 req/hr · API key param', 'unsplash-featured-images' ),
);

if ( ! function_exists( 'fp_gauge_class' ) ) {
	function fp_gauge_class( $remaining, $total ) {
		if ( $total <= 0 ) {
			return 'fp-gauge--unknown';
		}
		$pct = $remaining / $total;
		if ( $pct >= 0.4 ) {
			return 'fp-gauge--ok';
		}
		if ( $pct >= 0.15 ) {
			return 'fp-gauge--warn';
		}
		return 'fp-gauge--critical';
	}
}
?>
<div class="wrap fp-settings">

	<h1 class="fp-page-title">
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="20" height="20" fill="currentColor" aria-hidden="true" class="fp-page-title__icon"><path d="M10 9V0h12v9H10zm12 5h10v18H0V14h10v9h12v-9z"/></svg>
		<?php esc_html_e( 'FeaturedPilot', 'unsplash-featured-images' ); ?>
		<span class="fp-version">v<?php echo esc_html( UNSPLASH_FI_VERSION ); ?></span>
	</h1>

	<?php settings_errors( 'fp_settings_sources' ); ?>
	<?php settings_errors( 'fp_settings_automation' ); ?>
	<?php settings_errors( 'fp_settings_images' ); ?>
	<?php settings_errors( 'fp_settings_logs' ); ?>

	<!-- Tab Navigation -->
	<nav class="fp-tab-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'unsplash-featured-images' ); ?>">
		<ul class="fp-tab-nav__list" role="tablist">
			<li role="presentation">
				<button id="fp-tab-btn-sources" role="tab" aria-selected="true" aria-controls="fp-tab-sources" class="fp-tab-nav__item fp-tab-nav__item--active" tabindex="0">
					<?php esc_html_e( 'Sources', 'unsplash-featured-images' ); ?>
				</button>
			</li>
			<li role="presentation">
				<button id="fp-tab-btn-automation" role="tab" aria-selected="false" aria-controls="fp-tab-automation" class="fp-tab-nav__item" tabindex="-1">
					<?php esc_html_e( 'Automation', 'unsplash-featured-images' ); ?>
				</button>
			</li>
			<li role="presentation">
				<button id="fp-tab-btn-images" role="tab" aria-selected="false" aria-controls="fp-tab-images" class="fp-tab-nav__item" tabindex="-1">
					<?php esc_html_e( 'Images', 'unsplash-featured-images' ); ?>
				</button>
			</li>
			<li role="presentation">
				<button id="fp-tab-btn-bulk" role="tab" aria-selected="false" aria-controls="fp-tab-bulk" class="fp-tab-nav__item" tabindex="-1">
					<?php esc_html_e( 'Bulk Run', 'unsplash-featured-images' ); ?>
				</button>
			</li>
			<li role="presentation">
				<button id="fp-tab-btn-logs" role="tab" aria-selected="false" aria-controls="fp-tab-logs" class="fp-tab-nav__item" tabindex="-1">
					<?php esc_html_e( 'Activity Log', 'unsplash-featured-images' ); ?>
				</button>
			</li>
		</ul>
	</nav>

	<!-- =====================================================================
	     TAB: SOURCES
	     ===================================================================== -->
	<div id="fp-tab-sources" role="tabpanel" aria-labelledby="fp-tab-btn-sources" class="fp-tab-panel fp-tab-panel--active">

		<form method="post" action="options.php" class="fp-form">
			<?php settings_fields( 'fp_settings_sources' ); ?>

			<!-- Source Priority -->
			<div class="fp-card">
				<h2 class="fp-card__title"><?php esc_html_e( 'Source Priority', 'unsplash-featured-images' ); ?></h2>
				<p class="fp-card__desc"><?php esc_html_e( 'Drag to reorder. The first connected source is used; others are automatic fallbacks.', 'unsplash-featured-images' ); ?></p>

				<ul id="fp-source-order" class="fp-source-order">
					<?php foreach ( $priority as $slug ) : ?>
					<li class="fp-source-order__item" data-source="<?php echo esc_attr( $slug ); ?>">
						<span class="fp-source-order__handle dashicons dashicons-menu" aria-hidden="true"></span>
						<span class="fp-source-order__name"><?php echo esc_html( $source_labels[ $slug ] ?? $slug ); ?></span>
						<?php if ( $all_status[ $slug ]['connected'] ?? false ) : ?>
							<span class="fp-source-order__badge fp-source-order__badge--connected"><?php esc_html_e( 'Connected', 'unsplash-featured-images' ); ?></span>
						<?php else : ?>
							<span class="fp-source-order__badge fp-source-order__badge--disconnected"><?php esc_html_e( 'Not configured', 'unsplash-featured-images' ); ?></span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>

				<input type="hidden"
					   name="unsplash_source_priority"
					   id="fp-source-priority-input"
					   value="<?php echo esc_attr( implode( ',', $priority ) ); ?>" />
			</div>

			<!-- Per-Source API Cards -->
			<?php foreach ( array( 'unsplash', 'pexels', 'pixabay' ) as $slug ) :
				$opt_key   = $slug . '_api_key';
				$raw_key   = get_option( $opt_key, '' );
				$masked    = $settings->mask_key( $raw_key );
				$status    = $all_status[ $slug ] ?? array( 'remaining' => 0, 'total' => 1, 'hits_today' => 0, 'connected' => false );
				$remaining = absint( $status['remaining'] );
				$total     = max( 1, absint( $status['total'] ) );
				$pct       = min( 100, (int) round( $remaining / $total * 100 ) );
				$gauge_cls = fp_gauge_class( $remaining, $total );
			?>
			<div class="fp-source-card" id="fp-source-card-<?php echo esc_attr( $slug ); ?>">
				<div class="fp-source-card__header">
					<h2 class="fp-source-card__title"><?php echo esc_html( $source_labels[ $slug ] ); ?></h2>
					<span class="fp-source-card__hint"><?php echo esc_html( $source_descriptions[ $slug ] ); ?></span>
				</div>

				<div class="fp-source-card__body">
					<div class="fp-source-card__key-row">
						<label for="<?php echo esc_attr( $opt_key ); ?>" class="fp-label">
							<?php esc_html_e( 'API Key', 'unsplash-featured-images' ); ?>
						</label>
						<div class="fp-source-card__key-wrap">
							<input type="password"
								   name="<?php echo esc_attr( $opt_key ); ?>"
								   id="<?php echo esc_attr( $opt_key ); ?>"
								   value="<?php echo esc_attr( $raw_key ); ?>"
								   class="regular-text"
								   autocomplete="new-password"
								   placeholder="<?php echo esc_attr( ! empty( $raw_key ) ? $masked : __( 'Paste your API key…', 'unsplash-featured-images' ) ); ?>" />
							<button type="button"
									class="button fp-test-btn"
									data-source="<?php echo esc_attr( $slug ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'unsplash_action_nonce' ) ); ?>">
								<?php esc_html_e( 'Test', 'unsplash-featured-images' ); ?>
							</button>
							<span class="fp-test-result" aria-live="polite"></span>
						</div>
					</div>

					<!-- Rate Gauge -->
					<div class="fp-gauge <?php echo esc_attr( $gauge_cls ); ?>"
						 data-source="<?php echo esc_attr( $slug ); ?>"
						 data-remaining="<?php echo esc_attr( $remaining ); ?>"
						 data-total="<?php echo esc_attr( $total ); ?>">
						<div class="fp-gauge__labels">
							<span class="fp-gauge__label"><?php esc_html_e( 'Requests', 'unsplash-featured-images' ); ?></span>
							<span class="fp-gauge__counts">
								<span class="fp-gauge__remaining"><?php echo esc_html( $remaining ); ?></span><?php
								echo ' / ';
								?><span class="fp-gauge__total"><?php echo esc_html( $total ); ?></span>
							</span>
						</div>
						<div class="fp-gauge__track">
							<div class="fp-gauge__fill"
								 style="width:<?php echo esc_attr( $pct ); ?>%"
								 role="progressbar"
								 aria-valuenow="<?php echo esc_attr( $remaining ); ?>"
								 aria-valuemin="0"
								 aria-valuemax="<?php echo esc_attr( $total ); ?>">
							</div>
						</div>
						<div class="fp-gauge__meta">
							<span class="fp-gauge__hits">
								<?php
								printf(
									/* translators: %d: number of rate-limit hits today */
									esc_html__( 'Hits today: %d', 'unsplash-featured-images' ),
									absint( $status['hits_today'] ?? 0 )
								);
								?>
							</span>
							<span class="fp-gauge__resets" data-resets="0"></span>
						</div>
					</div>
				</div>
			</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save Sources', 'unsplash-featured-images' ) ); ?>
		</form>
	</div><!-- #fp-tab-sources -->

	<!-- =====================================================================
	     TAB: AUTOMATION
	     ===================================================================== -->
	<div id="fp-tab-automation" role="tabpanel" aria-labelledby="fp-tab-btn-automation" class="fp-tab-panel" hidden>

		<form method="post" action="options.php" class="fp-form">
			<?php settings_fields( 'fp_settings_automation' ); ?>

			<!-- Keyword Mode -->
			<div class="fp-card">
				<h2 class="fp-card__title"><?php esc_html_e( 'Keyword Mode', 'unsplash-featured-images' ); ?></h2>
				<p class="fp-card__desc"><?php esc_html_e( 'How should the image search keyword be determined?', 'unsplash-featured-images' ); ?></p>

				<div class="fp-option-cards" role="radiogroup" aria-label="<?php esc_attr_e( 'Keyword mode', 'unsplash-featured-images' ); ?>">

					<label class="fp-option-card<?php echo 'title' === $keyword_mode ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_keyword_mode" value="title" <?php checked( 'title', $keyword_mode ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h10M4 18h7"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Post content', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__desc"><?php esc_html_e( 'Title → Category → Tag → Default keyword', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

					<label class="fp-option-card<?php echo 'keyword' === $keyword_mode ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_keyword_mode" value="keyword" <?php checked( 'keyword', $keyword_mode ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Global keyword only', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__desc"><?php esc_html_e( 'Always use the default keyword below, regardless of post title', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

					<label class="fp-option-card<?php echo 'combined' === $keyword_mode ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_keyword_mode" value="combined" <?php checked( 'combined', $keyword_mode ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Combined', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__desc"><?php esc_html_e( 'Global keyword + post title terms merged into one query', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

				</div>
			</div>

			<!-- Default Keyword -->
			<div class="fp-card">
				<h2 class="fp-card__title"><?php esc_html_e( 'Default Keyword', 'unsplash-featured-images' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="unsplash_default_keyword"><?php esc_html_e( 'Keyword', 'unsplash-featured-images' ); ?></label></th>
						<td>
							<input type="text" name="unsplash_default_keyword" id="unsplash_default_keyword"
								   value="<?php echo esc_attr( get_option( 'unsplash_default_keyword', 'nature' ) ); ?>"
								   class="regular-text" />
							<p class="description"><?php esc_html_e( 'Fallback used when no keyword can be derived from the post.', 'unsplash-featured-images' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Schedule -->
			<div class="fp-card">
				<h2 class="fp-card__title"><?php esc_html_e( 'Schedule', 'unsplash-featured-images' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="unsplash_schedule_enabled"><?php esc_html_e( 'Enable', 'unsplash-featured-images' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="unsplash_schedule_enabled" id="unsplash_schedule_enabled" value="1"
									   <?php checked( 1, get_option( 'unsplash_schedule_enabled', 0 ) ); ?> />
								<?php esc_html_e( 'Automatically assign featured images on a schedule', 'unsplash-featured-images' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="unsplash_schedule_frequency"><?php esc_html_e( 'Frequency', 'unsplash-featured-images' ); ?></label></th>
						<td>
							<select name="unsplash_schedule_frequency" id="unsplash_schedule_frequency">
								<option value="daily" <?php selected( 'daily', get_option( 'unsplash_schedule_frequency', 'daily' ) ); ?>><?php esc_html_e( 'Daily', 'unsplash-featured-images' ); ?></option>
								<option value="weekly" <?php selected( 'weekly', get_option( 'unsplash_schedule_frequency', 'daily' ) ); ?>><?php esc_html_e( 'Weekly', 'unsplash-featured-images' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="unsplash_schedule_target"><?php esc_html_e( 'Target Posts', 'unsplash-featured-images' ); ?></label></th>
						<td>
							<select name="unsplash_schedule_target" id="unsplash_schedule_target">
								<option value="no_featured_image" <?php selected( 'no_featured_image', get_option( 'unsplash_schedule_target', 'no_featured_image' ) ); ?>><?php esc_html_e( 'Posts without a featured image', 'unsplash-featured-images' ); ?></option>
								<option value="all_posts" <?php selected( 'all_posts', get_option( 'unsplash_schedule_target', 'no_featured_image' ) ); ?>><?php esc_html_e( 'All published posts', 'unsplash-featured-images' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( __( 'Save Automation', 'unsplash-featured-images' ) ); ?>
		</form>
	</div><!-- #fp-tab-automation -->

	<!-- =====================================================================
	     TAB: IMAGES
	     ===================================================================== -->
	<div id="fp-tab-images" role="tabpanel" aria-labelledby="fp-tab-btn-images" class="fp-tab-panel" hidden>

		<form method="post" action="options.php" class="fp-form">
			<?php settings_fields( 'fp_settings_images' ); ?>

			<!-- Orientation -->
			<div class="fp-card">
				<h2 class="fp-card__title"><?php esc_html_e( 'Orientation', 'unsplash-featured-images' ); ?></h2>
				<p class="fp-card__desc"><?php esc_html_e( 'Preferred image orientation for search results.', 'unsplash-featured-images' ); ?></p>

				<div class="fp-option-cards fp-option-cards--4" role="radiogroup" aria-label="<?php esc_attr_e( 'Orientation', 'unsplash-featured-images' ); ?>">

					<label class="fp-option-card<?php echo '' === $orientation ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_image_orientation" value="" <?php checked( '', $orientation ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Any', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

					<label class="fp-option-card<?php echo 'landscape' === $orientation ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_image_orientation" value="landscape" <?php checked( 'landscape', $orientation ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Landscape', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

					<label class="fp-option-card<?php echo 'portrait' === $orientation ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_image_orientation" value="portrait" <?php checked( 'portrait', $orientation ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="2" width="12" height="20" rx="2"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Portrait', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

					<label class="fp-option-card<?php echo 'squarish' === $orientation ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_image_orientation" value="squarish" <?php checked( 'squarish', $orientation ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Square', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

				</div>
			</div>

			<!-- Content Filter -->
			<div class="fp-card">
				<h2 class="fp-card__title"><?php esc_html_e( 'Content Filter', 'unsplash-featured-images' ); ?></h2>
				<p class="fp-card__desc"><?php esc_html_e( 'Controls how strict the safe-search filter is.', 'unsplash-featured-images' ); ?></p>

				<div class="fp-option-cards" role="radiogroup" aria-label="<?php esc_attr_e( 'Content filter', 'unsplash-featured-images' ); ?>">

					<label class="fp-option-card<?php echo 'low' === $content_filter ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_image_content_filter" value="low" <?php checked( 'low', $content_filter ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Standard', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__desc"><?php esc_html_e( 'Default safe-search behaviour', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

					<label class="fp-option-card<?php echo 'high' === $content_filter ? ' fp-option-card--selected' : ''; ?>">
						<input type="radio" name="unsplash_image_content_filter" value="high" <?php checked( 'high', $content_filter ); ?> />
						<span class="fp-option-card__icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
						</span>
						<span class="fp-option-card__title"><?php esc_html_e( 'Strict', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__desc"><?php esc_html_e( 'Family-safe only', 'unsplash-featured-images' ); ?></span>
						<span class="fp-option-card__check" aria-hidden="true">&#10003;</span>
					</label>

				</div>
			</div>

			<!-- Min Dimensions -->
			<div class="fp-card">
				<h2 class="fp-card__title"><?php esc_html_e( 'Minimum Dimensions', 'unsplash-featured-images' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="unsplash_image_min_width"><?php esc_html_e( 'Min Width (px)', 'unsplash-featured-images' ); ?></label></th>
						<td>
							<input type="number" name="unsplash_image_min_width" id="unsplash_image_min_width"
								   value="<?php echo esc_attr( absint( get_option( 'unsplash_image_min_width', 0 ) ) ); ?>"
								   min="0" class="small-text" /> px
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="unsplash_image_min_height"><?php esc_html_e( 'Min Height (px)', 'unsplash-featured-images' ); ?></label></th>
						<td>
							<input type="number" name="unsplash_image_min_height" id="unsplash_image_min_height"
								   value="<?php echo esc_attr( absint( get_option( 'unsplash_image_min_height', 0 ) ) ); ?>"
								   min="0" class="small-text" /> px
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( __( 'Save Image Settings', 'unsplash-featured-images' ) ); ?>
		</form>
	</div><!-- #fp-tab-images -->

	<!-- =====================================================================
	     TAB: BULK RUN
	     ===================================================================== -->
	<div id="fp-tab-bulk" role="tabpanel" aria-labelledby="fp-tab-btn-bulk" class="fp-tab-panel" hidden>

		<div class="fp-card">
			<h2 class="fp-card__title"><?php esc_html_e( 'Bulk Image Assignment', 'unsplash-featured-images' ); ?></h2>
			<p class="fp-card__desc"><?php esc_html_e( 'Find and assign featured images to multiple posts at once. The job pauses automatically if the rate limit is hit and resumes after the window resets.', 'unsplash-featured-images' ); ?></p>

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
						<div class="unsplash-bulk-run__bar" id="unsplash-bulk-bar" style="width:0%"
							 role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
					</div>
					<p id="unsplash-bulk-label" class="unsplash-bulk-run__label"></p>
				</div>
			</div>
		</div>
	</div><!-- #fp-tab-bulk -->

	<!-- =====================================================================
	     TAB: ACTIVITY LOG
	     ===================================================================== -->
	<div id="fp-tab-logs" role="tabpanel" aria-labelledby="fp-tab-btn-logs" class="fp-tab-panel" hidden>

		<div class="fp-card">
			<div class="fp-card__title-row">
				<h2 class="fp-card__title"><?php esc_html_e( 'Activity Log', 'unsplash-featured-images' ); ?></h2>
				<div class="fp-card__actions">
					<button type="button" id="fp-clear-logs" class="button button-secondary">
						<?php esc_html_e( 'Clear All Logs', 'unsplash-featured-images' ); ?>
					</button>
					<span id="fp-clear-logs-result" class="fp-inline-result" aria-live="polite"></span>
				</div>
			</div>
			<?php require UNSPLASH_FI_PLUGIN_DIR . 'admin/views/activity-log.php'; ?>
		</div>
	</div><!-- #fp-tab-logs -->

</div><!-- .fp-settings -->
