<?php
/**
 * Sitemap Health Ability Callback
 *
 * Execute callback for the check-sitemap-health ability. Exposes the sitemap
 * health guardrail via WP-CLI, REST, MCP, and chat through the Abilities API.
 *
 * @package ExtraChill\SEO\Abilities
 */

namespace ExtraChill\SEO\Abilities;

use function ExtraChill\SEO\Core\ec_seo_check_sitemap_health;
use const ExtraChill\SEO\Core\EC_SEO_SITEMAP_HEALTH_OPTION;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute callback for the check-sitemap-health ability.
 *
 * Runs the live sitemap health check (or returns the last stored result) and
 * reports whether every sampled core sitemap sub-file returns HTTP 200.
 *
 * @param array $input Input parameters.
 * @return array Result payload matching the ability output schema.
 */
function extrachill_seo_ability_check_sitemap_health( $input = array() ) {
	$use_cache = ! empty( $input['cached'] );

	if ( $use_cache ) {
		$result = get_site_option( EC_SEO_SITEMAP_HEALTH_OPTION );
		if ( empty( $result ) ) {
			$result = ec_seo_check_sitemap_health( ec_seo_sitemap_health_ability_args( $input ) );
		}
	} else {
		$result = ec_seo_check_sitemap_health( ec_seo_sitemap_health_ability_args( $input ) );
	}

	$failure_count = isset( $result['failures'] ) ? count( $result['failures'] ) : 0;

	$message = $result['healthy']
		? sprintf(
			/* translators: %d: number of sampled sitemap sub-files. */
			__( 'All %d sampled sitemap sub-files return HTTP 200.', 'extrachill-seo' ),
			isset( $result['checked'] ) ? (int) $result['checked'] : 0
		)
		: sprintf(
			/* translators: %d: number of failing sitemap sub-files. */
			__( '%d sitemap sub-file(s) are not returning HTTP 200. Likely an edge/nginx rule colliding with the core wp-sitemap namespace.', 'extrachill-seo' ),
			$failure_count
		);

	return array(
		'healthy'   => (bool) $result['healthy'],
		'checked'   => isset( $result['checked'] ) ? (int) $result['checked'] : 0,
		'failures'  => isset( $result['failures'] ) ? array_values( $result['failures'] ) : array(),
		'by_site'   => isset( $result['by_site'] ) ? $result['by_site'] : array(),
		'timestamp' => isset( $result['timestamp'] ) ? $result['timestamp'] : gmdate( 'c' ),
		'message'   => $message,
	);
}

/**
 * Build the runner args from ability input.
 *
 * @param array $input Ability input.
 * @return array Args for ec_seo_check_sitemap_health().
 */
function ec_seo_sitemap_health_ability_args( $input ) {
	$args = array();
	if ( isset( $input['blog_id'] ) && '' !== $input['blog_id'] ) {
		$args['blog_id'] = (int) $input['blog_id'];
	}
	return $args;
}
