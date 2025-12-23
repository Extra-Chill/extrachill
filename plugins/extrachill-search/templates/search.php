<?php
/**
 * Search Results Template for ExtraChill Search Plugin
 *
 * Displays multisite search results from across the WordPress network.
 *
 * @package ExtraChill\Search
 * @since 0.1.0
 */

get_header(); ?>

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
				global $post;
				$post = (object) $result;
				setup_postdata( $post );
				$post->_site_name = $result['site_name'];
				$post->_site_url  = $result['site_url'];
				$post->_origin_site_id = $result['site_id'];
				$post->taxonomies = $result['taxonomies'];
				$post->_thumbnail = isset( $result['thumbnail'] ) && ! empty( $result['thumbnail'] ) ? $result['thumbnail'] : array();
				$post->post_modified = $result['post_modified'];
				?>
				<?php get_template_part( 'inc/archives/post-card' ); ?>
				<?php $post_i++; ?>
			<?php endforeach; ?>
			<?php wp_reset_postdata(); ?>
		</div><!-- .article-container -->

		<?php
		if ( function_exists( 'extrachill_pagination' ) && function_exists( 'extrachill_create_search_query_object' ) ) {
			$search_query = extrachill_create_search_query_object( $total_results, $posts_per_page );
			extrachill_pagination( $search_query, 'search' );
		}
		?>
	</div><!-- .full-width-breakout -->

<?php else : ?>
	<?php extrachill_breadcrumbs(); ?>
	<?php do_action( 'extrachill_search_header' ); ?>
	<?php extrachill_no_results(); ?>
<?php endif; ?>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>