<?php
/**
 * From Around the Extra Chill Network — Single Post Bridge (thin consumer)
 *
 * Routes attention from high-traffic evergreen blog posts (the residual
 * song-meaning / music-history catalog) into the live platform surfaces that
 * breathe daily: the artist's profile hub, the events calendar, the festival
 * news wire, the shop, and the community.
 *
 * Cross-site resolution, transient caching, UTM tagging, and card rendering
 * stay in the shared Network primitives. This consumer only gives artist and
 * festival cards priority, then lets location and venue cards fill vacant
 * Events or Community slots.
 *
 * @package ExtraChillBlog
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the publication bridge configuration.
 *
 * @return array Bridge configuration.
 */
function extrachill_blog_network_bridge_args() {
	return array(
		'post_id'           => get_the_ID(),
		'allowed_site_keys' => array( 'artist', 'events', 'wire', 'shop', 'community' ),
		'slot_order'        => array( 'artist', 'events', 'wire', 'shop', 'community' ),
		'utm_source'        => 'extrachill_blog',
		'cache_prefix'      => 'ec_blog_network_bridge_',
		'heading_id'        => 'network-bridge-header',
		'heading_text'      => __( 'From Around the Extra Chill Network', 'extrachill-blog' ),
	);
}

/**
 * Resolve primary cards, then fill vacant geographic destination slots.
 *
 * Geography is bounded to Events and Community. Resolving it separately keeps
 * broad location counts from displacing artist or festival cards for the same
 * destination. Both passes use Network's verified resolver and cache path.
 *
 * @param array $args Bridge configuration.
 * @return array Ordered bridge cards.
 */
function extrachill_blog_network_bridge_cards( $args ) {
	$primary_cards = extrachill_network_bridge_get_cards(
		$args['post_id'],
		array( 'artist', 'festival' ),
		$args['allowed_site_keys'],
		$args['slot_order'],
		$args['utm_source'],
		$args['cache_prefix']
	);
	$by_site       = array();

	foreach ( $primary_cards as $card ) {
		if ( ! empty( $card['site_key'] ) ) {
			$by_site[ $card['site_key'] ] = $card;
		}
	}

	$geographic_slots = array( 'events', 'community' );
	if ( array_diff( $geographic_slots, array_keys( $by_site ) ) ) {
		$geographic_cards = extrachill_network_bridge_get_cards(
			$args['post_id'],
			array( 'location', 'venue' ),
			$geographic_slots,
			$geographic_slots,
			$args['utm_source'],
			$args['cache_prefix'] . 'geographic_'
		);

		foreach ( $geographic_cards as $card ) {
			$site_key = isset( $card['site_key'] ) ? $card['site_key'] : '';
			if ( in_array( $site_key, $geographic_slots, true ) && ! isset( $by_site[ $site_key ] ) ) {
				$by_site[ $site_key ] = $card;
			}
		}
	}

	$cards = array();
	foreach ( $args['slot_order'] as $site_key ) {
		if ( isset( $by_site[ $site_key ] ) ) {
			$cards[] = $by_site[ $site_key ];
		}
	}

	return $cards;
}

/**
 * Render the geographic-aware bridge on single posts.
 *
 * Hooked at priority 6 on `extrachill_after_post_content` so it renders just
 * after the share card (priority 5). Renders nothing when no verified
 * cross-site destination resolves.
 */
function extrachill_blog_network_bridge() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	if ( ! function_exists( 'extrachill_network_bridge_get_cards' )
		|| ! function_exists( 'extrachill_cross_site_link_button' ) ) {
		return;
	}

	$args  = extrachill_blog_network_bridge_args();
	$cards = extrachill_blog_network_bridge_cards( $args );
	if ( empty( $cards ) ) {
		return;
	}

	wp_enqueue_style( 'extrachill-network-bridge' );
	?>
	<div class="network-bridge-section related-tax-section" aria-labelledby="<?php echo esc_attr( $args['heading_id'] ); ?>">
		<h3 class="network-bridge-header related-tax-header" id="<?php echo esc_attr( $args['heading_id'] ); ?>"><?php echo esc_html( $args['heading_text'] ); ?></h3>
		<div class="network-bridge-links ec-cross-site-links">
			<?php foreach ( $cards as $card ) : ?>
				<?php extrachill_cross_site_link_button( $card, 'network-bridge-link' ); ?>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}
add_action( 'extrachill_after_post_content', 'extrachill_blog_network_bridge', 6 );
