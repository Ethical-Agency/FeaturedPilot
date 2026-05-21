<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads a photo from any configured source and uploads it to the WordPress Media Library.
 */
class Image_Handler {

	const MAX_FILE_SIZE  = 10485760; // 10 MB in bytes.
	const ALLOWED_MIMES  = array( 'image/jpeg', 'image/png' );

	/** @var Source_Manager */
	private $source_manager;

	/** @var Activity_Logger */
	private $logger;

	/** @var Magnific_API */
	private $magnific;

	public function __construct( Source_Manager $source_manager, Activity_Logger $logger, Magnific_API $magnific = null ) {
		$this->source_manager = $source_manager;
		$this->logger         = $logger;
		$this->magnific       = $magnific;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Download a photo from a source and set it as the featured image for a post.
	 *
	 * @param int    $post_id          Target post.
	 * @param string $photo_id         Source-native photo ID.
	 * @param bool   $replace_existing Replace if post already has a featured image.
	 * @param string $source_slug      'unsplash' | 'pexels' | 'pixabay'.
	 * @return int|WP_Error  Attachment ID on success.
	 */
	public function download_and_upload_image( $post_id, $photo_id, $replace_existing = false, $source_slug = 'unsplash' ) {
		$post_id     = absint( $post_id );
		$photo_id    = sanitize_text_field( $photo_id );
		$source_slug = sanitize_key( $source_slug ?: 'unsplash' );

		if ( ! $replace_existing && has_post_thumbnail( $post_id ) ) {
			return new WP_Error( 'has_thumbnail', __( 'Post already has a featured image.', 'unsplash-featured-images' ) );
		}

		// Get photo metadata.
		$photo_data = $this->source_manager->get_photo( $photo_id, $source_slug );
		if ( is_wp_error( $photo_data ) ) {
			$this->logger->log_error( 'Failed to get photo data: ' . $photo_data->get_error_message(), $post_id );
			return $photo_data;
		}

		// Get the download URL (triggers Unsplash download event when applicable).
		$image_url = $this->source_manager->download_photo( $photo_id, 'regular', $source_slug );
		if ( is_wp_error( $image_url ) ) {
			$this->logger->log_error( 'Failed to get download URL: ' . $image_url->get_error_message(), $post_id );
			return $image_url;
		}

		// Optionally upscale via Magnific — fall back to original URL on any failure.
		if ( $this->magnific && $this->magnific->is_enabled() ) {
			$upscaled = $this->magnific->upscale_image( $image_url, $this->magnific->get_scale_factor() );
			if ( ! is_wp_error( $upscaled ) ) {
				$image_url = $upscaled;
			} else {
				$this->logger->log_error( 'Magnific upscale failed (using original): ' . $upscaled->get_error_message(), $post_id );
			}
		}

		// Download to temp file.
		$tmp_path = $this->download_file( $image_url );
		if ( is_wp_error( $tmp_path ) ) {
			$this->logger->log_error( 'Download failed: ' . $tmp_path->get_error_message(), $post_id );
			return $tmp_path;
		}

		// Validate before upload.
		$valid = $this->validate_image_file( $tmp_path );
		if ( is_wp_error( $valid ) ) {
			wp_delete_file( $tmp_path );
			$this->logger->log_error( 'Validation failed: ' . $valid->get_error_message(), $post_id );
			return $valid;
		}

		// Build a safe filename.
		$ext      = 'jpg';
		$filetype = wp_check_filetype( $tmp_path );
		if ( ! empty( $filetype['ext'] ) ) {
			$ext = $filetype['ext'];
		}
		$filename = $this->get_safe_filename( $source_slug . '-' . $photo_id . '.' . $ext );

		// Upload to Media Library.
		$attachment_id = $this->upload_to_media_library( $tmp_path, $filename, $photo_data );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp_path );
			$this->logger->log_error( 'Upload failed: ' . $attachment_id->get_error_message(), $post_id );
			return $attachment_id;
		}

		// Set as featured image.
		$set = $this->set_featured_image( $post_id, $attachment_id, $photo_data );
		if ( is_wp_error( $set ) ) {
			$this->logger->log_error( 'Set thumbnail failed: ' . $set->get_error_message(), $post_id );
			return $set;
		}

		$this->logger->log_action(
			'image_assigned',
			$post_id,
			array(
				'photo_id'      => $photo_id,
				'source'        => $source_slug,
				'attachment_id' => $attachment_id,
			)
		);

