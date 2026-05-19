/* global unsplashMetaBox, jQuery */
(function ( $ ) {
	'use strict';

	$(function () {

		var postId   = unsplashMetaBox.postId;
		var $fi      = $( '#unsplash-fi-' + postId );

		if ( ! $fi.length ) return;

		var $btn     = $fi.find( '#unsplash-find-image' );
		var $spinner = $fi.find( '.unsplash-fi__spinner' );
		var $status  = $fi.find( '#unsplash-status' );

		// ---------------------------------------------------------------
		// Set Unsplash Image button
		// ---------------------------------------------------------------
		$btn.on( 'click', function () {
			var keyword = $fi.find( '.unsplash-fi__keyword' ).val().trim();

			setRunning( true );
			showStatus( '', '' );

			$.ajax({
				url:    unsplashMetaBox.ajaxUrl,
				method: 'POST',
				data: {
					action:           'unsplash_update_image',
					nonce:            unsplashMetaBox.nonce,
					post_id:          postId,
					keyword:          keyword,
					replace_existing: 1,
				},
				success: function ( response ) {
					if ( response.success ) {
						showStatus( response.data.message || unsplashMetaBox.i18n.success, 'success' );
						refreshThumbnail( response.data.thumbnail_url );
					} else {
						showStatus( response.data.message || unsplashMetaBox.i18n.error, 'error' );
					}
				},
				error: function () {
					showStatus( unsplashMetaBox.i18n.error, 'error' );
				},
				complete: function () {
					setRunning( false );
				}
			});
		});

		// ---------------------------------------------------------------
		// Helpers
		// ---------------------------------------------------------------

		function setRunning( running ) {
			$btn.prop( 'disabled', running );
			$spinner.toggleClass( 'is-active', running );
			if ( running ) {
				showStatus( unsplashMetaBox.i18n.finding, 'info' );
			}
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

		/**
		 * After a successful assignment, refresh the native featured image
		 * thumbnail that WordPress shows at the top of the box, without
		 * a full page reload.
		 */
		function refreshThumbnail( url ) {
			if ( ! url ) return;

			// The native featured image area uses #set-post-thumbnail.
			var $thumb = $( '#set-post-thumbnail' );
			if ( $thumb.length ) {
				var existing = $thumb.find( 'img' );
				if ( existing.length ) {
					existing.attr( 'src', url );
				} else {
					$thumb.html( '<img src="' + escAttr( url ) + '" style="max-width:100%;height:auto;" />' );
				}
			}

			// Block editor: update the sidebar panel image if present.
			var $blockThumb = $( '.editor-post-featured-image__preview img' );
			if ( $blockThumb.length ) {
				$blockThumb.attr( 'src', url );
			}
		}

		function escAttr( str ) {
			return $( '<span>' ).attr( 'data-v', String( str ) ).attr( 'data-v' );
		}

	});

}( jQuery ));
