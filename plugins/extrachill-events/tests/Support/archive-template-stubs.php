<?php
/**
 * Theme-owned helpers needed by archive template tests.
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! function_exists( 'extrachill_breadcrumbs' ) ) {
	/** Render no breadcrumb markup in the managed plugin test runtime. */
	function extrachill_breadcrumbs(): void {}
}
