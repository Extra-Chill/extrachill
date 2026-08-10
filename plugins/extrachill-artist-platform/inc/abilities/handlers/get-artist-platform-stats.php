<?php
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

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Return point-in-time artist platform aggregates.
 *
 * @param array $input Input arguments containing the optional recent-metrics window.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_get_artist_platform_stats( array $input ) {
	$days = isset( $input['days'] ) ? min( 90, max( 0, (int) $input['days'] ) ) : 28;

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'dependency_missing', 'Multisite not configured.' );
	}

	$did_switch = false;
	if ( get_current_blog_id() !== $artist_blog_id ) {
		switch_to_blog( $artist_blog_id );
		$did_switch = true;
	}

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
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);
	$total_link_pages = (int) $link_page_query->found_posts;
	$link_page_ids    = array_map(
		static function ( $post ) {
			return $post instanceof WP_Post ? (int) $post->ID : (int) $post;
		},
		$link_page_query->posts
	);

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

	$active_link_pages_recent   = 0;
	$link_page_analytics_status = 'disabled';
	$link_page_analytics_error  = null;
	$authorization_error        = null;

	if ( $days > 0 ) {
		$analytics_ability = wp_get_ability( 'extrachill/get-link-page-analytics' );
		if ( ! $analytics_ability ) {
			$active_link_pages_recent   = null;
			$link_page_analytics_status = 'unavailable';
		} elseif ( empty( $link_page_ids ) ) {
			$link_page_analytics_status = 'no_data';
		} else {
			foreach ( $link_page_ids as $link_page_id ) {
				$analytics_result = $analytics_ability->execute(
					array(
						'link_page_id' => $link_page_id,
						'date_range'   => $days,
					)
				);

				if ( is_wp_error( $analytics_result ) ) {
					$error_code = $analytics_result->get_error_code();
					if ( in_array( $error_code, array( 'ability_permission_denied', 'permission_denied' ), true ) ) {
						$authorization_error = $analytics_result;
						break;
					}

					$active_link_pages_recent   = null;
					$link_page_analytics_status = 'error';
					$link_page_analytics_error  = $error_code;
					break;
				}

				$summary = is_array( $analytics_result ) && isset( $analytics_result['summary'] ) && is_array( $analytics_result['summary'] )
					? $analytics_result['summary']
					: null;
				if (
					null === $summary
					|| ! isset( $summary['total_views'], $summary['total_clicks'] )
					|| ! is_int( $summary['total_views'] )
					|| ! is_int( $summary['total_clicks'] )
					|| $summary['total_views'] < 0
					|| $summary['total_clicks'] < 0
				) {
					$active_link_pages_recent   = null;
					$link_page_analytics_status = 'malformed_response';
					$link_page_analytics_error  = 'invalid_analytics_response';
					break;
				}

				if ( $summary['total_views'] > 0 || $summary['total_clicks'] > 0 ) {
					++$active_link_pages_recent;
				}
			}

			if ( null !== $active_link_pages_recent ) {
				$link_page_analytics_status = $active_link_pages_recent > 0 ? 'available' : 'no_data';
			}
		}
	}

	if ( $did_switch ) {
		restore_current_blog();
	}
	if ( $authorization_error ) {
		return $authorization_error;
	}

	return array(
		'total_artist_profiles'      => $total_artist_profiles,
		'total_link_pages'           => $total_link_pages,
		'profiles_created_recent'    => $profiles_created_recent,
		'active_link_pages_recent'   => $active_link_pages_recent,
		'link_page_analytics_status' => $link_page_analytics_status,
		'link_page_analytics_error'  => $link_page_analytics_error,
		'days'                       => $days,
	);
}
