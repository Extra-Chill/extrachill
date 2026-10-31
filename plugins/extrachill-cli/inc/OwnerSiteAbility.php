<?php
/**
 * Owner-site ability resolution for WP-CLI commands.
 *
 * @package ExtraChill\CLI
 */

namespace ExtraChill\CLI;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OwnerSiteAbility {

	/**
	 * Resolve an ability from its normally bootstrapped owner site.
	 *
	 * @param string $site_key     Network site key.
	 * @param string $site_label   Human-readable owner label.
	 * @param string $ability_name Ability name.
	 * @return \WP_Ability|null Ability instance. Errors when unavailable.
	 */
	public static function get( $site_key, $site_label, $ability_name ) {
		if ( ! function_exists( 'ec_get_blog_id' ) ) {
			WP_CLI::error( 'Extra Chill Network site resolution is unavailable. Ensure the Network plugin is active.' );
		}

		$owner_blog_id = (int) ec_get_blog_id( $site_key );
		if ( ! $owner_blog_id ) {
			WP_CLI::error( sprintf( 'Could not resolve the %s owner site.', $site_label ) );
		}

		$owner_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( $site_key ) : get_home_url( $owner_blog_id );
		if ( get_current_blog_id() !== $owner_blog_id ) {
			WP_CLI::error(
				sprintf(
					'%1$s is owned by the %2$s site. Run this command with --url=%3$s.',
					$ability_name,
					$site_label,
					$owner_url
				)
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'Abilities API not available (requires WordPress 6.9+).' );
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			$message = sprintf(
				'%1$s ability not available on %2$s. Ensure its owner plugin is active for %3$s.',
				$ability_name,
				$site_label,
				$owner_url
			);
			WP_CLI::error( $message );
		}

		return $ability;
	}
}
