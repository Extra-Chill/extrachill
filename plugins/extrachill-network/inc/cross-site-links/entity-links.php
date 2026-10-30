<?php
/**
 * Cross-Site Entity Links
 *
 * Functions for linking user profiles and artist entities across sites.
 * Migrated from extrachill-users/inc/author-links.php and artist-profiles.php.
 *
 * @package ExtraChillNetwork
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the community profile URL for a user.
 *
 * @param int    $user_id    User ID.
 * @param string $user_email Optional. Email address for lookup.
 * @return string Community profile URL or empty string.
 */
function extrachill_get_user_community_profile_url( $user_id, $user_email = '' ) {
	$community_blog_id = ec_get_blog_id( 'community' );
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
 * Get the Community public-profile editor URL for a user.
 *
 * @param int    $user_id    User ID.
 * @param string $user_email Optional. Email address for lookup.
 * @return string Community profile edit URL or empty string.
 */
function extrachill_get_user_community_profile_edit_url( $user_id, $user_email = '' ) {
	$profile_url = extrachill_get_user_community_profile_url( $user_id, $user_email );
	if ( empty( $profile_url ) ) {
		return '';
	}

	$community_blog_id = ec_get_blog_id( 'community' );
	$user_id           = absint( $user_id );
	$community_user    = null;
	$edit_url          = '';
	$fallback_url      = '';

	switch_to_blog( $community_blog_id );
	try {
		$fallback_url = user_trailingslashit( trailingslashit( $profile_url ) . 'edit' );

		if ( ! empty( $user_email ) ) {
			$community_user = get_user_by( 'email', $user_email );
		}

		if ( ! $community_user && $user_id > 0 ) {
			$community_user = get_userdata( $user_id );
		}

		if ( $community_user && function_exists( 'bbp_get_user_profile_edit_url' ) ) {
			$edit_url = bbp_get_user_profile_edit_url( $community_user->ID, $community_user->user_nicename );
		}
	} finally {
		restore_current_blog();
	}

	if ( ! empty( $edit_url ) ) {
		return (string) $edit_url;
	}

	return $fallback_url;
}

/**
 * Get the main-site author archive URL for a user.
 *
 * @param int $user_id User ID.
 * @return string Author archive URL or empty string.
 */
function extrachill_get_user_author_archive_url( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return '';
	}

	$main_blog_id = ec_get_blog_id( 'main' );
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
 * Resolution order: Community profile -> Main site author archive -> Default author URL.
 *
 * @param int    $user_id    User ID.
 * @param string $user_email Optional. User email for lookup.
 * @return string User profile URL.
 */
function extrachill_get_user_profile_url( $user_id, $user_email = '' ) {
	$community_url = extrachill_get_user_community_profile_url( $user_id, $user_email );
	if ( ! empty( $community_url ) ) {
		return $community_url;
	}

	$author_archive_url = extrachill_get_user_author_archive_url( $user_id );
	if ( ! empty( $author_archive_url ) ) {
		return $author_archive_url;
	}

	return get_author_posts_url( $user_id );
}

/**
 * Get comment author link HTML with multisite profile URL.
 *
 * @param WP_Comment $comment Comment object.
 * @return string Author link HTML.
 */
function extrachill_get_comment_author_link_multisite( $comment ) {
	$author_url = extrachill_get_user_profile_url( (int) $comment->user_id, $comment->comment_author_email );

	if ( $comment->user_id > 0 ) {
		return '<a href="' . esc_url( $author_url ) . '">' . get_comment_author( $comment ) . '</a>';
	}

	return get_comment_author_link( $comment );
}

/**
 * Check if comment should use multisite linking.
 *
 * @param WP_Comment $comment Comment object.
 * @return bool True if comment after Feb 9, 2024.
 */
function extrachill_should_use_multisite_comment_links( $comment ) {
	$comment_date = strtotime( $comment->comment_date );
	$cutoff_date  = strtotime( '2024-02-09 00:00:00' );

	return $comment_date > $cutoff_date;
}

/**
 * Customize comment form logged_in_as text with community profile edit link.
 *
 * @param array $defaults Comment form defaults.
 * @return array Modified defaults.
 */
function extrachill_customize_comment_form_logged_in( $defaults ) {
	if ( ! is_user_logged_in() ) {
		return $defaults;
	}

	$user             = wp_get_current_user();
	$profile_edit_url = extrachill_get_user_community_profile_edit_url( $user->ID, $user->user_email );
	$logout_url       = wp_logout_url( home_url() );

	$defaults['logged_in_as'] = sprintf(
		/* translators: 1: user display name, 2: profile edit URL, 3: logout URL */
		__( 'Logged in as %1$s. <a href="%2$s">Edit profile</a> | <a href="%3$s">Log out</a>', 'extrachill-network' ),
		$user->display_name,
		esc_url( $profile_edit_url ),
		esc_url( $logout_url )
	);

	return $defaults;
}
add_filter( 'comment_form_defaults', 'extrachill_customize_comment_form_logged_in' );

/**
 * Get cross-site links for a user
 *
 * Returns links to community profile, author archive, and artist profiles if they exist.
 *
 * @param int $user_id User ID.
 * @return array Array of link data.
 */
function extrachill_get_cross_site_user_links( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return array();
	}

	$links            = array();
	$current_site_key = extrachill_get_current_site_key();

	// Check community profile.
	if ( 'community' !== $current_site_key ) {
		$community_url = extrachill_get_user_community_profile_url( $user_id );
		if ( ! empty( $community_url ) ) {
			$links[] = array(
				'type'  => 'community_profile',
				'url'   => $community_url,
				'label' => __( 'View Community Profile', 'extrachill-network' ),
			);
		}
	}

	// Check author archive (only if user has posts).
	if ( 'main' !== $current_site_key ) {
		$main_blog_id = ec_get_blog_id( 'main' );
		if ( $main_blog_id ) {
			switch_to_blog( $main_blog_id );
			try {
				$post_count = count_user_posts( $user_id, 'post', true );
				if ( $post_count > 0 ) {
					$author_url = get_author_posts_url( $user_id );
					$links[]    = array(
						'type'  => 'author_archive',
						'url'   => $author_url,
						'label' => __( 'View Blog Posts', 'extrachill-network' ),
						'count' => $post_count,
					);
				}
			} finally {
				restore_current_blog();
			}
		}
	}

	// Check artist profiles user manages.
	if ( 'artist' !== $current_site_key && function_exists( 'ec_get_artists_for_user' ) ) {
		$artist_ids = ec_get_artists_for_user( $user_id );
		if ( ! empty( $artist_ids ) ) {
			$artist_blog_id = ec_get_blog_id( 'artist' );
			if ( $artist_blog_id ) {
				switch_to_blog( $artist_blog_id );
				try {
					foreach ( $artist_ids as $artist_id ) {
						$artist_post = get_post( $artist_id );
						if ( $artist_post && 'publish' === $artist_post->post_status ) {
							$links[] = array(
								'type'  => 'artist_profile',
								'url'   => get_permalink( $artist_id ),
								'label' => $artist_post->post_title,
							);
						}
					}
				} finally {
					restore_current_blog();
				}
			}
		}
	}

	return $links;
}

