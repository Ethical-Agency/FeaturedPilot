<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores plugin activity as a JSON array in wp_options, capped at 1000 entries.
 */
class Activity_Logger {

	const OPTION_KEY  = 'unsplash_activity_logs';
	const MAX_ENTRIES = 1000;

	public function __construct() {}

	/**
	 * Log a general action.
	 *
	 * @param string $action   Short action label (e.g. 'image_assigned').
	 * @param int    $post_id  Associated post ID (0 for system-level).
	 * @param array  $details  Extra key/value context.
	 * @param string $status   'success' | 'error' | 'warning'.
	 */
	public function log_action( $action, $post_id = 0, $details = array(), $status = 'success' ) {
		if ( ! get_option( 'unsplash_log_enabled', '1' ) ) {
			return;
		}

		$entry = array(
			'id'      => uniqid( 'ufi_', true ),
			'action'  => sanitize_key( $action ),
			'post_id' => absint( $post_id ),
			'status'  => in_array( $status, array( 'success', 'error', 'warning' ), true ) ? $status : 'success',
			'details' => is_array( $details ) ? $details : array(),
			'time'    => current_time( 'mysql' ),
		);

		$this->store_log_entry( $entry );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message  Error message.
	 * @param int    $post_id  Related post (optional).
	 * @param array  $details  Extra context.
	 */
	public function log_error( $message, $post_id = 0, $details = array() ) {
		$details['message'] = sanitize_text_field( $message );
		$this->log_action( 'error', $post_id, $details, 'error' );
	}

	/**
	 * Log an API search call.
	 *
	 * @param string $keyword        Keyword used.
	 * @param int    $results_count  Number of photos returned.
	 * @param float  $response_time  Time in seconds.
	 */
	public function log_api_call( $keyword, $results_count, $response_time ) {
		$this->log_action(
			'api_search',
			0,
			array(
				'keyword'       => sanitize_text_field( $keyword ),
				'results_count' => absint( $results_count ),
				'response_time' => round( (float) $response_time, 4 ),
			),
			'success'
		);
	}

	/**
	 * Retrieve log entries.
	 *
	 * @param int      $limit    Max entries to return.
	 * @param int      $offset   Offset for pagination.
	 * @param int|null $post_id  Filter by post ID (null = all).
	 * @return array
	 */
	public function get_logs( $limit = 50, $offset = 0, $post_id = null ) {
		$logs = $this->load_logs();

		if ( null !== $post_id ) {
			$post_id = absint( $post_id );
			$logs    = array_filter( $logs, function( $entry ) use ( $post_id ) {
				return isset( $entry['post_id'] ) && absint( $entry['post_id'] ) === $post_id;
			} );
			$logs    = array_values( $logs );
		}

		// Newest first.
		$logs = array_reverse( $logs );

		return array_slice( $logs, absint( $offset ), absint( $limit ) );
	}

	/**
	 * Return log entries for a specific post.
	 *
	 * @param int $post_id
	 * @param int $limit
	 * @return array
	 */
	public function get_logs_by_post( $post_id, $limit = 20 ) {
		return $this->get_logs( $limit, 0, $post_id );
	}

	/**
	 * Summary stats for the last N days.
	 *
	 * @param int $days
	 * @return array
	 */
	public function get_log_summary( $days = 7 ) {
		$logs      = $this->load_logs();
		$cutoff    = strtotime( "-{$days} days" );
		$summary   = array(
			'total'   => 0,
			'success' => 0,
			'error'   => 0,
			'warning' => 0,
		);

		foreach ( $logs as $entry ) {
			$entry_time = strtotime( $entry['time'] ?? '' );
			if ( $entry_time && $entry_time >= $cutoff ) {
				$summary['total']++;
				$status = $entry['status'] ?? 'success';
				if ( isset( $summary[ $status ] ) ) {
					$summary[ $status ]++;
				}
			}
		}

		return $summary;
	}

	/**
	 * Remove log entries older than N days.
	 *
	 * @param int $older_than_days
	 */
	public function clear_logs( $older_than_days = 30 ) {
		$logs   = $this->load_logs();
		$cutoff = strtotime( "-{$older_than_days} days" );

		$logs = array_filter( $logs, function( $entry ) use ( $cutoff ) {
			$entry_time = strtotime( $entry['time'] ?? '' );
			return $entry_time && $entry_time >= $cutoff;
		} );

		update_option( self::OPTION_KEY, array_values( $logs ), false );
	}

	/**
	 * Delete a single log entry by its ID.
	 *
	 * @param string $log_id
	 */
	public function delete_log_entry( $log_id ) {
		$logs = $this->load_logs();
		$logs = array_filter( $logs, function( $entry ) use ( $log_id ) {
			return ( $entry['id'] ?? '' ) !== $log_id;
		} );
		update_option( self::OPTION_KEY, array_values( $logs ), false );
	}

	/**
	 * Append entry to the stored log array, enforcing the 1000-entry cap.
	 *
	 * @param array $entry
	 */
	private function store_log_entry( $entry ) {
		$logs   = $this->load_logs();
		$logs[] = $entry;

		// FIFO cap.
		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $logs, false );
	}

	/**
	 * Load raw log array from options.
	 *
	 * @return array
	 */
	private function load_logs() {
		$logs = get_option( self::OPTION_KEY, array() );
		return is_array( $logs ) ? $logs : array();
	}
}
