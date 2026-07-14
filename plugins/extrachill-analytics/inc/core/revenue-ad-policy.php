<?php
/**
 * Revenue ad-policy integration.
 *
 * Analytics consumes the network-owned policy contract. It does not infer site
 * eligibility from plugin activation or maintain its own site/route matrix.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a network ad-policy response for analytics output.
 *
 * @param mixed $policy Network policy response, or null when unavailable.
 * @return array{site_enabled:?bool,serve_ads:?bool,reason:string}
 */
function extrachill_analytics_revenue_normalize_ad_policy( $policy ) {
	if ( ! is_array( $policy ) || ! array_key_exists( 'site_enabled', $policy ) || ! array_key_exists( 'serve_ads', $policy ) ) {
		return array(
			'site_enabled' => null,
			'serve_ads'    => null,
			'reason'       => 'not_instrumented',
		);
	}

	return array(
		'site_enabled' => is_bool( $policy['site_enabled'] ) ? $policy['site_enabled'] : null,
		'serve_ads'    => is_bool( $policy['serve_ads'] ) ? $policy['serve_ads'] : null,
		'reason'       => isset( $policy['reason'] ) && '' !== (string) $policy['reason'] ? (string) $policy['reason'] : 'unknown',
	);
}

/**
 * Read the authoritative network ad policy, degrading to unknown when absent.
 *
 * @param array $context Site/request context understood by the network policy.
 * @return array{site_enabled:?bool,serve_ads:?bool,reason:string}
 */
function extrachill_analytics_revenue_get_ad_policy( array $context = array() ) {
	if ( ! function_exists( 'extrachill_get_ad_policy' ) ) {
		return extrachill_analytics_revenue_normalize_ad_policy( null );
	}

	$policy = extrachill_get_ad_policy( $context );
	if ( function_exists( 'is_wp_error' ) && is_wp_error( $policy ) ) {
		$policy = null;
	}

	return extrachill_analytics_revenue_normalize_ad_policy( $policy );
}

/**
 * Build anonymous route context without re-resolving persisted attribution.
 *
 * @param int    $blog_id     Owning blog ID.
 * @param string $url         Persisted URL/path.
 * @param string $post_type   Persisted content post type, when resolved.
 * @param string $route_family Existing Analytics route classification.
 * @return array<string,mixed>
 */
function extrachill_analytics_revenue_ad_policy_context( $blog_id, $url = '', $post_type = '', $route_family = '' ) {
	$is_home = 'home' === $route_family;

	return array(
		'blog_id'              => (int) $blog_id,
		'url'                  => (string) $url,
		'post_type'            => (string) $post_type,
		'is_front_page'        => $is_home,
		'is_home'              => $is_home,
		'is_page'              => 'page' === $post_type,
		'is_search'            => false,
		'is_archive'           => in_array( $route_family, array( 'pagination', 'taxonomy-archive' ), true ),
		'is_singular'          => '' !== $post_type,
		'is_post_type_archive' => false,
		'is_lifetime_member'   => false,
	);
}

/**
 * Convert effective policy into the revenue-analysis applicability state.
 *
 * @param array $policy Normalized policy.
 * @return string applicable|not_applicable|unknown
 */
function extrachill_analytics_revenue_policy_status( array $policy ) {
	if ( true === $policy['serve_ads'] ) {
		return 'applicable';
	}
	if ( false === $policy['serve_ads'] ) {
		return 'not_applicable';
	}
	return 'unknown';
}

/**
 * Whether imported revenue contradicts an intentional no-ads policy.
 *
 * @param array $policy  Normalized policy.
 * @param float $revenue Imported source revenue.
 * @return bool
 */
function extrachill_analytics_revenue_policy_conflicts( array $policy, $revenue ) {
	return false === $policy['serve_ads'] && (float) $revenue > 0;
}

/**
 * Summarize policy states represented by aggregate rows.
 *
 * @param array $statuses Policy status counts.
 * @return string applicable|not_applicable|mixed|unknown
 */
function extrachill_analytics_revenue_aggregate_policy_status( array $statuses ) {
	$present = array_keys(
		array_filter(
			$statuses,
			static function ( $count ) {
				return (int) $count > 0;
			}
		)
	);

	if ( 1 === count( $present ) ) {
		return $present[0];
	}
	if ( count( $present ) > 1 ) {
		return 'mixed';
	}
	return 'unknown';
}
