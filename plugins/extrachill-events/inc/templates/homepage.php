<?php
/**
 * Events Homepage Template
 *
 * Complete homepage template for events.extrachill.com
 * Overrides theme homepage via extrachill_template_homepage filter
 * Displays homepage content from WordPress editor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="events-calendar-container full-width-content">
	<?php
	// Get the homepage/front page post object
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
