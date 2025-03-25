<?php
/**
 * The template used for displaying page content in page.php
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<?php do_action( 'extrachill_before_post_content' ); ?>

	<?php if ( ( get_theme_mod( 'colormag_featured_image_single_page_show', 1 ) == 1 ) && ( has_post_thumbnail() ) ) { ?>
		<!-- Removed the featured image code -->
	<?php } ?>

	<header class="entry-header">
		<?php 
		// Display the breadcrumbs above the title
		if (function_exists('display_breadcrumbs')) {
			display_breadcrumbs(); 
		} 
		?>

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
			'before'      => '<div style="clear: both;"></div><div class="pagination clearfix">' . __( 'Pages:', 'colormag-pro' ),
			'after'       => '</div>',
			'link_before' => '<span>',
			'link_after'  => '</span>',
		) );
		?>
	</div>

	<div class="entry-footer">
		<?php
		// Edit button remove option add
		if ( get_theme_mod( 'colormag_edit_button_entry_meta_remove', 0 ) == 0 ) {
			edit_post_link( __( 'Edit', 'colormag-pro' ), '<span class="edit-link">', '</span>' );
		}
		?>
	</div>

	<?php do_action( 'extrachill_after_post_content' ); ?>
</article>
