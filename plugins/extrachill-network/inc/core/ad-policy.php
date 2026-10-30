<?php
/**
 * Canonical network ad policy.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the network-owned list of ad-enabled site IDs.
 *
 * An absent option preserves the production migration defaults. An explicitly
 * saved empty array disables ads across the network.
 *
 * @return int[]
 */
function extrachill_get_ad_enabled_site_ids(): array {
	$site_ids = get_site_option( 'extrachill_ad_enabled_site_ids', null );

	if ( null === $site_ids ) {
		$site_ids = array( 1, 7, 11 );
	}

	if ( ! is_array( $site_ids ) ) {
		return array();
	}

	$site_ids = array_map( 'absint', $site_ids );
	$site_ids = array_values( array_unique( array_filter( $site_ids ) ) );
	sort( $site_ids );

	return $site_ids;
}

/**
 * Build the request context consumed by existing ad exclusion extensions.
 *
 * @param array<string, mixed> $context Explicit context overrides.
 * @return array<string, mixed>
 */
function extrachill_get_ad_request_context( array $context = array() ): array {
	$blog_id       = isset( $context['blog_id'] ) ? absint( $context['blog_id'] ) : get_current_blog_id();
	$is_current    = get_current_blog_id() === $blog_id;
	$runtime_value = static function ( string $key, callable $resolver ) use ( $context, $is_current ): bool {
		if ( array_key_exists( $key, $context ) ) {
			return (bool) $context[ $key ];
		}

		return $is_current ? (bool) $resolver() : false;
	};

	return array_merge(
		$context,
		array(
			'blog_id'              => $blog_id,
			'post_type'            => isset( $context['post_type'] ) ? sanitize_key( (string) $context['post_type'] ) : ( $is_current && is_singular() ? (string) get_post_type() : '' ),
			'is_front_page'        => $runtime_value( 'is_front_page', 'is_front_page' ),
			'is_home'              => $runtime_value( 'is_home', 'is_home' ),
			'is_page'              => $runtime_value( 'is_page', 'is_page' ),
			'is_search'            => $runtime_value( 'is_search', 'is_search' ),
			'is_archive'           => $runtime_value( 'is_archive', 'is_archive' ),
			'is_singular'          => $runtime_value( 'is_singular', 'is_singular' ),
			'is_post_type_archive' => $runtime_value( 'is_post_type_archive', 'is_post_type_archive' ),
		)
	);
}

/**
 * Return the authoritative effective ad policy for a request.
 *
 * Product owners can return `route_blocked` or `member_benefit` from the
 * `extrachill_ad_policy_exclusion` filter. The legacy boolean
 * `extrachill_should_block_ads` filter remains supported and maps to
 * `route_blocked` when no reason-aware exclusion was supplied.
 *
 * Integration adapters provide health evidence through
 * `extrachill_ad_integration_health`; vendor identity never enters the public
 * policy contract.
 *
 * @param array<string, mixed> $context Request context overrides.
 * @return array<string, mixed>
 */
function extrachill_get_ad_policy( array $context = array() ): array {
	$context      = extrachill_get_ad_request_context( $context );
	$blog_id      = (int) $context['blog_id'];
	$site_enabled = in_array( $blog_id, extrachill_get_ad_enabled_site_ids(), true );
	$health       = apply_filters(
		'extrachill_ad_integration_health',
		array(
			'available'         => false,
			'delivery_detected' => false,
		),
		$blog_id,
		$context
	);
	$health       = is_array( $health ) ? $health : array();
	$available    = ! empty( $health['available'] );
	$delivery     = ! empty( $health['delivery_detected'] );
	$drift        = 'none';

	if ( $site_enabled && ! $available ) {
		$drift = 'enabled_without_delivery';
	} elseif ( ! $site_enabled && $delivery ) {
		$drift = 'disabled_with_delivery';
	}

	$reason = 'enabled';
	if ( ! $site_enabled ) {
		$reason = 'site_disabled';
	} else {
		$exclusion = apply_filters( 'extrachill_ad_policy_exclusion', null, $context );
		if ( in_array( $exclusion, array( 'route_blocked', 'member_benefit' ), true ) ) {
			$reason = $exclusion;
		} elseif ( (bool) apply_filters( 'extrachill_should_block_ads', false, $context ) ) {
			$reason = 'route_blocked';
		} elseif ( ! $available ) {
			$reason = 'integration_unavailable';
		}
	}

	return array(
		'blog_id'               => $blog_id,
		'site_enabled'          => $site_enabled,
		'serve_ads'             => 'enabled' === $reason,
		'reason'                => $reason,
		'integration_available' => $available,
		'delivery_detected'     => $delivery,
		'drift'                 => $drift,
	);
}
