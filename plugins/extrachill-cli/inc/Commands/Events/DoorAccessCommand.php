<?php
/**
 * Door Access CLI Command
 *
 * Thin adapter over the extrachill-events `extrachill/grant-door-access`
 * ability (extrachill#1244): give a person check-in access to an event's
 * venue or promoter team from just an email.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Give people door (check-in) access. */
class DoorAccessCommand {

	/**
	 * Give a person door access as a non-owner team member.
	 *
	 * Resolves or creates their account from the email and adds them to the
	 * event's venue team (or its promoter team with --team-type=promoter).
	 * Never grants ownership; an existing member is left unchanged.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Email of the person getting door access.
	 *
	 * [--event=<id>]
	 * : Event post ID. Resolves to its venue team by default.
	 *
	 * [--venue=<term-id>]
	 * : Venue term ID, instead of --event.
	 *
	 * [--promoter=<term-id>]
	 * : Promoter term ID, instead of --event.
	 *
	 * [--team-type=<type>]
	 * : Which of the event's teams to join.
	 * ---
	 * options:
	 *   - venue
	 *   - promoter
	 * ---
	 *
	 * [--user-login=<login>]
	 * : Attach this existing account instead of resolving the email.
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
	 *     wp extrachill events door-access grant door@example.com --event=628174 --user=1 --url=events.extrachill.com
	 *     wp extrachill events door-access grant door@example.com --event=628174 --user-login=doorperson --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function grant( $args, $assoc_args ) {
		$input = array( 'email' => (string) $args[0] );
		foreach ( array(
			'event'    => 'event_id',
			'venue'    => 'venue_term_id',
			'promoter' => 'promoter_term_id',
		) as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				if ( ! is_numeric( $assoc_args[ $flag ] ) || (int) $assoc_args[ $flag ] < 1 ) {
					WP_CLI::error( sprintf( '--%s must be a positive ID.', $flag ) );
				}
				$input[ $key ] = (int) $assoc_args[ $flag ];
			}
		}
		if ( isset( $assoc_args['team-type'] ) ) {
			$input['team_type'] = (string) $assoc_args['team-type'];
		}
		if ( isset( $assoc_args['user-login'] ) ) {
			$input['user_login'] = (string) $assoc_args['user-login'];
		}

		$result = OwnerSiteAbility::get( 'events', 'Events', 'extrachill/grant-door-access' )->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$result = is_array( $result ) ? $result : array();

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$membership = (array) ( $result['membership'] ?? array() );
		Utils\format_items(
			'table',
			array(
				array(
					'user_id'         => $result['user_id'] ?? '',
					'user_login'      => $result['user_login'] ?? '',
					'email'           => $result['email'] ?? '',
					'created_account' => ! empty( $result['created_account'] ) ? 'yes' : 'no',
					'team'            => ( $result['team_type'] ?? '' ) . ' ' . ( $result['team_term_id'] ?? '' ),
					'membership'      => ! empty( $membership['created'] ) ? 'added' : 'already a member',
				),
			),
			array( 'user_id', 'user_login', 'email', 'created_account', 'team', 'membership' )
		);
		if ( ! empty( $result['claim_url'] ) ) {
			WP_CLI::line( 'Claim link: ' . $result['claim_url'] );
		}
		foreach ( (array) ( $result['near_matches'] ?? array() ) as $match ) {
			WP_CLI::warning( sprintf( 'Near match (not used): %s <%s>, user %d, registered %s. Re-run with --user-login=%s if that is the right person.', $match['user_login'], $match['email'], $match['user_id'], $match['registered'], $match['user_login'] ) );
		}
	}
}
