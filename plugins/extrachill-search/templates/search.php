<?php
/**
 * Search Results Template for ExtraChill Search Plugin
 *
 * Displays multisite search results from across the WordPress network.
 *
 * @package ExtraChill\Search
 * @since 1.0.0
 */

get_header(); ?>

<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action('extrachill_before_body_content'); ?>

<?php
$search_data = extrachill_get_search_results();
$search_results = $search_data['results'];
$total_results = $search_data['total'];
$search_term = get_search_query();
$current_page = max( 1, get_query_var( 'paged' ) );
$posts_per_page = get_option( 'posts_per_page', 10 );
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
				$post->taxonomies = $result['taxonomies'];
				$post->_thumbnail = ! empty( $result['thumbnail'] ) ? $result['thumbnail'] : array();
				?>
				<?php get_template_part( 'inc/archives/post-card' ); ?>
				<?php $post_i++; ?>
			<?php endforeach; ?>
			<?php wp_reset_postdata(); ?>

			<?php
			// Display pagination using theme's native function with mock query
			if ( function_exists( 'extrachill_pagination' ) && function_exists( 'extrachill_create_search_query_object' ) ) {
				$search_query = extrachill_create_search_query_object( $total_results, $posts_per_page );
				extrachill_pagination( $search_query, 'search' );
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