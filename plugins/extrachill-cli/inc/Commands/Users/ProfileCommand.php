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
				array(
					'Field' => 'user_id',
					'Value' => $result['user_id'],
				),
				array(
					'Field' => 'display_name',
					'Value' => $result['display_name'],
				),
				array(
					'Field' => 'username',
					'Value' => $result['username'],
				),
				array(
					'Field' => 'custom_title',
					'Value' => $result['custom_title'] ? $result['custom_title'] : '(default)',
				),
				array(
					'Field' => 'bio',
					'Value' => mb_substr( $result['bio'] ?? '', 0, 80 ) . ( mb_strlen( $result['bio'] ?? '' ) > 80 ? '...' : '' ),
				),
				array(
					'Field' => 'local_scene',
					'Value' => $this->format_local_scene( $result['local_scene'] ?? null ),
				),
				array(
					'Field' => 'links',
					'Value' => $links_count . ' link(s)',
				),
				array(
					'Field' => 'artist_status',
					'Value' => $result['artist_access']['status'] ?? 'none',
				),
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
	 * : Deprecated alias for --local-scene.
	 *
	 * [--local-scene=<location-slug>]
	 * : Canonical Events location slug. Pass an empty string to clear it.
	 *
	 * [--local-scene-visibility=<visibility>]
	 * : Local Scene visibility: public or private.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users profile update chubes --bio="Founder of Extra Chill" --local-scene=charleston-sc
	 *     wp extrachill users profile update 1 --custom-title="Captain Chill"
	 *
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$input = array( 'user_id' => (int) $user->ID );

		if ( isset( $assoc_args['custom-title'] ) ) {
			$input['custom_title'] = (string) $assoc_args['custom-title'];
		}
		if ( isset( $assoc_args['bio'] ) ) {
			$input['bio'] = (string) $assoc_args['bio'];
		}

		$settings_input = array( 'user_id' => (int) $user->ID );
		if ( isset( $assoc_args['local-scene'] ) ) {
			$settings_input['local_scene'] = (string) $assoc_args['local-scene'];
		}
		if ( isset( $assoc_args['local-city'] ) ) {
			WP_CLI::warning( '--local-city is deprecated; use --local-scene=<location-slug> instead.' );
			if ( ! isset( $assoc_args['local-scene'] ) ) {
				$settings_input['local_scene'] = (string) $assoc_args['local-city'];
			}
		}
		if ( isset( $assoc_args['local-scene-visibility'] ) ) {
			$settings_input['local_scene_visibility'] = (string) $assoc_args['local-scene-visibility'];
		}

		$results = array();
		if ( count( $input ) > 1 ) {
			$ability = wp_get_ability( 'extrachill/update-user-profile' );
			if ( ! $ability ) {
				WP_CLI::error( 'extrachill/update-user-profile ability not available.' );
			}
			$results['profile'] = $ability->execute( $input );
			if ( is_wp_error( $results['profile'] ) ) {
				WP_CLI::error( $results['profile']->get_error_message() );
			}
		}

		if ( count( $settings_input ) > 1 ) {
			$ability = wp_get_ability( 'extrachill/update-user-settings' );
			if ( ! $ability ) {
				WP_CLI::error( 'extrachill/update-user-settings ability not available.' );
			}
			$results['settings'] = $ability->execute( $settings_input );
			if ( is_wp_error( $results['settings'] ) ) {
				WP_CLI::error( $results['settings']->get_error_message() );
			}
		}

		WP_CLI::success( sprintf( 'Profile updated for user %d (%s).', (int) $user->ID, $user->user_login ) );
		WP_CLI::log( wp_json_encode( count( $results ) === 1 ? reset( $results ) : $results, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Update user profile links.
	 *
	 * Replaces all existing profile links with the provided set.
	 * Each link requires a type key and URL. Pass links as a JSON array.
	 *
	 * Valid type keys: website, facebook, instagram, twitter, youtube,
	 * tiktok, spotify, soundcloud, bandcamp, github, other.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * <links-json>
	 * : JSON array of link objects. Each object: {"type_key":"...", "url":"...", "custom_label":"..."}.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users profile update-links chubes '[{"type_key":"website","url":"https://extrachill.com"},{"type_key":"instagram","url":"https://instagram.com/extrachill"}]'
	 *     wp extrachill users profile update-links 1 '[]'
	 *
	 * @subcommand update-links
	 * @when after_wp_load
	 */
	public function update_links( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$links_raw = $args[1] ?? '';
		$links     = json_decode( $links_raw, true );

		if ( ! is_array( $links ) ) {
			WP_CLI::error( 'Links must be a valid JSON array.' );
		}

		$ability = wp_get_ability( 'extrachill/update-user-links' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/update-user-links ability not available.' );
		}

		$result = $ability->execute(
			array(
				'user_id' => (int) $user->ID,
				'links'   => $links,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$count = isset( $result['links'] ) ? count( $result['links'] ) : 0;
		WP_CLI::success( sprintf( 'Updated %d link(s) for user %d (%s).', $count, (int) $user->ID, $user->user_login ) );
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

	private function format_local_scene( $local_scene ) {
		if ( ! is_array( $local_scene ) ) {
			return '(none)';
		}

		return $local_scene['slug'] ?? ( $local_scene['name'] ?? '(none)' );
	}
}
