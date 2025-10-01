<?php
/**
 * Search Results Template
 *
 * Displays multisite search results from across the WordPress network.
 * Calls extrachill_multisite_search() directly for cross-site results.
 *
 * @package ExtraChill
 * @since 1.0
 */

get_header(); ?>

<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action('extrachill_before_body_content'); ?>

<?php
$search_term = get_search_query();
$search_results = array();
$total_results = 0;
$current_page = max( 1, get_query_var( 'paged' ) );
$posts_per_page = get_option( 'posts_per_page', 10 );
$offset = ( $current_page - 1 ) * $posts_per_page;

if ( ! empty( $search_term ) && function_exists( 'extrachill_multisite_search' ) ) {
	$search_data = extrachill_multisite_search(
		$search_term,
		array(),
		array(
			'limit'        => $posts_per_page,
			'offset'       => $offset,
			'return_count' => true,
		)
	);

	if ( ! empty( $search_data ) && is_array( $search_data ) ) {
		$search_results = isset( $search_data['results'] ) ? $search_data['results'] : array();
		$total_results = isset( $search_data['total'] ) ? $search_data['total'] : 0;
	}
}
?>

<?php if ( ! empty( $search_results ) ) : ?>
	<?php extrachill_breadcrumbs(); ?>

	<?php do_action( 'extrachill_search_header' ); ?>

	<?php
	do_action( 'extrachill_archive_below_description' );
	do_action( 'extrachill_archive_above_posts' );
	?>

	<div class="full-width-breakout">
		<div class="article-container">
			<?php global $post_i; $post_i = 1; ?>
			<?php foreach ( $search_results as $result ) : ?>
				<?php
				// Create pseudo-post object for template compatibility
				global $post;
				$post = (object) $result;
				setup_postdata( $post );
				$post->_site_name = $result['site_name'];
				$post->_site_url  = $result['site_url'];
				?>
				<?php get_template_part( 'inc/archives/post-card' ); ?>
				<?php $post_i++; ?>
			<?php endforeach; ?>
			<?php wp_reset_postdata(); ?>

			<?php
			// Display pagination for search results
			if ( $total_results > 0 ) {
				$max_num_pages = ceil( $total_results / $posts_per_page );

				if ( $max_num_pages > 1 ) {
					// Calculate display values
					$start = ( ( $current_page - 1 ) * $posts_per_page ) + 1;
					$end = min( $current_page * $posts_per_page, $total_results );

					// Generate count display
					if ( $total_results == 1 ) {
						$count_html = 'Viewing 1 result';
					} elseif ( $end == $start ) {
						$count_html = sprintf( 'Viewing result %s of %s', number_format( $start ), number_format( $total_results ) );
					} else {
						$count_html = sprintf( 'Viewing results %s-%s of %s total', number_format( $start ), number_format( $end ), number_format( $total_results ) );
					}

					// Generate pagination links
					$big = 999999999;
					$links_html = paginate_links( array(
						'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
						'format'    => '?paged=%#%',
						'total'     => $max_num_pages,
						'current'   => $current_page,
						'prev_text' => '&laquo; Previous',
						'next_text' => 'Next &raquo;',
						'type'      => 'list',
						'end_size'  => 1,
						'mid_size'  => 2,
						'add_args'  => array( 's' => $search_term ),
					) );

					if ( $links_html ) {
						echo '<div class="extrachill-pagination pagination-search">';
						echo '<div class="pagination-count">' . esc_html( $count_html ) . '</div>';
						echo '<div class="pagination-links">' . $links_html . '</div>';
						echo '</div>';
					}
				}
			}
			?>
		</div><!-- .article-container -->
	</div><!-- .full-width-breakout -->

	<div class="back-home-link-container">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="back-home-link">← Back Home</a>
	</div>

<?php else : ?>
	<?php extrachill_breadcrumbs(); ?>
	<?php do_action( 'extrachill_search_header' ); ?>
	<?php extrachill_no_results(); ?>
<?php endif; ?>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
