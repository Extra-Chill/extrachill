<?php
/**
 * The template used for displaying page content in page.php
 *
 * @package    ExtraChill
 * @since      1.0
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<?php do_action( 'extrachill_before_post_content' ); ?>

	<?php 
	/* Page featured image option removed - not used */
	if (function_exists('display_breadcrumbs')) {
		display_breadcrumbs(); 
	} 
	?>
	<header class="entry-header">
		<?php if ( is_front_page() ) : ?>
			<h2 class="entry-title">
				<?php the_title(); ?>
			</h2>
		<?php else : ?>
			<h1 class="entry-title">
				<?php the_title(); ?>
			</h1>
		<?php endif; ?>
	</header>

	<div class="entry-content">
		<?php
		the_content();

		wp_link_pages( array(
			            'before'      => '<div style="clear: both;"></div><div class="pagination clearfix">' . __( 'Pages:', 'extrachill' ),
			'after'       => '</div>',
			'link_before' => '<span>',
			'link_after'  => '</span>',
		) );
		?>
	</div>

	<div class="entry-footer">
		<?php
		// Edit button always shown - option removed
		edit_post_link( __( 'Edit', 'extrachill' ), '<span class="edit-link">', '</span>' );
		?>
	</div>

	<?php do_action( 'extrachill_after_post_content' ); ?>
</article>
