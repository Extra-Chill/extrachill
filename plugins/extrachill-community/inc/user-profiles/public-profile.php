<?php
/**
 * Visibility-filtered public profile helpers.
 *
 * @package ExtraChill\Community
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get a user's public Local Scene through the Users-owned profile contract.
 *
 * @param int $user_id User ID.
 * @return array|null Resolved public Local Scene, or null when private/unavailable.
 */
function extrachill_community_get_public_local_scene( $user_id ) {
	static $scenes = array();

	$user_id = absint( $user_id );
	if ( ! $user_id || ! function_exists( 'wp_get_ability' ) ) {
		return null;
	}

	if ( array_key_exists( $user_id, $scenes ) ) {
		return $scenes[ $user_id ];
	}

	$ability = wp_get_ability( 'extrachill/get-user-profile' );
	if ( ! $ability ) {
		$scenes[ $user_id ] = null;
		return null;
	}

	$profile = $ability->execute( array( 'user_id' => $user_id ) );
	$scene   = ! is_wp_error( $profile ) && is_array( $profile ) && isset( $profile['local_scene'] ) && is_array( $profile['local_scene'] )
		? $profile['local_scene']
		: null;

	$scenes[ $user_id ] = $scene;
	return $scene;
}

/**
 * Render a canonical Local Scene as a linked platform location badge.
 *
 * @param array $scene   Visibility-filtered Local Scene data.
 * @param bool  $compact Whether to use only the city name.
 */
function extrachill_community_render_local_scene_badge( array $scene, bool $compact = false ): void {
	$name      = sanitize_text_field( $scene['name'] ?? '' );
	$hierarchy = is_array( $scene['hierarchy'] ?? null ) ? $scene['hierarchy'] : array();
	$label     = $compact ? $name : sanitize_text_field( $hierarchy['label'] ?? $name );
	$url       = esc_url( $scene['url'] ?? '' );
	$slug      = sanitize_title( $scene['slug'] ?? '' );

	if ( '' === $label ) {
		return;
	}

	$classes = 'taxonomy-badge location-badge';
	if ( '' !== $slug ) {
		$classes .= ' location-' . sanitize_html_class( $slug );
	}

	if ( '' !== $url ) {
		printf( '<a class="%1$s" href="%2$s">%3$s</a>', esc_attr( $classes ), $url, esc_html( $label ) );
		return;
	}

	printf( '<span class="%1$s">%2$s</span>', esc_attr( $classes ), esc_html( $label ) );
}
