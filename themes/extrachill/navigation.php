<?php
/**
 * The template part for displaying navigation.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */
?>

<?php
if ( is_archive() || is_home() || is_search() ) {
	/**
	 * Checking WP-PageNaviplugin exist
	 */
	if ( function_exists( 'wp_pagenavi' ) ) :
		wp_pagenavi();

	else:
		global $wp_query;
		if ( $wp_query->max_num_pages > 1 ) :
			?>
			<ul class="default-wp-page clearfix">
				<li class="previous"><?php next_posts_link( __( '&larr; Previous', 'colormag-pro' ) ); ?></li>
				<li class="next"><?php previous_posts_link( __( 'Next &rarr;', 'colormag-pro' ) ); ?></li>
			</ul>
		<?php
		endif;
	endif;
}

if ( is_single() ) {
	if ( is_attachment() ) {
		?>
		<ul class="default-wp-page clearfix">
			<li class="previous"><?php previous_image_link( false, __( '&larr; Previous', 'colormag-pro' ) ); ?></li>
			<li class="next"><?php next_image_link( false, __( 'Next &rarr;', 'colormag-pro' ) ); ?></li>
		</ul>
		<?php
	} else {
		if ( get_theme_mod( 'colormag_post_navigation', 'default' ) == 'small_featured_image' ) {
			$prev_post = get_previous_post();
			if ( $prev_post ) {
				$prev_thumb_image = get_the_post_thumbnail( $prev_post->ID, 'colormag-featured-post-small' );
			} else {
				$prev_thumb_image = '';
			}

			// function to retrieve next post
			$next_post = get_next_post();
			if ( $next_post ) {
				$next_thumb_image = get_the_post_thumbnail( $next_post->ID, 'colormag-featured-post-small' );
			} else {
				$next_thumb_image = '';
			}
			?>

			<ul class="default-wp-page clearfix thumbnail-pagination">
				<?php if ( get_previous_post_link() ) { ?>
					<li class="previous">
						<?php previous_post_link( $prev_thumb_image . '%link', '<span class="meta-nav">' . _x( '&larr; Previous', 'Previous post link', 'colormag-pro' ) . '</span> %title' ); ?>
					</li>
				<?php } ?>

				<?php if ( get_next_post_link() ) { ?>
					<li class="next">
						<?php next_post_link( '%link' . $next_thumb_image, '%title <span class="meta-nav">' . _x( 'Next &rarr;', 'Next post link', 'colormag-pro' ) . '</span>' ); ?>
					</li>
				<?php } ?>
			</ul>

		<?php } elseif ( get_theme_mod( 'colormag_post_navigation', 'default' ) == 'medium_featured_image' ) {
			$prev_post = get_previous_post();
			if ( $prev_post ) {
				$prev_thumb_image = get_the_post_thumbnail( $prev_post->ID, 'colormag-featured-post-medium' );
			} else {
				$prev_thumb_image = '';
			}

			// function to retrieve next post
			$next_post = get_next_post();
			if ( $next_post ) {
				$next_thumb_image = get_the_post_thumbnail( $next_post->ID, 'colormag-featured-post-medium' );
			} else {
				$next_thumb_image = '';
			}
			?>

			<ul class="default-wp-page clearfix thumbnail-background-pagination">
				<?php if ( get_previous_post_link() ) { ?>
					<li class="previous">
						<?php previous_post_link( $prev_thumb_image . '%link', '<span class="meta-nav">' . _x( '&larr; Previous', 'Previous post link', 'colormag-pro' ) . '</span> %title' ); ?>
					</li>
				<?php } ?>

				<?php if ( get_next_post_link() ) { ?>
					<li class="next">
						<?php next_post_link( '%link' . $next_thumb_image, '<span class="meta-nav">' . _x( 'Next &rarr;', 'Next post link', 'colormag-pro' ) . '</span> %title' ); ?>
					</li>
				<?php } ?>
			</ul>

		<?php } else { ?>

			<ul class="default-wp-page clearfix">
				<li class="previous"><?php previous_post_link( '%link', '<span class="meta-nav">' . _x( '&larr;', 'Previous post link', 'colormag-pro' ) . '</span> %title' ); ?></li>
				<li class="next"><?php next_post_link( '%link', '%title <span class="meta-nav">' . _x( '&rarr;', 'Next post link', 'colormag-pro' ) . '</span>' ); ?></li>
			</ul>
			<?php
		}
	}
}

?>
