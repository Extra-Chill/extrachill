<?php
/**
 * Location Archive Promoted Events
 *
 * Prepends 1-3 promoted upcoming events above the chronological calendar on
 * a location taxonomy archive. Resolves against the archive's own term
 * only — no geolocation, no user meta — because a visitor here has already
 * self-selected this city. Renders nothing when the location has no
 * promoted upcoming events. Never reorders the calendar or archive listing
 * itself; this is a prepended callout, not a sort change.
 *
 * @package ExtraChillEvents
 * @since 0.68.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$queried_term = get_queried_object();
if ( ! $queried_term || ! isset( $queried_term->term_id, $queried_term->taxonomy ) || 'location' !== $queried_term->taxonomy ) {
	return;
}

if ( ! function_exists( 'extrachill_get_promoted_events_for_location_term' ) ) {
	return;
}

$promoted_ids = extrachill_get_promoted_events_for_location_term( (int) $queried_term->term_id );
if ( empty( $promoted_ids ) ) {
	return;
}
?>
<div class="promoted-events promoted-events--archive ec-edge-gutter">
	<?php foreach ( $promoted_ids as $promoted_event_id ) : ?>
		<?php extrachill_render_promoted_event_card( (int) $promoted_event_id ); ?>
	<?php endforeach; ?>
</div>
