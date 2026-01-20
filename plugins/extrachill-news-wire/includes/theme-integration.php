<?php
/**
 * Theme Integration
 *
 * Hooks into Extra Chill theme filters to register festival_wire post type
 * for theme assets and taxonomies. Keeps all EC-specific logic in the plugin
 * while allowing theme to remain generic.
 *
 * @package ExtraChillNewsWire
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register festival and location taxonomies for festival_wire post type.
 *
 * Theme registers these taxonomies for 'post' only. Plugin adds support
 * for festival_wire via register_taxonomy_for_object_type().
 */
function ec_news_wire_register_taxonomies_for_post_type() {
	if ( taxonomy_exists( 'festival' ) ) {
		register_taxonomy_for_object_type( 'festival', 'festival_wire' );
	}
	if ( taxonomy_exists( 'location' ) ) {
		register_taxonomy_for_object_type( 'location', 'festival_wire' );
	}
}
add_action( 'init', 'ec_news_wire_register_taxonomies_for_post_type', 20 );

/**
 * Add festival_wire to single post style post types.
 *
 * @param array $post_types Post types that load single-post.css.
 * @return array Modified post types.
 */
function ec_news_wire_single_post_style_types( $post_types ) {
	$post_types[] = 'festival_wire';
	return $post_types;
}
add_filter( 'extrachill_single_post_style_post_types', 'ec_news_wire_single_post_style_types' );

/**
 * Add festival_wire to sidebar style post types.
 *
 * @param array $post_types Post types that load sidebar.css.
 * @return array Modified post types.
 */
function ec_news_wire_sidebar_style_types( $post_types ) {
	$post_types[] = 'festival_wire';
	return $post_types;
}
add_filter( 'extrachill_sidebar_style_post_types', 'ec_news_wire_sidebar_style_types' );

/**
 * Provide custom sidebar recent posts for festival_wire singles.
 *
 * @param string|false $content Custom sidebar content or false for default.
 * @return string|false Modified content or false.
 */
function ec_news_wire_sidebar_recent_posts( $content ) {
	if ( ! is_singular( 'festival_wire' ) ) {
		return $content;
	}

	$post_id = get_the_ID();
	$args    = array(
		'post_type'      => 'festival_wire',
		'posts_per_page' => 3,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'post__not_in'   => array( $post_id ),
	);

	$query = new WP_Query( $args );

	if ( ! $query->have_posts() ) {
		return $content;
	}

	ob_start();
	echo '<div class="sidebar-card">';
	echo '<div class="widget my-recent-posts-widget">';
	echo '<div class="my-recent-posts">';
	echo '<h3 class="widget-title sidebar-recent-title-margin"><span>Latest Festival Wire</span></h3>';

	$counter = 0;
	while ( $query->have_posts() ) :
		$query->the_post();
		++$counter;
		echo '<div class="post mini-card">';
		if ( has_post_thumbnail() ) {
			echo '<a id="post-thumbnail-link-' . $counter . '" href="' . get_permalink() . '" aria-label="Read more about ' . esc_attr( get_the_title() ) . ', an image is attached"><div class="post-thumbnail">' . get_the_post_thumbnail( get_the_ID(), 'medium_large' ) . '</div></a>';
		}
		echo '<h2 class="recent-title"><a id="post-title-link-' . $counter . '" href="' . get_permalink() . '" aria-label="Read more about ' . esc_attr( get_the_title() ) . '">' . get_the_title() . '</a></h2>';
		echo '</div>';
	endwhile;

	echo '</div>';
	echo '</div>';
	echo '</div>';

	wp_reset_postdata();

	return ob_get_clean();
}
add_filter( 'extrachill_sidebar_recent_posts_content', 'ec_news_wire_sidebar_recent_posts' );
