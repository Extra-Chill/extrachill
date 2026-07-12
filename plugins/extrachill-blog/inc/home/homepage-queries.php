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
 * Uses internal REST API call to get top 8 locations with upcoming events,
 * sorted by event count descending.
 *
 * @return array Array of location data: name, slug, count, url
 */
function extrachill_blog_get_location_event_counts() {
	$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
	$request->set_query_params(
		array(
			'taxonomy' => 'location',
			'limit'    => 8,
		)
	);

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return array();
	}

	$data = $response->get_data();
	return is_array( $data ) ? $data : array();
}

/**
 * Get the latest Festival Wire posts from wire.extrachill.com.
 *
 * Direct cross-blog read via switch_to_blog() — the Wire publishes multiple
 * dispatches per day, so this is the homepage's freshest live signal. Each
 * item carries a humanized age ("3 hours ago") to make recency visible.
 *
 * @param int $limit Number of posts to return.
 * @return array[] Array of items: title, url, time_diff.
 */
function extrachill_blog_get_wire_latest( $limit = 4 ) {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return array();
	}

	$wire_blog_id = ec_get_blog_id( 'wire' );
	if ( ! $wire_blog_id ) {
		return array();
	}

	$items = array();

	switch_to_blog( $wire_blog_id );

	$wire_posts = get_posts(
		array(
			'numberposts' => absint( $limit ),
			'post_type'   => 'festival_wire',
		)
	);

	foreach ( $wire_posts as $wire_post ) {
		$items[] = array(
			'title'     => get_the_title( $wire_post ),
			'url'       => get_permalink( $wire_post ),
			'time_diff' => human_time_diff( (int) get_post_time( 'U', true, $wire_post ), time() ),
		);
	}

	restore_current_blog();

	return $items;
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

		if ( empty( $release['tag_name'] ) || empty( $release['published_at'] ) || empty( $release['html_url'] ) ) {
			continue;
		}

		$releases[] = array(
			'repo'         => $repo_name,
			'tag'          => $release['tag_name'],
			'summary'      => extrachill_blog_summarize_release_body( $release['body'] ?? '' ),
			'published_at' => strtotime( $release['published_at'] ),
			'url'          => $release['html_url'],
			'type'         => extrachill_blog_classify_shipped_repo( $repo_name ),
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
 * Reduce a GitHub release body to a single-line, markdown-stripped summary.
 *
 * Takes the first meaningful bullet/line of the release body (homeboy
 * writes "## What's Changed" + conventional-commit bullets), strips
 * markdown link syntax and PR references, and truncates to ~80 chars.
 *
 * @param string $body Raw release body (markdown).
 * @return string Plain-text summary, empty string if nothing usable.
 */
function extrachill_blog_summarize_release_body( $body ) {
	if ( '' === trim( $body ) ) {
		return '';
	}

	$lines = preg_split( '/\r\n|\r|\n/', $body );
	if ( false === $lines ) {
		return '';
	}

	foreach ( $lines as $line ) {
		$line = trim( $line );

		// Skip empty lines, headings, and the auto-generated changelog footer.
		if ( '' === $line || '#' === substr( $line, 0, 1 ) || false !== stripos( $line, 'Full Changelog' ) ) {
			continue;
		}

		// Strip leading bullet markers.
		$line = preg_replace( '/^[-*]\s*/', '', $line );

		// Strip trailing "by @user in <PR URL>" attribution.
		$line = preg_replace( '/\s+by\s+@\S+\s+in\s+https?:\/\/\S+\s*$/i', '', $line );

		// Strip markdown links, keeping the link text: [text](url) => text.
		$line = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $line );

		// Strip bare URLs and stray markdown emphasis characters.
		$line = preg_replace( '/https?:\/\/\S+/', '', $line );
		$line = preg_replace( '/[`*_]/', '', $line );
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		return extrachill_blog_truncate_summary( $line, 80 );
	}

	return '';
}

/**
 * Truncate a summary string to an approximate character length on a
 * word boundary, appending an ellipsis when truncated.
 *
 * @param string $text   Source text.
 * @param int    $length Approximate max length.
 * @return string Truncated text.
 */
function extrachill_blog_truncate_summary( $text, $length ) {
	if ( strlen( $text ) <= $length ) {
		return $text;
	}

	$truncated = substr( $text, 0, $length );
	$space_pos = strrpos( $truncated, ' ' );

	if ( false !== $space_pos ) {
		$truncated = substr( $truncated, 0, $space_pos );
	}

	return rtrim( $truncated, " \t\n\r\0\x0B.,;:-" ) . '…';
}

/**
 * Classify a repo name into a display "type" badge via a filterable
 * prefix map. Vendor/repo-specific names live only in this config map,
 * never hardcoded in the template.
 *
 * @param string $repo_name GitHub repo name.
 * @return string Type label: platform, agents, orchestration, or tools.
 */
function extrachill_blog_classify_shipped_repo( $repo_name ) {
	$default_map = array(
		'extrachill-'  => 'platform',
		'data-machine' => 'agents',
		'homeboy'      => 'orchestration',
	);

	/**
	 * Filter the repo-name-prefix => type-label map used to classify
	 * Recently Shipped rows.
	 *
	 * @param array<string,string> $default_map Prefix => type label.
	 */
	$map = apply_filters( 'extrachill_blog_shipped_type_map', $default_map );

	foreach ( $map as $prefix => $type ) {
		if ( 0 === strpos( $repo_name, $prefix ) ) {
			return $type;
		}
	}

	return 'tools';
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

	if ( ! is_array( $stats ) ) {
		return array();
	}

	$values = array();

	foreach ( $keys as $key ) {
		if ( empty( $stats[ $key ] ) || ! is_array( $stats[ $key ] ) ) {
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
