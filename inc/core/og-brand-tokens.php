<?php
/**
 * OG Image Brand Tokens
 *
 * Supplies brand identity (colors, fonts, labels) to Data Machine's GD
 * image templates. Any plugin that renders an image via the
 * `datamachine/render-image-template` ability automatically gets an
 * Extra Chill–branded card when this filter is in place.
 *
 * Source of truth: /assets/css/root.css (CSS design tokens) and
 * /assets/fonts/ (TTF files that GD can rasterize).
 *
 * @package ExtraChill
 * @since 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Absolute path to a theme font file.
 *
 * @param string $filename Font filename relative to /assets/fonts/.
 * @return string|null Absolute path if the file exists, null otherwise.
 */
function extrachill_og_font_path( string $filename ): ?string {
	$path = get_template_directory() . '/assets/fonts/' . $filename;
	return file_exists( $path ) ? $path : null;
}

/**
 * Map the current blog ID to the site label shown on OG cards.
 *
 * Mirrors the network in NETWORK.md. Returns an empty string for the main
 * site so the main blog reads just "Extra Chill".
 *
 * @return string Short label for the current site.
 */
function extrachill_og_site_label(): string {
	$label = match ( (int) get_current_blog_id() ) {
		1       => '',
		2       => 'Community',
		3       => 'Shop',
		4       => 'Artists',
		7       => 'Events',
		9       => 'Newsletter',
		10      => 'Docs',
		11      => 'Wire',
		12      => 'Studio',
		default => '',
	};

	/**
	 * Filter the OG card site label for the current blog.
	 *
	 * Useful when adding new sites to the network or wanting a custom
	 * label per surface without touching theme code.
	 *
	 * @param string $label    Default label resolved from blog ID.
	 * @param int    $blog_id  Current blog ID.
	 */
	return (string) apply_filters( 'extrachill_og_site_label', $label, (int) get_current_blog_id() );
}

/**
 * Provide Extra Chill brand tokens to Data Machine image templates.
 *
 * Colors track the `:root` vars in assets/css/root.css. Fonts point at the
 * TTF files shipped with the theme (GD cannot use woff/woff2).
 *
 * @param array  $tokens      Default tokens from Data Machine.
 * @param string $template_id Template requesting tokens (unused — same brand everywhere).
 * @param mixed  $context     Optional context (WP_Post, data array).
 * @return array Branded token array.
 */
add_filter(
	'datamachine/image_template/brand_tokens',
	function ( array $tokens, string $template_id = '', $context = null ): array {
		$colors = array(
			// Mirrors root.css light-mode tokens.
			'background'      => '#ffffff',
			'background_dark' => '#000000',
			'surface'         => '#f1f5f9',
			'accent'          => '#53940b',
			'accent_hover'    => '#3d6b08',
			'accent_2'        => '#36454f',
			'accent_3'        => '#00c8e3',
			'text_primary'    => '#000000',
			'text_muted'      => '#6b7280',
			'text_inverse'    => '#ffffff',
			'header_bg'       => '#000000',
			'border'          => '#dddddd',
		);

		$fonts = array(
			'heading' => extrachill_og_font_path( 'WilcoLoftSans-Treble.ttf' ),
			'body'    => extrachill_og_font_path( 'helvetica.ttf' ),
			// Theme ships Lobster in woff2 only — GD cannot render it.
			// Fall back to the heading face for brand text so the card
			// still uses a theme-shipped font instead of system DejaVu.
			'brand'   => extrachill_og_font_path( 'WilcoLoftSans-Treble.ttf' ),
			'mono'    => extrachill_og_font_path( 'helvetica.ttf' ),
		);

		$tokens['colors']     = array_merge( $tokens['colors'] ?? array(), $colors );
		$tokens['fonts']      = array_merge( $tokens['fonts'] ?? array(), $fonts );
		$tokens['brand_text'] = 'Extra Chill';
		$tokens['site_label'] = extrachill_og_site_label();
		$tokens['logo_path']  = extrachill_og_font_path( '../images/logo-og.png' ) ?? null;

		return $tokens;
	},
	10,
	3
);
