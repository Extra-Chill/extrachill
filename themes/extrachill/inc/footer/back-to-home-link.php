<?php
/**
 * Universal Back to Home Navigation Link
 *
 * Displays smart navigation button before footer on all pages except main homepage.
 * - Main homepage: No button
 * - Subsite homepages: Link to main site
 * - All other pages: Link to current site homepage
 *
 * @package ExtraChill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display smart navigation button with conditional logic.
 */
function extrachill_display_back_to_home_link() {
	// No button on main site homepage
	if ( is_main_site() && is_front_page() ) {
		return;
	}

	// Determine URL and label based on context
	if ( ! is_main_site() && is_front_page() ) {
		// Subsite homepages link to main site
		$url   = 'https://extrachill.com';
		$label = '← Back to Main Site';
	} else {
		// All other pages link to current site homepage
		$url   = home_url();
		$label = '← Back to Home';
	}

	?>
	<div class="back-to-home-container">
		<a href="<?php echo esc_url( $url ); ?>" class="button-1 button-large"><?php echo esc_html( $label ); ?></a>
	</div>
	<?php
}
add_action( 'extrachill_above_footer', 'extrachill_display_back_to_home_link', 20 );
