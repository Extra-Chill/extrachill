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
 * Guest list settings (extrachill#433). A perk can be turned into a
 * password-gated, capacity-limited guest list: the password is stored only
 * as a core wp_hash_password() hash, and capacity caps how many passes the
 * event will ever have outstanding (active + redeemed).
 */
const EXTRACHILL_EVENTS_PERK_PASSWORD_META = '_extrachill_event_perk_password_hash';
const EXTRACHILL_EVENTS_PERK_CAPACITY_META = '_extrachill_event_perk_capacity';
const EXTRACHILL_EVENTS_PERK_PASSWORD_MAX  = 128;
const EXTRACHILL_EVENTS_PERK_CAPACITY_MAX  = 100000;

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
 * Whether claiming this event's pass requires the guest list password.
 *
 * @param int $post_id Event post ID.
 * @return bool
 */
function extrachill_events_perk_password_required( int $post_id ): bool {
	return '' !== (string) get_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_PASSWORD_META, true );
}

/**
 * Check a submitted guest list password against the stored hash.
 *
 * An event with no password accepts anything (there is nothing to check).
 *
 * @param int    $post_id  Event post ID.
 * @param string $password Submitted password.
 * @return bool
 */
function extrachill_events_perk_check_password( int $post_id, string $password ): bool {
	$hash = (string) get_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_PASSWORD_META, true );
	if ( '' === $hash ) {
		return true;
	}

	if ( '' === $password ) {
		return false;
	}

	return (bool) wp_check_password( $password, $hash );
}

/**
 * Store (or clear) the guest list password. Only the hash is persisted.
 *
 * @param int    $post_id  Event post ID.
 * @param string $password Plaintext password; empty string clears it.
 */
function extrachill_events_set_perk_password( int $post_id, string $password ): void {
	if ( '' === $password ) {
		delete_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_PASSWORD_META );
		return;
	}

	update_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_PASSWORD_META, wp_hash_password( substr( $password, 0, EXTRACHILL_EVENTS_PERK_PASSWORD_MAX ) ) );
}

/**
 * Guest list capacity: the most passes this event will have outstanding.
 *
 * @param int $post_id Event post ID.
 * @return int 0 means unlimited.
 */
function extrachill_events_get_perk_capacity( int $post_id ): int {
	return max( 0, (int) get_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_CAPACITY_META, true ) );
}

/**
 * Store (or clear) the guest list capacity.
 *
 * @param int $post_id  Event post ID.
 * @param int $capacity Capacity; 0 clears it (unlimited).
 */
function extrachill_events_set_perk_capacity( int $post_id, int $capacity ): void {
	$capacity = max( 0, min( EXTRACHILL_EVENTS_PERK_CAPACITY_MAX, $capacity ) );
	if ( 0 === $capacity ) {
		delete_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_CAPACITY_META );
		return;
	}

	update_post_meta( $post_id, EXTRACHILL_EVENTS_PERK_CAPACITY_META, $capacity );
}

/**
 * Guest list counts for display and enforcement.
 *
 * @param int $post_id Event post ID.
 * @return array{capacity:int, claimed:int, checked_in:int, spots_left:int|null, full:bool}
 *         spots_left is null when capacity is unlimited.
 */
function extrachill_events_get_guest_list_counts( int $post_id ): array {
	$capacity = extrachill_events_get_perk_capacity( $post_id );
	$counts   = class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' )
		? \ExtraChillEvents\Core\RsvpPassesTable::count_for_event( $post_id )
		: array(
			'claimed'    => 0,
			'checked_in' => 0,
		);

	$spots_left = $capacity > 0 ? max( 0, $capacity - $counts['claimed'] ) : null;

	return array(
		'capacity'   => $capacity,
		'claimed'    => $counts['claimed'],
		'checked_in' => $counts['checked_in'],
		'spots_left' => $spots_left,
		'full'       => 0 === $spots_left,
	);
}

/**
 * Door-facing guest list summary, e.g. "12 of 60 checked in. 37 spots left."
 *
 * @param array $counts extrachill_events_get_guest_list_counts() result.
 * @return string
 */
