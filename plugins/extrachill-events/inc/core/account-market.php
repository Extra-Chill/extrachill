<?php
/**
 * Account-backed event market integration.
 *
 * Extra Chill Users owns the private preference. This integration consumes its
 * resolved coordinates and feeds them into Data Machine Events' existing geo
 * inputs without teaching the generic event layer about accounts.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the current user's resolved default event location.
 *
 * The Ability contract is provided by extrachill-users#179. Until that
 * dependency is available, or when it returns an invalid/empty preference,
 * this fails open.
 *
 * @return array{lat: float|null, lon: float|null, slug: string, term_id: int}|null
 */
function extrachill_events_get_account_market(): ?array {
	static $resolved = false;
	static $market   = null;

	if ( $resolved ) {
		return $market;
	}
	$resolved = true;

	if ( ! is_user_logged_in() || ! function_exists( 'wp_get_ability' ) ) {
		return null;
	}

	$ability = wp_get_ability( 'extrachill/get-user-settings' );
	if ( ! $ability ) {
		return null;
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) || ! is_array( $result ) || ! is_array( $result['default_event_location'] ?? null ) ) {
		return null;
	}

	$location    = $result['default_event_location'];
	$coordinates = is_array( $location['coordinates'] ?? null ) ? $location['coordinates'] : array();
	$lat         = filter_var( $coordinates['lat'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
	$lon         = filter_var( $coordinates['lon'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
	$term_id     = absint( $location['term_id'] ?? 0 );
	$slug        = sanitize_title( $location['slug'] ?? '' );

	if ( $term_id < 1 || '' === $slug ) {
		return null;
	}
	if ( null !== $lat && ( $lat < -90 || $lat > 90 ) ) {
		$lat = null;
	}
	if ( null !== $lon && ( $lon < -180 || $lon > 180 ) ) {
		$lon = null;
	}

	$market = array(
		'lat'     => null !== $lat ? (float) $lat : null,
		'lon'     => null !== $lon ? (float) $lon : null,
		'slug'    => $slug,
		'term_id' => $term_id,
	);

	return $market;
}

/**
 * Whether the request already carries an explicit location selection.
 */
function extrachill_events_has_explicit_market(): bool {
	if ( is_tax() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only calendar filters.
	if ( ! empty( $_GET['tax_filter'] ) ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only geo parameters.
	$lat = isset( $_GET['lat'] ) ? sanitize_text_field( wp_unslash( $_GET['lat'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only geo parameters.
	$lng = isset( $_GET['lng'] ) ? sanitize_text_field( wp_unslash( $_GET['lng'] ) ) : '';
	return '' !== $lat && '' !== $lng;
}

/**
 * Whether this page owns the unscoped public calendar/map experience.
 *
 * Embedded calendars such as My Shows and related events have their own query
 * contracts and must not inherit a market fallback.
 */
function extrachill_events_supports_account_market(): bool {
	if ( function_exists( 'ec_is_events_site' ) && ! ec_is_events_site() ) {
		return false;
	}

	return is_front_page()
		|| ( function_exists( 'extrachill_events_is_all_events_page' ) && extrachill_events_is_all_events_page() )
		|| ( function_exists( 'extrachill_events_is_near_me_page' ) && extrachill_events_is_near_me_page() );
}

/**
 * Add account market defaults before the calendar request is parsed.
 *
 * Explicit request/archive state wins. Near Me receives geo defaults so browser
 * geolocation can replace them; other public calendar surfaces receive the
 * canonical taxonomy term. CalendarRequest sanitizes every injected value.
 *
 * @param array $query_args Assembled calendar request arguments.
 * @param array $context    Calendar render context.
 * @return array
 */
function extrachill_events_calendar_account_market_defaults( array $query_args, array $context ): array {
	if ( ! extrachill_events_supports_account_market() || ! empty( $context['archive_term'] ) || ! empty( $query_args['tax_filter'] ) ) {
		return $query_args;
	}

	if ( ! empty( $query_args['lat'] ) || ! empty( $query_args['lng'] ) ) {
		return $query_args;
	}

	$market = extrachill_events_get_account_market();
	if ( null === $market ) {
		return $query_args;
	}

	if ( function_exists( 'extrachill_events_is_near_me_page' ) && extrachill_events_is_near_me_page() ) {
		if ( null !== $market['lat'] && null !== $market['lon'] ) {
			$query_args += array(
				'lat' => $market['lat'],
				'lng' => $market['lon'],
			);
		}
	} else {
		$query_args += array(
			'tax_filter' => array(
				'location' => array( $market['term_id'] ),
			),
		);
	}

	return $query_args;
}
add_filter( 'data_machine_events_calendar_request_args', 'extrachill_events_calendar_account_market_defaults', 10, 2 );

/**
 * Use the account market as a map center only when no stronger context exists.
 *
 * @param mixed $center  Existing map center.
 * @param array $context Map render context.
 * @return mixed
 */
function extrachill_events_account_market_map_center( $center, array $context ) {
	if ( null !== $center || ! extrachill_events_supports_account_market() || ! empty( $context['is_taxonomy'] ) || extrachill_events_has_explicit_market() ) {
		return $center;
	}

	$market = extrachill_events_get_account_market();
	if ( null === $market || null === $market['lat'] || null === $market['lon'] ) {
		return $center;
	}

	return array(
		'lat' => $market['lat'],
		'lon' => $market['lon'],
	);
}
add_filter( 'data_machine_events_map_center', 'extrachill_events_account_market_map_center', 5, 2 );
