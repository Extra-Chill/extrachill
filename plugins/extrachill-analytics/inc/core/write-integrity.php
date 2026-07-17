<?php
/**
 * Admission checks for public analytics writes.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the browser host that initiated the current write.
 *
 * @return string Lowercase host, or an empty string.
 */
function extrachill_analytics_public_write_source_host() {
	$source = '';
	if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
		$source = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
	} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
		$source = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
	}

	$host = wp_parse_url( $source, PHP_URL_HOST );

	return is_string( $host ) ? strtolower( rtrim( $host, '.' ) ) : '';
}

/**
 * Determine whether a host belongs to the current network site.
 *
 * Includes mapped domains such as the public link-page domain when the network
 * domain map is available.
 *
 * @param string $host Candidate host.
 * @return bool
 */
function extrachill_analytics_public_write_host_is_current_site( $host ) {
	$host            = strtolower( rtrim( (string) $host, '.' ) );
	$current_blog_id = (int) get_current_blog_id();
	$allowed_hosts   = array();

	foreach ( array( home_url( '/' ), function_exists( 'site_url' ) ? site_url( '/' ) : '' ) as $site_url ) {
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );
		if ( is_string( $site_host ) && '' !== $site_host ) {
			$allowed_hosts[] = strtolower( rtrim( $site_host, '.' ) );
		}
	}

	if ( function_exists( 'ec_get_domain_map' ) ) {
		foreach ( ec_get_domain_map() as $domain => $blog_id ) {
			if ( $current_blog_id === (int) $blog_id ) {
				$allowed_hosts[] = strtolower( rtrim( (string) $domain, '.' ) );
			}
		}
	}

	return '' !== $host && in_array( $host, array_unique( $allowed_hosts ), true );
}

/**
 * Apply an atomic fixed-window cap to public analytics writes.
 *
 * The cache key contains only a salted hash of the client IP. The raw address
 * is never persisted by this limiter.
 *
 * @return true|WP_Error
 */
function extrachill_analytics_check_public_write_rate_limit() {
	$limit = (int) apply_filters( 'extrachill_analytics_public_write_rate_limit', 240 );
	if ( $limit < 1 ) {
		return true;
	}

	$ip = function_exists( 'extrachill_analytics_get_client_ip' ) ? extrachill_analytics_get_client_ip() : '';
	if (
		'' === $ip
		|| ! function_exists( 'wp_using_ext_object_cache' )
		|| ! wp_using_ext_object_cache()
		|| ! function_exists( 'wp_cache_add' )
		|| ! function_exists( 'wp_cache_incr' )
	) {
		return new WP_Error(
			'analytics_write_limiter_unavailable',
			__( 'Analytics write admission is temporarily unavailable.', 'extrachill-analytics' ),
			array( 'status' => 503 )
		);
	}

	$key   = 'write_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	$group = 'extrachill-analytics-admission';
	if ( wp_cache_add( $key, 1, $group, MINUTE_IN_SECONDS ) ) {
		$count = 1;
	} else {
		$count = wp_cache_incr( $key, 1, $group );
	}

	if ( false === $count || ! is_numeric( $count ) ) {
		return new WP_Error(
			'analytics_write_limiter_unavailable',
			__( 'Analytics write admission is temporarily unavailable.', 'extrachill-analytics' ),
			array( 'status' => 503 )
		);
	}

	if ( (int) $count > $limit ) {
		return new WP_Error(
			'analytics_write_rate_limited',
			__( 'Too many analytics writes.', 'extrachill-analytics' ),
			array( 'status' => 429 )
		);
	}

	return true;
}

/**
 * Create a cache-safe proof for one rendered pageview tuple.
 *
 * @param int    $post_id      Post ID, or zero for a route view.
 * @param string $source_path  Normalized route path.
 * @param string $route_family Route family.
 * @param string $source_host  Browser host.
 * @return string
 */
