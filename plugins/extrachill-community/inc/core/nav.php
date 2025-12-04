<?php
/**
 * Navigation Integration
 *
 * Secondary header navigation for the community site.
 *
 * @package ExtraChillCommunity
 * @since 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add community quick links to secondary header.
 *
 * Uses hardcoded domain since this plugin runs on multiple sites.
 *
 * @hook extrachill_secondary_header_items
 * @param array $items Current secondary header items.
 * @return array Items with community links added.
 */
function extrachill_community_secondary_header_items( $items ) {
    $items[] = array(
        'url'      => 'https://community.extrachill.com/recent',
        'label'    => __( 'Recent', 'extra-chill-community' ),
        'priority' => 5,
    );
    $items[] = array(
        'url'      => 'https://community.extrachill.com/r/local-scenes',
        'label'    => __( 'Local Scenes', 'extra-chill-community' ),
        'priority' => 10,
    );
    $items[] = array(
        'url'      => 'https://community.extrachill.com/r/music-discussion',
        'label'    => __( 'Music Discussion', 'extra-chill-community' ),
        'priority' => 15,
    );
    return $items;
}
add_filter( 'extrachill_secondary_header_items', 'extrachill_community_secondary_header_items' );
