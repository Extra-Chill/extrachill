<?php
/**
 * Events Homepage Template
 *
 * Renders static homepage content configured via Settings → Reading → "A static page".
 * Supports dm-events calendar block via WordPress editor.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @package ExtraChillEvents
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

extrachill_breadcrumbs();
?>

<div class="events-calendar-container full-width-content">
	<?php
	$homepage_id = get_option( 'page_on_front' );

	if ( $homepage_id ) {
		$homepage = get_post( $homepage_id );
		if ( $homepage ) {
			echo apply_filters( 'the_content', $homepage->post_content );
		}
	}
	?>
</div>

<?php
get_footer();
?>
