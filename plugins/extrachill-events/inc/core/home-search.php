<?php
/**
 * Events homepage "Find shows" search.
 *
 * One box on the events homepage that takes a city, venue, artist or show and
 * sends the visitor to the most specific page that answers it:
 *
 * 1. A city that matches exactly goes to that city's calendar. Several cities
 *    with the same name go to the filtered city directory.
 * 2. The start of a city name ("nash") goes to the filtered city directory.
 * 3. Venues before artists: an exact name, or the only distinct name that
 *    matches, goes to that page. Several matching venues fall through.
 * 4. Anything else goes to the full calendar with the search applied.
 *
 * Term matching and normalization come from data-machine-events'
 * EventSearchTerms (the same rules the calendar search uses), so "lofi",
 * "lo fi" and "Lo-Fi" all resolve the same way.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Query parameter carried by the homepage search form. */
const EXTRACHILL_EVENTS_HOME_SEARCH_PARAM = 'find';

/**
 * Taxonomies whose terms are destination pages, most specific first.
 *
 * @return string[]
 */
function extrachill_events_home_search_page_taxonomies(): array {
	return array( 'venue', 'artist' );
}

/**
 * Pick the destination for a homepage search.
 *
 * Pure: every input is supplied by the caller so the routing rules can be
 * tested without WordPress.
 *
 * @param string   $query      Raw search text.
 * @param array    $cities     City rows: name, label, url.
 * @param array    $terms      Venue/artist candidates that match the query: name, taxonomy, count, url.
 * @param array    $urls       Destination bases: home, directory, all.
 * @param callable $normalize  Text normalizer (e.g. EventSearchTerms::needle).
 * @return array{type:string,url:string}
 */
function extrachill_events_home_search_resolve( string $query, array $cities, array $terms, array $urls, callable $normalize ): array {
	$needle = (string) $normalize( $query );
	if ( '' === $needle ) {
		return array(
			'type' => 'home',
			'url'  => (string) $urls['home'],
		);
	}

	$directory = '' === (string) ( $urls['directory'] ?? '' ) ? '' : add_query_arg( 'search', rawurlencode( $query ), (string) $urls['directory'] );

	// 1. Exact city.
	$exact_cities = array_values(
		array_filter(
			$cities,
			static fn( array $city ): bool => $needle === $normalize( (string) ( $city['name'] ?? '' ) )
				|| $needle === $normalize( (string) ( $city['label'] ?? '' ) )
		)
	);
	if ( 1 === count( $exact_cities ) ) {
		return array(
			'type' => 'city',
			'url'  => (string) $exact_cities[0]['url'],
		);
	}
	if ( count( $exact_cities ) > 1 && '' !== $directory ) {
		return array(
			'type' => 'cities',
			'url'  => $directory,
		);
	}

	// 2. Start of a city name ("nash" → Nashville), before same-named acts.
	foreach ( '' === $directory ? array() : $cities as $city ) {
		if ( str_starts_with( (string) $normalize( (string) ( $city['label'] ?? '' ) ), $needle ) ) {
			return array(
				'type' => 'cities',
				'url'  => $directory,
			);
		}
	}

	// 3. Exact venue/artist, else the only one that matches.
	$order = array_flip( extrachill_events_home_search_page_taxonomies() );
	$terms = array_values(
		array_filter(
			$terms,
			static fn( array $term ): bool => isset( $order[ $term['taxonomy'] ?? '' ] ) && '' !== (string) ( $term['url'] ?? '' )
		)
	);
	usort(
		$terms,
		static fn( array $a, array $b ): int => array( $order[ $a['taxonomy'] ], -1 * (int) ( $a['count'] ?? 0 ) ) <=> array( $order[ $b['taxonomy'] ], -1 * (int) ( $b['count'] ?? 0 ) )
	);

	// Venues first (a venue's full name is often longer than a same-named
	// act: "Blind Tiger Pub" vs the band "Blind Tiger"), then artists. Within
	// each: an exact name, else the only distinct name that matches.
	foreach ( array_keys( $order ) as $taxonomy ) {
		$group = array_values( array_filter( $terms, static fn( array $term ): bool => $taxonomy === $term['taxonomy'] ) );
		if ( empty( $group ) ) {
			continue;
		}

		foreach ( $group as $term ) {
			if ( $needle === $normalize( (string) $term['name'] ) ) {
				return array(
					'type' => $taxonomy,
					'url'  => (string) $term['url'],
				);
			}
		}

		$distinct_names = array_unique( array_map( static fn( array $term ): string => (string) $normalize( (string) $term['name'] ), $group ) );
		if ( 1 === count( $distinct_names ) ) {
			return array(
				'type' => $taxonomy,
				'url'  => (string) $group[0]['url'],
			);
		}

		// Several venues match: let the calendar search show them all
		// rather than guessing an artist.
		if ( 'venue' === $taxonomy ) {
			break;
		}
	}

	// 4. Everything else searches the calendar.
	return array(
		'type' => 'events',
		'url'  => add_query_arg( 'event_search', rawurlencode( $query ), (string) $urls['all'] ),
	);
}

