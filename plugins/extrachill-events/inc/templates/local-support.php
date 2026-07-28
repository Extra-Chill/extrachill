<?php
/**
 * Private Local Support workspace route.
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
<main class="page-content ec-local-support-page">
	<?php extrachill_events_render_local_support_workspace(); ?>
</main>
<?php get_footer(); ?>
