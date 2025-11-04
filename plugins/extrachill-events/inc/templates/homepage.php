<?php
/**
 * Events Homepage Template
 *
 * Displays static homepage content (Settings → Reading → A static page).
 * Supports dm-events calendar block via WordPress editor.
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
