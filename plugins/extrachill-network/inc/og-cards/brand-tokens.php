<?php
/**
 * Brand Tokens Bridge for Data Machine Image Templates.
 *
 * Reads the Extra Chill design system (root.css generated from
 * @extrachill/tokens) and feeds the colors/fonts/labels to the
 * Data Machine `datamachine/image_template/brand_tokens` filter.
 *
 * The theme stays a passive token publisher — it does not know that
 * Data Machine exists. This bridge file is the single point where the
 * EC platform tells DM how to look. Any DM-rendered image (OG cards,
 * social cards, etc.) network-wide picks up EC branding from here.
 *
 * @package ExtraChillNetwork\OgCards
 * @since 1.11.0
 */

namespace ExtraChillNetwork\OgCards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve an absolute path to a theme font file.
 *
 * GD only reads TTF/OTF — woff/woff2 are useless here. Returns null
 * when the requested file is missing so the caller can fall back to a
 * system font without throwing.
 *
 * @param string $filename Font filename relative to the active theme's /assets/fonts/.
 * @return string|null
 */
function font_path( string $filename ): ?string {
	$path = get_template_directory() . '/assets/fonts/' . $filename;
	return file_exists( $path ) ? $path : null;
}

/**
 * Resolve an absolute path to a file in the *main* site's uploads
 * directory, regardless of which site in the network is currently active.
 *
 * The Extra Chill wordmark is an org-level brand asset, not a per-site
 * one — it lives in the main site's media library. Templates render in
 * whatever site's context called them (e.g. events.extrachill.com), so a
 * plain `wp_upload_dir()` call here would resolve against the *current*
 * site's uploads path (`.../uploads/sites/7/...`) and silently miss the
 * file. Switching to the main site for this one lookup is the correct,
 * multisite-safe way to reach a shared asset by filesystem path — an
 * attachment ID would not work here at all, since attachment IDs are
 * scoped per-site and the main site's ID has no meaning in another
 * site's posts table.
 *
 * Returns null when the file is missing so callers can omit the token
 * entirely rather than pass a dangling path — EventOgCardTemplate is
 * built to treat an omitted/unreadable logo token as "not supplied" and
 * fall through to the next level of its own resolution chain.
 *
 * @param string $relative_path Path relative to the main site's uploads basedir.
 * @return string|null
 */
function main_site_upload_path( string $relative_path ): ?string {
	$main_site_id = is_multisite() ? get_main_site_id() : get_current_blog_id();
	$switched     = is_multisite() && get_current_blog_id() !== $main_site_id;

	if ( $switched ) {
		switch_to_blog( $main_site_id );
	}

	$upload_dir = wp_upload_dir();
	$path       = empty( $upload_dir['error'] )
		? trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative_path, '/' )
		: null;

	if ( $switched ) {
		restore_current_blog();
	}

	return ( null !== $path && file_exists( $path ) ) ? $path : null;
}

/**
 * Absolute path to the plugin-shipped light-ink logo, or null if missing.
 *
 * @return string|null
 */
function inverse_logo_path(): ?string {
	$path = dirname( __DIR__, 2 ) . '/assets/images/extra-chill-logo-inverse.png';
	return file_exists( $path ) ? $path : null;
}

/**
 * Map the current blog ID to the short label shown on OG cards.
 *
 * Returns an empty string for the main site so cards there read just
 * "Extra Chill" without a trailing separator. Filterable for new sites
 * or per-surface overrides.
 *
 * @return string
 */
function site_label(): string {
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
	 * @param string $label    Default label resolved from blog ID.
	 * @param int    $blog_id  Current blog ID.
	 */
	return (string) apply_filters( 'extrachill_og_card_site_label', $label, (int) get_current_blog_id() );
}

/**
 * Provide Extra Chill brand tokens to Data Machine image templates.
 *
 * Colors mirror the `:root` vars in the active theme's root.css. Fonts
 * point at the TTF files shipped with the theme.
 *
 * @param array  $tokens      Default tokens from Data Machine.
 * @param string $template_id Template requesting tokens (unused here — same brand for all templates).
 * @param mixed  $context     Optional context (typically a WP_Post).
 * @return array
 */
