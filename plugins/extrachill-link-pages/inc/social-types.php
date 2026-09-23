<?php
/**
 * Owner-neutral social link type catalog for page-owned social links.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Return the runtime-owned catalog of known social link types. */
function ec_link_page_social_types() {
	static $types = null;
	if ( null === $types ) {
		$types = array(
			'apple_music' => array(
				'label' => 'Apple Music',
				'icon'  => 'fab fa-apple',
			),
			'bandcamp'    => array(
				'label' => 'Bandcamp',
				'icon'  => 'fab fa-bandcamp',
			),
			'bluesky'     => array(
				'label' => 'Bluesky',
				'icon'  => 'fa-brands fa-bluesky',
			),
			'custom'      => array(
				'label' => 'Custom Link',
				'icon'  => 'fas fa-link',
			),
			'facebook'    => array(
				'label' => 'Facebook',
				'icon'  => 'fab fa-facebook-f',
			),
			'github'      => array(
				'label' => 'GitHub',
				'icon'  => 'fab fa-github',
			),
			'instagram'   => array(
				'label' => 'Instagram',
				'icon'  => 'fab fa-instagram',
			),
			'patreon'     => array(
				'label' => 'Patreon',
				'icon'  => 'fab fa-patreon',
			),
			'pinterest'   => array(
				'label' => 'Pinterest',
				'icon'  => 'fab fa-pinterest',
			),
			'soundcloud'  => array(
				'label' => 'SoundCloud',
				'icon'  => 'fab fa-soundcloud',
			),
			'spotify'     => array(
				'label' => 'Spotify',
				'icon'  => 'fab fa-spotify',
			),
			'substack'    => array(
				'label' => 'Substack',
				'icon'  => 'fab fa-substack',
			),
			'tiktok'      => array(
				'label' => 'TikTok',
				'icon'  => 'fab fa-tiktok',
			),
			'twitch'      => array(
				'label' => 'Twitch',
				'icon'  => 'fab fa-twitch',
			),
			'twitter_x'   => array(
				'label' => 'Twitter / X',
				'icon'  => 'fab fa-x-twitter',
			),
			'venmo'       => array(
				'label' => 'Venmo',
				'icon'  => 'fab fa-venmo',
			),
			'website'     => array(
				'label' => 'Website',
				'icon'  => 'fas fa-globe',
			),
			'youtube'     => array(
				'label' => 'YouTube',
				'icon'  => 'fab fa-youtube',
			),
		);
		$types = apply_filters( 'ec_link_page_social_types', $types );
	}
	return $types;
}

/** Validate and sanitize a page-owned social links array. */
function ec_sanitize_link_page_social_links( $social_links ) {
	if ( ! is_array( $social_links ) ) {
		return new WP_Error( 'invalid_link_page_social_links', 'Link Page social links must be an array.' );
	}
	if ( count( $social_links ) > 20 ) {
		return new WP_Error( 'too_many_link_page_social_links', 'Too many Link Page social links were supplied.' );
	}
	$known     = ec_link_page_social_types();
	$sanitized = array();
	$seen_ids  = array();
	$sequence  = 0;
	foreach ( $social_links as $social ) {
		if ( ! is_array( $social ) ) {
			return new WP_Error( 'invalid_link_page_social_links', 'A Link Page social link is malformed.' );
		}
		++$sequence;
		$type = isset( $social['type'] ) ? sanitize_key( (string) $social['type'] ) : '';
		if ( ! isset( $known[ $type ] ) ) {
			return new WP_Error( 'invalid_link_page_social_type', 'A Link Page social link type is not supported.', array( 'type' => $type ) );
		}
		$url = isset( $social['url'] ) ? esc_url_raw( wp_unslash( (string) $social['url'] ), array( 'http', 'https' ) ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'invalid_link_page_social_url', 'A Link Page social link URL is invalid.' );
		}
		$id = isset( $social['id'] ) ? sanitize_text_field( (string) $social['id'] ) : '';
		if ( '' === $id || isset( $seen_ids[ $id ] ) ) {
			$id = $type . '-' . $sequence;
		}
		$seen_ids[ $id ] = true;
		$sanitized[]     = array(
			'id'   => $id,
			'type' => $type,
			'url'  => $url,
		);
	}
	return $sanitized;
}