function extrachill_analytics_pageview_proof( $post_id, $source_path, $route_family, $source_host ) {
	$payload = implode(
		'|',
		array(
			(int) get_current_blog_id(),
			(int) $post_id,
			strtolower( rtrim( (string) $source_host, '.' ) ),
			(string) $source_path,
			(string) $route_family,
		)
	);

	return hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
}

/**
 * Determine whether a post exists in the published state.
 *
 * Custom-domain post types can be publicly routed while their registration is
 * not `public`, so `is_post_publicly_viewable()` is not sufficient here.
 *
 * @param WP_Post $post Post object.
 * @return bool
 */
function extrachill_analytics_write_post_is_published( $post ) {
	$status = isset( $post->post_status ) ? (string) $post->post_status : get_post_status( $post );

	return 'publish' === $status;
}

/**
 * Determine whether a public post is legitimately served at a browser source.
 *
 * Canonical permalinks match exactly. Mapped custom domains may expose the
 * same post at its slug path; a mapped domain root may expose the one post
 * whose slug is the normalized domain label.
 *
 * @param int    $post_id     Post ID.
 * @param string $source_path Normalized browser path.
 * @param string $source_host Browser host.
 * @return bool
 */
function extrachill_analytics_public_post_matches_source( $post_id, $source_path, $source_host ) {
	$post = get_post( $post_id );
	if ( ! $post || ! extrachill_analytics_write_post_is_published( $post ) ) {
		return false;
	}

	$permalink      = get_permalink( $post );
	$canonical_host = is_string( $permalink ) ? wp_parse_url( $permalink, PHP_URL_HOST ) : '';
	$canonical_path = is_string( $permalink ) ? extrachill_analytics_normalize_route_path( $permalink ) : '';
	$canonical_host = is_string( $canonical_host ) ? strtolower( rtrim( $canonical_host, '.' ) ) : '';
	$source_host    = strtolower( rtrim( (string) $source_host, '.' ) );
	if ( $canonical_host === $source_host && $canonical_path === $source_path ) {
		return true;
	}

	if ( ! extrachill_analytics_public_write_host_is_current_site( $source_host ) || $canonical_host === $source_host ) {
		return false;
	}

	$post_slug = isset( $post->post_name ) ? sanitize_title( $post->post_name ) : '';
	$path_slug = sanitize_title( basename( untrailingslashit( $source_path ) ) );
	if ( '' !== $post_slug && $post_slug === $path_slug ) {
		return true;
	}

	$host_label = sanitize_title( strtok( $source_host, '.' ) );

	return '/' === $source_path
		&& '' !== $post_slug
		&& str_replace( '-', '', $post_slug ) === str_replace( '-', '', $host_label );
}

/**
 * Validate a browser pageview against its server-rendered tuple.
 *
 * @param int    $post_id      Post ID, or zero for a route view.
 * @param string $source_path  Normalized route path.
 * @param string $route_family Route family.
 * @param string $proof        Server-rendered proof.
 * @return true|WP_Error
 */
function extrachill_analytics_validate_pageview_write( $post_id, $source_path, $route_family, $proof ) {
	$rate_limit = extrachill_analytics_check_public_write_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$source_host = extrachill_analytics_public_write_source_host();
	if ( ! extrachill_analytics_public_write_host_is_current_site( $source_host ) ) {
		return new WP_Error(
			'invalid_pageview_origin',
			__( 'Pageviews must originate on the current public site.', 'extrachill-analytics' ),
			array( 'status' => 403 )
		);
	}

	$expected = extrachill_analytics_pageview_proof( $post_id, $source_path, $route_family, $source_host );
	if ( '' === $proof || ! hash_equals( $expected, $proof ) ) {
		return new WP_Error(
			'invalid_pageview_proof',
			__( 'Pageview source details do not match the rendered page.', 'extrachill-analytics' ),
			array( 'status' => 403 )
		);
	}

	if ( $post_id > 0 ) {
		$post = get_post( $post_id );
		if ( ! $post || ! extrachill_analytics_write_post_is_published( $post ) ) {
			return new WP_Error(
				'invalid_pageview_post',
				__( 'Pageview post is not publicly viewable.', 'extrachill-analytics' ),
				array( 'status' => 400 )
			);
		}
	}

	return true;
}

