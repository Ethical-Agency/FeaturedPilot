/* global unsplashAdmin, jQuery */
(function ( $ ) {
	'use strict';

	$(function () {

		// ===================================================================
		// Tab Switching
		// ===================================================================
		var STORAGE_KEY = 'fp_active_tab';

		function activateTab( slug ) {
			$( '.fp-tab-nav__item' ).each(function () {
				var target = $( this ).attr( 'aria-controls' );
				var isThis = ( target === slug );
				$( this )
					.toggleClass( 'fp-tab-nav__item--active', isThis )
					.attr( 'aria-selected', isThis ? 'true' : 'false' )
					.attr( 'tabindex', isThis ? '0' : '-1' );
			});

			$( '.fp-tab-panel' ).each(function () {
				var isThis = ( this.id === slug );
				$( this ).toggleClass( 'fp-tab-panel--active', isThis );
				if ( isThis ) {
					$( this ).removeAttr( 'hidden' );
				} else {
					$( this ).attr( 'hidden', '' );
				}
			});

			try { localStorage.setItem( STORAGE_KEY, slug ); } catch ( e ) {}
		}

		function getDefaultTab() {
			var hash = location.hash.replace( '#', '' );
			if ( hash && $( '#' + hash ).length ) {
				return hash;
			}
			try {
				var saved = localStorage.getItem( STORAGE_KEY );
				if ( saved && $( '#' + saved ).length ) {
					return saved;
				}
			} catch ( e ) {}
			return 'fp-tab-sources';
		}

		// Activate initial tab.
		activateTab( getDefaultTab() );

		// Tab click handler.
		$( '.fp-tab-nav__item' ).on( 'click', function () {
			activateTab( $( this ).attr( 'aria-controls' ) );
		});

		// Hash navigation support.
		$( window ).on( 'hashchange', function () {
			var hash = location.hash.replace( '#', '' );
			if ( hash && $( '#' + hash ).length ) {
				activateTab( hash );
			}
		});

		// ===================================================================
		// Option Cards — JS fallback for browsers without :has() support
		// ===================================================================
		function syncOptionCards( $group ) {
			$group.find( 'input[type="radio"]' ).each(function () {
				$( this ).closest( '.fp-option-card' )
					.toggleClass( 'fp-option-card--selected', $( this ).is( ':checked' ) );
			});
		}

		// Initial sync.
		$( '.fp-option-cards' ).each(function () {
			syncOptionCards( $( this ) );
		});

		// On change.
		$( document ).on( 'change', '.fp-option-cards input[type="radio"]', function () {
			syncOptionCards( $( this ).closest( '.fp-option-cards' ) );
		});

		// ===================================================================
		// Source Priority Drag-to-Reorder (jQuery UI Sortable)
		// ===================================================================
		var $order = $( '#fp-source-order' );
		var $priorityInput = $( '#fp-source-priority-input' );

		if ( $order.length && $.fn.sortable ) {
			$order.sortable({
				handle:   '.fp-source-order__handle',
				axis:     'y',
				distance: 5,
				stop: function () {
					var slugs = [];
					$order.find( '.fp-source-order__item' ).each(function () {
						slugs.push( $( this ).data( 'source' ) );
					});
					$priorityInput.val( slugs.join( ',' ) );
				},
			});
		}

		// ===================================================================
		// Per-Source Test Connection Buttons
		// ===================================================================
		$( document ).on( 'click', '.fp-test-btn', function () {
			var $btn    = $( this );
			var source  = $btn.data( 'source' );
			var $result = $btn.closest( '.fp-source-card__key-wrap' ).find( '.fp-test-result' );
			var $keyInput = $btn.siblings( 'input[type="password"]' );
			var keyVal  = $keyInput.val().trim();

			$btn.prop( 'disabled', true );
			$result.text( unsplashAdmin.i18n.testing )
				   .removeClass( 'fp-test-result--ok fp-test-result--err' );

			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'fp_test_source',
					nonce:  unsplashAdmin.nonce,
					source: source,
					key:    keyVal,
				},
				success: function ( response ) {
					if ( response && response.success ) {
						$result.text( unsplashAdmin.i18n.testSuccess )
							   .addClass( 'fp-test-result--ok' );
					} else {
						var msg = ( response && response.data && response.data.message ) ? response.data.message : '';
						$result.text( unsplashAdmin.i18n.testFail + ( msg ? ' — ' + msg : '' ) )
							   .addClass( 'fp-test-result--err' );
					}
				},
				error: function () {
					$result.text( unsplashAdmin.i18n.testFail ).addClass( 'fp-test-result--err' );
				},
				complete: function () {
					$btn.prop( 'disabled', false );
				},
			});
		});

		// ===================================================================
		// Live Rate Gauges — poll every 60 s, update on response
		// ===================================================================
		function updateGauges( statusData ) {
			$.each( statusData, function ( slug, info ) {
				var $gauge = $( '.fp-gauge[data-source="' + slug + '"]' );
				if ( ! $gauge.length ) return;

				var remaining = parseInt( info.remaining, 10 ) || 0;
				var total     = parseInt( info.total, 10 )     || 1;
				var pct       = Math.min( 100, Math.round( remaining / total * 100 ) );

				// Width + aria.
				$gauge.find( '.fp-gauge__fill' )
					  .css( 'width', pct + '%' )
					  .attr( 'aria-valuenow', remaining )
					  .attr( 'aria-valuemax', total );

				$gauge.find( '.fp-gauge__remaining' ).text( remaining );
				$gauge.find( '.fp-gauge__total' ).text( total );
				$gauge.find( '.fp-gauge__hits' ).text( 'Hits today: ' + ( parseInt( info.hits_today, 10 ) || 0 ) );

				// Store next-reset timestamp for the countdown ticker.
				if ( info.next_reset_at ) {
					$gauge.data( 'fpNextReset', parseInt( info.next_reset_at, 10 ) );
				}

				// Badge on the order list item.
				var $badge = $( '.fp-source-order__item[data-source="' + slug + '"] .fp-source-order__badge' );
				if ( $badge.length ) {
					if ( info.connected ) {
						$badge.text( 'Connected' )
							  .removeClass( 'fp-source-order__badge--disconnected' )
							  .addClass( 'fp-source-order__badge--connected' );
					} else {
						$badge.text( 'Not configured' )
							  .removeClass( 'fp-source-order__badge--connected' )
							  .addClass( 'fp-source-order__badge--disconnected' );
					}
				}

				// Colour class.
				$gauge.removeClass( 'fp-gauge--ok fp-gauge--warn fp-gauge--critical fp-gauge--unknown' );
				if ( total <= 0 ) {
					$gauge.addClass( 'fp-gauge--unknown' );
				} else if ( pct >= 40 ) {
					$gauge.addClass( 'fp-gauge--ok' );
				} else if ( pct >= 15 ) {
					$gauge.addClass( 'fp-gauge--warn' );
				} else {
					$gauge.addClass( 'fp-gauge--critical' );
				}
			});
		}

		// Tick the reset countdown on every gauge once per second.
		function tickResetCountdowns() {
			var now = Math.floor( Date.now() / 1000 );
			$( '.fp-gauge' ).each(function () {
				var nextReset = parseInt( $( this ).data( 'fpNextReset' ), 10 ) || 0;
				var $span     = $( this ).find( '.fp-gauge__resets' );
				if ( nextReset <= 0 ) {
					$span.text( '' );
					return;
				}
				var secs = nextReset - now;
				if ( secs <= 0 ) {
					$span.text( unsplashAdmin.i18n.resetsNow );
				} else {
					var m = Math.floor( secs / 60 );
					var s = secs % 60;
					$span.text(
						unsplashAdmin.i18n.resetsIn
							.replace( '{m}', m )
							.replace( '{s}', s < 10 ? '0' + s : String( s ) )
					);
				}
			});
		}

		// Seed initial next-reset values from PHP-rendered data attributes.
		$( '.fp-gauge[data-fp-next-reset]' ).each(function () {
			var v = parseInt( $( this ).attr( 'data-fp-next-reset' ), 10 );
			if ( v > 0 ) {
				$( this ).data( 'fpNextReset', v );
			}
		});

		// Apply initial values from inline data.
		if ( unsplashAdmin.rateStatus ) {
			updateGauges( unsplashAdmin.rateStatus );
		}

		// Start the shared per-second countdown ticker.
		tickResetCountdowns();
		setInterval( tickResetCountdowns, 1000 );

		// Poll for fresh data every 60 s.
		setInterval(function () {
			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data:   { action: 'fp_rate_limit_status', nonce: unsplashAdmin.nonce },
				success: function ( response ) {
					if ( response && response.success && response.data ) {
						updateGauges( response.data );
					}
				},
			});
		}, 60000 );

		// ===================================================================
		// Clear Logs
		// ===================================================================
		$( '#fp-clear-logs' ).on( 'click', function () {
			if ( ! window.confirm( unsplashAdmin.i18n.clearLogsConfirm ) ) return;

			var $btn    = $( this );
			var $result = $( '#fp-clear-logs-result' );

			$btn.prop( 'disabled', true );
			$result.text( '' ).removeClass( 'fp-inline-result--success fp-inline-result--error' );

			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data:   { action: 'fp_clear_logs', nonce: unsplashAdmin.nonce },
				success: function ( response ) {
					if ( response && response.success ) {
						$result.text( unsplashAdmin.i18n.clearLogsOk ).addClass( 'fp-inline-result--success' );
						// Empty the table body, replace with "no logs" message.
						var $logWrap = $( '.unsplash-activity-log' );
						if ( $logWrap.length ) {
							$logWrap.find( 'table' ).remove();
							if ( ! $logWrap.find( 'p' ).length ) {
								$logWrap.append( '<p>' + unsplashAdmin.i18n.clearLogsOk + '</p>' );
							}
						}
					} else {
						$result.text( 'Error.' ).addClass( 'fp-inline-result--error' );
					}
				},
				error: function () {
					$result.text( 'Error.' ).addClass( 'fp-inline-result--error' );
				},
				complete: function () {
					$btn.prop( 'disabled', false );
				},
			});
		});

		// ===================================================================
		// Bulk Run (unchanged logic, just living in its tab now)
		// ===================================================================
		var $startBtn      = $( '#unsplash-bulk-start' );
		var $cancelBtn     = $( '#unsplash-bulk-cancel' );
		var $bulkStatus    = $( '#unsplash-bulk-status' );
		var $bar           = $( '#unsplash-bulk-bar' );
		var $label         = $( '#unsplash-bulk-label' );
		var $replace       = $( '#unsplash-bulk-replace' );

		var bulkActive     = false;
		var pollTimer      = null;
		var countdownTimer = null;

		// Restore any running/paused job on page load.
		fetchBulkStatus( function ( data ) {
			if ( data && data.status && 'idle' !== data.status ) {
				applyBulkStatus( data );
				if ( 'paused' === data.status ) {
					startPoll( 30000 );
				}
			}
		});

		$startBtn.on( 'click', function () {
			if ( bulkActive ) return;
			bulkActive = true;
			stopPoll();
			$startBtn.prop( 'disabled', true );
			$cancelBtn.show().prop( 'disabled', false );
			$bulkStatus.show();
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
						resetBulkUI();
						return;
					}
					applyBulkStatus( response.data );
					if ( 'running' === response.data.status ) {
						runNextBatch();
					}
				},
				error: function () {
					setLabel( unsplashAdmin.i18n.bulkError );
					bulkActive = false;
					resetBulkUI();
				},
			});
		});

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
					resetBulkUI();
				},
			});
		});

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
						resetBulkUI();
						return;
					}
					applyBulkStatus( response.data );
					if ( 'running' === response.data.status ) {
						runNextBatch();
					}
				},
				error: function () {
					if ( ! bulkActive ) return;
					setLabel( unsplashAdmin.i18n.bulkError );
					bulkActive = false;
					resetBulkUI();
				},
			});
		}

		function applyBulkStatus( data ) {
			var s    = data.status || 'idle';
			var done = ( data.processed || 0 ) + ( data.errors || 0 );
			var tot  = data.total || 0;
			var pct  = tot > 0 ? Math.min( 100, Math.round( done / tot * 100 ) ) : 0;

			setBar( pct );
			$bulkStatus.show();

			if ( 'complete' === s ) {
				bulkActive = false;
				stopPoll();
				setBar( 100 );
				setLabel( unsplashAdmin.i18n.bulkDone + ' ' + summaryText( data ) );
				resetBulkUI();
			} else if ( 'cancelled' === s ) {
				bulkActive = false;
				stopPoll();
				setLabel( unsplashAdmin.i18n.bulkCancelled + ' ' + summaryText( data ) );
				resetBulkUI();
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

		function startCountdown( nextRunAt ) {
			if ( countdownTimer ) { clearInterval( countdownTimer ); countdownTimer = null; }
			function tick() {
				var secs = Math.max( 0, nextRunAt - Math.floor( Date.now() / 1000 ) );
				var m = Math.floor( secs / 60 );
				var s = secs % 60;
				setLabel( unsplashAdmin.i18n.bulkPaused
					.replace( '{m}', m )
					.replace( '{s}', s < 10 ? '0' + s : String( s ) ) );
				if ( 0 === secs ) {
					clearInterval( countdownTimer );
					countdownTimer = null;
					setLabel( unsplashAdmin.i18n.bulkResuming );
					stopPoll();
					startPoll( 10000 );
				}
			}
			tick();
			countdownTimer = setInterval( tick, 1000 );
		}

		function startPoll( ms ) {
			stopPoll();
			pollTimer = setInterval(function () {
				fetchBulkStatus(function ( data ) {
					if ( ! data ) return;
					applyBulkStatus( data );
					if ( 'complete' === data.status || 'cancelled' === data.status ) {
						stopPoll();
					}
				});
			}, ms );
		}

		function stopPoll() {
			if ( pollTimer )      { clearInterval( pollTimer );      pollTimer      = null; }
			if ( countdownTimer ) { clearInterval( countdownTimer ); countdownTimer = null; }
		}

		function fetchBulkStatus( callback ) {
			$.ajax({
				url:    unsplashAdmin.ajaxUrl,
				method: 'POST',
				data:   { action: 'unsplash_bulk_status', nonce: unsplashAdmin.nonce },
				success: function ( response ) {
					callback( ( response && response.success ) ? response.data : null );
				},
			});
		}

		function resetBulkUI() {
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
