<?php
/**
 * Owner-neutral font catalog and CSS emission for Link Page rendering.
 *
 * The runtime owns the font catalog and emits Google Fonts links and local
 *
 * @font-face rules itself, so a page renders its selected typography with no
 * owner plugin loaded (extrachill-link-pages#37).
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Return the runtime-owned font catalog. */
function ec_link_page_font_catalog() {
	static $catalog = null;
	if ( null === $catalog ) {
		$catalog = array(
			array(
				'value'             => 'Helvetica',
				'label'             => 'Helvetica',
				'stack'             => "'Helvetica', Arial, sans-serif",
				'google_font_param' => 'local_default',
			),
			array(
				'value'             => 'Loft Sans',
				'label'             => 'Loft Sans',
				'stack'             => "'Loft Sans', Helvetica, Arial, sans-serif",
				'google_font_param' => 'local_default',
			),
			array(
				'value'             => 'Roboto',
				'label'             => 'Roboto',
				'stack'             => "'Roboto', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Roboto:wght@400;600;700',
			),
			array(
				'value'             => 'Open Sans',
				'label'             => 'Open Sans',
				'stack'             => "'Open Sans', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Open+Sans:wght@400;600;700',
			),
			array(
				'value'             => 'Lato',
				'label'             => 'Lato',
				'stack'             => "'Lato', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Lato:wght@400;600;700',
			),
			array(
				'value'             => 'Montserrat',
				'label'             => 'Montserrat',
				'stack'             => "'Montserrat', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Montserrat:wght@400;600;700',
			),
			array(
				'value'             => 'Oswald',
				'label'             => 'Oswald',
				'stack'             => "'Oswald', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Oswald:wght@400;600;700',
			),
			array(
				'value'             => 'Raleway',
				'label'             => 'Raleway',
				'stack'             => "'Raleway', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Raleway:wght@400;600;700',
			),
			array(
				'value'             => 'Poppins',
				'label'             => 'Poppins',
				'stack'             => "'Poppins', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Poppins:wght@400;600;700',
			),
			array(
				'value'             => 'Nunito',
				'label'             => 'Nunito',
				'stack'             => "'Nunito', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Nunito:wght@400;600;700',
			),
			array(
				'value'             => 'Caveat',
				'label'             => 'Caveat',
				'stack'             => "'Caveat', Helvetica, Arial, cursive",
				'google_font_param' => 'Caveat:wght@400;600;700',
			),
			array(
				'value'             => 'Space Grotesk',
				'label'             => 'Space Grotesk',
				'stack'             => "'Space Grotesk', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Space+Grotesk:wght@400;600;700',
			),
			array(
				'value'             => 'Bebas Neue',
				'label'             => 'Bebas Neue',
				'stack'             => "'Bebas Neue', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Bebas+Neue:wght@400',
			),
			array(
				'value'             => 'Inter',
				'label'             => 'Inter',
				'stack'             => "'Inter', Helvetica, Arial, sans-serif",
				'google_font_param' => 'Inter:wght@400;600;700',
			),
		);
		$catalog = apply_filters( 'ec_link_page_font_catalog', $catalog );
	}
	return $catalog;
}

/** Normalize a stored font value (bare name or resolved stack) to its primary name. */
function ec_link_page_normalize_font_value( $font_value ) {
	if ( ! is_string( $font_value ) ) {
		return '';
	}
	$font_value = trim( $font_value );
	if ( '' === $font_value ) {
		return '';
	}
	$primary = trim( explode( ',', $font_value )[0] );
	return trim( $primary, " \t\n\r\0\x0B'\"" );
}

/** Resolve a stored font value into its full CSS font-family stack. */
function ec_link_page_font_stack( $font_value ) {
	$normalized = ec_link_page_normalize_font_value( $font_value );
	if ( '' === $normalized ) {
		return "'Helvetica', Arial, sans-serif";
	}
	foreach ( ec_link_page_font_catalog() as $font ) {
		if ( $normalized === $font['value'] ) {
			return $font['stack'];
		}
	}
	// Already a multi-token stack (e.g. previously resolved, or an owner
	// projection override) — pass it through unchanged.
	if ( false !== strpos( (string) $font_value, ',' ) ) {
		return (string) $font_value;
	}
	return "'" . $normalized . "', 'Helvetica', Arial, sans-serif";
}

