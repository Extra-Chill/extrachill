<?php
/**
 * Search Results Template
 *
 * Displays network-wide search results across all Extra Chill multisite properties.
 * Uses direct call to extrachill_multisite_search() for clean, explicit search logic.
 *
 * @package ExtraChill
 * @since 69.57
 */

get_header();

// Get search term
$search_term = get_search_query();

// Call multisite search directly - searches ALL sites by default
$results = array();
if ( function_exists( 'extrachill_multisite_search' ) && ! empty( $search_term ) ) {
	$results = extrachill_multisite_search(
		$search_term,
		array(), // Empty = search all network sites
		array(
			'post_types' => array( 'post', 'page', 'topic', 'reply', 'product', 'link_page' ),
			'limit'      => 20, // Per site
			'orderby'    => 'date',
			'order'      => 'DESC',
		)
	);
}

// Pagination
$posts_per_page    = get_option( 'posts_per_page', 10 );
$paged             = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
$total_results     = count( $results );
$offset            = ( $paged - 1 ) * $posts_per_page;
$paginated_results = array_slice( $results, $offset, $posts_per_page );
$max_pages         = ceil( $total_results / $posts_per_page );
?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<div class="full-width-breakout">
	<?php extrachill_breadcrumbs(); ?>

	<header class="page-header">
		<h1 class="page-title">
			Search Results for: <span class="search-query"><?php echo esc_html( $search_term ); ?></span>
		</h1>
		<p class="search-results-count">
			Found <?php echo esc_html( number_format( $total_results ) ); ?> results across all Extra Chill sites
		</p>
	</header>

	<?php if ( ! empty( $paginated_results ) ) : ?>
		<div class="article-container">
			<?php foreach ( $paginated_results as $result ) : ?>
				<?php extrachill_render_search_result( $result, $search_term ); ?>
			<?php endforeach; ?>
		</div>

		<?php
		// Custom pagination
		extrachill_search_pagination( $paged, $max_pages, $search_term );
		?>

	<?php else : ?>
		<div class="no-results">
			<h2>No results found</h2>
			<p>Try different keywords or browse our <a href="<?php echo home_url( '/blog/' ); ?>">latest articles</a>.</p>
			<?php get_search_form(); ?>
		</div>
	<?php endif; ?>
</div><!-- .full-width-breakout -->

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
