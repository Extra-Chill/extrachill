<?php
/**
 * Artist hub link for recurring artist/venue shows.
 *
 * extrachill-seo owns the rolling indexable-window policy. This template
 * layer only consumes its group data and consolidates users on the existing
 * artist taxonomy hub; no canonical is changed.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render "All N dates at <venue>" for groups beyond the indexable window.
 *
 * @param int    $post_id    Event post ID.
 * @param string $ticket_url Ticket URL (unused).
 * @param string $timing     Event timing state (unused).
 */
function ec_events_recurring_hub_link( $post_id, $ticket_url = '', $timing = '' ) {
	unset( $ticket_url, $timing );

	if ( ! is_singular( 'data_machine_events' )
		|| ! function_exists( 'ExtraChill\\SEO\\Core\\ec_seo_recurring_event_group' )
		|| ! function_exists( 'ExtraChill\\SEO\\Core\\ec_seo_recurring_event_limit' ) ) {
		return;
	}

	$group = \ExtraChill\SEO\Core\ec_seo_recurring_event_group( (int) $post_id );
	if ( ! $group || (int) $group['total'] <= \ExtraChill\SEO\Core\ec_seo_recurring_event_limit() ) {
		return;
	}

	$url = get_term_link( $group['artist'] );
	if ( ! $url || is_wp_error( $url ) ) {
		return;
	}
	?>
	<div class="event-recurring-hub-link">
		<a href="<?php echo esc_url( $url ); ?>" class="button-3 button-small">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: upcoming date count, 2: venue name. */
					__( 'All %1$d dates at %2$s →', 'extrachill-events' ),
					(int) $group['total'],
					$group['venue']->name
				)
			);
			?>
		</a>
	</div>
	<?php
}
add_action( 'data_machine_events_action_buttons', 'ec_events_recurring_hub_link', 25, 3 );
