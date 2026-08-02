<?php
/**
 * Vendor request router template.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;
get_header();
if ( function_exists( 'extrachill_breadcrumbs' ) ) {
	extrachill_breadcrumbs();
}
?>
<main class="page-content ec-vendor-request-page">
	<?php extrachill_events_is_vendor_application_page() ? extrachill_events_render_vendor_application() : extrachill_events_render_vendor_request_workspace(); ?>
</main>
<?php get_footer(); ?>
