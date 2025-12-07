<?php
/**
 * Navigation Integration
 *
 * Secondary header navigation for the main blog site.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add category quick links to secondary header
 *
 * @hook extrachill_secondary_header_items
 * @param array $items Current secondary header items.
 * @return array Items with blog category links added
 */
function extrachill_blog_secondary_header_items( $items ) {
	$items[] = array(
		'url'      => home_url( '/blog/' ),
		'label'    => __( 'Latest', 'extrachill-blog' ),
		'priority' => 5,
	);
	$items[] = array(
		'url'      => home_url( '/category/interviews/' ),
		'label'    => __( 'Interviews', 'extrachill-blog' ),
		'priority' => 10,
	);
	$items[] = array(
		'url'      => home_url( '/category/live-music-reviews/' ),
		'label'    => __( 'Reviews', 'extrachill-blog' ),
		'priority' => 15,
	);
	return $items;
}
add_filter( 'extrachill_secondary_header_items', 'extrachill_blog_secondary_header_items' );