function extrachill_events_format_guest_list_count( array $counts ): string {
	$capacity   = (int) ( $counts['capacity'] ?? 0 );
	$checked_in = (int) ( $counts['checked_in'] ?? 0 );
	$claimed    = (int) ( $counts['claimed'] ?? 0 );

	if ( $capacity <= 0 ) {
		/* translators: 1: checked-in count, 2: passes issued. */
		return sprintf( __( '%1$d checked in of %2$d passes.', 'extrachill-events' ), $checked_in, $claimed );
	}

	$spots_left = max( 0, $capacity - $claimed );

	return sprintf(
		/* translators: 1: checked-in count, 2: capacity, 3: unclaimed spots. */
		_n( '%1$d of %2$d checked in. %3$d spot left.', '%1$d of %2$d checked in. %3$d spots left.', $spots_left, 'extrachill-events' ),
		$checked_in,
		$capacity,
		$spots_left
	);
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
	<p>
		<label for="extrachill_event_perk_password"><?php esc_html_e( 'Guest list password', 'extrachill-events' ); ?></label>
		<input
			type="password"
			id="extrachill_event_perk_password"
			name="extrachill_event_perk_password"
			autocomplete="new-password"
			style="width:100%;"
			maxlength="<?php echo esc_attr( (string) EXTRACHILL_EVENTS_PERK_PASSWORD_MAX ); ?>"
			placeholder="<?php echo extrachill_events_perk_password_required( $post->ID ) ? esc_attr__( 'Set (leave blank to keep)', 'extrachill-events' ) : esc_attr__( 'None', 'extrachill-events' ); ?>"
		>
	</p>
	<?php if ( extrachill_events_perk_password_required( $post->ID ) ) : ?>
	<p>
		<label>
			<input type="checkbox" name="extrachill_event_perk_clear_password" value="1">
			<?php esc_html_e( 'Remove the password', 'extrachill-events' ); ?>
		</label>
	</p>
	<?php endif; ?>
	<p>
		<label for="extrachill_event_perk_capacity"><?php esc_html_e( 'Guest list spots (0 = unlimited)', 'extrachill-events' ); ?></label>
		<input
			type="number"
			id="extrachill_event_perk_capacity"
			name="extrachill_event_perk_capacity"
			min="0"
			max="<?php echo esc_attr( (string) EXTRACHILL_EVENTS_PERK_CAPACITY_MAX ); ?>"
			step="1"
			style="width:100%;"
			value="<?php echo esc_attr( (string) extrachill_events_get_perk_capacity( $post->ID ) ); ?>"
		>
	</p>
	<p class="description"><?php esc_html_e( 'With a password, only people who know it can claim a pass. Once every spot is claimed, the guest list closes.', 'extrachill-events' ); ?></p>
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

	// Guest list settings go before the enabled flag: enabling backfills
	// passes, and that must see the password and capacity.
	// Password: blank keeps the current one; the checkbox clears it.
	if ( isset( $_POST['extrachill_event_perk_clear_password'] ) && '1' === $_POST['extrachill_event_perk_clear_password'] ) {
		extrachill_events_set_perk_password( $post_id, '' );
	} elseif ( isset( $_POST['extrachill_event_perk_password'] ) ) {
		$password = (string) wp_unslash( $_POST['extrachill_event_perk_password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords are hashed verbatim, never echoed.
		if ( '' !== $password ) {
			extrachill_events_set_perk_password( $post_id, $password );
		}
	}

	if ( isset( $_POST['extrachill_event_perk_capacity'] ) ) {
		extrachill_events_set_perk_capacity( $post_id, absint( wp_unslash( $_POST['extrachill_event_perk_capacity'] ) ) );
	}

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

/**
 * Purge the event's cached page when its perk settings change.
 *
 * The page cache only purges on post status transitions, but the perk is post
 * meta written by the set-event-perk ability or the meta box. Without this,
 * logged-out visitors keep seeing the old perk (or none) until the cache
 * expires.
 *
 * @param int|int[] $meta_ids  Meta ID(s).
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 */
function extrachill_events_purge_on_perk_meta_change( $meta_ids, $object_id, $meta_key ): void {
	unset( $meta_ids );

	$perk_keys = array(
		EXTRACHILL_EVENTS_PERK_ENABLED_META,
		EXTRACHILL_EVENTS_PERK_TEXT_META,
		EXTRACHILL_EVENTS_PERK_PASSWORD_META,
		EXTRACHILL_EVENTS_PERK_CAPACITY_META,
	);
	if ( ! in_array( $meta_key, $perk_keys, true ) ) {
		return;
	}

	if ( function_exists( 'extrachill_cache_purge_post' ) ) {
		extrachill_cache_purge_post( (int) $object_id );
	}
}
add_action( 'added_post_meta', 'extrachill_events_purge_on_perk_meta_change', 20, 3 );
add_action( 'updated_post_meta', 'extrachill_events_purge_on_perk_meta_change', 20, 3 );
add_action( 'deleted_post_meta', 'extrachill_events_purge_on_perk_meta_change', 20, 3 );
