<?php
/**
 * Search Result Card Renderer
 *
 * Functions for rendering multisite search results with site badges and pagination.
 *
 * @package ExtraChill
 * @since 69.57
 */

/**
 * Render a single search result with site context
 *
 * @param array  $result      Search result from extrachill_multisite_search()
 * @param string $search_term Original search term for excerpt highlighting
 */
function extrachill_render_search_result( $result, $search_term ) {
	$site_badge_class = 'site-badge site-' . sanitize_title( $result['site_name'] );
	$post_type_label  = extrachill_get_post_type_label( $result['post_type'] );
	$excerpt          = ec_get_contextual_excerpt_multisite(
		wp_strip_all_tags( $result['post_content'] ),
		$search_term,
		30
	);
	?>
	<article class="post-card multisite-search-result">
		<div class="site-badges">
			<span class="<?php echo esc_attr( $site_badge_class ); ?>">
				From: <?php echo esc_html( $result['site_name'] ); ?>
			</span>
			<span class="post-type-badge">
				<?php echo esc_html( $post_type_label ); ?>
			</span>
		</div>

		<h2 class="post-title">
			<a href="<?php echo esc_url( $result['permalink'] ); ?>">
				<?php echo esc_html( $result['post_title'] ); ?>
			</a>
		</h2>

		<div class="post-meta">
			<span class="post-date">
				<time datetime="<?php echo esc_attr( $result['post_date'] ); ?>">
					<?php echo esc_html( date( 'F j, Y', strtotime( $result['post_date'] ) ) ); ?>
				</time>
			</span>
		</div>

		<div class="post-excerpt">
			<?php echo wp_kses_post( wpautop( $excerpt ) ); ?>
		</div>

		<a href="<?php echo esc_url( $result['permalink'] ); ?>" class="read-more">
			Read More →
		</a>
	</article>
	<?php
}

/**
 * Get human-readable post type label
 *
 * @param string $post_type WordPress post type slug
 * @return string Human-readable label
 */
function extrachill_get_post_type_label( $post_type ) {
	$labels = array(
		'post'      => 'Article',
		'page'      => 'Page',
		'topic'     => 'Forum Topic',
		'reply'     => 'Forum Reply',
		'product'   => 'Product',
		'link_page' => 'Artist Profile',
	);

	return isset( $labels[ $post_type ] ) ? $labels[ $post_type ] : ucfirst( str_replace( '_', ' ', $post_type ) );
}

/**
 * Display custom pagination for search results
 *
 * @param int    $current_page Current page number
 * @param int    $max_pages    Total number of pages
 * @param string $search_term  Search query for URL building
 */
function extrachill_search_pagination( $current_page, $max_pages, $search_term ) {
	if ( $max_pages <= 1 ) {
		return;
	}

	$base_url     = home_url( '/' );
	$search_param = urlencode( $search_term );

	echo '<nav class="pagination-nav" role="navigation" aria-label="Search results pagination">';
	echo '<div class="pagination-links">';

	// Previous
	if ( $current_page > 1 ) {
		$prev_page = $current_page - 1;
		$prev_url  = $prev_page > 1
			? add_query_arg( array( 's' => $search_param, 'paged' => $prev_page ), $base_url )
			: add_query_arg( array( 's' => $search_param ), $base_url );
		echo '<a href="' . esc_url( $prev_url ) . '" class="prev-page" rel="prev">← Previous</a>';
	}

	// Page numbers (show 5 pages max)
	$range = 2;
	$start = max( 1, $current_page - $range );
	$end   = min( $max_pages, $current_page + $range );

	if ( $start > 1 ) {
		$page_url = add_query_arg( array( 's' => $search_param ), $base_url );
		echo '<a href="' . esc_url( $page_url ) . '" class="page-number">1</a>';
		if ( $start > 2 ) {
			echo '<span class="page-dots">...</span>';
		}
	}

	for ( $i = $start; $i <= $end; $i++ ) {
		$page_url = $i > 1
			? add_query_arg( array( 's' => $search_param, 'paged' => $i ), $base_url )
			: add_query_arg( array( 's' => $search_param ), $base_url );
		$class    = ( $i === $current_page ) ? 'page-number current' : 'page-number';
		echo '<a href="' . esc_url( $page_url ) . '" class="' . esc_attr( $class ) . '" aria-current="' . ( $i === $current_page ? 'page' : 'false' ) . '">' . $i . '</a>';
	}

	if ( $end < $max_pages ) {
		if ( $end < $max_pages - 1 ) {
			echo '<span class="page-dots">...</span>';
		}
		$page_url = add_query_arg( array( 's' => $search_param, 'paged' => $max_pages ), $base_url );
		echo '<a href="' . esc_url( $page_url ) . '" class="page-number">' . $max_pages . '</a>';
	}

	// Next
	if ( $current_page < $max_pages ) {
		$next_url = add_query_arg( array( 's' => $search_param, 'paged' => $current_page + 1 ), $base_url );
		echo '<a href="' . esc_url( $next_url ) . '" class="next-page" rel="next">Next →</a>';
	}

	echo '</div>';
	echo '</nav>';
}
