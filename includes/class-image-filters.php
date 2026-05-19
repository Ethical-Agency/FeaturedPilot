<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages image-quality filters (orientation, dimensions, content filter)
 * that are applied to Unsplash API search queries.
 */
class Image_Filters {

	const VALID_ORIENTATIONS    = array( '', 'landscape', 'portrait', 'squarish' );
	const VALID_CONTENT_FILTERS = array( 'low', 'high' );

	public function __construct() {}

	/**
	 * Return all available filter definitions.
	 *
	 * @return array
	 */
	public function get_available_filters() {
		return array(
			'orientation'    => array(
				'label'   => __( 'Orientation', 'unsplash-featured-images' ),
				'options' => array(
					''          => __( 'Any', 'unsplash-featured-images' ),
					'landscape' => __( 'Landscape', 'unsplash-featured-images' ),
					'portrait'  => __( 'Portrait', 'unsplash-featured-images' ),
					'squarish'  => __( 'Square', 'unsplash-featured-images' ),
				),
			),
			'content_filter' => array(
				'label'   => __( 'Content Filter', 'unsplash-featured-images' ),
				'options' => array(
					'low'  => __( 'Standard', 'unsplash-featured-images' ),
					'high' => __( 'Strict (family-safe)', 'unsplash-featured-images' ),
				),
			),
			'min_width'  => array(
				'label' => __( 'Minimum Width (px)', 'unsplash-featured-images' ),
				'type'  => 'integer',
			),
			'min_height' => array(
				'label' => __( 'Minimum Height (px)', 'unsplash-featured-images' ),
				'type'  => 'integer',
			),
		);
	}

	/**
	 * Return the currently saved filter settings.
	 *
	 * @return array
	 */
	public function get_active_filters() {
		return array(
			'orientation'    => get_option( 'unsplash_image_orientation', '' ),
			'content_filter' => get_option( 'unsplash_image_content_filter', 'low' ),
			'min_width'      => absint( get_option( 'unsplash_image_min_width', 0 ) ),
			'min_height'     => absint( get_option( 'unsplash_image_min_height', 0 ) ),
		);
	}

	/**
	 * Persist filter settings.
	 *
	 * @param array $filters  Associative array of filter values.
	 * @return true|WP_Error
	 */
	public function save_filters( $filters ) {
		$validated = $this->validate_filters( $filters );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		update_option( 'unsplash_image_orientation', $validated['orientation'] );
		update_option( 'unsplash_image_content_filter', $validated['content_filter'] );
		update_option( 'unsplash_image_min_width', $validated['min_width'] );
		update_option( 'unsplash_image_min_height', $validated['min_height'] );

		return true;
	}

	/**
	 * Validate and sanitize filter input.
	 *
	 * @param array $filters
	 * @return array|WP_Error
	 */
	public function validate_filters( $filters ) {
		$orientation = isset( $filters['orientation'] ) ? $filters['orientation'] : '';
		if ( ! in_array( $orientation, self::VALID_ORIENTATIONS, true ) ) {
			$orientation = '';
		}

		$content_filter = isset( $filters['content_filter'] ) ? $filters['content_filter'] : 'low';
		if ( ! in_array( $content_filter, self::VALID_CONTENT_FILTERS, true ) ) {
			$content_filter = 'low';
		}

		return array(
			'orientation'    => $orientation,
			'content_filter' => $content_filter,
			'min_width'      => absint( $filters['min_width'] ?? 0 ),
			'min_height'     => absint( $filters['min_height'] ?? 0 ),
		);
	}

	/**
	 * Merge active filters into an existing API params array.
	 *
	 * @param array $api_params  Existing search parameters.
	 * @return array
	 */
	public function apply_filters_to_query( $api_params ) {
		$filters = $this->get_active_filters();

		if ( ! empty( $filters['orientation'] ) ) {
			$api_params['orientation'] = $filters['orientation'];
		}

		if ( ! empty( $filters['content_filter'] ) ) {
			$api_params['content_filter'] = $filters['content_filter'];
		}

		return $api_params;
	}

	/**
	 * Check whether a photo from an API response meets the dimension filters.
	 *
	 * @param array $photo_data  Single photo object from Unsplash API.
	 * @return bool
	 */
	public function should_accept_photo( $photo_data ) {
		$min_width  = absint( get_option( 'unsplash_image_min_width', 0 ) );
		$min_height = absint( get_option( 'unsplash_image_min_height', 0 ) );

		if ( $min_width > 0 && isset( $photo_data['width'] ) && absint( $photo_data['width'] ) < $min_width ) {
			return false;
		}

		if ( $min_height > 0 && isset( $photo_data['height'] ) && absint( $photo_data['height'] ) < $min_height ) {
			return false;
		}

		return true;
	}
}
