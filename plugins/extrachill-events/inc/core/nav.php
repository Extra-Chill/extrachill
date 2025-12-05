<?php
/**
 * Navigation Integration
 *
 * ExtraChill theme navigation hooks for the events site.
 *
 * @package ExtraChillEvents
 * @since 0.1.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add submit event link to secondary header
 *
 * @hook extrachill_secondary_header_items
 * @param array $items Current secondary header items
 * @return array Items with submit link added
 */
function extrachill_events_secondary_header_items( $items ) {
    $items[] = array(
        'url'      => home_url( '/submit/' ),
        'label'    => __( 'Submit Event', 'extrachill-events' ),
        'priority' => 10,
    );
    return $items;
}
add_filter( 'extrachill_secondary_header_items', 'extrachill_events_secondary_header_items' );
