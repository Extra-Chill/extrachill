<?php
/**
 * Homepage Promoted Event Card
 *
 * Surfaces 1-3 promoted upcoming events for the viewer's Local Scene.
 * Targeted by construction: renders nothing for logged-out visitors, users
 * with no Local Scene set, or a scene with no promoted events. Never falls
 * back to client-side geolocation — Near Me stays scoped to /near-me/, and
 * showing a promoted event from the wrong city is worse than showing
 * nothing.
 *
 * @package ExtraChillEvents
 * @since 0.68.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! is_user_logged_in() ) {
	return;
}

if ( ! function_exists( 'extrachill_users_get_local_scene' ) || ! function_exists( 'extrachill_get_promoted_events_for_location_term' ) ) {
	return;
}

$local_scene = extrachill_users_get_local_scene( get_current_user_id() );
if ( ! is_array( $local_scene ) || empty( $local_scene['term_id'] ) ) {
	return;
}

$promoted_ids = extrachill_get_promoted_events_for_location_term( (int) $local_scene['term_id'] );
if ( empty( $promoted_ids ) ) {
	return;
}
?>
<div class="promoted-events promoted-events--home ec-edge-gutter">
	<h2 class="promoted-events__heading">
		<?php
		printf(
			/* translators: %s: Local Scene city name. */
			esc_html__( 'Promoted in %s', 'extrachill-events' ),
			esc_html( (string) ( $local_scene['name'] ?? '' ) )
		);
		?>
	</h2>
	<?php foreach ( $promoted_ids as $promoted_event_id ) : ?>
		<?php extrachill_render_promoted_event_card( (int) $promoted_event_id ); ?>
	<?php endforeach; ?>
</div>
