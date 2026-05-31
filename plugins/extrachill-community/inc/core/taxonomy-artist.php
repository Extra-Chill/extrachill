<?php
/**
 * Artist Taxonomy Integration for bbPress
 *
 * Registers the shared 'artist' taxonomy (defined in the Extra Chill theme)
 * for bbPress topics, enabling artist-based identity on forum content. This is
 * the keystone wire for the artist/fan engagement loop (epics #53, #80).
 *
 * The taxonomy itself is defined in the theme's custom-taxonomies.php on the
 * 'post' type at init priority 5; this file only attaches it to the community
 * 'topic' CPT, mirroring the existing 'location' integration.
 *
 * @package ExtraChillCommunity
 * @subpackage Core
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the artist taxonomy for the bbPress topic post type.
 *
 * The 'artist' taxonomy is defined in the Extra Chill theme (network-active on
 * all sites) and registered on the 'post' type at init priority 5. This runs at
 * priority 20 so the taxonomy already exists when we attach it, matching the
 * 'location' integration in taxonomy-location.php.
 *
 * Scope: topics only. Replies inherit their parent topic's artist context, so
 * tagging at the topic level is the high-value target; attaching to 'reply'
 * would duplicate identity per-post without adding signal.
 */
function extrachill_register_artist_for_bbpress() {
    if ( ! taxonomy_exists( 'artist' ) ) {
        return;
    }

    register_taxonomy_for_object_type( 'artist', 'topic' );
}
add_action( 'init', 'extrachill_register_artist_for_bbpress', 20 );

// Note: theme registers the 'artist' taxonomy; this file only attaches it to
// the bbPress 'topic' CPT.
