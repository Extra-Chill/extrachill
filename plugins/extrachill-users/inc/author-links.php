<?php
/**
 * Author Links Multisite Integration
 *
 * Centralized author profile URL logic across the multisite network with bidirectional linking.
 * Links ExtraChill.com users to author pages, community-only users to bbPress profiles.
 * Provides reciprocal community profile links on author archives via extrachill_after_author_bio hook.
 *
 * @package ExtraChill\Users
 * @since 1.0.0
 */

/**
 * Get user profile URL with intelligent multisite fallback.
 *
 * Resolution order: Main site author page → Community email lookup → Community ID lookup → Default author URL.
 *
 * @param int    $user_id User ID
 * @param string $user_email Optional. User email for fallback lookup
 * @return string User profile URL
 */
function ec_get_user_profile_url( $user_id, $user_email = '' ) {
    if ( $user_id > 0 && function_exists( 'ec_has_main_site_account' ) && ec_has_main_site_account( $user_id ) ) {

        switch_to_blog( 1 );
        $author_url = get_author_posts_url( $user_id );
        restore_current_blog();
        return $author_url;
    }

    if ( ! empty( $user_email ) ) {
        switch_to_blog( 2 );
        $community_user = get_user_by( 'email', $user_email );
        restore_current_blog();

        if ( $community_user && ! empty( $community_user->user_nicename ) ) {
            return 'https://community.extrachill.com/u/' . $community_user->user_nicename;
        }
    }

    if ( $user_id > 0 ) {
        switch_to_blog( 2 );
        $community_user = get_userdata( $user_id );
        restore_current_blog();

        if ( $community_user && ! empty( $community_user->user_nicename ) ) {
            return 'https://community.extrachill.com/u/' . $community_user->user_nicename;
        }
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
	$profile_edit_url = 'https://community.extrachill.com/u/' . $user->user_nicename . '/edit';
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


	switch_to_blog( 2 );
	$community_user = get_userdata( $author_id );
	restore_current_blog();

	if ( ! $community_user || empty( $community_user->user_nicename ) ) {
		return;
	}

	$community_profile_url = 'https://community.extrachill.com/u/' . $community_user->user_nicename . '/';

	echo '<div class="author-community-link">';
	echo '<a href="' . esc_url( $community_profile_url ) . '" class="button-2 button-medium">' . esc_html__( 'View Community Profile', 'extrachill-users' ) . '</a>';
	echo '</div>';
}
add_action( 'extrachill_after_author_bio', 'ec_display_author_community_link', 10 );
