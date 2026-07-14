<?php
/**
 * Contextual single-event exploration bridge.
 *
 * The reverse bridge on the single-event page: events.extrachill.com is a large
 * attention pool whose single-event template otherwise dead-ends with zero
 * outbound links to the rest of the network. This bridge gives the reader a
 * contextual path OUTWARD — an artist profile, nearby shows, editorial
 * coverage, or a real community discussion — driven by the event's own terms.
 *
 * The bridge itself — terms resolution, transient caching, slot assembly, UTM
 * tagging, and render markup — lives in the shared primitive
 * `extrachill_render_network_bridge()` in extrachill-multisite (and the shared
 * stylesheet is registered there too). This file decides WHEN to render
 * (inside the single event action composition), orders the resolved cards, and
 * supplies event-specific labels. The shared primitive remains responsible for
 * cross-site URL resolution, caching, and analytics tags.
 *
 * Full same-site event cards remain in inc/single-event/related-events.php;
 * this compact module adds only one canonical location-archive path.
 *
 * @package ExtraChillEvents
 * @since 0.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build shared bridge arguments for an event.
 *
 * Upcoming and ongoing events lead with the artist profile. Past events lead
 * with coverage. Both paths remain bounded to three real destinations.
 *
 * @param int    $post_id Event post ID.
 * @param string $timing  Event timing state.
 * @return array
 */
function ec_events_network_bridge_args( $post_id, $timing ) {
	$is_past = 'past' === $timing;

	return array(
		'post_id'           => (int) $post_id,
		'taxonomies'        => array( 'artist', 'festival' ),
		'allowed_site_keys' => array( 'artist', 'main', 'community' ),
		'slot_order'        => $is_past
			? array( 'main', 'artist', 'community', 'events' )
			: array( 'artist', 'events', 'community', 'main' ),
		'utm_source'        => 'extrachill_events',
		'cache_prefix'      => 'ec_events_network_bridge_v2_' . ( $is_past ? 'past_' : 'current_' ),
		'heading_id'        => 'events-network-bridge-header-' . (int) $post_id,
		'heading_text'      => __( 'Keep Exploring', 'extrachill-events' ),
	);
}

/**
 * Build at most three contextual cards from canonical destinations.
 *
 * Cross-site resolution and caching stay in the shared network primitive. This
 * consumer changes labels only on the returned copies, avoiding pollution of
 * the shared term-link cache. Upcoming events also add the canonical location
 * archive as a same-site discovery path.
 *
 * @param array $args Bridge arguments from ec_events_network_bridge_args().
 * @return array
 */
function ec_events_network_bridge_cards( $args ) {
	$cards = extrachill_network_bridge_get_cards(
		$args['post_id'],
		$args['taxonomies'],
		$args['allowed_site_keys'],
		$args['allowed_site_keys'],
		$args['utm_source'],
		$args['cache_prefix']
	);

	$labels  = array(
		'artist'    => __( 'Profile', 'extrachill-events' ),
		'main'      => __( 'Coverage', 'extrachill-events' ),
		'community' => __( 'Discussions', 'extrachill-events' ),
	);
	$by_site = array();

	foreach ( $cards as $card ) {
		$site_key = isset( $card['site_key'] ) ? $card['site_key'] : '';
		if ( ! isset( $labels[ $site_key ] ) ) {
			continue;
		}

		$card['label']        = $labels[ $site_key ];
		$by_site[ $site_key ] = $card;
	}

	if ( 'events' === $args['slot_order'][1] ) {
		$locations = get_the_terms( $args['post_id'], 'location' );
		if ( $locations && ! is_wp_error( $locations ) ) {
			$location     = reset( $locations );
			$location_url = get_term_link( $location );

			if ( ! is_wp_error( $location_url ) ) {
				$by_site['events'] = array(
					'site_key'     => 'events',
					'url'          => $location_url,
					'label'        => __( 'More Shows', 'extrachill-events' ),
					'term_name'    => $location->name,
					'is_same_site' => true,
				);
			}
		}
	}

	$ordered = array();
	foreach ( $args['slot_order'] as $site_key ) {
		if ( isset( $by_site[ $site_key ] ) ) {
			$ordered[] = $by_site[ $site_key ];
		}

		if ( 3 === count( $ordered ) ) {
			break;
		}
	}

	return $ordered;
}

/**
 * Render contextual exploration links beneath the event's primary actions.
 *
 * Hooked inside the Event Details action composition after tickets,
 * attendance, calendar, and sharing. The CSS gives the bridge its own full
 * row, so the primary conversion actions remain visually distinct.
 *
 * @param int    $post_id    Event post ID.
 * @param string $ticket_url Ticket URL (unused).
 * @param string $timing     Event timing state.
 */
function ec_events_network_bridge( $post_id, $ticket_url = '', $timing = '' ) {
	static $rendered_post_ids = array();

	unset( $ticket_url );

	if ( ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	if ( ! function_exists( 'extrachill_network_bridge_get_cards' )
		|| ! function_exists( 'extrachill_network_bridge_tag_url' )
		|| ! function_exists( 'extrachill_cross_site_link_button' ) ) {
		return;
	}

	$args  = ec_events_network_bridge_args( $post_id, $timing );
	$cards = ec_events_network_bridge_cards( $args );
	if ( empty( $cards ) || isset( $rendered_post_ids[ $post_id ] ) ) {
		return;
	}
	$rendered_post_ids[ $post_id ] = true;

	wp_enqueue_style( 'extrachill-network-bridge' );
	?>
	<div class="network-bridge-section related-tax-section" aria-labelledby="<?php echo esc_attr( $args['heading_id'] ); ?>">
		<h3 class="network-bridge-header related-tax-header" id="<?php echo esc_attr( $args['heading_id'] ); ?>"><?php echo esc_html( $args['heading_text'] ); ?></h3>
		<div class="network-bridge-links ec-cross-site-links">
			<?php foreach ( $cards as $card ) : ?>
				<?php if ( ! empty( $card['is_same_site'] ) ) : ?>
					<a href="<?php echo esc_url( $card['url'] ); ?>" class="button-3 button-small event-exploration-link"><?php echo esc_html( $card['term_name'] . ' ' . $card['label'] ); ?></a>
				<?php else : ?>
					<?php extrachill_cross_site_link_button( $card, 'network-bridge-link event-exploration-link' ); ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}
add_action( 'data_machine_events_action_buttons', 'ec_events_network_bridge', 30, 3 );
