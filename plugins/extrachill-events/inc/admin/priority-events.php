<?php
/**
 * Priority Events Admin
 *
 * Admin UI for marking events as priority via post meta.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add priority event meta box to event edit screen
 *
 * @return void
 */
function extrachill_events_priority_event_meta_box() {
	add_meta_box(
		'extrachill-priority-event',
		__( 'Priority Event', 'extrachill-events' ),
		'extrachill_events_priority_event_meta_box_callback',
		'datamachine_events',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'extrachill_events_priority_event_meta_box' );

/**
 * Render priority event meta box content
 *
 * @param WP_Post $post Current post object.
 * @return void
 */
function extrachill_events_priority_event_meta_box_callback( $post ) {
	wp_nonce_field( 'extrachill_priority_event_nonce', 'extrachill_priority_event_nonce_field' );
	$is_priority = get_post_meta( $post->ID, '_extrachill_priority_event', true );
	?>
	<label>
		<input type="checkbox" name="extrachill_priority_event" value="1" <?php checked( $is_priority, true ); ?>>
		<?php esc_html_e( 'Mark as priority event', 'extrachill-events' ); ?>
	</label>
	<p class="description"><?php esc_html_e( 'Priority events appear first in calendar day groups.', 'extrachill-events' ); ?></p>
	<?php
}

/**
 * Save priority event meta on post save
 *
 * @param int $post_id Post ID being saved.
 * @return void
 */
function extrachill_events_save_priority_event( $post_id ) {
	if ( ! isset( $_POST['extrachill_priority_event_nonce_field'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['extrachill_priority_event_nonce_field'] ) ), 'extrachill_priority_event_nonce' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$is_priority = isset( $_POST['extrachill_priority_event'] ) && '1' === $_POST['extrachill_priority_event'];

	if ( $is_priority ) {
		update_post_meta( $post_id, '_extrachill_priority_event', true );
	} else {
		delete_post_meta( $post_id, '_extrachill_priority_event' );
	}

	wp_cache_delete( 'extrachill_priority_event_ids', 'extrachill-events' );
}
add_action( 'save_post_datamachine_events', 'extrachill_events_save_priority_event' );

/**
 * Get all priority event IDs (cached)
 *
 * @return array Array of priority event post IDs.
 */
function extrachill_get_priority_event_ids() {
	$cached = wp_cache_get( 'extrachill_priority_event_ids', 'extrachill-events' );
	if ( false !== $cached ) {
		return $cached;
	}

	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			'_extrachill_priority_event',
			'1'
		)
	);

	$ids = array_map( 'intval', $ids );
	wp_cache_set( 'extrachill_priority_event_ids', $ids, 'extrachill-events', HOUR_IN_SECONDS );

	return $ids;
}
