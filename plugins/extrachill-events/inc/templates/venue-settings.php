<?php
/**
 * Venue settings application route.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
if ( function_exists( 'extrachill_breadcrumbs' ) ) {
	extrachill_breadcrumbs();
}
?>
<main class="page-content ec-venue-settings-page">
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns rendered block HTML.
	echo do_blocks( '<!-- wp:extrachill/venue-settings /-->' );
	?>
</main>
<?php get_footer(); ?>