/**
 * Event types accepted from public browser adapters.
 *
 * @return string[]
 */
function extrachill_analytics_public_browser_event_types() {
	return array( 'bridge_click', 'bridge_impression', 'outbound_click', 'share_click' );
}

/**
 * Validate and normalize an Analytics-owned public browser event contract.
 *
 * @param string $event_type Event type.
 * @param array  $event_data Event dimensions.
 * @param string $source_url Claimed browser source.
 * @return array{event_data: array, source_url: string}|WP_Error
 */
function extrachill_analytics_validate_public_event_write( $event_type, $event_data, $source_url ) {
	if ( ! in_array( $event_type, extrachill_analytics_public_browser_event_types(), true ) ) {
		return array(
			'event_data' => $event_data,
			'source_url' => $source_url,
		);
	}

	$rate_limit = extrachill_analytics_check_public_write_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$source_host = extrachill_analytics_public_write_source_host();
	if ( ! extrachill_analytics_public_write_host_is_current_site( $source_host ) ) {
		return new WP_Error( 'invalid_event_origin', __( 'Analytics events must originate on the current public site.', 'extrachill-analytics' ), array( 'status' => 403 ) );
	}

	$parts        = wp_parse_url( (string) $source_url );
	$claimed_host = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	if ( '' !== $claimed_host && $source_host !== $claimed_host ) {
		return new WP_Error( 'invalid_event_source', __( 'Event source host does not match the browser origin.', 'extrachill-analytics' ), array( 'status' => 403 ) );
	}

	$source_path = extrachill_analytics_normalize_route_path( (string) $source_url );
	if ( '' === $source_path ) {
		return new WP_Error( 'invalid_event_source', __( 'A valid event source path is required.', 'extrachill-analytics' ), array( 'status' => 400 ) );
	}

	if ( ! is_array( $event_data ) ) {
		return new WP_Error( 'invalid_event_data', __( 'Event data must be an object.', 'extrachill-analytics' ), array( 'status' => 400 ) );
	}

	$source_post = isset( $event_data['source_post'] ) ? (int) $event_data['source_post'] : 0;
	if ( $source_post > 0 ) {
		if ( '/' === $source_path ) {
			$event_data['source_post'] = 0;
		} elseif ( ! extrachill_analytics_public_post_matches_source( $source_post, $source_path, $source_host ) ) {
			return new WP_Error( 'invalid_event_post', __( 'Event source post does not match the source path.', 'extrachill-analytics' ), array( 'status' => 400 ) );
		}
	}

	if ( isset( $event_data['source_site'] ) && '' !== $event_data['source_site'] && function_exists( 'ec_get_blog_slug_by_id' ) ) {
		$current_site = (string) ec_get_blog_slug_by_id( get_current_blog_id() );
		if ( '' !== $current_site && $current_site !== (string) $event_data['source_site'] ) {
			return new WP_Error( 'invalid_event_source_site', __( 'Event source site does not match the current site.', 'extrachill-analytics' ), array( 'status' => 400 ) );
		}
	}

	if ( isset( $event_data['dest_site'] ) && '' !== $event_data['dest_site'] && function_exists( 'ec_get_blog_id' ) ) {
		$dest_blog_id = ec_get_blog_id( (string) $event_data['dest_site'] );
		if ( null === $dest_blog_id || (int) get_current_blog_id() === (int) $dest_blog_id ) {
			return new WP_Error( 'invalid_event_destination_site', __( 'Event destination must be another network site.', 'extrachill-analytics' ), array( 'status' => 400 ) );
		}
	}

	unset( $event_data['is_bot'] );

	return array(
		'event_data' => $event_data,
		'source_url' => $source_path,
	);
}
