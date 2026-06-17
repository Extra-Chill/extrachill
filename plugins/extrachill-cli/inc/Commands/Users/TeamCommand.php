<?php
/**
 * Team CLI Command
 *
 * Wraps the team-experience stats ability from extrachill-users.
 *
 * @package ExtraChill\CLI\Commands\Users
 */

namespace ExtraChill\CLI\Commands\Users;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Team-experience CLI command surface.
 */
class TeamCommand {

	/**
	 * Show team-experience cohort stats.
	 *
	 * Thin wrapper over the extrachill/get-team-experience-stats ability:
	 * current extra_chill_team count, members added/removed in the window,
	 * and the Studio/Roadie/submission event counts. All logic lives in the
	 * ability (extrachill-users#127).
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Window in days for the event counts. 0 for all time.
	 * ---
	 * default: 28
	 * ---
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
	 *     wp extrachill users team stats
	 *     wp extrachill users team stats --days=90
	 *     wp extrachill users team stats --format=json
	 *
	 * @when after_wp_load
	 * @subcommand stats
	 */
	public function stats( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-team-experience-stats' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill-users plugin is required (ability not found).' );
		}

		$days   = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 28;
		$result = $ability->execute( array( 'days' => $days ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$events = isset( $result['events'] ) && is_array( $result['events'] ) ? $result['events'] : array();

		$rows = array(
			array(
				'Metric' => 'Period',
				'Value'  => (string) ( $result['period'] ?? '' ),
			),
			array(
				'Metric' => 'Team members (current)',
				'Value'  => (string) ( $result['team_member_count'] ?? 0 ),
			),
			array(
				'Metric' => 'Members added (window)',
				'Value'  => (string) ( $result['team_members_added'] ?? 0 ),
			),
			array(
				'Metric' => 'Members removed (window)',
				'Value'  => (string) ( $result['team_members_removed'] ?? 0 ),
			),
		);

		foreach ( $events as $event_type => $count ) {
			$rows[] = array(
				'Metric' => (string) $event_type,
				'Value'  => (string) (int) $count,
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'Metric', 'Value' ) );
	}
}
