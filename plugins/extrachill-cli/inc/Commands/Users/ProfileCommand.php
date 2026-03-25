<?php
/**
 * Users Profile CLI Command
 *
 * Wraps user profile abilities from extrachill-users.
 *
 * @package ExtraChill\CLI\Commands\Users
 */

namespace ExtraChill\CLI\Commands\Users;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProfileCommand {

	/**
	 * Get user profile data.
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
	 *     wp extrachill users profile get 1
	 *     wp extrachill users profile get chubes --format=table
	 *
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/get-user-profile' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-user-profile ability not available.' );
		}

		$result = $ability->execute( array( 'user_id' => (int) $user->ID ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'json';

		if ( 'table' === $format ) {
			$links_count = isset( $result['links'] ) ? count( $result['links'] ) : 0;
			$fields = array(
				array( 'Field' => 'user_id', 'Value' => $result['user_id'] ),
				array( 'Field' => 'display_name', 'Value' => $result['display_name'] ),
				array( 'Field' => 'username', 'Value' => $result['username'] ),
				array( 'Field' => 'custom_title', 'Value' => $result['custom_title'] ?: '(default)' ),
				array( 'Field' => 'bio', 'Value' => mb_substr( $result['bio'] ?? '', 0, 80 ) . ( mb_strlen( $result['bio'] ?? '' ) > 80 ? '...' : '' ) ),
				array( 'Field' => 'local_city', 'Value' => $result['local_city'] ?: '(none)' ),
				array( 'Field' => 'links', 'Value' => $links_count . ' link(s)' ),
				array( 'Field' => 'artist_status', 'Value' => $result['artist_access']['status'] ?? 'none' ),
			);
			WP_CLI\Utils\format_items( 'table', $fields, array( 'Field', 'Value' ) );
		} else {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Update user profile fields.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--custom-title=<custom-title>]
	 * : Custom title (e.g. "Music Lover").
	 *
	 * [--bio=<bio>]
	 * : User bio/description.
	 *
	 * [--local-city=<local-city>]
	 * : Local scene city/region.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users profile update chubes --bio="Founder of Extra Chill" --local-city="Austin, TX"
	 *     wp extrachill users profile update 1 --custom-title="Captain Chill"
	 *
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/update-user-profile' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/update-user-profile ability not available.' );
		}

		$input = array( 'user_id' => (int) $user->ID );

		if ( isset( $assoc_args['custom-title'] ) ) {
			$input['custom_title'] = (string) $assoc_args['custom-title'];
		}
		if ( isset( $assoc_args['bio'] ) ) {
			$input['bio'] = (string) $assoc_args['bio'];
		}
		if ( isset( $assoc_args['local-city'] ) ) {
			$input['local_city'] = (string) $assoc_args['local-city'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Profile updated for user %d (%s).', (int) $user->ID, $user->user_login ) );
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
