<?php
/**
 * Custom Avatar Display System
 *
 * Filters pre_get_avatar and pre_get_avatar_data to provide custom avatars
 * before Gravatar fallback. Uses custom_avatar_id user meta (WordPress
 * attachment ID stored on the community subsite).
 *
 * Performance: resolves avatar data once per user per request via static cache,
 * minimizing switch_to_blog() calls. Serves size-appropriate images and includes
 * loading="lazy", decoding="async", and srcset attributes.
 *
 * @package ExtraChill\Users
 */

/**
 * Resolve user from mixed identifier.
 *
 * @param mixed $id_or_email User ID, email, WP_User, WP_Post, WP_Comment, or object with user_id.
 * @return WP_User|false
 */
function extrachill_resolve_avatar_user( $id_or_email ) {
	if ( $id_or_email instanceof WP_User ) {
		return $id_or_email;
	}

	if ( is_numeric( $id_or_email ) ) {
		return get_user_by( 'id', (int) $id_or_email );
	}

	if ( $id_or_email instanceof WP_Post ) {
		return get_user_by( 'id', (int) $id_or_email->post_author );
	}

	if ( $id_or_email instanceof WP_Comment ) {
		return ! empty( $id_or_email->user_id )
			? get_user_by( 'id', (int) $id_or_email->user_id )
			: ( $id_or_email->comment_author_email ? get_user_by( 'email', $id_or_email->comment_author_email ) : false );
	}

	if ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
		return get_user_by( 'id', (int) $id_or_email->user_id );
	}

	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		return get_user_by( 'email', $id_or_email );
	}

	return false;
}

/**
 * Select the best WordPress image size for the requested pixel size.
 *
 * @param int $size Requested avatar size in pixels.
 * @return string WordPress image size name.
 */
function extrachill_avatar_image_size( $size ) {
	if ( $size <= 150 ) {
		return 'thumbnail';
	}

	if ( $size <= 400 ) {
		return 'medium';
	}

	return 'large';
}

/**
 * Resolve avatar attachment data for a user, with per-request static cache.
 *
 * Returns cached attachment ID or false. Only calls switch_to_blog() on the
 * first lookup for each user within a single PHP request.
 *
 * @param int $user_id WordPress user ID.
 * @return int|false Attachment ID on the community blog, or false.
 */
function extrachill_get_avatar_attachment_id( $user_id ) {
	static $cache = array();

	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id || ! function_exists( 'switch_to_blog' ) ) {
		$cache[ $user_id ] = false;
		return false;
	}

	switch_to_blog( $community_blog_id );

	try {
		$custom_avatar_id = get_user_option( 'custom_avatar_id', $user_id );

		if ( $custom_avatar_id && wp_attachment_is_image( $custom_avatar_id ) ) {
			$cache[ $user_id ] = (int) $custom_avatar_id;
		} else {
			$cache[ $user_id ] = false;
		}
	} finally {
		restore_current_blog();
	}

	return $cache[ $user_id ];
}

/**
 * Get avatar image URL for a given attachment and size, with static cache.
 *
 * Caches resolved URLs keyed by attachment_id:wp_size to avoid repeated
 * switch_to_blog() calls for the same image at the same size.
 *
 * @param int    $attachment_id Attachment ID on the community blog.
 * @param string $wp_size       WordPress image size name.
 * @return string|false Image URL or false.
 */
function extrachill_get_avatar_url_cached( $attachment_id, $wp_size ) {
	static $url_cache = array();
	$cache_key        = $attachment_id . ':' . $wp_size;

	if ( isset( $url_cache[ $cache_key ] ) ) {
		return $url_cache[ $cache_key ];
	}

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id ) {
		return false;
	}

	switch_to_blog( $community_blog_id );

	try {
		$url                     = wp_get_attachment_image_url( $attachment_id, $wp_size );
		$url_cache[ $cache_key ] = $url ? $url : false;
	} finally {
		restore_current_blog();
	}

	return $url_cache[ $cache_key ];
}

/**
 * Provide custom avatar HTML before Gravatar fallback.
 *
 * Hooked to pre_get_avatar. Returns a complete <img> tag with proper sizing,
 * lazy loading, async decoding, and srcset when available.
 *
 * @param string|null $avatar      Avatar HTML or null.
 * @param mixed       $id_or_email User identifier.
 * @param array       $args        Avatar arguments including size, alt, class, loading.
 * @return string|null Avatar HTML or null for Gravatar fallback.
 */
