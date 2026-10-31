<?php
/**
 * Standalone tests for the canonical ad policy and ability contract.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ad_policy_options']   = array();
$GLOBALS['ad_policy_filters']   = array();
$GLOBALS['ad_policy_abilities'] = array();
$GLOBALS['ad_policy_member']    = false;

function get_site_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['ad_policy_options'] ) ? $GLOBALS['ad_policy_options'][ $name ] : $default;
}

function get_current_blog_id() {
	return 1;
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}

function is_singular() {
	return false;
}

function get_post_type() {
	return '';
}

function is_front_page() {
	return false;
}

function is_home() {
	return false;
}

function is_page() {
	return false;
}

function is_search() {
	return false;
}

function is_archive() {
	return false;
}

function is_post_type_archive() {
	return false;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ad_policy_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function remove_all_filters( $hook ) {
	unset( $GLOBALS['ad_policy_filters'][ $hook ] );
}

function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['ad_policy_filters'][ $hook ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['ad_policy_filters'][ $hook ] );
	foreach ( $GLOBALS['ad_policy_filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as list( $callback, $accepted_args ) ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}

	return $value;
}

function add_action() {
	// Ability registration is invoked explicitly in this standalone suite.
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['ad_policy_abilities'][ $name ] = $args;
}

function __( $text ) {
	return $text;
}

function is_user_lifetime_member() {
	return $GLOBALS['ad_policy_member'];
}

require_once dirname( __DIR__ ) . '/inc/core/ad-policy.php';
require_once dirname( __DIR__ ) . '/inc/integrations/member-ad-benefit.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/AdPolicyAbility.php';

function ad_policy_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function ad_policy_reset(): void {
	$GLOBALS['ad_policy_options'] = array();
	$GLOBALS['ad_policy_member']  = false;
	remove_all_filters( 'extrachill_ad_integration_health' );
	remove_all_filters( 'extrachill_ad_policy_exclusion' );
	remove_all_filters( 'extrachill_should_block_ads' );
}

function ad_policy_available_health(): void {
	add_filter(
		'extrachill_ad_integration_health',
		static fn() => array(
			'available'         => true,
			'delivery_detected' => true,
		)
	);
}

ad_policy_reset();
ad_policy_assert_same( array( 1, 7, 11 ), extrachill_get_ad_enabled_site_ids(), 'Migration defaults enable only Main, Events, and Wire.' );
foreach ( array( 1, 2, 3, 4, 7, 9, 10, 11, 12 ) as $blog_id ) {
	$expected = in_array( $blog_id, array( 1, 7, 11 ), true );
	ad_policy_assert_same( $expected, extrachill_get_ad_policy( array( 'blog_id' => $blog_id ) )['site_enabled'], "Default eligibility is correct for blog {$blog_id}." );
}

ad_policy_reset();
$GLOBALS['ad_policy_options']['extrachill_ad_enabled_site_ids'] = array( 2 );
ad_policy_available_health();
ad_policy_assert_same( 'enabled', extrachill_get_ad_policy( array( 'blog_id' => 2 ) )['reason'], 'An explicitly enabled site serves ads when delivery is available.' );
ad_policy_assert_same( 'site_disabled', extrachill_get_ad_policy( array( 'blog_id' => 1 ) )['reason'], 'An explicitly disabled site reports intentional policy.' );

ad_policy_reset();
ad_policy_available_health();
add_filter( 'extrachill_should_block_ads', static fn( $blocked, $context ) => ! empty( $context['is_search'] ), 10, 2 );
$route_policy = extrachill_get_ad_policy( array( 'blog_id' => 1, 'is_search' => true ) );
ad_policy_assert_same( 'route_blocked', $route_policy['reason'], 'Legacy route exclusions map to route_blocked.' );
ad_policy_assert_same( false, $route_policy['serve_ads'], 'Route exclusions prevent serving ads.' );

ad_policy_reset();
ad_policy_available_health();
$GLOBALS['ad_policy_member'] = true;
add_filter( 'extrachill_ad_policy_exclusion', 'extrachill_member_ad_policy_exclusion' );
$member_policy = extrachill_get_ad_policy( array( 'blog_id' => 1 ) );
ad_policy_assert_same( 'member_benefit', $member_policy['reason'], 'Reason-aware member exclusions propagate their stable reason.' );
ad_policy_assert_same( false, $member_policy['serve_ads'], 'Member benefits prevent serving ads.' );

ad_policy_reset();
$unavailable = extrachill_get_ad_policy( array( 'blog_id' => 1 ) );
ad_policy_assert_same( 'integration_unavailable', $unavailable['reason'], 'Eligible traffic reports unavailable delivery.' );
ad_policy_assert_same( 'enabled_without_delivery', $unavailable['drift'], 'Enabled-without-delivery drift is diagnosed.' );

ad_policy_reset();
add_filter( 'extrachill_ad_integration_health', static fn() => array( 'available' => true, 'delivery_detected' => true ) );
$disabled_delivery = extrachill_get_ad_policy( array( 'blog_id' => 2 ) );
ad_policy_assert_same( 'site_disabled', $disabled_delivery['reason'], 'Delivery evidence does not override disabled intent.' );
ad_policy_assert_same( 'disabled_with_delivery', $disabled_delivery['drift'], 'Disabled-with-delivery drift is diagnosed.' );

ad_policy_reset();
ad_policy_available_health();
$ability = new \ExtraChillNetwork\Abilities\AdPolicyAbility();
$ability->register();
$output = $ability->execute( array( 'blog_id' => 7 ) );
ad_policy_assert_same( true, $output['serve_ads'], 'Ability output uses the canonical primitive.' );
ad_policy_assert_same( 'enabled', $output['reason'], 'Ability output includes the stable reason.' );
ad_policy_assert_same(
	array( 'enabled', 'site_disabled', 'route_blocked', 'member_benefit', 'integration_unavailable' ),
	$GLOBALS['ad_policy_abilities']['extrachill/get-ad-policy']['output_schema']['properties']['reason']['enum'],
	'Ability schema publishes every stable policy reason.'
);

fwrite( STDOUT, "Ad policy tests passed.\n" );
