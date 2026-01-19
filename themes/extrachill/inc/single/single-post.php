<?php
/**
 * Single Post Template
 *
 * @package ExtraChill
 * @since 1.0.0
 */

get_header(); ?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<section class="main-content">
	<?php
	while ( have_posts() ) :
		the_post();
		?>

		<?php extrachill_breadcrumbs(); ?>

<div class="single-post-card">
<article id="post-<?php the_ID(); ?>">
		<?php do_action( 'extrachill_before_post_content' ); ?>

	<header id="postvote">
		<?php do_action( 'extrachill_above_post_title' ); ?>
		<h1>
			<?php the_title(); ?>
		</h1>
	</header>
		<?php extrachill_entry_meta(); ?>

		<?php if ( has_post_thumbnail() ) : ?>
			<?php $featured_image_id = get_post_thumbnail_id(); ?>
		<figure class="wp-block-image size-large">
			<?php the_post_thumbnail( 'large' ); ?>
			<?php
			$featured_image_caption = wp_get_attachment_caption( $featured_image_id );
			if ( ! empty( $featured_image_caption ) ) {
				echo '<figcaption class="wp-element-caption">' . wp_kses_post( $featured_image_caption ) . '</figcaption>';
			}
			?>
		</figure>
	<?php endif; ?>

	<div class="entry-content">
		<?php the_content(); ?>
	</div>

		<?php do_action( 'extrachill_after_post_content' ); ?>
</article>
</div>

	<?php endwhile; ?>

<aside>
	<?php
	do_action( 'extrachill_before_comments_template' );
	if ( comments_open() || '0' != get_comments_number() ) {
		do_action( 'extrachill_comments_section' );
	}
	do_action( 'extrachill_after_comments_template' );
	require_once get_template_directory() . '/inc/single/related-posts.php';

	$post_id            = get_the_ID();
	$related_taxonomies = apply_filters( 'extrachill_related_posts_taxonomies', array( 'artist', 'venue' ), $post_id, get_post_type() );

	foreach ( $related_taxonomies as $taxonomy ) {
		extrachill_display_related_posts( $taxonomy, $post_id );
	}
	?>
</aside>
</section><!-- .main-content -->

<?php get_sidebar(); ?>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