function extrachill_custom_avatar( $avatar, $id_or_email, $args ) {
	$user = extrachill_resolve_avatar_user( $id_or_email );
	if ( ! $user ) {
		return null;
	}

	$attachment_id = extrachill_get_avatar_attachment_id( $user->ID );
	if ( ! $attachment_id ) {
		return null;
	}

	$size    = isset( $args['size'] ) ? (int) $args['size'] : 96;
	$wp_size = extrachill_avatar_image_size( $size );
	$src     = extrachill_get_avatar_url_cached( $attachment_id, $wp_size );

	if ( ! $src ) {
		return null;
	}

	$alt = isset( $args['alt'] ) ? $args['alt'] : '';

	// Build CSS classes.
	$class = array( 'avatar', 'avatar-' . $size, 'photo' );
	if ( ! empty( $args['class'] ) ) {
		if ( is_array( $args['class'] ) ) {
			$class = array_merge( $class, $args['class'] );
		} else {
			$class[] = $args['class'];
		}
	}

	// Build srcset for 2x display density when a larger size is available.
	$srcset_attr = '';
	if ( $size <= 200 ) {
		$wp_size_2x = extrachill_avatar_image_size( $size * 2 );
		if ( $wp_size_2x !== $wp_size ) {
			$src_2x = extrachill_get_avatar_url_cached( $attachment_id, $wp_size_2x );
			if ( $src_2x && $src_2x !== $src ) {
				$srcset_attr = sprintf( ' srcset="%s 1x, %s 2x"', esc_url( $src ), esc_url( $src_2x ) );
			}
		}
	}

	// Respect loading preference from args (WP passes 'lazy' by default).
	$loading = isset( $args['loading'] ) && in_array( $args['loading'], array( 'lazy', 'eager' ), true )
		? $args['loading']
		: 'lazy';

	return sprintf(
		'<img src="%1$s"%2$s alt="%3$s" width="%4$d" height="%4$d" class="%5$s" loading="%6$s" decoding="async" />',
		esc_url( $src ),
		$srcset_attr,
		esc_attr( $alt ),
		$size,
		esc_attr( implode( ' ', $class ) ),
		esc_attr( $loading )
	);
}
add_filter( 'pre_get_avatar', 'extrachill_custom_avatar', 10, 3 );

/**
 * Provide custom avatar URL data before Gravatar fallback.
 *
 * Hooked to pre_get_avatar_data. Used by get_avatar_url() which does not
 * trigger pre_get_avatar. Shares the same cache layer.
 *
 * @param array $args        Avatar data arguments.
 * @param mixed $id_or_email User identifier.
 * @return array Modified args with url set, or unmodified for Gravatar fallback.
 */
function extrachill_custom_avatar_data( $args, $id_or_email ) {
	$user = extrachill_resolve_avatar_user( $id_or_email );
	if ( ! $user ) {
		return $args;
	}

	$attachment_id = extrachill_get_avatar_attachment_id( $user->ID );
	if ( ! $attachment_id ) {
		return $args;
	}

	$size    = isset( $args['size'] ) ? (int) $args['size'] : 96;
	$wp_size = extrachill_avatar_image_size( $size );
	$url     = extrachill_get_avatar_url_cached( $attachment_id, $wp_size );

	if ( $url ) {
		$args['url']          = $url;
		$args['found_avatar'] = true;
	}

	return $args;
}
add_filter( 'pre_get_avatar_data', 'extrachill_custom_avatar_data', 10, 2 );

/**
 * Legacy migration: Generate custom_avatar_id from custom_avatar URL.
 */
function generate_custom_avatar_ids() {
	$users_with_custom_avatars = get_users(
		array(
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'     => 'custom_avatar_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'custom_avatar',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	foreach ( $users_with_custom_avatars as $user ) {
		$custom_avatar_url = get_user_meta( $user->ID, 'custom_avatar', true );
		$attachment_id     = attachment_url_to_postid( $custom_avatar_url );

		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			add_user_meta( $user->ID, 'custom_avatar_id', $attachment_id, true );
			printf( "User %d: Added custom avatar ID.\n", (int) $user->ID );
		} else {
			printf( "User %d: Failed to add custom avatar ID.\n", (int) $user->ID );
		}
	}

	echo "Custom avatar ID generation completed.\n";
}

/**
 * Handle admin trigger for avatar ID migration.
 */
add_action( 'admin_init', 'handle_custom_avatar_id_generation' );
function handle_custom_avatar_id_generation() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin-only maintenance trigger gated by capability and explicit query arg.
	if ( isset( $_GET['generate_custom_avatar_ids'] ) && current_user_can( 'manage_options' ) ) {
		generate_custom_avatar_ids();
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}
