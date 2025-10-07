<?php
/**
 * The Sidebar with hook-based content areas
 *
 * Supports full content replacement via extrachill_sidebar_content filter.
 * Falls back to hook-based sections for granular control.
 *
 * @package ExtraChill
 * @since 1.0
 */
?>

<aside class="sidebar">
	<?php
	$sidebar_content = apply_filters( 'extrachill_sidebar_content', false );

	if ( $sidebar_content !== false ) {
		echo $sidebar_content;
	} else {
		do_action( 'extrachill_before_sidebar' );
		do_action( 'extrachill_sidebar_top' );
		do_action( 'extrachill_sidebar_middle' );
		do_action( 'extrachill_sidebar_bottom' );
		do_action( 'extrachill_after_sidebar' );
	}
	?>
</aside>
