<?php
/**
 * Event Guest List CLI Commands
 *
 * Thin adapter over the extrachill-events RSVP perk abilities
 * (`extrachill/set-event-perk`, `extrachill/get-event-perk`) for running a
 * password-gated, capacity-limited guest list (extrachill#433).
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

/** Configure and check an event's guest list. */
class GuestListCommand {

	/**
	 * Run an Events-owned ability, erroring on failure.
	 *
	 * @param string $ability_name Ability name.
	 * @param array  $input        Input.
	 * @return array
	 */
	private function run( $ability_name, array $input = array() ) {
		$ability = OwnerSiteAbility::get( 'events', 'Events', $ability_name );
		$result  = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Turn on or update an event's guest list.
	 *
	 * Sets the RSVP perk, its password, and its capacity in one call. The
	 * password is stored hashed; omit it to keep the current one.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * [--text=<text>]
	 * : Attendee-facing perk text, e.g. "Extra Chill guest list".
	 *
	 * [--password=<password>]
	 * : Guest list password. Only people who know it can claim a pass.
	 *
	 * [--clear-password]
	 * : Remove the password (anyone can claim).
	 *
	 * [--capacity=<n>]
	 * : Most passes the event will issue. 0 = unlimited.
	 *
	 * [--disable]
	 * : Turn the guest list off.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list set 640348 --text="Extra Chill guest list" --password=spooky --capacity=60 --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function set( $args, $assoc_args ) {
		$input = array(
			'event'   => (string) $args[0],
			'enabled' => ! Utils\get_flag_value( $assoc_args, 'disable', false ),
		);

		if ( isset( $assoc_args['text'] ) ) {
			$input['text'] = (string) $assoc_args['text'];
		} else {
			$current       = $this->run( 'extrachill/get-event-perk', array( 'event' => (string) $args[0] ) );
			$input['text'] = (string) ( $current['text'] ?? '' );
		}

		if ( Utils\get_flag_value( $assoc_args, 'clear-password', false ) ) {
			$input['clear_password'] = true;
		} elseif ( isset( $assoc_args['password'] ) ) {
			if ( '' === (string) $assoc_args['password'] ) {
				WP_CLI::error( 'The password cannot be empty. Use --clear-password to remove it.' );
			}
			$input['password'] = (string) $assoc_args['password'];
		}

		if ( isset( $assoc_args['capacity'] ) ) {
			if ( ! is_numeric( $assoc_args['capacity'] ) || (int) $assoc_args['capacity'] < 0 ) {
				WP_CLI::error( '--capacity must be 0 or a positive number.' );
			}
			$input['capacity'] = (int) $assoc_args['capacity'];
		}

		$this->run( 'extrachill/set-event-perk', $input );
		$this->status( $args, array() );
	}

	/**
	 * Show an event's guest list settings and counts.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
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
	 *     wp extrachill events guest-list status 640348 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$perk = $this->run( 'extrachill/get-event-perk', array( 'event' => (string) $args[0] ) );

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $perk, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = array();
		foreach ( array( 'post_id', 'enabled', 'text', 'password_required', 'capacity', 'claimed', 'checked_in', 'spots_left', 'full' ) as $field ) {
			$value = $perk[ $field ] ?? null;
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} elseif ( null === $value ) {
				$value = 'capacity' === $field || 'spots_left' === $field ? 'unlimited' : '';
			}
			$rows[] = array(
				'field' => $field,
				'value' => (string) $value,
			);
		}

		Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}
