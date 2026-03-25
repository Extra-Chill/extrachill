<?php
/**
 * Users Settings CLI Command
 *
 * Wraps user settings abilities from extrachill-users.
 *
 * @package ExtraChill\CLI\Commands\Users
 */

namespace ExtraChill\CLI\Commands\Users;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsCommand {

	/**
	 * Get user account settings.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users settings get 1
	 *     wp extrachill users settings get chubes --format=table
	 *
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/get-user-settings' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-user-settings ability not available.' );
		}

		$result = $ability->execute( array( 'user_id' => (int) $user->ID ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'json';

		if ( 'table' === $format ) {
			$fields = array(
				array( 'Field' => 'user_id', 'Value' => $result['user_id'] ),
				array( 'Field' => 'first_name', 'Value' => $result['first_name'] ),
				array( 'Field' => 'last_name', 'Value' => $result['last_name'] ),
				array( 'Field' => 'display_name', 'Value' => $result['display_name'] ),
				array( 'Field' => 'email', 'Value' => $result['email'] ),
				array( 'Field' => 'pending_email', 'Value' => $result['pending_email'] ?? '(none)' ),
				array( 'Field' => 'display_name_options', 'Value' => implode( ', ', $result['display_name_options'] ) ),
			);
			WP_CLI\Utils\format_items( 'table', $fields, array( 'Field', 'Value' ) );
		} else {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Update user account settings.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--first-name=<first-name>]
	 * : First name.
	 *
	 * [--last-name=<last-name>]
	 * : Last name.
	 *
	 * [--display-name=<display-name>]
	 * : Display name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users settings update chubes --first-name=Chris --last-name=Huber
	 *     wp extrachill users settings update 1 --display-name="Chris Huber"
	 *
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/update-user-settings' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/update-user-settings ability not available.' );
		}

		$input = array( 'user_id' => (int) $user->ID );

		if ( isset( $assoc_args['first-name'] ) ) {
			$input['first_name'] = (string) $assoc_args['first-name'];
		}
		if ( isset( $assoc_args['last-name'] ) ) {
			$input['last_name'] = (string) $assoc_args['last-name'];
		}
		if ( isset( $assoc_args['display-name'] ) ) {
			$input['display_name'] = (string) $assoc_args['display-name'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Settings updated for user %d (%s).', (int) $user->ID, $user->user_login ) );
		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}

	private function resolve_user( $identifier ) {
		if ( is_numeric( $identifier ) ) {
			return get_user_by( 'id', (int) $identifier );
		}

		if ( is_email( $identifier ) ) {
			return get_user_by( 'email', $identifier );
		}

		return get_user_by( 'login', $identifier );
	}
}
