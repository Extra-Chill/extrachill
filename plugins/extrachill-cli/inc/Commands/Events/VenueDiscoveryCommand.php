<?php
/**
 * Venue Discovery CLI Commands
 *
 * Wraps extrachill/discover-venues ability from extrachill-events.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueDiscoveryCommand {

	/**
	 * Discover music venues in a city using Google Places API.
	 *
	 * Searches for venues not already in the calendar and returns
	 * results with website URLs for qualification.
	 *
	 * ## OPTIONS
	 *
	 * <city>
	 * : City with state, e.g. "Nashville, TN" or "Austin, Texas".
	 *
	 * [--query=<query>]
	 * : Custom search query. Defaults to "music venues in {city}".
	 *   Examples: "jazz clubs in Nashville", "dive bars with live music in Austin".
	 *
	 * [--include-known]
	 * : Include venues that already exist in our taxonomy.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues discover "Nashville, TN"
	 *     wp extrachill venues discover "Austin, TX" --query="jazz clubs in Austin"
	 *     wp extrachill venues discover "Charleston, SC" --include-known
	 *     wp extrachill venues discover "Nashville, TN" --format=json
	 *
	 * @subcommand discover
	 * @when after_wp_load
	 */
	public function discover( $args, $assoc_args ) {
		$city = $args[0] ?? '';

		if ( empty( $city ) ) {
			WP_CLI::error( 'City is required. Example: wp extrachill venues discover "Nashville, TN"' );
		}

		$ability = wp_get_ability( 'extrachill/discover-venues' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/discover-venues ability not available. Is extrachill-events active on this site?' );
		}

		WP_CLI::log( sprintf( 'Discovering venues in %s...', $city ) );

		$result = $ability->execute( array(
			'city'          => $city,
			'query'         => $assoc_args['query'] ?? '',
			'include_known' => ! empty( $assoc_args['include-known'] ),
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Summary.
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Found %d venues (%d new, %d already known)',
			$result['total_found'],
			$result['new_venues'],
			$result['known_venues']
		) );
		WP_CLI::log( '' );

		if ( empty( $result['venues'] ) ) {
			WP_CLI::log( 'No new venues found.' );
			return;
		}

		// Format for table output.
		$rows = array();
		foreach ( $result['venues'] as $venue ) {
			$rows[] = array(
				'name'     => $venue['name'],
				'address'  => mb_substr( $venue['address'], 0, 45 ),
				'website'  => $venue['website'] ?: '(none)',
				'known'    => $venue['is_known'] ? 'yes' : 'NEW',
			);
		}

		Utils\format_items( $format, $rows, array( 'name', 'address', 'website', 'known' ) );

		// Actionable summary.
		$with_website = count( array_filter( $result['venues'], fn( $v ) => ! empty( $v['website'] ) && ! $v['is_known'] ) );
		WP_CLI::log( '' );
		if ( $with_website > 0 ) {
			WP_CLI::log( sprintf( '%d new venues have websites — qualify them with:', $with_website ) );
			WP_CLI::log( '  wp extrachill venues qualify "<website>"' );
		}
	}

	/**
	 * Qualify a venue website — find its events page and check for scrapable listings.
	 *
	 * Crawls the homepage for event page links, tries common URL patterns
	 * (/events, /calendar, /shows, etc.), checks for WordPress Tribe Events API,
	 * and reports whether the site has scrapable event data.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Venue website URL (homepage). The tool will crawl to find the events page.
	 *
	 * [--name=<name>]
	 * : Venue name (for display).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues qualify https://exitin.com
	 *     wp extrachill venues qualify https://www.stationinn.com --name="The Station Inn"
	 *     wp extrachill venues qualify https://endnashville.com --format=json
	 *
	 * @subcommand qualify
	 * @when after_wp_load
	 */
	public function qualify( $args, $assoc_args ) {
		$url = $args[0] ?? '';

		if ( empty( $url ) ) {
			WP_CLI::error( 'URL is required. Example: wp extrachill venues qualify https://exitin.com' );
		}

		$ability = wp_get_ability( 'extrachill/qualify-venue' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/qualify-venue ability not available. Is extrachill-events active on this site?' );
		}

		$name = $assoc_args['name'] ?? '';
		$label = $name ? "{$name} ({$url})" : $url;
		WP_CLI::log( sprintf( 'Qualifying %s...', $label ) );

		$result = $ability->execute( array(
			'url'  => $url,
			'name' => $name,
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::log( '' );

		if ( $result['qualified'] ) {
			WP_CLI::success( sprintf( 'QUALIFIED — events found at %s', $result['events_url'] ) );
			WP_CLI::log( sprintf( '  Method: %s', $result['method'] ?? 'unknown' ) );
			if ( ! empty( $result['event_count'] ) ) {
				WP_CLI::log( sprintf( '  Events detected: %d', $result['event_count'] ) );
			}
			if ( ! empty( $result['link_text'] ) ) {
				WP_CLI::log( sprintf( '  Found via link: "%s"', $result['link_text'] ) );
			}
			$extraction = $result['extraction_info'] ?? array();
			if ( ! empty( $extraction['extraction_method'] ) ) {
				WP_CLI::log( sprintf( '  Extraction method: %s', $extraction['extraction_method'] ) );
			}
			if ( ! empty( $extraction['source_type'] ) ) {
				WP_CLI::log( sprintf( '  Source type: %s', $extraction['source_type'] ) );
			}
			if ( ! empty( $result['warnings'] ) ) {
				foreach ( $result['warnings'] as $w ) {
					WP_CLI::warning( $w );
				}
			}
			WP_CLI::log( '' );
			WP_CLI::log( 'Add this venue with:' );
			WP_CLI::log( sprintf( '  wp extrachill venues add --pipeline=<id> --name="%s" --events-url="%s"',
				$result['name'] ?: '(venue name)',
				$result['events_url']
			) );
		} else {
			WP_CLI::warning( 'NOT QUALIFIED — scraper could not extract events.' );
			if ( ! empty( $result['urls_tested'] ) ) {
				WP_CLI::log( '  URLs tested:' );
				foreach ( array_slice( $result['urls_tested'], 0, 10 ) as $tested ) {
					WP_CLI::log( "    - {$tested}" );
				}
			}
			if ( ! empty( $result['warnings'] ) ) {
				foreach ( $result['warnings'] as $w ) {
					WP_CLI::warning( $w );
				}
			}
		}
	}

	/**
	 * Add a venue scraper flow to an existing city pipeline.
	 *
	 * Creates the venue taxonomy term and a universal_web_scraper flow
	 * configured to scrape the venue's events page.
	 *
	 * ## OPTIONS
	 *
	 * --pipeline=<id>
	 * : Pipeline ID for the city this venue belongs to.
	 *
	 * --name=<name>
	 * : Venue name, e.g. "Exit/In".
	 *
	 * --events-url=<url>
	 * : Venue events page URL (the page the scraper will hit).
	 *
	 * [--address=<address>]
	 * : Venue street address.
	 *
	 * [--city=<city>]
	 * : Venue city name. Defaults to pipeline's city.
	 *
	 * [--state=<state>]
	 * : Venue state.
	 *
	 * [--zip=<zip>]
	 * : Venue zip code.
	 *
	 * [--website=<website>]
	 * : Venue homepage URL (if different from events page).
	 *
	 * [--interval=<interval>]
	 * : Scheduling interval. Default: twicedaily.
	 *
	 * [--dry-run]
	 * : Preview what would be created without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues add --pipeline=20 --name="Exit/In" --events-url="https://exitin.com"
	 *     wp extrachill venues add --pipeline=20 --name="Station Inn" --events-url="https://www.stationinn.com" --address="402 12th Ave S" --city=Nashville --state=Tennessee
	 *     wp extrachill venues add --pipeline=3 --name="The Royal American" --events-url="https://theroyalamerican.com/shows" --dry-run
	 *
	 * @subcommand add
	 * @when after_wp_load
	 */
	public function add( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/add-venue' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/add-venue ability not available. Is extrachill-events active on this site?' );
		}

		$required = array( 'pipeline', 'name', 'events-url' );
		foreach ( $required as $key ) {
			if ( empty( $assoc_args[ $key ] ) ) {
				WP_CLI::error( sprintf( '--%s is required.', $key ) );
			}
		}

		WP_CLI::log( sprintf( 'Adding venue "%s" to pipeline %s...', $assoc_args['name'], $assoc_args['pipeline'] ) );

		$result = $ability->execute( array(
			'pipeline_id' => (int) $assoc_args['pipeline'],
			'name'        => $assoc_args['name'],
			'url'         => $assoc_args['events-url'],
			'address'     => $assoc_args['address'] ?? '',
			'city'        => $assoc_args['city'] ?? '',
			'state'       => $assoc_args['state'] ?? '',
			'zip'         => $assoc_args['zip'] ?? '',
			'website'     => $assoc_args['website'] ?? '',
			'interval'    => $assoc_args['interval'] ?? '',
			'dry_run'     => ! empty( $assoc_args['dry-run'] ),
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		if ( ! empty( $result['dry_run'] ) || ( $result['message'] ?? '' ) === 'Dry run — no changes made.' ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'DRY RUN — would create:' );
			WP_CLI::log( sprintf( '  Pipeline: %s (ID: %d)', $result['pipeline_name'] ?? '?', $result['pipeline_id'] ) );
			WP_CLI::log( sprintf( '  Venue: %s', $result['venue_name'] ?? '?' ) );
			WP_CLI::log( sprintf( '  Events URL: %s', $result['events_url'] ?? '?' ) );
			WP_CLI::log( sprintf( '  Location: %s', $result['location_term'] ?? '?' ) );
			WP_CLI::log( sprintf( '  Interval: %s', $result['interval'] ?? '?' ) );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( '  Flow ID: %d', $result['flow_id'] ?? 0 ) );
		WP_CLI::log( sprintf( '  Venue term ID: %d', $result['venue_term_id'] ?? 0 ) );
		WP_CLI::log( sprintf( '  Events URL: %s', $result['events_url'] ?? '' ) );
		WP_CLI::log( sprintf( '  Interval: %s', $result['interval'] ?? '' ) );
	}
}