function provide_tokens( array $tokens, string $template_id = '', $context = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter callback contract; trailing args are part of the Data Machine filter signature.
	$colors = array(
		// Mirrors the EC light-mode palette from root.css.
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
		// Dark-mode palette, from the theme's root.css
		// `@media (prefers-color-scheme: dark)` block. Dark-background
		// renders (event posters) use these so they match the site in
		// dark mode instead of reusing light-mode accents.
		'dark_background' => '#1a1a1a',
		'dark_text'       => '#e5e5e5',
		'dark_muted'      => '#b0b0b0',
		'dark_accent'     => '#6ab312',
	);

	$fonts = array(
		'heading' => font_path( 'WilcoLoftSans-Treble.ttf' ),
		'body'    => font_path( 'helvetica.ttf' ),
		// Theme ships Lobster only as woff2 — fall back to the heading face
		// so brand strips still use a theme-shipped font, not system DejaVu.
		'brand'   => font_path( 'WilcoLoftSans-Treble.ttf' ),
		'mono'    => font_path( 'helvetica.ttf' ),
	);

	$tokens['colors']     = array_merge( (array) ( $tokens['colors'] ?? array() ), $colors );
	$tokens['fonts']      = array_merge( (array) ( $tokens['fonts'] ?? array() ), $fonts );
	$tokens['brand_text'] = 'Extra Chill';
	$tokens['site_label'] = site_label();

	// Logo token — dark ink, for use on light card backgrounds. Verified
	// transparent PNG, ink averaging rgb(0,0,30), works on white.
	$logo_path = main_site_upload_path( '2023/04/extra-chill-logo-no-bg.png' );
	if ( null !== $logo_path ) {
		$tokens['logo_path'] = $logo_path;
	}

	// Inverse logo token — light ink for dark backgrounds (dark OG cards,
	// event posters). Unlike the dark wordmark this ships with the plugin,
	// so it is versioned with the code that relies on it and never depends
	// on a particular site's media library. It is the media-library
	// wordmark with its colors negated (alpha preserved).
	$logo_path_inverse = inverse_logo_path();
	if ( null !== $logo_path_inverse ) {
		$tokens['logo_path_inverse'] = $logo_path_inverse;
	}

	return $tokens;
}

add_filter( 'datamachine/image_template/brand_tokens', __NAMESPACE__ . '\\provide_tokens', 10, 3 );

/**
 * Load and cache badge color pairs from the theme's root.css.
 *
 * `root.css` is generated from @extrachill/tokens at build time and is the
 * production source of truth on disk. We parse it once per request and
 * extract the `--badge-*-bg` / `--badge-*-text` pairs into a flat lookup
 * map keyed by the slug fragment (e.g. 'location-austin').
 *
 * @return array<string, array{bg: string, text: string}>
 */
function badge_token_map(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$path = get_template_directory() . '/assets/css/root.css';
	if ( ! file_exists( $path ) ) {
		$cache = array();
		return $cache;
	}

	$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem read of a trusted plugin asset.
	if ( ! $raw ) {
		$cache = array();
		return $cache;
	}

	if ( ! preg_match_all( '/--badge-([a-z0-9-]+?)-(bg|text):\s*([#a-fA-F0-9rgba(),.\s]+?);/', $raw, $matches, PREG_SET_ORDER ) ) {
		$cache = array();
		return $cache;
	}

	$pairs = array();
	foreach ( $matches as $match ) {
		$key  = $match[1];
		$role = $match[2];
		$val  = trim( $match[3] );

		$pairs[ $key ][ $role ] = $val;
	}

	$out = array();
	foreach ( $pairs as $key => $value ) {
		if ( isset( $value['bg'], $value['text'] ) ) {
			$out[ $key ] = array(
				'bg'   => $value['bg'],
				'text' => $value['text'],
			);
		}
	}

	$cache = $out;
	return $cache;
}

/**
 * Resolve badge colors for a single taxonomy term.
 *
 * @param \WP_Term $term     Term object.
 * @param string   $tax_slug Taxonomy slug (matches the badge naming, e.g. 'location').
 * @return array{bg: string, text: string}|null
 */
function term_badge_colors( \WP_Term $term, string $tax_slug ): ?array {
	$tokens = badge_token_map();
	$key    = $tax_slug . '-' . $term->slug;
	return $tokens[ $key ] ?? null;
}
