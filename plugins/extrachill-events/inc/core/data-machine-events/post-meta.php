<?php
/**
 * Post Meta
 *
 * Hide theme post meta for data_machine_events post type.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize post meta hooks
 */
function extrachill_events_init_post_meta() {
	add_filter( 'extrachill_post_meta', 'extrachill_events_hide_post_meta', 10, 3 );
}

/**
 * Hide post meta for data_machine_events post type
 *
 * Event meta handled by data-machine-events plugin, prevents duplicate display.
 *
 * @param string $default_meta Default post meta HTML from theme.
 * @param int    $post_id      Post ID.
 * @param string $post_type    Post type.
 * @return string Empty for data_machine_events, unchanged for other post types.
 */
function extrachill_events_hide_post_meta( $default_meta, $post_id, $post_type ) {
	if ( $post_type === 'data_machine_events' ) {
		return '';
	}
	return $default_meta;
}
