<?php
/**
 * Universal Back to Home Navigation
 *
 * Smart navigation: subsite homepages link to main site, other pages link to current site homepage.
 * Hidden on main site homepage.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display smart navigation button
 */
function extrachill_display_back_to_home_link() {
	if ( is_main_site() && is_front_page() ) {
		return;
	}

	$site_title = extrachill_get_site_title();

	if ( ! is_main_site() && is_front_page() ) {
		$url   = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : home_url();
		$label = '← Back to ' . $site_title;
	} else {
		$url   = home_url();
		$label = '← Back to ' . $site_title;
	}

	// Allow plugins to override the label (e.g., "Back to Community", "Back to Merch Store")
	$label = apply_filters( 'extrachill_back_to_home_label', $label, $url );

	?>
	<div class="back-to-home-container">
		<a href="<?php echo esc_url( $url ); ?>" class="button-1 button-large"><?php echo esc_html( $label ); ?></a>
	</div>
	<?php
}
add_action( 'extrachill_above_footer', 'extrachill_display_back_to_home_link', 20 );