/** Return the Google Fonts query parameter for a font value, or null for a local font. */
function ec_link_page_google_font_param( $font_value ) {
	$normalized = ec_link_page_normalize_font_value( $font_value );
	foreach ( ec_link_page_font_catalog() as $font ) {
		if ( $normalized === $font['value'] ) {
			return 'local_default' !== $font['google_font_param'] ? $font['google_font_param'] : null;
		}
	}
	return null;
}

/** Build one Google Fonts stylesheet URL for the given font values. */
function ec_link_page_google_fonts_url( $font_values ) {
	$params = array();
	foreach ( (array) $font_values as $value ) {
		$param = ec_link_page_google_font_param( $value );
		if ( $param ) {
			$params[] = $param;
		}
	}
	if ( ! $params ) {
		return '';
	}
	return 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', array_unique( $params ) ) . '&display=swap';
}

/**
 * Base URL of a locally-hosted font face, supplied by the site.
 *
 * Portable by design: the runtime bundles no font files and assumes no theme,
 * so locally-hosted faces are resolved through the
 * `ec_link_page_local_font_face_url` filter. Catalog entries whose face no
 * site provides still render through their CSS fallback stack.
 *
 * @param string $font_value Normalized font name.
 * @return string Base URL without extension, or '' when no local face exists.
 */
function ec_link_page_local_font_face_url( $font_value ) {
	/**
	 * Filters the base URL (without extension) of a locally-hosted font face.
	 *
	 * The runtime ships no font files and assumes no theme, so it has no
	 * default: a site that offers a locally-hosted font in the catalog answers
	 * this filter with where its .woff2/.woff files live. Returning '' means
	 * "no local face", and the font falls back through its CSS stack.
	 *
	 * @param string $url        Base URL without extension, or ''.
	 * @param string $font_value Normalized font name.
	 */
	$url = apply_filters( 'ec_link_page_local_font_face_url', '', (string) $font_value );
	return is_string( $url ) ? $url : '';
}

/** Build @font-face CSS for every locally-hosted font among the given values. */
function ec_link_page_local_fonts_css( $font_values ) {
	$css     = '';
	$emitted = array();
	foreach ( (array) $font_values as $raw ) {
		$value = ec_link_page_normalize_font_value( $raw );
		if ( '' === $value || isset( $emitted[ $value ] ) ) {
			continue;
		}
		if ( null !== ec_link_page_google_font_param( $value ) ) {
			continue; // A Google-hosted font, not local.
		}
		$base = ec_link_page_local_font_face_url( $value );
		if ( '' === $base ) {
			continue;
		}
		$emitted[ $value ] = true;
		$css              .= "@font-face{font-family:'" . str_replace( "'", "\\'", $value ) . "';src:url('" . esc_url( $base . '.woff2' ) . "') format('woff2');font-weight:normal;font-style:normal;font-display:swap;}\n";
	}
	return $css;
}

/** Enqueue the Google Fonts stylesheet and local @font-face rules a page owns. */
function ec_enqueue_link_page_fonts( $link_page_id ) {
	$data = ec_read_link_page_persistence( $link_page_id );
	if ( is_wp_error( $data ) ) {
		return;
	}
	$values = array_values(
		array_filter(
			array(
				$data['css_vars']['--link-page-title-font-family'] ?? '',
				$data['css_vars']['--link-page-body-font-family'] ?? '',
			)
		)
	);
	if ( ! $values ) {
		return;
	}
	$url = ec_link_page_google_fonts_url( $values );
	if ( $url ) {
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts URLs are immutable for their family query.
		wp_enqueue_style( 'extrch-link-page-fonts', $url, array(), null );
	}
	$css = ec_link_page_local_fonts_css( $values );
	if ( $css ) {
		wp_add_inline_style( 'extrch-link-page', $css );
	}
}
