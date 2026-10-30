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
 * Get a user's linked artist memberships as [ name => url ] pairs.
 *
 * Resolves artist post IDs through the canonical extrachill-users seam and
 * reads titles/slugs from the artist site. Returns an empty array when the
 * user has none or the seam is unavailable.
 *
 * @param int $user_id User ID.
 * @return array<string,string> Artist name => artist profile URL.
 */
function extrachill_community_get_artist_memberships( $user_id ) {
	if ( ! function_exists( 'ec_get_artists_for_user' ) || ! function_exists( 'ec_get_blog_id' ) ) {
		return array();
	}

	$artist_ids = ec_get_artists_for_user( (int) $user_id );
	if ( empty( $artist_ids ) ) {
		return array();
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return array();
	}

	$memberships = array();

	switch_to_blog( $artist_blog_id );
	try {
		foreach ( $artist_ids as $artist_id ) {
			$artist_post = get_post( $artist_id );
			if ( $artist_post && 'publish' === $artist_post->post_status ) {
				$memberships[ $artist_post->post_title ] = get_permalink( $artist_post );
			}
		}
	} finally {
		restore_current_blog();
	}

	return $memberships;
}

/**
 * Render the hero identity line: Local Scene and artist memberships.
 *
 * Replaces the old full-width Artists card (and the Local Scene line that
 * lived in the About card) with compact identity facts in the profile header:
 * "Local Scene: Charleston · Member of: Extra Chill". Artist names link to
 * their profiles on the artist site. Renders nothing when the user has
 * neither fact.
 */
function extrachill_community_display_identity_line() {
	$user_id = bbp_get_displayed_user_id();
	if ( ! $user_id ) {
		return;
	}

	$parts = array();

	$local_scene = extrachill_community_get_public_local_scene( (int) $user_id );
	if ( ! empty( $local_scene['name'] ) ) {
		// Compose the canonical linked location badge (Local Scene archives)
		// so the hero placement keeps the archive link.
		ob_start();
		extrachill_community_render_local_scene_badge( $local_scene, true );
		$scene_badge = trim( ob_get_clean() );
		if ( '' !== $scene_badge ) {
			$parts[] = '<strong>' . esc_html__( 'Local Scene:', 'extra-chill-community' ) . '</strong> ' . $scene_badge;
		}
	}

	$memberships = extrachill_community_get_artist_memberships( (int) $user_id );
	if ( ! empty( $memberships ) ) {
		$links = array();
		foreach ( $memberships as $name => $url ) {
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
		}
		$parts[] = '<strong>' . esc_html__( 'Member of:', 'extra-chill-community' ) . '</strong> ' . implode( ', ', $links );
	}

	if ( empty( $parts ) ) {
		return;
	}

	echo '<p class="bbp-user-identity-line">' . implode( '<span class="bbp-user-meta-sep" aria-hidden="true"> · </span>', $parts ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped at build time above.
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
	$slug      = sanitize_title( $scene['slug'] ?? '' );
	$url       = '' !== $slug ? extrachill_community_get_local_scene_url( $slug ) : '';
	$aria      = sprintf( 'Local Scene: %s', $label );

	if ( '' === $label ) {
		return;
	}

	$classes = 'taxonomy-badge location-badge';
	if ( '' !== $slug ) {
		$classes .= ' location-' . sanitize_html_class( $slug );
	}

	if ( '' !== $url ) {
		printf( '<a class="%1$s" href="%2$s" aria-label="%3$s">%4$s</a>', esc_attr( $classes ), esc_url( $url ), esc_attr( $aria ), esc_html( $label ) );
		return;
	}

	printf( '<span class="%1$s" aria-label="%2$s">%3$s</span>', esc_attr( $classes ), esc_attr( $aria ), esc_html( $label ) );
}
