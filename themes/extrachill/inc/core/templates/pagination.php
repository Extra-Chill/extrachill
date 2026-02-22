<?php
/**
 * Pagination
 *
 * Native WordPress pagination with GET parameter preservation.
 * Supports WP_Query objects or array-based pagination data for custom queries.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render pagination for archives and custom queries
 *
 * @param WP_Query|array|null $query_or_data WP_Query object or array with keys: current_page, total_pages, total_items, per_page
 * @param string $context Context identifier for CSS class (e.g., 'archive', 'artist-archive')
 * @param string $item_label Singular label for items (e.g., 'post', 'artist') - used for count text
 */
function extrachill_pagination( $query_or_data = null, $context = 'default', $item_label = 'post' ) {
	global $wp_query;

	// Handle array-based pagination data for custom queries
	if ( is_array( $query_or_data ) ) {
		$current_page = isset( $query_or_data['current_page'] ) ? absint( $query_or_data['current_page'] ) : 1;
		$total_pages  = isset( $query_or_data['total_pages'] ) ? absint( $query_or_data['total_pages'] ) : 1;
		$total_items  = isset( $query_or_data['total_items'] ) ? absint( $query_or_data['total_items'] ) : 0;
		$per_page     = isset( $query_or_data['per_page'] ) ? absint( $query_or_data['per_page'] ) : 12;

		if ( $total_pages <= 1 ) {
			return;
		}
	} else {
		// Handle WP_Query object (existing behavior)
		$pagination_query = $query_or_data ? $query_or_data : $wp_query;

		if ( ! $pagination_query || $pagination_query->max_num_pages <= 1 ) {
			return;
		}

		if ( $query_or_data ) {
			$current_page = max( 1, $pagination_query->get( 'paged', 1 ) );
		} else {
			$current_page = max( 1, get_query_var( 'paged' ) );
		}

		$total_pages = $pagination_query->max_num_pages;
		$total_items = $pagination_query->found_posts;
		$per_page    = $pagination_query->query_vars['posts_per_page'];

		if ( $per_page == -1 ) {
			$per_page = $total_items;
		}
	}

	$start = ( ( $current_page - 1 ) * $per_page ) + 1;
	$end   = min( $current_page * $per_page, $total_items );

	// Build count text with dynamic item label
	$item_plural = $item_label . 's';
	if ( 1 == $total_items ) {
		$count_html = sprintf( 'Viewing 1 %s', $item_label );
	} elseif ( $end == $start ) {
		$count_html = sprintf( 'Viewing %s %s of %s', $item_label, number_format( $start ), number_format( $total_items ) );
	} else {
		$count_html = sprintf( 'Viewing %s %s-%s of %s total', $item_plural, number_format( $start ), number_format( $end ), number_format( $total_items ) );
	}

	$big      = 999999999;
	$base_url = str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) );

	$links_html = paginate_links(
		array(
			'base'      => $base_url,
			'format'    => '',
			'total'     => $total_pages,
			'current'   => $current_page,
			'prev_text' => '&laquo; Previous',
			'next_text' => 'Next &raquo;',
			'type'      => 'list',
			'end_size'  => 1,
			'mid_size'  => 2,
		)
	);

	// Add button-2 class to pagination links (skip current page - not clickable)
	if ( $links_html ) {
		$links_html = str_replace( 'class="prev page-numbers', 'class="prev page-numbers button-2 button-medium', $links_html );
		$links_html = str_replace( 'class="next page-numbers', 'class="next page-numbers button-2 button-medium', $links_html );
		$links_html = preg_replace( '/class="page-numbers"(?!\sclass)/', 'class="page-numbers button-2 button-medium"', $links_html );
	}

	if ( $links_html ) {
		echo '<div class="extrachill-pagination pagination-' . esc_attr( $context ) . '">';
		echo '<div class="pagination-count">' . esc_html( $count_html ) . '</div>';
		echo '<div class="pagination-links">' . $links_html . '</div>';
		echo '</div>';
	}
}

function extrachill_pagination_template_part() {
	extrachill_pagination();
}
