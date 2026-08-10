<?php
/**
 * Homepage Queries
 *
 * Pre-fetches homepage content into global variables for template consumption.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $latest_blog_posts;

$latest_blog_posts = get_posts(
	array(
		'numberposts' => 5,
	)
);

/**
 * Get location terms with upcoming event counts from events.extrachill.com
 *
 * Uses the existing user-settings and upcoming-counts contracts to put an
 * authenticated user's preferred market first. All failures retain the global
 * top-eight response.
 *
 * @return array Array of location data: name, slug, count, url
 */
function extrachill_blog_get_location_event_counts() {
	$locations = extrachill_blog_request_location_event_counts(
		array(
			'taxonomy' => 'location',
			'limit'    => 8,
		)
	);

	if ( empty( $locations ) ) {
		return array();
	}

	$preferred_slug = extrachill_blog_get_local_scene_slug();
	if ( '' === $preferred_slug ) {
		return $locations;
	}

	foreach ( $locations as $location ) {
		if ( isset( $location['slug'] ) && $preferred_slug === $location['slug'] ) {
			return extrachill_blog_prioritize_event_market( $locations, $location );
		}
	}

	$preferred = extrachill_blog_request_location_event_counts(
		array(
			'taxonomy' => 'location',
			'slug'     => $preferred_slug,
		)
	);

	if ( empty( $preferred[0] ) ) {
		return $locations;
	}

	return extrachill_blog_prioritize_event_market( $locations, $preferred[0] );
}

/**
 * Request upcoming location counts through the existing network REST contract.
 *
 * @param array $query Query parameters accepted by upcoming-counts.
 * @return array[] Location count rows.
 */
function extrachill_blog_request_location_event_counts( $query ) {
	$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
	$request->set_query_params( $query );

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return array();
	}

	$data = $response->get_data();
	return is_array( $data ) ? $data : array();
}

/**
 * Read the authenticated user's canonical local scene.
 *
 * The preference is supplied by extrachill-users#179 through the existing
 * self-only settings Ability. Its resolved object is canonicalized by the
 * Events-owned extrachill/events-locations Ability; this consumer does not
 * repeat taxonomy validation. Unavailable or malformed data intentionally
 * fails open to global markets.
 *
 * @return string Canonical location slug, or an empty string.
 */
function extrachill_blog_get_local_scene_slug() {
	if (
		! is_user_logged_in() ||
		! function_exists( 'wp_has_ability' ) ||
		! function_exists( 'wp_get_ability' ) ||
		! wp_has_ability( 'extrachill/get-user-settings' )
	) {
		return '';
	}

	$ability = wp_get_ability( 'extrachill/get-user-settings' );
	if ( ! $ability ) {
		return '';
	}

	$settings = $ability->execute( array() );
	if ( is_wp_error( $settings ) || ! is_array( $settings ) ) {
		return '';
	}

	$location = $settings['local_scene'] ?? null;
	if ( ! is_array( $location ) || empty( $location['slug'] ) ) {
		return '';
	}

	return (string) $location['slug'];
}

/**
 * Lead market rows with a preferred location and remove duplicate slugs.
 *
 * @param array[] $locations Global market rows.
 * @param array   $preferred Preferred market row.
 * @return array[] At most eight market rows.
 */
function extrachill_blog_prioritize_event_market( $locations, $preferred ) {
	if ( empty( $preferred['slug'] ) ) {
		return array_slice( $locations, 0, 8 );
	}

	$preferred_slug = (string) $preferred['slug'];
	$prioritized    = array( $preferred );

	foreach ( $locations as $location ) {
		if ( empty( $location['slug'] ) || $preferred_slug === (string) $location['slug'] ) {
			continue;
		}

		$prioritized[] = $location;
		if ( 8 === count( $prioritized ) ) {
			break;
		}
	}

	return $prioritized;
}

/**
 * Get the latest Festival Wire posts from wire.extrachill.com.
 *
 * Maps the Wire-owned public activity projection into the established
 * homepage card shape. Each item carries a humanized age ("3 hours ago") to
 * make recency visible.
 *
 * @param int $limit Number of posts to return.
 * @return array[] Array of items: title, url, time_diff.
 */
function extrachill_blog_get_wire_latest( $limit = 4 ) {
	$limit  = max( 1, min( 20, absint( $limit ) ) );
	$result = extrachill_blog_request_cross_site_ability(
		'wire',
		'extrachill-news-wire/get-recent-activity',
		array( 'limit' => $limit )
	);

	if ( ! extrachill_blog_is_activity_projection( $result ) ) {
		return array();
	}

	$items = array();
	foreach ( array_slice( $result['items'], 0, $limit ) as $activity ) {
		if ( ! extrachill_blog_is_wire_activity_item( $activity ) ) {
			continue;
		}

		$items[] = array(
			'title'     => $activity['title'],
			'url'       => $activity['canonical_url'],
			'time_diff' => human_time_diff( strtotime( $activity['timestamp'] ), time() ),
		);
	}

	return $items;
}

/**
 * Validate one version-one Wire activity item.
 *
 * @param mixed $item Owner-projected item.
 * @return bool
 */
