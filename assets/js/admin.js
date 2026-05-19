/* global unsplashAdmin, jQuery */
(function ( $ ) {
	'use strict';

	$(function () {

		// ---------------------------------------------------------------
		// API connection test
		// ---------------------------------------------------------------
		$( '#unsplash-test-api' ).on( 'click', function () {
			var $btn    = $( this );
			var $result = $( '#unsplash-api-test-result' );

			$btn.prop( 'disabled', true );
			$result
				.text( unsplashAdmin.i18n.testing )
				.removeClass( 'unsplash-inline-result--success unsplash-inline-result--error' );

			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action:  'unsplash_update_image',
					nonce:   unsplashAdmin.nonce,
					post_id: 0,
					dry_run: 1,
				},
				success: function ( response ) {
					if ( response && response.success ) {
						$result.text( unsplashAdmin.i18n.testSuccess )
							   .addClass( 'unsplash-inline-result--success' );
					} else {
						var msg = ( response && response.data && response.data.message ) ? response.data.message : '';
						$result.text( unsplashAdmin.i18n.testFail + ( msg ? ' ' + msg : '' ) )
							   .addClass( 'unsplash-inline-result--error' );
					}
				},
				error: function () {
					$result.text( unsplashAdmin.i18n.testFail )
						   .addClass( 'unsplash-inline-result--error' );
				},
				complete: function () {
					$btn.prop( 'disabled', false );
				}
			});
		});

		// ---------------------------------------------------------------
		// Bulk Run — persistent queue, hourly auto-resume via WP-Cron
		// ---------------------------------------------------------------
		var $startBtn      = $( '#unsplash-bulk-start' );
		var $cancelBtn     = $( '#unsplash-bulk-cancel' );
		var $status        = $( '#unsplash-bulk-status' );
		var $bar           = $( '#unsplash-bulk-bar' );
		var $label         = $( '#unsplash-bulk-label' );
		var $replace       = $( '#unsplash-bulk-replace' );

		var bulkActive     = false;  // true while we are driving AJAX batches
		var pollTimer      = null;
		var countdownTimer = null;

		// On page load restore any job that was already running/paused.
		fetchStatus( function ( data ) {
			if ( data && data.status && 'idle' !== data.status ) {
				applyStatus( data );
				if ( 'paused' === data.status ) {
					startPoll( 30000 );
				}
			}
		} );

		// ------- Run Now -------
		$startBtn.on( 'click', function () {
			if ( bulkActive ) return;
			bulkActive = true;
			stopPoll();
			$startBtn.prop( 'disabled', true );
			$cancelBtn.show().prop( 'disabled', false );
			$status.show();
			setBar( 0 );
			setLabel( unsplashAdmin.i18n.bulkStarting );

			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action:           'unsplash_bulk_init',
					nonce:            unsplashAdmin.nonce,
					replace_existing: $replace.is( ':checked' ) ? 1 : 0,
				},
				success: function ( response ) {
					if ( ! response || ! response.success || ! response.data ) {
						setLabel( unsplashAdmin.i18n.bulkError );
						bulkActive = false;
						resetUI();
						return;
					}
					applyStatus( response.data );
					if ( 'running' === response.data.status ) {
						runNextBatch();
					}
				},
				error: function () {
					setLabel( unsplashAdmin.i18n.bulkError );
					bulkActive = false;
					resetUI();
				}
			});
		} );

		// ------- Cancel -------
		$cancelBtn.on( 'click', function () {
			bulkActive = false;
			stopPoll();
			$cancelBtn.prop( 'disabled', true );
			setLabel( unsplashAdmin.i18n.bulkCancelling );

			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data:   { action: 'unsplash_bulk_cancel', nonce: unsplashAdmin.nonce },
				complete: function () {
					setLabel( unsplashAdmin.i18n.bulkCancelled );
					setBar( 0 );
					resetUI();
				}
			});
		} );

		// ------- Core batch loop (JS-driven) -------
		function runNextBatch() {
			if ( ! bulkActive ) return;

			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data:   { action: 'unsplash_bulk_process', nonce: unsplashAdmin.nonce },
				success: function ( response ) {
					if ( ! bulkActive ) return;
					if ( ! response || ! response.success || ! response.data ) {
						setLabel( unsplashAdmin.i18n.bulkError );
						bulkActive = false;
						resetUI();
						return;
					}
					applyStatus( response.data );
					if ( 'running' === response.data.status ) {
						runNextBatch(); // chain immediately until paused or complete
					}
				},
				error: function () {
					if ( ! bulkActive ) return;
					setLabel( unsplashAdmin.i18n.bulkError );
					bulkActive = false;
					resetUI();
				}
			});
		}

		// ------- Apply status to UI -------
		function applyStatus( data ) {
			var s     = data.status || 'idle';
			var done  = ( data.processed || 0 ) + ( data.errors || 0 );
			var total = data.total || 0;
			var pct   = total > 0 ? Math.min( 100, Math.round( done / total * 100 ) ) : 0;

			setBar( pct );
			$status.show();

			if ( 'complete' === s ) {
				bulkActive = false;
				stopPoll();
				setBar( 100 );
				setLabel( unsplashAdmin.i18n.bulkDone + ' ' + summaryText( data ) );
				resetUI();

			} else if ( 'cancelled' === s ) {
				bulkActive = false;
				stopPoll();
				setLabel( unsplashAdmin.i18n.bulkCancelled + ' ' + summaryText( data ) );
				resetUI();

			} else if ( 'paused' === s ) {
				bulkActive = false;
				$startBtn.prop( 'disabled', true );
				$cancelBtn.show().prop( 'disabled', false );
				startCountdown( data.next_run_at || 0 );
				startPoll( 30000 );

			} else if ( 'running' === s ) {
				$startBtn.prop( 'disabled', true );
				$cancelBtn.show().prop( 'disabled', false );
				setLabel( progressText( data ) );
			}
		}

		// ------- Countdown while paused -------
		function startCountdown( nextRunAt ) {
			if ( countdownTimer ) {
				clearInterval( countdownTimer );
				countdownTimer = null;
			}

			function tick() {
				var secs = Math.max( 0, nextRunAt - Math.floor( Date.now() / 1000 ) );
				var m    = Math.floor( secs / 60 );
				var s    = secs % 60;
				setLabel( unsplashAdmin.i18n.bulkPaused
					.replace( '{m}', m )
					.replace( '{s}', s < 10 ? '0' + s : String( s ) ) );

				if ( 0 === secs ) {
					clearInterval( countdownTimer );
					countdownTimer = null;
					setLabel( unsplashAdmin.i18n.bulkResuming );
					// Poll more often once the window should have reset.
					stopPoll();
					startPoll( 10000 );
				}
			}

			tick();
			countdownTimer = setInterval( tick, 1000 );
		}

		// ------- Polling (used while paused) -------
		function startPoll( ms ) {
			stopPoll();
			pollTimer = setInterval( function () {
				fetchStatus( function ( data ) {
					if ( ! data ) return;
					applyStatus( data );
					if ( 'complete' === data.status || 'cancelled' === data.status ) {
						stopPoll();
					}
				} );
			}, ms );
		}

		function stopPoll() {
			if ( pollTimer )      { clearInterval( pollTimer );      pollTimer      = null; }
			if ( countdownTimer ) { clearInterval( countdownTimer ); countdownTimer = null; }
		}

		function fetchStatus( callback ) {
			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data:   { action: 'unsplash_bulk_status', nonce: unsplashAdmin.nonce },
				success: function ( response ) {
					callback( ( response && response.success ) ? response.data : null );
				}
			});
		}

		// ------- UI helpers -------
		function resetUI() {
			$startBtn.prop( 'disabled', false );
			$cancelBtn.hide().prop( 'disabled', false );
		}

		function setBar( pct ) {
			$bar.css( 'width', pct + '%' ).attr( 'aria-valuenow', pct );
		}

		function setLabel( text ) {
			$label.text( text );
		}

		function progressText( data ) {
			return unsplashAdmin.i18n.bulkProgress
				.replace( '{processed}', data.processed || 0 )
				.replace( '{total}',     data.total     || 0 )
				.replace( '{errors}',    data.errors    || 0 );
		}

		function summaryText( data ) {
			return unsplashAdmin.i18n.bulkSummary
				.replace( '{processed}', data.processed || 0 )
				.replace( '{errors}',    data.errors    || 0 );
		}

	});

}( jQuery ));
