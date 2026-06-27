<?php
/**
 * From Around the Extra Chill Network — Single Post Bridge (thin consumer)
 *
 * Routes attention from high-traffic evergreen blog posts (the residual
 * song-meaning / music-history catalog) into the live platform surfaces that
 * breathe daily: the artist's profile hub, the events calendar, the festival
 * news wire, the shop, and the community.
 *
 * The bridge itself — terms resolution, transient caching, slot assembly, UTM
 * tagging, and render markup — lives in the shared primitive
 * `extrachill_render_network_bridge()` in extrachill-multisite. This file is a
 * thin hook that decides WHEN to render (single `post` views) and passes the
 * blog's per-site arguments. The shared primitive owns the rest.
 *
 * @package ExtraChillBlog
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the "From Around the Extra Chill Network" section on single posts.
 *
 * Hooked at priority 6 on `extrachill_after_post_content` so it renders just
 * after the share card (priority 5). Guarded to single `post` views.
 *
 * Renders NOTHING when the post carries no artist/festival terms or when no
 * cross-site content matches (no empty box) — that behavior lives in the
 * shared primitive.
 */
function extrachill_blog_network_bridge() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	if ( ! function_exists( 'extrachill_render_network_bridge' ) ) {
		return;
	}

	extrachill_render_network_bridge(
		array(
			'post_id'           => get_the_ID(),
			'taxonomies'        => array( 'artist', 'festival' ),
			'allowed_site_keys' => array( 'artist', 'events', 'wire', 'shop', 'community' ),
			'slot_order'        => array( 'artist', 'events', 'wire', 'shop', 'community' ),
			'utm_source'        => 'extrachill_blog',
			'cache_prefix'      => 'ec_blog_network_bridge_',
			'heading_id'        => 'network-bridge-header',
		)
	);
}
add_action( 'extrachill_after_post_content', 'extrachill_blog_network_bridge', 6 );
