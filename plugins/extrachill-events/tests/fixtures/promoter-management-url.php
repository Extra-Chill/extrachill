<?php
/**
 * Promoter management URL fixture.
 *
 * @package ExtraChillEvents\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );

/**
 * Return the fixture Events site ID.
 *
 * @param string $site Site key.
 */
function ec_get_blog_id( $site ): int {
	return 'events' === $site ? 7 : 0;
}
/**
 * Return a deterministic fixture home URL.
 *
 * @param int    $blog_id Blog ID.
 * @param string $path    Requested path.
 */
function get_home_url( $blog_id, $path = '' ): string {
	unset( $blog_id );
	return 'https://events.example' . $path;
}
/**
 * Add one deterministic fixture query argument.
 *
 * @param string $key   Query key.
 * @param string $value Query value.
 * @param string $url   Base URL.
 */
function add_query_arg( $key, $value, $url ): string {
	return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

require_once dirname( __DIR__, 2 ) . '/inc/Core/PromoterLinkPages.php';

$url   = \ExtraChillEvents\Core\PromoterLinkPages::management_url( 30 );
$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Deterministic fixture URL.
parse_str( (string) ( $parts['query'] ?? '' ), $query );
echo json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone fixture without WordPress.
	array(
		'path'     => $parts['path'] ?? '',
		'identity' => $query['identity'] ?? '',
		'fragment' => $parts['fragment'] ?? '',
	)
);