function extrachill_blog_is_wire_activity_item( $item ) {
	if ( ! is_array( $item ) || array( 'canonical_url', 'title', 'timestamp', 'image', 'summary', 'source', 'type' ) !== array_keys( $item ) ) {
		return false;
	}

	return is_string( $item['canonical_url'] )
		&& false !== filter_var( $item['canonical_url'], FILTER_VALIDATE_URL )
		&& is_string( $item['title'] )
		&& '' !== $item['title']
		&& is_string( $item['timestamp'] )
		&& false !== strtotime( $item['timestamp'] )
		&& ( null === $item['image'] || ( is_string( $item['image'] ) && false !== filter_var( $item['image'], FILTER_VALIDATE_URL ) ) )
		&& ( null === $item['summary'] || is_string( $item['summary'] ) )
		&& 'extra-chill-news-wire' === $item['source']
		&& 'festival-wire' === $item['type'];
}

/**
 * Get the "Recently Shipped" homepage payload — recent GitHub releases
 * across the whole Extra-Chill org plus a monthly-activity stat.
 *
 * Reads only the durable last-good option populated by the scheduled warmer.
 * A legacy transient is migrated when present. If no payload is available,
 * the section renders nothing.
 *
 * @return array{releases: array[], repos_active_this_month: int, repos_total: int}|array{}
 */
function extrachill_blog_get_recently_shipped() {
	$option_name = 'extrachill_blog_recently_shipped_last_good';
	$last_good   = get_option( $option_name, array() );
	if ( is_array( $last_good ) && ! empty( $last_good ) ) {
		return $last_good;
	}

	$last_good = get_transient( $option_name );
	if ( is_array( $last_good ) && ! empty( $last_good ) ) {
		update_option( $option_name, $last_good, false );
		delete_transient( $option_name );

		return $last_good;
	}

	return array();
}

/**
 * Fetch and assemble the Recently Shipped payload from the GitHub API.
 *
 * The scheduled warmer makes one org request plus up to ten release requests:
 * the latest release for each of the ten most recently active repositories.
 * The resulting rows are release rows from that candidate set, not a complete
 * ranking of the newest releases across the organization.
 *
 * @return array{releases: array[], repos_active_this_month: int, repos_total: int}|array{}
 */
function extrachill_blog_fetch_recently_shipped() {
	$repos_response = wp_remote_get(
		'https://api.github.com/orgs/Extra-Chill/repos?per_page=100&sort=pushed',
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json' ),
		)
	);

	if ( is_wp_error( $repos_response ) || 200 !== wp_remote_retrieve_response_code( $repos_response ) ) {
		return array();
	}

	$repos = json_decode( wp_remote_retrieve_body( $repos_response ), true );

	if ( ! is_array( $repos ) || empty( $repos ) ) {
		return array();
	}

	$repos_total             = count( $repos );
	$month_start             = strtotime( gmdate( 'Y-m-01 00:00:00' ) . ' UTC' );
	$repos_active_this_month = 0;

	foreach ( $repos as $repo ) {
		if ( ! empty( $repo['pushed_at'] ) && strtotime( $repo['pushed_at'] ) >= $month_start ) {
			++$repos_active_this_month;
		}
	}

	$candidate_repos = array_slice( wp_list_pluck( $repos, 'name' ), 0, 10 );
	$releases        = array();

	foreach ( $candidate_repos as $repo_name ) {
		$release_response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/Extra-Chill/%s/releases/latest', rawurlencode( $repo_name ) ),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $release_response ) || 200 !== wp_remote_retrieve_response_code( $release_response ) ) {
			continue;
		}

		$release_data = json_decode( wp_remote_retrieve_body( $release_response ), true );

		if ( ! is_array( $release_data ) || empty( $release_data['tag_name'] ) ) {
			continue;
		}

		$release = $release_data;

		if ( empty( $release['published_at'] ) || empty( $release['html_url'] ) ) {
			continue;
		}

		$releases[] = array(
			'repo'         => $repo_name,
			'tag'          => $release['tag_name'],
			'published_at' => strtotime( $release['published_at'] ),
			'url'          => $release['html_url'],
		);
	}

	if ( empty( $releases ) ) {
		return array();
	}

	usort(
		$releases,
		function ( $a, $b ) {
			return $b['published_at'] <=> $a['published_at'];
		}
	);

	$releases = array_slice( $releases, 0, 4 );

	return array(
		'releases'                => $releases,
		'repos_active_this_month' => $repos_active_this_month,
		'repos_total'             => $repos_total,
	);
}

/**
 * Resolve live network proof numbers for the hero stat strip.
 *
 * Wraps ec_get_network_stats() (extrachill-multisite NetworkStats primitive)
 * with a function_exists() guard. Only metrics that are explicitly available
 * with a numeric value are returned — never a fabricated zero.
 *
 * @return array<string,int> Map of metric key => integer value.
 */
function extrachill_blog_get_hero_stats() {
	if ( ! function_exists( 'ec_get_network_stats' ) ) {
		return array();
	}

	$keys  = array( 'events_count', 'events_cities', 'total_members', 'artist_profiles' );
	$stats = ec_get_network_stats( $keys );

	$values = array();

	foreach ( $keys as $key ) {
		if ( empty( $stats[ $key ] ) ) {
			continue;
		}

		$metric = $stats[ $key ];

		if ( empty( $metric['available'] ) || ! isset( $metric['value'] ) || ! is_numeric( $metric['value'] ) ) {
			continue;
		}

		$values[ $key ] = (int) $metric['value'];
	}

	return $values;
}
