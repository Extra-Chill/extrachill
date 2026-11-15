<?php
/**
 * Artist routing, asset and defaults compatibility for the standalone public runtime.
 *
 * The public projection itself comes from the Artist_Link_Pages owner definition.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Preserve the historical public query variable. */
function ec_artist_link_page_public_query_var() {
	return 'artist_link_page';
}

/**
 * Preserve historical Artist public query variables.
 *
 * @param string[] $vars Public query variables.
 * @return string[]
 */
function ec_artist_link_page_public_query_vars( $vars ) {
	return array_values( array_unique( array_merge( $vars, array( 'artist_link_page', 'dev_view_link_page', 'artist_id' ) ) ) );
}

/**
 * Preserve historical Artist route exclusions.
 *
 * @param string[] $excluded Public route exclusions.
 * @return string[]
 */
function ec_artist_link_page_public_exclusions( $excluded ) {
	return array_values( array_unique( array_merge( $excluded, array( 'manage-artist', 'manage-link-page', 'join' ) ) ) );
}

/**
 * Project the historical join redirect without teaching generic routing its policy.
 *
 * @param mixed  $route Existing special route projection.
 * @param string $path  Requested public path.
 * @return mixed
 */
function ec_artist_link_page_public_special_route( $route, $path ) {
	if ( 'join' !== $path ) {
		return $route;
	}
	return array(
		'url'    => ec_get_site_url( 'artist' ) . '/login/?from_join=true',
		'status' => 301,
		'safe'   => false,
	);
}

/**
 * Enqueue only artist-owned public asset extensions.
 *
 * @param array $context Public runtime context.
 * @return true
 */
function ec_artist_link_page_enqueue_asset_extensions( $context ) {
	if ( class_exists( 'ExtraChillArtistPlatform_Fonts' ) ) {
		// Page-owned styles only: no Artist-site read on the public request.
		$data = ec_read_link_page_persistence( (int) $context['link_page_id'] );
		if ( is_wp_error( $data ) ) {
			return true;
		}
		$values  = array_filter( array( $data['css_vars']['--link-page-title-font-family'] ?? '', $data['css_vars']['--link-page-body-font-family'] ?? '' ) );
		$manager = ExtraChillArtistPlatform_Fonts::instance();
		$url     = $manager->get_google_fonts_url( $values );
		$css     = $manager->get_local_fonts_css( $values );
		if ( $url ) {
			wp_enqueue_style( 'extrch-link-page-artist-fonts', $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts URLs are immutable for their family query.
		}
		if ( $css ) {
			wp_add_inline_style( 'extrch-link-page', $css );
		}
	}
	return true;
}

/** Preserve legacy defaults globals while standalone owns their implementation. */
function ec_get_link_page_defaults() {
	return ec_link_page_defaults();
}

/**
 * Return one legacy defaults category from standalone storage.
 *
 * @param string $category Defaults category.
 * @return array
 */
function ec_get_link_page_defaults_for( $category ) {
	return ec_link_page_defaults_for( $category );
}

/**
 * Return one legacy default value from standalone storage.
 *
 * @param string $category Defaults category.
 * @param string $key      Defaults key.
 * @param mixed  $fallback Fallback value.
 * @return mixed
 */
function ec_get_link_page_default( $category, $key, $fallback = null ) {
	return ec_link_page_default( $category, $key, $fallback );
}
