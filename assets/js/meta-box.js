/* global unsplashMetaBox, jQuery */
(function ( $ ) {
	'use strict';

	$(function () {

		var postId   = unsplashMetaBox.postId;
		var $fi      = $( '#unsplash-fi-' + postId );

		if ( ! $fi.length ) return;

		var $keyword    = $fi.find( '.unsplash-fi__keyword' );
		var $fetchBtn   = $fi.find( '#unsplash-fetch-previews' );
		var $spinner    = $fi.find( '.unsplash-fi__spinner' );
		var $status     = $fi.find( '#unsplash-status' );
		var $grid       = $fi.find( '#unsplash-preview-grid' );
		var $sourcePref = $fi.find( '#fp-preferred-source' );

		// ---------------------------------------------------------------
		// Source pill selector
		// ---------------------------------------------------------------
		$fi.on( 'click', '.fp-pill', function () {
			var $pill   = $( this );
			var source  = $pill.data( 'source' );

			$fi.find( '.fp-pill' ).each(function () {
				$( this ).removeClass( 'fp-pill--active' ).attr( 'aria-pressed', 'false' );
			});

			$pill.addClass( 'fp-pill--active' ).attr( 'aria-pressed', 'true' );
			$sourcePref.val( source );
		});

		// ---------------------------------------------------------------
		// Fetch Previews
		// ---------------------------------------------------------------
		$fetchBtn.on( 'click', function () {
			var keyword = $keyword.val().trim();
			var source  = $sourcePref.val();

			setRunning( true );
			showStatus( unsplashMetaBox.i18n.loadingPreviews || 'Loading previews…', 'info' );
			$grid.empty();

			$.ajax({
				url:    unsplashMetaBox.ajaxUrl,
				method: 'POST',
				data: {
					action:   'unsplash_search_preview',
					nonce:    unsplashMetaBox.nonce,
					post_id:  postId,
					keyword:  keyword,
					source:   source,
					per_page: 3,
				},
				success: function ( response ) {
					if ( response.success && response.data && response.data.photos && response.data.photos.length ) {
						renderGrid( response.data.photos );
						showStatus( '', '' );
					} else {
						var msg = ( response.data && response.data.message ) ? response.data.message : ( unsplashMetaBox.i18n.noResults || 'No results found.' );
						showStatus( msg, 'error' );
					}
				},
				error: function () {
					showStatus( unsplashMetaBox.i18n.error || 'An error occurred.', 'error' );
				},
				complete: function () {
					setRunning( false );
				}
			});
		});

		// ---------------------------------------------------------------
		// Render preview grid
		// ---------------------------------------------------------------
		function renderGrid( photos ) {
			$grid.empty();

			$.each( photos, function ( i, photo ) {
				var thumbUrl    = escAttr( photo.urls && photo.urls.thumb ? photo.urls.thumb : '' );
				var photoUrl    = escAttr( photo.links && photo.links.html ? photo.links.html : '#' );
				var userName    = photo.user && photo.user.name ? photo.user.name : '';
				var userUrl     = photo.user && photo.user.links && photo.user.links.html ? photo.user.links.html : '#';
				var source      = photo.source || 'unsplash';
				var photoId     = photo.id || '';
				var altText     = photo.alt_description ? escAttr( photo.alt_description ) : '';
				var badgeLabel  = source.charAt(0).toUpperCase() + source.slice(1);
				var byLabel     = unsplashMetaBox.i18n.by || 'by';
				var useLabel    = unsplashMetaBox.i18n.useThis || 'Use This';

				var $card = $(
					'<div class="fp-preview-card" data-photo-id="' + escAttr( photoId ) + '" data-source="' + escAttr( source ) + '">' +
						'<a href="' + escAttr( photoUrl ) + '" target="_blank" rel="noopener noreferrer">' +
							'<img src="' + thumbUrl + '" alt="' + altText + '" loading="lazy" />' +
						'</a>' +
						'<div class="fp-preview-card__footer">' +
							'<div class="fp-preview-card__credit">' +
								'<span class="fp-source-badge fp-source-badge--' + escAttr( source ) + '">' + escHtml( badgeLabel ) + '</span>' +
								'<span>' + escHtml( byLabel ) + ' <a href="' + escAttr( userUrl ) + '" target="_blank" rel="noopener noreferrer">' + escHtml( userName ) + '</a></span>' +
							'</div>' +
						'</div>' +
						'<button type="button" class="fp-preview-card__use">' + escHtml( useLabel ) + '</button>' +
					'</div>'
				);

				$grid.append( $card );
			});
		}

		// ---------------------------------------------------------------
		// Use This
		// ---------------------------------------------------------------
		$grid.on( 'click', '.fp-preview-card__use', function () {
			var $btn    = $( this );
			var $card   = $btn.closest( '.fp-preview-card' );
			var photoId = $card.data( 'photo-id' );
			var source  = $card.data( 'source' );

			if ( $btn.hasClass( 'is-loading' ) ) return;

			$btn.addClass( 'is-loading' ).text( '…' );
			showStatus( '', '' );

			$.ajax({
				url:    unsplashMetaBox.ajaxUrl,
				method: 'POST',
				data: {
					action:           'fp_set_photo',
					nonce:            unsplashMetaBox.nonce,
					post_id:          postId,
					photo_id:         photoId,
					source:           source,
					replace_existing: 1,
				},
				success: function ( response ) {
					if ( response.success ) {
						showStatus( response.data.message || unsplashMetaBox.i18n.success || 'Featured image set.', 'success' );
						refreshThumbnail( response.data.thumbnail_url );
						$grid.empty();
					} else {
						var msg = ( response.data && response.data.message ) ? response.data.message : ( unsplashMetaBox.i18n.error || 'Error.' );
						showStatus( msg, 'error' );
						$btn.removeClass( 'is-loading' ).text( unsplashMetaBox.i18n.useThis || 'Use This' );
					}
				},
				error: function () {
					showStatus( unsplashMetaBox.i18n.error || 'An error occurred.', 'error' );
					$btn.removeClass( 'is-loading' ).text( unsplashMetaBox.i18n.useThis || 'Use This' );
				}
			});
		});

		// ---------------------------------------------------------------
		// Helpers
		// ---------------------------------------------------------------

		function setRunning( running ) {
			$fetchBtn.prop( 'disabled', running );
			$spinner.toggleClass( 'is-active', running );
		}

		function showStatus( text, type ) {
			if ( ! text ) {
				$status.hide().text( '' ).removeAttr( 'class' );
				return;
			}
			$status
				.text( text )
				.attr( 'class', 'unsplash-fi__status unsplash-fi__status--' + type )
				.show();
		}

		function refreshThumbnail( url ) {
			if ( ! url ) return;

			var $thumb = $( '#set-post-thumbnail' );
			if ( $thumb.length ) {
				var existing = $thumb.find( 'img' );
				if ( existing.length ) {
					existing.attr( 'src', url );
				} else {
					$thumb.html( '<img src="' + escAttr( url ) + '" style="max-width:100%;height:auto;" />' );
				}
			}

			var $blockThumb = $( '.editor-post-featured-image__preview img' );
			if ( $blockThumb.length ) {
				$blockThumb.attr( 'src', url );
			}
		}

		function escAttr( str ) {
			return $( '<span>' ).attr( 'data-v', String( str ) ).attr( 'data-v' );
		}

		function escHtml( str ) {
			return $( '<span>' ).text( String( str ) ).html();
		}

	});

}( jQuery ));