/**
 * Get published artist profile by slug.
 *
 * @param string $slug Artist profile slug.
 * @return array|false Array with 'id' and 'permalink', or false.
 */
function extrachill_get_artist_profile_by_slug( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( empty( $slug ) ) {
		return false;
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return false;
	}

	switch_to_blog( $artist_blog_id );
	try {
		$posts = get_posts(
			array(
				'post_type'      => 'artist_profile',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			return false;
		}

		$artist_id   = (int) $posts[0];
		$artist_post = get_post( $artist_id );
		$post_type   = get_post_type_object( 'artist_profile' );
		$rewrite     = $post_type && ! empty( $post_type->rewrite['slug'] ) ? trim( $post_type->rewrite['slug'], '/' ) : 'artists';
		$permalink   = $artist_post ? home_url( $rewrite . '/' . $artist_post->post_name ) : '';
		// The artist CPT is not registered when switched from another blog, so
		// get_permalink() yields ?p=. Build the pretty URL and apply the target
		// blog's own trailing-slash rule (user_trailingslashit() would use the
		// origin blog's $wp_rewrite and add a 301-ing slash).
		if ( '' !== $permalink && function_exists( 'extrachill_normalize_url_for_blog' ) ) {
			$permalink = extrachill_normalize_url_for_blog( $permalink, $artist_blog_id );
		}

		if ( ! $permalink ) {
			return false;
		}

		return array(
			'id'        => $artist_id,
			'permalink' => $permalink,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Get artist's blog archive URL if posts exist
 *
 * Queries main site for matching artist taxonomy term.
 *
 * @param string $artist_slug Artist slug to search for.
 * @return array|null Array with 'url' and 'count', or null if not found.
 */
function extrachill_get_artist_blog_archive_url( $artist_slug ) {
	if ( empty( $artist_slug ) ) {
		return null;
	}

	$main_blog_id = ec_get_blog_id( 'main' );
	if ( ! $main_blog_id ) {
		return null;
	}

	switch_to_blog( $main_blog_id );
	try {
		$term = get_term_by( 'slug', $artist_slug, 'artist' );

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		// Get actual published post count.
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy' => 'artist',
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		if ( $query->found_posts < 1 ) {
			return null;
		}

		$archive_link = get_term_link( $term );
		if ( is_wp_error( $archive_link ) ) {
			return null;
		}

		return array(
			'url'   => $archive_link,
			'count' => $query->found_posts,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Get cross-site links for an artist profile page
 *
 * Returns links to blog coverage, upcoming events, and shop products.
 * Used on artist.extrachill.com profile pages.
 *
 * @param string $artist_slug Artist slug.
 * @return array Array of link data.
 */
function extrachill_get_cross_site_artist_links( $artist_slug ) {
	if ( empty( $artist_slug ) ) {
		return array();
	}

	$links = array();

	// Blog coverage.
	$archive = extrachill_get_artist_blog_archive_url( $artist_slug );
	if ( $archive ) {
		$links[] = array(
			'type'  => 'blog_archive',
			'url'   => $archive['url'],
			'label' => __( 'Blog', 'extrachill-network' ),
			'count' => $archive['count'],
		);
	}

	// Upcoming events via REST API.
	$events = extrachill_get_events_upcoming_count_via_api( $artist_slug, 'artist' );
	if ( $events && $events['count'] > 0 ) {
		$links[] = array(
			'type'  => 'events',
			'url'   => $events['url'],
			'label' => __( 'Events', 'extrachill-network' ),
			'count' => $events['count'],
		);
	}

	// Shop products via REST API.
	$shop = extrachill_get_shop_taxonomy_count_via_api( $artist_slug, 'artist' );
	if ( $shop && $shop['count'] > 0 ) {
		$links[] = array(
			'type'  => 'shop',
			'url'   => $shop['url'],
			'label' => __( 'Shop', 'extrachill-network' ),
			'count' => $shop['count'],
		);
	}

	return $links;
}
