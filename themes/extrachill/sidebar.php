<?php
/**
 * The Sidebar with hook-based content areas
 *
 * @package ExtraChill
 * @since 1.0
 */
?>

<aside id="secondary">
	<?php do_action( 'extrachill_before_sidebar' ); ?>

	<?php do_action( 'extrachill_sidebar_top' ); ?>

	<?php do_action( 'extrachill_sidebar_middle' ); ?>

	<?php do_action( 'extrachill_sidebar_bottom' ); ?>

	<?php do_action( 'extrachill_after_sidebar' ); ?>
</aside>
