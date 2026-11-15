<?php
/**
 * iOS Smart App Banner
 *
 * Outputs <meta name="apple-itunes-app"> in wp_head on every network site
 * once the native app's App Store ID is configured in the network option
 * `extrachill_app_store_id`. Until then nothing is output, so this ships
 * safely before launch.
 *
 * The `app-argument` carries the page's final canonical URL so the app's
 * URL routing (and its deep links) can open the same screen.
 *
 * The Android counterpart is a visible, dismissible notice owned by the
 * extrachill theme (inc/core/app-prompt.php); this module owns head meta only.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the configured App Store ID.
 *
 * App Store IDs are numeric; anything else is stripped so a mistyped value
 * (e.g. "id123456789") still resolves, and a garbage value outputs nothing.
 *
 * @return string Numeric App Store ID, or empty string when unset.
 */
function ec_seo_get_app_store_id() {
	$store_id = get_site_option( 'extrachill_app_store_id', '' );

	if ( ! is_scalar( $store_id ) ) {
		return '';
	}

	return (string) preg_replace( '/\D+/', '', (string) $store_id );
}

/**
 * Build the apple-itunes-app meta content value.
 *
 * @param string $store_id  Numeric App Store ID.
 * @param string $canonical Canonical URL for the current page (may be empty).
 * @return string Content attribute value, or empty string without a store ID.
 */
function ec_seo_build_apple_itunes_app_content( $store_id, $canonical ) {
	if ( '' === $store_id ) {
		return '';
	}

	$content = 'app-id=' . $store_id;

	$canonical = esc_url_raw( (string) $canonical );
	if ( '' !== $canonical ) {
		$content .= ', app-argument=' . $canonical;
	}

	return $content;
}

/**
 * Render the apple-itunes-app meta tag for the current request.
 *
 * @return string Meta tag markup, or empty string when the store ID is unset.
 */
function ec_seo_get_apple_itunes_app_meta() {
	$content = ec_seo_build_apple_itunes_app_content(
		ec_seo_get_app_store_id(),
		ec_seo_get_final_canonical_url()
	);

	if ( '' === $content ) {
		return '';
	}

	return sprintf( '<meta name="apple-itunes-app" content="%s" />' . "\n", esc_attr( $content ) );
}

add_action(
	'wp_head',
	function () {
		echo ec_seo_get_apple_itunes_app_meta(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ec_seo_get_apple_itunes_app_meta().
	},
	5
);
