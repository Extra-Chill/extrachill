<?php
/**
 * Author Links Multisite Integration
 *
 * Centralized author profile URL logic across the multisite network with bidirectional linking.
 * Links ExtraChill.com users to author pages, community-only users to bbPress profiles.
 * Provides reciprocal community profile links on author archives via extrachill_after_author_bio hook.
 *
 * @package ExtraChill\Users
 * @since 0.1.0
 */

/**
 * Get the community profile URL for a user.
 *
 * @param int    $user_id User ID.
 * @param string $user_email Optional. Email address for lookup.
 * @return string Community profile URL or empty string.
 */
function ec_get_user_community_profile_url( $user_id, $user_email = '' ) {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id ) {
		return '';
	}

	$user_id        = absint( $user_id );
	$community_user = null;

	switch_to_blog( $community_blog_id );
	try {
		if ( ! empty( $user_email ) ) {
			$community_user = get_user_by( 'email', $user_email );
		}

		if ( ! $community_user && $user_id > 0 ) {
			$community_user = get_userdata( $user_id );
		}
	} finally {
		restore_current_blog();
	}

	if ( ! $community_user || empty( $community_user->user_nicename ) ) {
		return '';
	}

	return ec_get_site_url( 'community' ) . '/u/' . $community_user->user_nicename;
}

/**
 * Get the main-site author archive URL for a user.
 *
 * @param int $user_id User ID.
 * @return string Author archive URL or empty string.
 */
function ec_get_user_author_archive_url( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return '';
	}

	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		return '';
	}

	switch_to_blog( $main_blog_id );
	try {
		$author_url = get_author_posts_url( $user_id );
	} finally {
		restore_current_blog();
	}

	return $author_url;
}

/**
 * Get user profile URL.
 *
 * Resolution order: Community profile → Main site author archive → Default author URL.
 *
 * @param int    $user_id User ID.
 * @param string $user_email Optional. User email for lookup.
 * @return string User profile URL.
 */
function ec_get_user_profile_url( $user_id, $user_email = '' ) {
	$community_url = ec_get_user_community_profile_url( $user_id, $user_email );
	if ( ! empty( $community_url ) ) {
		return $community_url;
	}

	$author_archive_url = ec_get_user_author_archive_url( $user_id );
	if ( ! empty( $author_archive_url ) ) {
		return $author_archive_url;
	}

	return get_author_posts_url( $user_id );
}

/**
 * Get comment author link HTML with multisite profile URL.
 *
 * @param WP_Comment $comment Comment object
 * @return string Author link HTML
 */
function ec_get_comment_author_link_multisite($comment) {
    $author_url = ec_get_user_profile_url( $comment->user_id, $comment->comment_author_email );

    if ( $comment->user_id > 0 ) {
        return '<a href="' . esc_url( $author_url ) . '">' . get_comment_author( $comment ) . '</a>';
    }

    return get_comment_author_link( $comment );
}

/**
 * Check if comment should use multisite linking.
 *
 * @param WP_Comment $comment Comment object
 * @return bool True if comment after Feb 9, 2024
 */
function ec_should_use_multisite_comment_links($comment) {
    $comment_date = strtotime($comment->comment_date);
    $cutoff_date = strtotime('2024-02-09 00:00:00');

    return $comment_date > $cutoff_date;
}

/**
 * Customize comment form logged_in_as text with community profile edit link.
 *
 * Fixes broken wp-admin profile links by routing to community.extrachill.com bbPress profile.
 *
 * @param array $defaults Comment form defaults
 * @return array Modified defaults
 */
function ec_customize_comment_form_logged_in( $defaults ) {
	if ( ! is_user_logged_in() ) {
		return $defaults;
	}

	$user = wp_get_current_user();
	$profile_edit_url = ec_get_site_url( 'community' ) . '/u/' . $user->user_nicename . '/edit';
	$logout_url = wp_logout_url( home_url() );

	$defaults['logged_in_as'] = sprintf(
		__( 'Logged in as %1$s. <a href="%2$s">Edit profile</a> | <a href="%3$s">Log out</a>' ),
		$user->display_name,
		esc_url( $profile_edit_url ),
		esc_url( $logout_url )
	);

	return $defaults;
}
add_filter( 'comment_form_defaults', 'ec_customize_comment_form_logged_in' );

/**
 * Display community profile link on author archive pages.
 *
 * Provides reciprocal link from author archive to community profile,
 * matching the existing link from community profile to author archive.
 *
 * @param int $author_id Author user ID
 */
function ec_display_author_community_link( $author_id ) {
	if ( ! $author_id || ! is_int( $author_id ) ) {
		return;
	}

	$author_nicename = get_the_author_meta( 'user_nicename', $author_id );

	if ( empty( $author_nicename ) ) {
		return;
	}


	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( $community_blog_id ) {
		switch_to_blog( $community_blog_id );
		$community_user = get_userdata( $author_id );
		restore_current_blog();
	}

	if ( ! $community_user || empty( $community_user->user_nicename ) ) {
		return;
	}

	$community_profile_url = ec_get_site_url( 'community' ) . '/u/' . $community_user->user_nicename . '/';

	echo '<div class="author-community-link">';
	echo '<a href="' . esc_url( $community_profile_url ) . '" class="button-2 button-medium">' . esc_html__( 'View Community Profile', 'extrachill-users' ) . '</a>';
	echo '</div>';
}
add_action( 'extrachill_after_author_bio', 'ec_display_author_community_link', 10 );
