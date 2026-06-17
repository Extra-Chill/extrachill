<?php
declare(strict_types=1);
/**
 * Handler: extrachill/get-artist-platform-stats
 *
 * Point-in-time platform aggregates the event stream can't reconstruct:
 * total published artist profiles, total published link pages, profiles
 * created in the last N days, and link pages with at least one view or
 * click in the last N days.
 *
 * Funnel conversion-over-time (created/requested/approved counts) is NOT
 * served here — those are analytics events read via
 * extrachill/get-analytics-summary. This ability only covers the
 * point-in-time aggregates that the event stream cannot derive.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return point-in-time artist platform aggregates.
 *
 * @param array $input {
 *     @type int $days Window (in days) for the "recent" aggregates. Default 28.
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_get_artist_platform_stats( array $input ): array|WP_Error {
	$days = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 28;

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'dependency_missing', 'Multisite not configured.' );
	}

	$did_switch = false;
	if ( get_current_blog_id() !== $artist_blog_id ) {
		switch_to_blog( $artist_blog_id );
		$did_switch = true;
	}

	global $wpdb;

	// Total published artist profiles (reuse the artists-list ability so the
	// count stays canonical and is never duplicated here). Fall back to a
	// direct found_posts count only if the ability is unavailable, so the
	// metric is never silently zero.
	$total_artist_profiles = null;
	$list_ability          = wp_get_ability( 'extrachill/artists-list' );
	if ( $list_ability ) {
		$list_result = $list_ability->execute(
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);
		if ( ! is_wp_error( $list_result ) && isset( $list_result['total'] ) ) {
			$total_artist_profiles = (int) $list_result['total'];
		}
	}
	if ( null === $total_artist_profiles ) {
		$profile_query         = new WP_Query(
			array(
				'post_type'      => 'artist_profile',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);
		$total_artist_profiles = (int) $profile_query->found_posts;
	}

	// Total published link pages.
	$link_page_query  = new WP_Query(
		array(
			'post_type'      => 'artist_link_page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);
	$total_link_pages = (int) $link_page_query->found_posts;

	// Artist profiles created in the last N days.
	$profiles_created_recent = 0;
	if ( $days > 0 ) {
		$created_query           = new WP_Query(
			array(
				'post_type'      => 'artist_profile',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'date_query'     => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) ),
						'column'    => 'post_date_gmt',
						'inclusive' => true,
					),
				),
			)
		);
		$profiles_created_recent = (int) $created_query->found_posts;
	}

	// Link pages with at least one view or click in the last N days.
	$views_table  = $wpdb->prefix . 'extrch_link_page_daily_views';
	$clicks_table = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
	$since        = $days > 0 ? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) : '1970-01-01';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$active_link_page_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT link_page_id FROM {$views_table} WHERE stat_date >= %s AND view_count > 0
			 UNION
			 SELECT link_page_id FROM {$clicks_table} WHERE stat_date >= %s AND click_count > 0",
			$since,
			$since
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$active_link_pages_recent = is_array( $active_link_page_ids ) ? count( $active_link_page_ids ) : 0;

	if ( $did_switch ) {
		restore_current_blog();
	}

	return array(
		'total_artist_profiles'    => $total_artist_profiles,
		'total_link_pages'         => $total_link_pages,
		'profiles_created_recent'  => $profiles_created_recent,
		'active_link_pages_recent' => $active_link_pages_recent,
		'days'                     => $days,
	);
}
