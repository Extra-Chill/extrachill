<?php
/**
 * The template part for displaying navigation.
 *
 * @package    ExtraChill
 * @since      1.0
 */
?>

<?php
if ( is_archive() || is_home() || is_search() ) {
	/**
	 * Display pagination for archive pages
	 */
	extrachill_pagination(null, 'archive');
}

if ( is_single() ) {
	if ( is_attachment() ) {
		?>
		<ul class="default-wp-page clearfix">
			<li class="previous"><?php previous_image_link( false, __( '&larr; Previous', 'extrachill' ) ); ?></li>
<li class="next"><?php next_image_link( false, __( 'Next &rarr;', 'extrachill' ) ); ?></li>
		</ul>
		<?php
	} else {
		// Post navigation options simplified - always show default navigation (no thumbnails)
		echo '<ul class="default-wp-page clearfix">';
		echo '<li class="previous">';
		previous_post_link( '%link', '<span class="meta-nav">' . _x( '&larr;', 'Previous post link', 'extrachill' ) . '</span> %title' );
		echo '</li>';
		echo '<li class="next">';
		next_post_link( '%link', '%title <span class="meta-nav">' . _x( '&rarr;', 'Next post link', 'extrachill' ) . '</span>' );
		echo '</li>';
		echo '</ul>';
	}
}
