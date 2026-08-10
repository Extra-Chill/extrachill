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
				array(
					'Field' => 'user_id',
					'Value' => $result['user_id'],
				),
				array(
					'Field' => 'first_name',
					'Value' => $result['first_name'],
				),
				array(
					'Field' => 'last_name',
					'Value' => $result['last_name'],
				),
				array(
					'Field' => 'display_name',
					'Value' => $result['display_name'],
				),
				array(
					'Field' => 'email',
					'Value' => $result['email'],
				),
				array(
					'Field' => 'pending_email',
					'Value' => $result['pending_email'] ?? '(none)',
				),
				array(
					'Field' => 'local_scene',
					'Value' => $this->format_local_scene( $result['local_scene'] ?? null ),
				),
				array(
					'Field' => 'local_scene_visibility',
					'Value' => $result['local_scene_visibility'] ?? 'public',
				),
				array(
					'Field' => 'display_name_options',
					'Value' => implode( ', ', $result['display_name_options'] ),
				),
			);
			WP_CLI\Utils\format_items( 'table', $fields, array( 'Field', 'Value' ) );
		} else {
			WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
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
	 * [--local-scene=<location-slug>]
	 * : Canonical Events location slug. Pass an empty string to clear it.
	 *
	 * [--local-scene-visibility=<visibility>]
	 * : Local Scene visibility: public or private.
	 *
	 * [--local-city=<location-slug>]
	 * : Deprecated alias for --local-scene.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users settings update chubes --first-name=Chris --last-name=Huber
	 *     wp extrachill users settings update 1 --display-name="Chris Huber"
	 *     wp extrachill users settings update chubes --local-scene=charleston-sc --local-scene-visibility=public
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
		if ( isset( $assoc_args['local-scene'] ) ) {
			$input['local_scene'] = (string) $assoc_args['local-scene'];
		}
		if ( isset( $assoc_args['local-city'] ) ) {
			WP_CLI::warning( '--local-city is deprecated; use --local-scene=<location-slug> instead.' );
			if ( ! isset( $assoc_args['local-scene'] ) ) {
				$input['local_scene'] = (string) $assoc_args['local-city'];
			}
		}
		if ( isset( $assoc_args['local-scene-visibility'] ) ) {
			$input['local_scene_visibility'] = (string) $assoc_args['local-scene-visibility'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Settings updated for user %d (%s).', (int) $user->ID, $user->user_login ) );
		WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Initiate an email change for a user.
	 *
	 * Sends a verification email to the new address. The email is not
	 * changed until the user clicks the confirmation link.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * <new-email>
	 * : New email address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users settings change-email chubes chris@example.com
	 *     wp extrachill users settings change-email 1 newemail@extrachill.com
	 *
	 * @subcommand change-email
	 * @when after_wp_load
	 */
	public function change_email( $args, $assoc_args ) {
		unset( $assoc_args );

		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$new_email = $args[1] ?? '';
		if ( empty( $new_email ) ) {
			WP_CLI::error( 'New email address is required.' );
		}

		$ability = wp_get_ability( 'extrachill/change-user-email' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/change-user-email ability not available.' );
		}

		$result = $ability->execute(
			array(
				'user_id'   => (int) $user->ID,
				'new_email' => (string) $new_email,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( $result['message'] ?? sprintf( 'Verification email sent for user %d (%s).', (int) $user->ID, $user->user_login ) );
	}

	/**
	 * Change a user's password.
	 *
	 * Requires the current password for verification.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * --current-password=<current-password>
	 * : Current password.
	 *
	 * --new-password=<new-password>
	 * : New password.
	 *
	 * --confirm-password=<confirm-password>
	 * : Confirm new password.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users settings change-password chubes --current-password=old123 --new-password=new456 --confirm-password=new456
	 *
	 * @subcommand change-password
	 * @when after_wp_load
	 */
	public function change_password( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/change-user-password' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/change-user-password ability not available.' );
		}

		$result = $ability->execute(
			array(
				'user_id'          => (int) $user->ID,
				'current_password' => (string) ( $assoc_args['current-password'] ?? '' ),
				'new_password'     => (string) ( $assoc_args['new-password'] ?? '' ),
				'confirm_password' => (string) ( $assoc_args['confirm-password'] ?? '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( $result['message'] ?? sprintf( 'Password changed for user %d (%s).', (int) $user->ID, $user->user_login ) );
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

	private function format_local_scene( $local_scene ) {
		if ( ! is_array( $local_scene ) ) {
			return '(none)';
		}

		return $local_scene['slug'] ?? ( $local_scene['name'] ?? '(none)' );
	}
}
