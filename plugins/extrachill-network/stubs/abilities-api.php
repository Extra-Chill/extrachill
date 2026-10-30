<?php
/**
 * Signature stubs for WordPress core's Abilities API (WP 6.9+ / 7.1).
 *
 * The `php-stubs/wordpress-stubs` package — pulled in by the Homeboy WordPress extension —
 * does not yet carry the current signature, so PHPStan reports
 * `wp_get_abilities invoked with 1 parameter, 0 required`. The call is correct;
 * the stub is stale. Core's real declaration, wp-includes/abilities-api.php:498:
 *
 *     function wp_get_abilities( array $args = array() ): array
 *
 * This declares the truth rather than suppressing the finding. Delete once the
 * upstream stubs catch up (Extra-Chill/homeboy-extensions tracks this).
 *
 * @package ExtraChillNetwork
 */

// phpcs:disable

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * @param array<string,mixed> $args Optional query args (category, namespace, meta).
	 * @return array<string,\WP_Ability>
	 */
	function wp_get_abilities( array $args = array() ): array {}
}