		return $attachment_id;
	}

	/**
	 * Set the featured image and store attribution meta.
	 *
	 * @param int    $post_id
	 * @param int    $attachment_id
	 * @param array  $photo_data
	 * @return true|WP_Error
	 */
	public function set_featured_image( $post_id, $attachment_id, $photo_data ) {
		$result = set_post_thumbnail( absint( $post_id ), absint( $attachment_id ) );
		if ( ! $result ) {
			return new WP_Error( 'set_thumbnail_failed', __( 'Could not set featured image.', 'unsplash-featured-images' ) );
		}

		$this->store_photo_metadata( $attachment_id, $photo_data );

		// Store photo reference on the post.
		update_post_meta( $post_id, '_unsplash_photo_id', sanitize_text_field( $photo_data['id'] ?? '' ) );
		update_post_meta( $post_id, '_unsplash_photo_url', esc_url_raw( $photo_data['links']['html'] ?? '' ) );
		update_post_meta( $post_id, '_unsplash_assignment_method', 'manual' );
		update_post_meta( $post_id, '_fp_photo_source', sanitize_key( $photo_data['source'] ?? 'unsplash' ) );

		return true;
	}

	/**
	 * Get Unsplash metadata stored for the current featured image of a post.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public function get_featured_image_data( $post_id ) {
		$post_id = absint( $post_id );
		$photo_id = get_post_meta( $post_id, '_unsplash_photo_id', true );
		if ( empty( $photo_id ) ) {
			return null;
		}

		$attachment_id = get_post_thumbnail_id( $post_id );

		return array(
			'photo_id'           => esc_html( $photo_id ),
			'photo_url'          => esc_url( get_post_meta( $post_id, '_unsplash_photo_url', true ) ),
			'attachment_id'      => absint( $attachment_id ),
			'photographer_name'  => esc_html( get_post_meta( $attachment_id, '_unsplash_photographer_name', true ) ),
			'photographer_url'   => esc_url( get_post_meta( $attachment_id, '_unsplash_photographer_url', true ) ),
			'assigned_date'      => esc_html( get_post_meta( $attachment_id, '_unsplash_assigned_date', true ) ),
		);
	}

	/**
	 * Remove the current featured image (and its Unsplash meta) from a post.
	 *
	 * @param int $post_id
	 */
	public function remove_featured_image( $post_id ) {
		$post_id = absint( $post_id );
		delete_post_thumbnail( $post_id );
		delete_post_meta( $post_id, '_unsplash_photo_id' );
		delete_post_meta( $post_id, '_unsplash_photo_url' );
		delete_post_meta( $post_id, '_unsplash_assignment_method' );
		$this->logger->log_action( 'image_removed', $post_id );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Download a remote URL to a temporary file using WP core helper.
	 *
	 * @param string $url
	 * @return string|WP_Error  Local temp file path.
	 */
	private function download_file( $url ) {
		// Validate URL is an external http/https address before fetching.
		$safe = $this->validate_remote_url( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = download_url( $safe, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		return $tmp;
	}

	/**
	 * Ensure a URL is a safe, public http/https address.
	 * Rejects private/loopback ranges and non-http schemes to prevent SSRF.
	 *
	 * @param string $url
	 * @return string|WP_Error  Sanitized URL on success.
	 */
	private function validate_remote_url( $url ) {
		$url = esc_url_raw( trim( $url ) );

		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'Empty image URL.', 'unsplash-featured-images' ) );
		}

		$parsed = wp_parse_url( $url );
		if ( ! $parsed || ! isset( $parsed['scheme'], $parsed['host'] ) ) {
			return new WP_Error( 'invalid_url', __( 'Malformed image URL.', 'unsplash-featured-images' ) );
		}

		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_url', __( 'Image URL must use http or https.', 'unsplash-featured-images' ) );
		}

		// Resolve host to IP and reject private / loopback ranges.
		$host = $parsed['host'];
		$ip   = gethostbyname( $host );

		if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			// Block loopback and private ranges.
			$private = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( false === $private ) {
				return new WP_Error( 'ssrf_blocked', __( 'Image URL resolves to a private or reserved address.', 'unsplash-featured-images' ) );
			}
		}

		return $url;
	}

	/**
	 * Validate that the file is an allowed image type and within size limits.
	 *
	 * @param string $file_path
	 * @return true|WP_Error
	 */
	private function validate_image_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Downloaded file does not exist.', 'unsplash-featured-images' ) );
		}

		// Size check.
		$size = filesize( $file_path );
		if ( false === $size || $size > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'file_too_large', __( 'Image exceeds the 10 MB size limit.', 'unsplash-featured-images' ) );
		}

		// MIME check via WP (uses finfo when available, falls back to extension).
		$filetype = wp_check_filetype( $file_path );
		if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], self::ALLOWED_MIMES, true ) ) {
			// Try mime_content_type for temp files that have no extension.
			if ( function_exists( 'mime_content_type' ) ) {
				$mime = mime_content_type( $file_path );
				if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
					return new WP_Error( 'invalid_mime', __( 'Only JPEG and PNG images are allowed.', 'unsplash-featured-images' ) );
				}
			}
		}

		return true;
	}

	/**
	 * Sideload the temp file into the WordPress Media Library.
	 *
	 * @param string $file_path   Path to temp file.
	 * @param string $filename    Desired filename.
	 * @param array  $photo_data  Unsplash photo data for title/alt.
	 * @return int|WP_Error  Attachment ID.
	 */
	private function upload_to_media_library( $file_path, $filename, $photo_data ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $file_path,
		);

		$overrides = array(
			'test_form' => false,
			'test_size' => true,
		);

		$sideloaded = wp_handle_sideload( $file_array, $overrides );

		if ( isset( $sideloaded['error'] ) ) {
			return new WP_Error( 'sideload_error', $sideloaded['error'] );
		}

		$attachment = array(
			'post_mime_type' => sanitize_mime_type( $sideloaded['type'] ),
			'post_title'     => sanitize_text_field( $this->generate_attachment_title( $photo_data ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $sideloaded['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate image metadata (thumbnails etc.).
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $sideloaded['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		// Set alt text.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $this->generate_alt_text( $photo_data ) ) );

		return $attachment_id;
	}

	/**
	 * Store Unsplash attribution metadata on an attachment.
	 *
	 * @param int   $attachment_id
	 * @param array $photo_data
	 */
	private function store_photo_metadata( $attachment_id, $photo_data ) {
		$attachment_id = absint( $attachment_id );

		update_post_meta( $attachment_id, '_unsplash_photo_id', sanitize_text_field( $photo_data['id'] ?? '' ) );
		update_post_meta( $attachment_id, '_unsplash_photographer_name', sanitize_text_field( $photo_data['user']['name'] ?? '' ) );
		update_post_meta( $attachment_id, '_unsplash_photographer_url', esc_url_raw( $photo_data['user']['links']['html'] ?? '' ) );
		update_post_meta( $attachment_id, '_unsplash_photo_url', esc_url_raw( $photo_data['links']['html'] ?? '' ) );
		update_post_meta( $attachment_id, '_unsplash_download_link', esc_url_raw( $photo_data['links']['download'] ?? '' ) );
		update_post_meta( $attachment_id, '_unsplash_assigned_date', current_time( 'mysql' ) );
		update_post_meta( $attachment_id, '_fp_photo_source', sanitize_key( $photo_data['source'] ?? 'unsplash' ) );
	}

	/**
	 * Sanitize a filename.
	 *
	 * @param string $original
	 * @return string
	 */
	private function get_safe_filename( $original ) {
		return sanitize_file_name( $original );
	}

	/**
	 * Build an attachment title from photo description or photographer name.
	 *
	 * @param array $photo_data
	 * @return string
	 */
	private function generate_attachment_title( $photo_data ) {
		$description = $photo_data['description'] ?? $photo_data['alt_description'] ?? '';
		if ( ! empty( $description ) ) {
			return wp_trim_words( sanitize_text_field( $description ), 10 );
		}
		$name   = $photo_data['user']['name'] ?? '';
		$source = ucfirst( sanitize_key( $photo_data['source'] ?? 'unsplash' ) );
		if ( ! empty( $name ) ) {
			/* translators: 1: photographer name, 2: source name (Unsplash/Pexels/Pixabay) */
			return sprintf( __( 'Photo by %1$s on %2$s', 'unsplash-featured-images' ), sanitize_text_field( $name ), $source );
		}
		/* translators: %s: source name */
		return sprintf( __( '%s Photo', 'unsplash-featured-images' ), $source );
	}

	/**
	 * Generate alt text from photo alt_description or photographer name.
	 *
	 * @param array $photo_data
	 * @return string
	 */
	private function generate_alt_text( $photo_data ) {
		$alt = $photo_data['alt_description'] ?? '';
		if ( ! empty( $alt ) ) {
			return sanitize_text_field( $alt );
		}
		$name   = $photo_data['user']['name'] ?? '';
		$source = ucfirst( sanitize_key( $photo_data['source'] ?? 'unsplash' ) );
		if ( ! empty( $name ) ) {
			/* translators: 1: photographer name, 2: source name */
			return sprintf( __( 'Photo by %1$s on %2$s', 'unsplash-featured-images' ), sanitize_text_field( $name ), $source );
		}
		/* translators: %s: source name */
		return sprintf( __( '%s Photo', 'unsplash-featured-images' ), $source );
	}
}
