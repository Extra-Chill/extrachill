<?php
/**
 * Icon Helper
 *
 * Centralized SVG sprite icon rendering for the Extra Chill platform.
 * The sprite is inlined into the page body for reliable cross-origin rendering
 * across all multisite subdomains (community, events, shop, etc.).
 *
 * @package ExtraChill
 */

/**
 * Inline the SVG sprite into the page body.
 *
 * Outputs the sprite as a hidden <svg> element right after <body> so all
 * ec_icon() references resolve locally via #fragment — no external HTTP
 * request, no cross-origin issues, no caching mismatches.
 */
function ec_inline_svg_sprite() {
	$sprite_path = get_template_directory() . '/assets/fonts/extrachill.svg';

	if ( ! file_exists( $sprite_path ) ) {
		return;
	}

	$svg = file_get_contents( $sprite_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read of a trusted theme asset.

	if ( empty( $svg ) ) {
		return;
	}

	// The sprite's root <svg> already has class="hidden" and zero dimensions.
	// Add aria-hidden and absolute positioning to keep it fully invisible.
	$svg = str_replace(
		'<svg ',
		'<svg aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden" ',
		$svg
	);

	echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted local SVG sprite file from theme assets.
}

add_action( 'wp_body_open', 'ec_inline_svg_sprite', 0 );

/**
 * Render an SVG icon from the inlined sprite.
 *
 * @param string $icon_id The symbol ID in extrachill.svg.
 * @param string $class   Additional CSS classes (optional).
 * @return string SVG markup.
 */
function ec_icon( $icon_id, $class = '' ) {
	$classes = 'ec-icon' . ( $class ? ' ' . esc_attr( $class ) : '' );

	return sprintf(
		'<svg class="%s"><use href="#%s"></use></svg>',
		$classes,
		esc_attr( $icon_id )
	);
}
