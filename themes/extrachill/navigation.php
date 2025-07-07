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
		// Post navigation options simplified - always show default navigation (no thumbnails)
		echo '<ul class="default-wp-page clearfix">';
		echo '<li class="previous">';
		previous_post_link( '%link', '<span class="meta-nav">' . _x( '&larr;', 'Previous post link', 'colormag-pro' ) . '</span> %title' );
		echo '</li>';
		echo '<li class="next">';
		next_post_link( '%link', '%title <span class="meta-nav">' . _x( '&rarr;', 'Next post link', 'colormag-pro' ) . '</span>' );
		echo '</li>';
		echo '</ul>';
	}
}
