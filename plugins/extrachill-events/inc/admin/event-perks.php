<?php
/**
 * RSVP Perk Admin
 *
 * Admin UI for offering an RSVP perk on an event ("RSVP and your first beer
 * is on Extra Chill"). Mirrors inc/admin/priority-events.php's meta-box
 * shape. Storage is post meta on the event; the pass/redemption/door-list
 * machinery that reacts to this setting lives in
 * inc/core/rsvp-pass-service.php and inc/core/rsvp-pass-integration.php.
 *
 * @package ExtraChillEvents
 * @since 0.69.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_EVENTS_PERK_ENABLED_META = '_extrachill_event_perk_enabled';
const EXTRACHILL_EVENTS_PERK_TEXT_META    = '_extrachill_event_perk_text';
const EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN  = 280;

/**
 * Whether an event currently offers an RSVP perk.
 *
 * @param int $post_id Event post ID.
 * @return bool
 */
function extrachill_events_perk_enabled( int $post_id ): bool {
	return (bool) get_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_ENABLED_META, true );
}

/**
 * Get the attendee-facing perk text for an event.
 *
 * @param int $post_id Event post ID.
 * @return string Empty string when no perk is configured.
 */
function extrachill_events_get_perk_text( int $post_id ): string {
	return (string) get_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_TEXT_META, true );
}

/**
 * Add the RSVP Perk meta box to the event edit screen.
 */
function extrachill_events_perk_meta_box() {
	add_meta_box(
		'extrachill-event-perk',
		__( 'RSVP Perk', 'extrachill-events' ),
		'extrachill_events_perk_meta_box_callback',
		'data_machine_events',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'extrachill_events_perk_meta_box' );

/**
 * Render the RSVP Perk meta box content.
 *
 * @param WP_Post $post Current post object.
 */
function extrachill_events_perk_meta_box_callback( $post ) {
	wp_nonce_field( 'extrachill_event_perk_nonce', 'extrachill_event_perk_nonce_field' );
	$enabled = extrachill_events_perk_enabled( $post->ID );
	$text    = extrachill_events_get_perk_text( $post->ID );
	?>
	<p>
		<label>
			<input type="checkbox" name="extrachill_event_perk_enabled" value="1" <?php checked( $enabled, true ); ?>>
			<?php esc_html_e( 'Offer an RSVP perk', 'extrachill-events' ); ?>
		</label>
	</p>
	<p>
		<label for="extrachill_event_perk_text"><?php esc_html_e( 'Perk description (shown to attendees)', 'extrachill-events' ); ?></label>
		<textarea
			id="extrachill_event_perk_text"
			name="extrachill_event_perk_text"
			rows="3"
			style="width:100%;"
			maxlength="<?php echo esc_attr( (string) EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN ); ?>"
		><?php echo esc_textarea( $text ); ?></textarea>
	</p>
	<p class="description"><?php esc_html_e( 'e.g. "First beer on Extra Chill." Marking Going issues each attendee a pass shown on screen, emailed to them, and visible to you at the door.', 'extrachill-events' ); ?></p>
	<?php
}

/**
 * Save RSVP perk meta on post save.
 *
 * @param int $post_id Post ID being saved.
 */
function extrachill_events_save_event_perk( $post_id ) {
	if ( ! isset( $_POST['extrachill_event_perk_nonce_field'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['extrachill_event_perk_nonce_field'] ) ), 'extrachill_event_perk_nonce' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$enabled = isset( $_POST['extrachill_event_perk_enabled'] ) && '1' === $_POST['extrachill_event_perk_enabled'];
	$text    = isset( $_POST['extrachill_event_perk_text'] )
		? substr( sanitize_textarea_field( wp_unslash( $_POST['extrachill_event_perk_text'] ) ), 0, EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN )
		: '';

	if ( $enabled ) {
		update_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_ENABLED_META, true );
	} else {
		delete_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_ENABLED_META );
	}

	if ( '' !== $text ) {
		update_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_TEXT_META, $text );
	} else {
		delete_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_TEXT_META );
	}
}
add_action( 'save_post_data_machine_events', 'extrachill_events_save_event_perk' );
