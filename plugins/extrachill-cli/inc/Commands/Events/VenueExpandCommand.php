<?php
/**
 * `wp extrachill venues expand`
 *
 * Thin adapter over the extrachill/expand-venues ability (extrachill-events).
 * Replaces the non-compliant `wp extrachill-events expand` root command.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueExpandCommand {

	/**
	 * Plan or schedule a bounded venue expansion batch. Dry-run is the default.
	 *
	 * ## OPTIONS
	 *
	 * [--city=<city>]
	 * : One city, e.g. "Charleston, SC". Use exactly one of --city or --cities-file.
	 *
	 * [--cities-file=<path>]
	 * : File with one city per line. Blank lines, `#` comments and duplicates are skipped.
	 *
	 * [--country=<country>]
	 * : Country hint for discovery.
	 *
	 * [--max-cities=<n>]
	 * : Maximum cities in the batch.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--max-venues-per-city=<n>]
	 * : Maximum venues to qualify per city.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--discovery-budget=<n>]
	 * : Discovery operations. Default: one per city.
	 *
	 * [--qualification-budget=<n>]
	 * : Qualification operations. Default: cities × max venues per city.
	 *
	 * [--[no-]skip-existing]
	 * : Skip venues already in the calendar. Default: true.
	 *
	 * [--request-delay-ms=<ms>]
	 * : Delay between external requests.
	 * ---
	 * default: 1000
	 * ---
	 *
	 * [--max-verdict-age-days=<days>]
	 * : Reuse qualification verdicts younger than this.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--city-delay-seconds=<seconds>]
	 * : Spacing between scheduled city starts.
	 * ---
	 * default: 60
	 * ---
	 *
	 * [--apply]
	 * : Schedule the batch. Without it, only the plan is printed.
	 *
	 * [--agent=<slug>]
	 * : Data Machine agent that owns the scheduled jobs (with --apply).
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues expand --city="Charleston, SC" --url=events.extrachill.com
	 *     wp extrachill venues expand --cities-file=cities.txt --apply --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$city     = trim( (string) ( $assoc_args['city'] ?? '' ) );
		$file     = trim( (string) ( $assoc_args['cities-file'] ?? '' ) );
		$has_city = '' !== $city;
		$has_file = '' !== $file;
		if ( $has_city === $has_file ) {
			WP_CLI::error( 'Provide exactly one of --city or --cities-file.' );
		}

		$cities = $has_city ? array( $city ) : self::parse_cities_file( $file );
		if ( empty( $cities ) ) {
			WP_CLI::error( 'No cities were found.' );
		}

		$ability = OwnerSiteAbility::get( 'events', 'Events', 'extrachill/expand-venues' );
		$apply   = ! empty( $assoc_args['apply'] );
		$input   = self::build_expand_input( $cities, $assoc_args );

		if ( $apply ) {
			$resolver = 'DataMachine\\Cli\\AgentResolver';
			if ( ! class_exists( $resolver ) ) {
				WP_CLI::error( 'Data Machine agent resolver is unavailable.' );
			}
			$agent_context = (array) call_user_func( array( $resolver, 'resolveEffectiveContext' ), $assoc_args );
			if ( ! empty( $agent_context['agent_slug'] ) ) {
				$input['agent_slug'] = (string) $agent_context['agent_slug'];
			}
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( ! $apply ) {
			WP_CLI::warning( 'Plan only. Pass --apply to schedule this bounded batch.' );
		}
	}

	/**
	 * Map CLI flags onto the expand-venues ability input.
	 *
	 * @param string[] $cities     Cities in first-seen order.
	 * @param array    $assoc_args Named arguments.
	 * @return array
	 */
	public static function build_expand_input( array $cities, array $assoc_args ) {
		$max_venues = (int) ( $assoc_args['max-venues-per-city'] ?? 10 );

		return array(
			'cities'               => $cities,
			'country'              => (string) ( $assoc_args['country'] ?? '' ),
			'max_cities'           => (int) ( $assoc_args['max-cities'] ?? 10 ),
			'max_venues_per_city'  => $max_venues,
			'discovery_budget'     => (int) ( $assoc_args['discovery-budget'] ?? count( $cities ) ),
			'qualification_budget' => (int) ( $assoc_args['qualification-budget'] ?? count( $cities ) * $max_venues ),
			'skip_existing'        => ! isset( $assoc_args['skip-existing'] ) || (bool) $assoc_args['skip-existing'],
			'apply'                => ! empty( $assoc_args['apply'] ),
			'request_delay_ms'     => (int) ( $assoc_args['request-delay-ms'] ?? 1000 ),
			'max_verdict_age_days' => (int) ( $assoc_args['max-verdict-age-days'] ?? 30 ),
			'city_delay_seconds'   => (int) ( $assoc_args['city-delay-seconds'] ?? 60 ),
		);
	}

	/**
	 * Read a cities file, skipping blanks, comments and duplicates in first-seen order.
	 *
	 * @param string $path File path.
	 * @return string[]
	 */
	public static function parse_cities_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$lines  = file( $path, FILE_IGNORE_NEW_LINES );
		$cities = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( ! in_array( $line, $cities, true ) ) {
				$cities[] = $line;
			}
		}

		return $cities;
	}
}
