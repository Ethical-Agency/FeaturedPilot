<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$plugin = Unsplash_Featured_Images::get_instance();
$logs   = $plugin->logger->get_logs( 50 );
$summary = $plugin->logger->get_log_summary( 7 );
?>
<div class="unsplash-activity-log">

	<div class="unsplash-activity-log__summary">
		<span class="unsplash-activity-log__stat">
			<?php
			printf(
				/* translators: %d: number of actions in last 7 days */
				esc_html__( 'Last 7 days: %d actions', 'unsplash-featured-images' ),
				absint( $summary['total'] )
			);
			?>
		</span>
		&mdash;
		<span class="unsplash-activity-log__stat unsplash-activity-log__stat--success">
			<?php echo absint( $summary['success'] ); ?> <?php esc_html_e( 'success', 'unsplash-featured-images' ); ?>
		</span>
		/
		<span class="unsplash-activity-log__stat unsplash-activity-log__stat--error">
			<?php echo absint( $summary['error'] ); ?> <?php esc_html_e( 'error', 'unsplash-featured-images' ); ?>
		</span>
	</div>

	<?php if ( empty( $logs ) ) : ?>
		<p><?php esc_html_e( 'No activity logged yet.', 'unsplash-featured-images' ); ?></p>
	<?php else : ?>
	<table class="widefat striped unsplash-activity-log__table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Time', 'unsplash-featured-images' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'unsplash-featured-images' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Post', 'unsplash-featured-images' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'unsplash-featured-images' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'unsplash-featured-images' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $logs as $entry ) :
			$status_class = 'error' === ( $entry['status'] ?? '' )
				? 'unsplash-activity-log__status--error'
				: 'unsplash-activity-log__status--success';
			$post_id  = absint( $entry['post_id'] ?? 0 );
			$post_link = '';
			if ( $post_id ) {
				$edit_url  = get_edit_post_link( $post_id );
				$post_title = get_the_title( $post_id );
				if ( $edit_url ) {
					$post_link = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $post_title ?: '#' . $post_id ) . '</a>';
				} else {
					$post_link = esc_html( '#' . $post_id );
				}
			}

			$details = '';
			if ( ! empty( $entry['details'] ) && is_array( $entry['details'] ) ) {
				$parts = array();
				foreach ( $entry['details'] as $k => $v ) {
					$parts[] = esc_html( $k ) . ': ' . esc_html( $v );
				}
				$details = implode( ', ', $parts );
			}
		?>
			<tr>
				<td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
				<td><?php echo esc_html( $entry['action'] ?? '' ); ?></td>
				<td><?php echo $post_link ? wp_kses_post( $post_link ) : '&mdash;'; ?></td>
				<td>
					<span class="unsplash-activity-log__status <?php echo esc_attr( $status_class ); ?>">
						<?php echo esc_html( $entry['status'] ?? 'success' ); ?>
					</span>
				</td>
				<td><?php echo esc_html( $details ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
