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
 * @param int $post_id Optional post ID. Defaults to the current post.
 * @return array Bridge configuration.
 */
function extrachill_blog_network_bridge_args( $post_id = 0 ) {
	return array(
		'post_id'           => $post_id ? (int) $post_id : get_the_ID(),
		'allowed_site_keys' => array( 'artist', 'events', 'wire', 'shop', 'community' ),
		'slot_order'        => array( 'artist', 'events', 'wire', 'shop', 'community' ),
		'utm_source'        => 'extrachill_blog',
		'cache_prefix'      => 'ec_blog_network_bridge_',
		'heading_id'        => 'network-bridge-header',
		'heading_text'      => __( 'From Around the Extra Chill Network', 'extrachill-blog' ),
	);
}

/**
 * Register the browser activation script for geographic treatment cards.
 */
function extrachill_blog_register_geographic_bridge_script() {
	$path = EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/js/geographic-bridge-experiment.js';
	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_register_script(
		'extrachill-blog-geographic-bridge',
		EXTRACHILL_BLOG_PLUGIN_URL . 'assets/js/geographic-bridge-experiment.js',
		array( 'extrachill-experiment-assignment' ),
		(string) filemtime( $path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_blog_register_geographic_bridge_script', 6 );

/**
 * Register the code-owned geographic bridge holdout.
 *
 * @param array $definitions Existing experiment definitions.
 * @return array Experiment definitions.
 */
function extrachill_blog_register_bridge_experiment( $definitions ) {
	if ( ! is_array( $definitions ) ) {
		$definitions = array();
	}

	$definitions['geo-bridge-holdout'] = array(
		'default_variant'      => 'control',
		'control_variant'      => 'control',
		'variants'             => array(
			'control'   => 50,
			'treatment' => 50,
		),
		'surfaces'             => array( 'single-post-bridge' ),
		'eligibility_callback' => 'extrachill_blog_geographic_bridge_experiment_eligible',
	);

	return $definitions;
}
add_filter( 'extrachill_experiment_definitions', 'extrachill_blog_register_bridge_experiment' );

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
function extrachill_blog_network_bridge_card_groups( $args ) {
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

	$geographic_cards = array();
	$geographic_slots = array( 'events', 'community' );
	if ( array_diff( $geographic_slots, array_keys( $by_site ) ) ) {
		$candidates = extrachill_network_bridge_get_cards(
			$args['post_id'],
			array( 'location', 'venue' ),
			$geographic_slots,
			$geographic_slots,
			$args['utm_source'],
			$args['cache_prefix'] . 'geographic_'
		);

		foreach ( $candidates as $card ) {
			$site_key = isset( $card['site_key'] ) ? $card['site_key'] : '';
			if ( in_array( $site_key, $geographic_slots, true ) && ! isset( $by_site[ $site_key ] ) ) {
				$geographic_cards[ $site_key ] = $card;
				$by_site[ $site_key ]          = $card;
			}
		}
	}

	return array(
		'primary'    => $primary_cards,
		'geographic' => array_values( $geographic_cards ),
	);
}

/**
 * Resolve the complete treatment card set in canonical slot order.
 *
 * @param array $args Bridge configuration.
 * @return array Ordered bridge cards.
 */
function extrachill_blog_network_bridge_cards( $args ) {
	$groups  = extrachill_blog_network_bridge_card_groups( $args );
	$by_site = array();

	foreach ( array_merge( $groups['primary'], $groups['geographic'] ) as $card ) {
		if ( ! empty( $card['site_key'] ) && ! isset( $by_site[ $card['site_key'] ] ) ) {
			$by_site[ $card['site_key'] ] = $card;
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
 * Re-resolve whether a published post has vacant geographic bridge capacity.
 *
 * @param array  $context Assignment context.
 * @param string $surface Requested experiment surface.
 * @return bool Whether the post is eligible for geographic treatment.
 */
function extrachill_blog_geographic_bridge_experiment_eligible( $context, $surface ) {
	if ( 'single-post-bridge' !== $surface
		|| ! is_array( $context )
		|| ! isset( $context['post_id'] )
		|| ! is_scalar( $context['post_id'] )
		|| ! function_exists( 'extrachill_network_bridge_get_cards' )
		|| ! function_exists( 'get_post_type' )
		|| ! function_exists( 'get_post_status' ) ) {
		return false;
	}

	$post_id = (int) $context['post_id'];
	if ( $post_id <= 0
		|| (string) $post_id !== (string) $context['post_id']
		|| 'post' !== get_post_type( $post_id )
		|| 'publish' !== get_post_status( $post_id ) ) {
		return false;
	}

	$groups = extrachill_blog_network_bridge_card_groups( extrachill_blog_network_bridge_args( $post_id ) );

	return ! empty( $groups['geographic'] );
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

	$args   = extrachill_blog_network_bridge_args();
	$groups = extrachill_blog_network_bridge_card_groups( $args );
	if ( empty( $groups['primary'] ) && empty( $groups['geographic'] ) ) {
		return;
	}

	$experiment_attributes = '';
	if ( ! empty( $groups['geographic'] )
		&& function_exists( 'extrachill_experiment_attributes' )
		&& function_exists( 'wp_script_is' )
		&& wp_script_is( 'extrachill-experiment-assignment', 'registered' )
		&& wp_script_is( 'extrachill-blog-geographic-bridge', 'registered' ) ) {
		$experiment_attributes = extrachill_experiment_attributes(
			'geo-bridge-holdout',
			'single-post-bridge',
			array( 'post_id' => $args['post_id'] )
		);
	}

	if ( empty( $groups['primary'] ) && '' === $experiment_attributes ) {
		return;
	}

	if ( '' !== $experiment_attributes ) {
		wp_enqueue_script( 'extrachill-blog-geographic-bridge' );
	} else {
		$groups['geographic'] = array();
	}

	$cards_by_site    = array();
	$geographic_sites = array();
	foreach ( $groups['primary'] as $card ) {
		if ( ! empty( $card['site_key'] ) ) {
			$cards_by_site[ $card['site_key'] ] = $card;
		}
	}
	foreach ( $groups['geographic'] as $card ) {
		if ( ! empty( $card['site_key'] ) && ! isset( $cards_by_site[ $card['site_key'] ] ) ) {
			$cards_by_site[ $card['site_key'] ]    = $card;
			$geographic_sites[ $card['site_key'] ] = true;
		}
	}

	wp_enqueue_style( 'extrachill-network-bridge' );
	?>
	<div class="network-bridge-section related-tax-section" aria-labelledby="<?php echo esc_attr( $args['heading_id'] ); ?>" <?php echo $experiment_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Network returns fully escaped, cache-neutral attributes. ?><?php echo empty( $groups['primary'] ) ? ' hidden' : ''; ?>>
		<h3 class="network-bridge-header related-tax-header" id="<?php echo esc_attr( $args['heading_id'] ); ?>"><?php echo esc_html( $args['heading_text'] ); ?></h3>
		<div class="network-bridge-links ec-cross-site-links">
			<?php foreach ( $args['slot_order'] as $site_key ) : ?>
				<?php if ( isset( $cards_by_site[ $site_key ] ) ) : ?>
					<?php if ( isset( $geographic_sites[ $site_key ] ) ) : ?>
						<span class="extrachill-blog-geographic-bridge-candidates" hidden inert aria-hidden="true">
					<?php endif; ?>
						<?php extrachill_cross_site_link_button( $cards_by_site[ $site_key ], 'network-bridge-link' ); ?>
					<?php if ( isset( $geographic_sites[ $site_key ] ) ) : ?>
						</span>
					<?php endif; ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}
add_action( 'extrachill_after_post_content', 'extrachill_blog_network_bridge', 6 );
