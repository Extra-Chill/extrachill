<?php
/**
 * Venue show-updates opt-in surfaces (extrachill#1724).
 *
 * Fans follow a venue's upcoming shows without buying a ticket, so a venue
 * can leave Eventbrite without losing its email audience. Everything here
 * is a thin surface over the existing Local Scene digest primitives, scoped
 * to the `venue` taxonomy:
 *
 *   - consent identity `venue_show_updates` on the `venue` taxonomy, stored
 *     in the extrachill-users entity-subscriptions table (no new table);
 *   - signed-in fans toggle it through the generic entity-subscribe
 *     abilities (assets/js/local-scene-digest.js, data-attribute driven);
 *   - signed-out fans use the guest double opt-in with `scope: venue`;
 *   - the weekly "This week at {venue}" email is the shared digest runner
 *     (`extrachill_events_run_venue_show_updates()`), with the shared signed
 *     unsubscribe carrying a `scope: venue` claim.
 *
 * Surfaces:
 *   - every venue archive header (`extrachill_events_venue_archive_actions`);
 *   - single event pages at venues that turned the offer on (term meta
 *     `ec_venue_show_updates` = 1, filterable), so city digest offers are
 *     not crowded out everywhere else.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_EVENTS_VENUE_UPDATES_OFFER_META = 'ec_venue_show_updates';

/**
 * Whether a venue shows the show-updates offer on its event pages.
 *
 * @param WP_Term $venue Venue term.
 * @return bool
 */
function extrachill_events_venue_show_updates_offer_enabled( WP_Term $venue ): bool {
	$enabled = '1' === (string) get_term_meta( (int) $venue->term_id, EXTRACHILL_EVENTS_VENUE_UPDATES_OFFER_META, true );

	/**
	 * Filters whether a venue's event pages offer its weekly show updates.
	 *
	 * @param bool    $enabled Whether the term meta flag is on.
	 * @param WP_Term $venue   Venue term.
	 */
	return (bool) apply_filters( 'extrachill_events_venue_show_updates_offer_enabled', $enabled, $venue );
}

/**
 * Resolve the single venue an event is at.
 *
 * @param int $post_id Event post ID.
 * @return WP_Term|null
 */
function extrachill_events_get_event_venue_term( int $post_id ): ?WP_Term {
	$terms = $post_id > 0 ? get_the_terms( $post_id, EXTRACHILL_EVENTS_VENUE_UPDATES_TAXONOMY ) : false;
	if ( ! is_array( $terms ) || 1 !== count( $terms ) ) {
		return null;
	}
	return reset( $terms );
}

/**
 * Render the venue show-updates control for one venue.
 *
 * Signed-in visitors get a progressive toggle over the generic entity
 * subscription abilities; signed-out visitors get the guest double opt-in.
 *
 * @param WP_Term $venue     Venue term.
 * @param string  $placement Analytics placement (venue_updates_archive|venue_updates_event).
 */
function extrachill_events_render_venue_show_updates_control( WP_Term $venue, string $placement ): void {
	/* translators: %s: Venue name. */
	$heading = sprintf( __( 'Get show updates from %s', 'extrachill-events' ), $venue->name );
	?>
	<aside class="notice notice-info venue-show-updates" data-venue-show-updates data-ec-track-placement="<?php echo esc_attr( $placement ); ?>">
		<p class="venue-show-updates__heading"><strong><?php echo esc_html( $heading ); ?></strong></p>
		<?php if ( is_user_logged_in() ) : ?>
			<div data-local-scene-digest-control>
				<?php extrachill_events_enqueue_venue_show_updates_toggle(); ?>
				<button class="button-1 button-small" type="button" disabled aria-pressed="false" data-local-scene-digest data-entity-type="<?php echo esc_attr( EXTRACHILL_EVENTS_VENUE_UPDATES_ENTITY ); ?>" data-taxonomy="<?php echo esc_attr( EXTRACHILL_EVENTS_VENUE_UPDATES_TAXONOMY ); ?>" data-label-on="<?php esc_attr_e( 'Getting show updates', 'extrachill-events' ); ?>" data-label-off="<?php esc_attr_e( 'Get show updates', 'extrachill-events' ); ?>" data-status-on="<?php esc_attr_e( 'Weekly show updates from this venue are on.', 'extrachill-events' ); ?>" data-status-off="<?php esc_attr_e( 'One email a week when this venue has shows coming up.', 'extrachill-events' ); ?>" data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-slug="<?php echo esc_attr( $venue->slug ); ?>" data-ec-track="<?php echo esc_attr( 'venue-updates-toggle-' . sanitize_key( $placement ) ); ?>"><?php esc_html_e( 'Get show updates', 'extrachill-events' ); ?></button>
				<p class="venue-show-updates__status" data-local-scene-digest-status></p>
			</div>
		<?php else : ?>
			<p><?php esc_html_e( 'One email a week when this venue has shows coming up. No ticket needed, unsubscribe any time.', 'extrachill-events' ); ?></p>
			<?php extrachill_events_render_guest_digest_form( $venue, $placement, 'venue' ); ?>
		<?php endif; ?>
	</aside>
	<?php
}

/** Enqueue the shared progressive subscription toggle. */
function extrachill_events_enqueue_venue_show_updates_toggle(): void {
	wp_enqueue_script( 'extrachill-events-local-scene-digest', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/local-scene-digest.js', array(), EXTRACHILL_EVENTS_VERSION, true );
}

/**
 * Offer show updates in every venue archive header.
 *
 * @hook extrachill_events_venue_archive_actions
 * @param mixed $term Venue term.
 */
function extrachill_events_render_venue_archive_show_updates( $term ): void {
	if ( ! $term instanceof WP_Term || EXTRACHILL_EVENTS_VENUE_UPDATES_TAXONOMY !== $term->taxonomy ) {
		return;
	}
	extrachill_events_render_venue_show_updates_control( $term, 'venue_updates_archive' );
}
add_action( 'extrachill_events_venue_archive_actions', 'extrachill_events_render_venue_archive_show_updates', 20 );

/**
 * Offer show updates on event pages at venues that turned the offer on.
 *
 * Priority 28: right after the city digest offer (27).
 *
 * @hook data_machine_events_action_buttons
 * @param int    $post_id    Event post ID.
 * @param string $ticket_url Ticket URL (unused).
 * @param string $timing     Event timing (unused).
 */
function extrachill_events_render_event_venue_show_updates( $post_id, $ticket_url = '', $timing = '' ): void {
	unset( $ticket_url, $timing );

	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	$venue = extrachill_events_get_event_venue_term( $post_id );
	if ( null === $venue || ! extrachill_events_venue_show_updates_offer_enabled( $venue ) ) {
		return;
	}

	extrachill_events_render_venue_show_updates_control( $venue, 'venue_updates_event' );
}
add_action( 'data_machine_events_action_buttons', 'extrachill_events_render_event_venue_show_updates', 28, 3 );