/**
 * Venue/artist terms whose names match the query, using the calendar rules.
 *
 * @param string $query Raw search text.
 * @return array Candidate rows: name, taxonomy, count, url.
 */
function extrachill_events_home_search_term_candidates( string $query ): array {
	if ( ! class_exists( '\DataMachineEvents\Core\EventSearchTerms' ) ) {
		return array();
	}

	$tt_ids = \DataMachineEvents\Core\EventSearchTerms::matchingTermTaxonomyIds( $query );
	if ( empty( $tt_ids ) ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'         => extrachill_events_home_search_page_taxonomies(),
			'term_taxonomy_id' => $tt_ids,
			'hide_empty'       => true,
		)
	);
	if ( is_wp_error( $terms ) ) {
		return array();
	}

	$rows = array();
	foreach ( $terms as $term ) {
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			continue;
		}
		$rows[] = array(
			'name'     => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'taxonomy' => $term->taxonomy,
			'count'    => (int) $term->count,
			'url'      => $url,
		);
	}

	return $rows;
}

/**
 * Cities with upcoming events, as the city directory lists them.
 *
 * @return array
 */
function extrachill_events_home_search_cities(): array {
	$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
	$request->set_query_params( array( 'taxonomy' => 'location' ) );
	$response = rest_do_request( $request );

	return $response->is_error() ? array() : extrachill_events_prepare_location_rows( (array) $response->get_data() );
}

/**
 * Redirect a homepage search to its destination.
 *
 * @hook template_redirect
 * @return void
 */
function extrachill_events_home_search_redirect(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only search.
	if ( ! ec_is_events_site() || ! isset( $_GET[ EXTRACHILL_EVENTS_HOME_SEARCH_PARAM ] ) || ! is_scalar( $_GET[ EXTRACHILL_EVENTS_HOME_SEARCH_PARAM ] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only search.
	$query     = trim( sanitize_text_field( (string) wp_unslash( $_GET[ EXTRACHILL_EVENTS_HOME_SEARCH_PARAM ] ) ) );
	$normalize = static function ( string $text ): string {
		if ( class_exists( '\DataMachineEvents\Core\EventSearchTerms' ) ) {
			return \DataMachineEvents\Core\EventSearchTerms::needle( $text );
		}

		return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $text ) );
	};

	$destination = extrachill_events_home_search_resolve(
		$query,
		'' === $query ? array() : extrachill_events_home_search_cities(),
		'' === $query ? array() : extrachill_events_home_search_term_candidates( $query ),
		array(
			'home'      => home_url( '/' ),
			'directory' => extrachill_events_location_directory_enabled() ? home_url( '/location/' ) : '',
			'all'       => home_url( '/all' ),
		),
		$normalize
	);

	nocache_headers();
	wp_safe_redirect( $destination['url'], 302, 'Extra Chill Events search' );
	exit;
}
add_action( 'template_redirect', 'extrachill_events_home_search_redirect', 5 );
