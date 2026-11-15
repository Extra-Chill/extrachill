<?php
/**
 * `wp extrachill events add-city`
 *
 * Thin adapter over the extrachill/add-city ability (extrachill-events).
 * Replaces the non-compliant `wp extrachill-events add-city` root command.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AddCityCommand {

	/**
	 * Add a city to the events calendar.
	 *
	 * Geocodes the city, creates a pipeline with event_import → AI → update steps,
	 * and sets up Ticketmaster and Dice.fm flows.
	 *
	 * ## OPTIONS
	 *
	 * <city>
	 * : City name with state, e.g. "Nashville, TN" or "Portland, OR"
	 *
	 * [--radius=<miles>]
	 * : Ticketmaster search radius in miles.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--interval=<interval>]
	 * : Scheduling interval for flows.
	 * ---
	 * default: daily
	 * options:
	 *   - manual
	 *   - hourly
	 *   - every_2_hours
	 *   - every_4_hours
	 *   - qtrdaily
	 *   - twicedaily
	 *   - daily
	 *   - every_3_days
	 *   - weekly
	 * ---
	 *
	 * [--skip-dice]
	 * : Skip creating a Dice.fm flow.
	 *
	 * [--force]
	 * : Force creation even if a pipeline for this city already exists.
	 *
	 * [--dry-run]
	 * : Preview what would be created without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events add-city "Nashville, TN" --url=events.extrachill.com
	 *     wp extrachill events add-city "New York, NY" --radius=25 --url=events.extrachill.com
	 *     wp extrachill events add-city "Portland, OR" --dry-run --url=events.extrachill.com
	 *     wp extrachill events add-city "Denver, CO" --interval=every_4_hours --skip-dice --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$city = trim( (string) ( $args[0] ?? '' ) );
		if ( '' === $city ) {
			WP_CLI::error( 'City name is required. Example: wp extrachill events add-city "Nashville, TN"' );
		}

		$input = array(
			'city'      => $city,
			'radius'    => (string) ( $assoc_args['radius'] ?? '50' ),
			'interval'  => (string) ( $assoc_args['interval'] ?? 'daily' ),
			'skip_dice' => ! empty( $assoc_args['skip-dice'] ),
			'force'     => ! empty( $assoc_args['force'] ),
			'dry_run'   => ! empty( $assoc_args['dry-run'] ),
		);

		$result = OwnerSiteAbility::get( 'events', 'Events', 'extrachill/add-city' )->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( isset( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		if ( $input['dry_run'] ) {
			self::print_preview( $result );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'City added.' );
		WP_CLI::log( '' );
		WP_CLI::log( '  Coordinates: ' . ( $result['coordinates'] ?? '' ) );
		WP_CLI::log( '  Pipeline ID: ' . ( $result['pipeline_id'] ?? '' ) );
		WP_CLI::log( '' );

		foreach ( $result['flows'] ?? array() as $flow ) {
			if ( 'created' === ( $flow['status'] ?? 'unknown' ) ) {
				WP_CLI::log( '  ✓ ' . $flow['name'] . ' (flow ID: ' . ( $flow['flow_id'] ?? 'n/a' ) . ')' );
			} else {
				WP_CLI::warning( '  ✗ ' . $flow['name'] . ': ' . ( $flow['error'] ?? 'unknown error' ) );
			}
		}

		WP_CLI::log( '' );
	}

	/**
	 * Print the dry-run plan.
	 *
	 * @param array $result Ability dry-run result.
	 */
	private static function print_preview( array $result ) {
		WP_CLI::log( '' );
		WP_CLI::log( 'DRY RUN — Preview:' );
		WP_CLI::log( '' );
		WP_CLI::log( '  City:        ' . ( $result['city'] ?? '' ) );
		WP_CLI::log( '  Label:       ' . ( $result['city_label'] ?? '' ) );
		WP_CLI::log( '  Coordinates: ' . ( $result['coordinates'] ?? '' ) );
		WP_CLI::log( '  Resolved:    ' . ( $result['display_name'] ?? '' ) );
		WP_CLI::log( '  Pipeline:    ' . ( $result['pipeline'] ?? '' ) );
		WP_CLI::log( '  Interval:    ' . ( $result['interval'] ?? '' ) );

		if ( ! empty( $result['warning'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::warning( $result['warning'] );
		}

		WP_CLI::log( '' );
		WP_CLI::log( '  Flows to create:' );
		foreach ( $result['flows'] ?? array() as $flow ) {
			WP_CLI::log( '    - ' . $flow['name'] . ' (' . $flow['handler'] . ')' );
		}
		WP_CLI::log( '' );
		WP_CLI::log( 'Run without --dry-run to create.' );
	}
}
