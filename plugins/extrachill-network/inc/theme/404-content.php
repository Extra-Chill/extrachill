<?php
/**
 * Extra Chill 404 Page Content
 *
 * Provides Extra Chill-specific 404 messaging and navigation links.
 *
 * @package ExtraChill\Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'extrachill_404_heading', 'extrachill_network_404_heading' );
add_filter( 'extrachill_404_message', 'extrachill_network_404_message' );
add_filter( 'extrachill_preload_fonts', 'extrachill_network_preload_fonts' );
add_filter( 'extrachill_404_heading', 'extrachill_network_404_heading' );
add_filter( 'extrachill_404_message', 'extrachill_network_404_message' );
add_filter( 'extrachill_fallback_error_heading', 'extrachill_network_fallback_error_heading' );
add_action( 'extrachill_404_content_links', 'extrachill_network_404_content_links' );

function extrachill_network_preload_fonts( $fonts ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract; the incoming value is intentionally overridden.
	return array(
		array(
			'url'  => get_template_directory_uri() . '/assets/fonts/WilcoLoftSans-Treble.woff2',
			'as'   => 'font',
			'type' => 'font/woff2',
		),
		array(
			'url'  => get_template_directory_uri() . '/assets/fonts/Lobster2.woff2',
			'as'   => 'font',
			'type' => 'font/woff2',
		),
	);
}

function extrachill_network_404_heading( $heading ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract; the incoming value is intentionally overridden.
	return "Well, that's not very chill of us.";
}

function extrachill_network_404_message( $message ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract; the incoming value is intentionally overridden.
	return "We can't find what you're looking for. Try a search instead.";
}

function extrachill_network_fallback_error_heading( $heading ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract; the incoming value is intentionally overridden.
	return 'Yeah, something is royally f*cked.';
}

function extrachill_network_404_content_links() {
	$main_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : home_url();
	?>
	<p><?php esc_html_e( 'Think this page should exist?', 'extrachill' ); ?> <a href="<?php echo esc_url( $main_site_url ); ?>/contact/"><?php esc_html_e( 'Let us know.', 'extrachill' ); ?></a></p>
	<div class="error-404-links">
		<a href="<?php echo esc_url( $main_site_url ); ?>/contact/" class="button-2 button-medium"><?php esc_html_e( 'Contact Us', 'extrachill' ); ?></a>
		<a href="<?php echo esc_url( ec_get_site_url( 'docs' ) ); ?>" class="button-2 button-medium"><?php esc_html_e( 'Browse Documentation', 'extrachill' ); ?></a>
		<a href="<?php echo esc_url( ec_get_site_url( 'community' ) . '/r/tech-support' ); ?>" class="button-2 button-medium"><?php esc_html_e( 'Tech Support Forum', 'extrachill' ); ?></a>
	</div>
	<?php
}
