<?php
/**
 * Filter Bar Default Items
 *
 * Registers default dropdown items for theme archives.
 * Category, child terms, artist filter, and sort dropdowns.
 *
 * @package ExtraChill
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'extrachill_filter_bar_items', 'extrachill_filter_bar_archive_items' );

/**
 * Register archive filter bar items.
 *
 * @param array $items Existing items.
 * @return array Modified items.
 */
function extrachill_filter_bar_archive_items( $items ) {
	if ( ! is_archive() && ! get_query_var( 'extrachill_blog_archive' ) ) {
		return $items;
	}

	// Category dropdown (blog archive only).
	if ( get_query_var( 'extrachill_blog_archive' ) ) {
		$category_item = extrachill_build_category_dropdown();
		if ( $category_item ) {
			$items[] = $category_item;
		}
	}

	// Child terms dropdown (categories with children, location taxonomy).
	$child_item = extrachill_build_child_terms_dropdown();
	if ( $child_item ) {
		$items[] = $child_item;
	}

	// Artist filter (song-meanings/music-history categories).
	if ( is_category( 'song-meanings' ) || is_category( 'music-history' ) ) {
		$artist_item = extrachill_build_artist_dropdown();
		if ( $artist_item ) {
			$items[] = $artist_item;
		}
	}

	// Sort dropdown (always present on archives).
	$items[] = extrachill_build_sort_dropdown();

	// Search input (always present, positioned last/right).
	$items[] = array(
		'type'        => 'search',
		'id'          => 'filter-bar-search',
		'name'        => 's',
		'placeholder' => __( 'Search...', 'extrachill' ),
		'current'     => get_search_query(),
	);

	return $items;
}

/**
 * Build category dropdown for blog archive.
 *
 * Uses redirect type since categories need full URL navigation.
 *
 * @return array|null Dropdown item or null.
 */
function extrachill_build_category_dropdown() {
	$categories = get_categories( array( 'hide_empty' => false ) );

	if ( empty( $categories ) ) {
		return null;
	}

	$options = array( '' => __( 'Select Category', 'extrachill' ) );
	foreach ( $categories as $category ) {
		$options[ get_category_link( $category->term_id ) ] = $category->name;
	}

	return array(
		'type'     => 'dropdown',
		'id'       => 'filter-bar-category',
		'name'     => 'category_redirect',
		'options'  => $options,
		'current'  => '',
		'redirect' => true,
	);
}

/**
 * Build child terms dropdown for hierarchical taxonomies.
 *
 * Uses redirect type since child terms need full URL navigation.
 *
 * @return array|null Dropdown item or null.
 */
function extrachill_build_child_terms_dropdown() {
	$term = get_queried_object();

	if ( ! $term || ! is_a( $term, 'WP_Term' ) ) {
		return null;
	}

	$child_terms = array();
	$select_text = '';

	if ( is_category() ) {
		$child_terms = get_categories(
			array(
				'child_of'   => $term->term_id,
				'hide_empty' => false,
			)
		);
		$select_text = __( 'Select Subcategory', 'extrachill' );
	} elseif ( is_tax( 'location' ) ) {
		$child_terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
				'parent'     => $term->term_id,
			)
		);
		$select_text = __( 'Choose a Sub-Location', 'extrachill' );
	}

	if ( empty( $child_terms ) || is_wp_error( $child_terms ) ) {
		return null;
	}

	$options = array( '' => $select_text );
	foreach ( $child_terms as $child_term ) {
		$term_link             = is_category() ? get_category_link( $child_term->term_id ) : get_term_link( $child_term );
		$options[ $term_link ] = $child_term->name;
	}

	return array(
		'type'     => 'dropdown',
		'id'       => 'filter-bar-child-terms',
		'name'     => 'child_term_redirect',
		'options'  => $options,
		'current'  => '',
		'redirect' => true,
	);
}

/**
 * Build artist filter dropdown for specific categories.
 *
 * @return array|null Dropdown item or null.
 */
function extrachill_build_artist_dropdown() {
	$current_artist = get_query_var( 'artist' );
	$category_id    = get_queried_object_id();

	$artists = get_terms(
		array(
			'taxonomy'   => 'artist',
			'orderby'    => 'name',
			'order'      => 'ASC',
			'hide_empty' => true,
			'object_ids' => get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'category'    => $category_id,
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			),
		)
	);

	if ( empty( $artists ) || is_wp_error( $artists ) ) {
		return null;
	}

	$options = array( '' => __( 'All Artists', 'extrachill' ) );
	foreach ( $artists as $artist ) {
		$options[ $artist->slug ] = $artist->name;
	}

	return array(
		'type'    => 'dropdown',
		'id'      => 'filter-bar-artist',
		'name'    => 'artist',
		'options' => $options,
		'current' => $current_artist,
	);
}

/**
 * Build sort dropdown.
 *
 * @return array Dropdown item.
 */
function extrachill_build_sort_dropdown() {
	$current_sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'recent';

	$options = apply_filters(
		'extrachill_filter_bar_sort_options',
		array(
			'recent'  => __( 'Sort by Recent', 'extrachill' ),
			'oldest'  => __( 'Sort by Oldest', 'extrachill' ),
			'random'  => __( 'Sort by Random', 'extrachill' ),
			'popular' => __( 'Sort by Popular', 'extrachill' ),
		)
	);

	return array(
		'type'    => 'dropdown',
		'id'      => 'filter-bar-sort',
		'name'    => 'sort',
		'options' => $options,
		'current' => $current_sort,
	);
}
