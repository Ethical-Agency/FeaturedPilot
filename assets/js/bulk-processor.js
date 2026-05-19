/* global unsplashBulk, jQuery */
(function ( $ ) {
	'use strict';

	/**
	 * Loaded on the Posts list page when the bulk-process action has been
	 * triggered programmatically (e.g., from a future "Run Now" button).
	 *
	 * The current bulk action flow uses standard WP form submission (handled
	 * by class-actions.php) and shows progress via an admin notice on redirect.
	 *
	 * This file is reserved for an async progress-bar experience and will be
	 * extended in Phase 3 UX polish.
	 */

	$(function () {

		// Guard: only run if the bulk progress container exists on the page.
		var $container = $( '#unsplash-bulk-progress' );
		if ( ! $container.length || typeof unsplashBulk === 'undefined' ) {
			return;
		}

		var $bar        = $container.find( '.unsplash-bulk__bar' );
		var $label      = $container.find( '.unsplash-bulk__label' );
		var $cancelBtn  = $container.find( '#unsplash-bulk-cancel' );
		var postIds     = unsplashBulk.postIds || [];
		var total       = postIds.length;
		var batchSize   = 5;
		var currentIdx  = 0;
		var processed   = 0;
		var errors      = 0;
		var cancelled   = false;

		if ( ! total ) {
			return;
		}

		$cancelBtn.on( 'click', function () {
			cancelled = true;
			$( this ).prop( 'disabled', true );
			updateLabel( 'Cancelling…' );
		} );

		processBatch();

		function processBatch() {
			if ( cancelled || currentIdx >= total ) {
				finish();
				return;
			}

			var batch = postIds.slice( currentIdx, currentIdx + batchSize );
			currentIdx += batchSize;

			$.ajax({
				url:    unsplashBulk.ajaxUrl,
				method: 'POST',
				data: {
					action:           'unsplash_bulk_process',
					nonce:            unsplashBulk.nonce,
					post_ids:         batch,
					replace_existing: unsplashBulk.replaceExisting ? 1 : 0,
				},
				success: function ( response ) {
					if ( response.success ) {
						processed += ( response.data.processed || 0 );
						errors    += ( response.data.errors || 0 );
					}
					updateProgress();
				},
				error: function () {
					errors += batch.length;
					updateProgress();
				},
				complete: function () {
					processBatch();
				}
			});
		}

		function updateProgress() {
			var pct = total > 0 ? Math.round( ( currentIdx / total ) * 100 ) : 0;
			pct = Math.min( pct, 100 );
			$bar.css( 'width', pct + '%' ).attr( 'aria-valuenow', pct );
			updateLabel( processed + ' / ' + total + ' processed, ' + errors + ' error(s)' );
		}

		function updateLabel( text ) {
			$label.text( text );
		}

		function finish() {
			updateProgress();
			$bar.css( 'width', '100%' );
			updateLabel( 'Done: ' + processed + ' processed, ' + errors + ' error(s).' );
			$cancelBtn.hide();
		}

	});

}( jQuery ));
