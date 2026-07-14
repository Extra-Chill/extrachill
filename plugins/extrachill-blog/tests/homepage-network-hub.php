<?php
/**
 * Focused tests for homepage bridge URLs and the concise Power explorer.
 *
 * Run: php tests/homepage-network-hub.php
 */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

function add_action() {}
function extrachill_network_bridge_tag_url( $url, $site_key, $source ) {
	return $url . '?' . http_build_query(
		array(
			'utm_source'   => $source,
			'utm_medium'   => 'network_bridge',
			'utm_campaign' => $site_key,
		)
	);
}
function __( $text ) {
	return $text;
}
function esc_html_e( $text ) {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES );
}
function home_url( $path = '' ) {
	return 'https://extrachill.com' . $path;
}
function number_format_i18n( $number ) {
	return number_format( $number );
}
function ec_get_network_stats() {
	return array();
}
function ec_get_site_url( $site_key ) {
	return 'https://' . $site_key . '.extrachill.com';
}

require dirname( __DIR__ ) . '/inc/home/homepage-hooks.php';
require dirname( __DIR__ ) . '/inc/power/power-template.php';

$failures = 0;
function check( $label, $condition ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: $label\n";
		return;
	}

	echo "FAIL: $label\n";
	++$failures;
}

$homepage_url = extrachill_blog_bridge_url( 'https://community.extrachill.com', 'community' );
check( 'homepage destination uses existing bridge analytics contract', false !== strpos( $homepage_url, 'utm_source=homepage' ) );
check( 'homepage destination carries canonical site key', false !== strpos( $homepage_url, 'utm_campaign=community' ) );

$power = extrachill_blog_power_manifesto_html();
check( 'Power opens with a concise network promise', false !== strpos( $power, 'One independent music scene with many doors.' ) );
check( 'Power leads visitors to the network doors', false !== strpos( $power, 'Pick a door' ) );
check( 'Power cards use existing bridge instrumentation class', false !== strpos( $power, 'power-network-card ec-cross-site-link' ) );
check( 'Power destinations identify the Power placement', false !== strpos( $power, 'utm_source=power' ) );
check( 'long manifesto pillars are removed', false === strpos( $power, 'What we stand for' ) );
check( 'duplicated artist conversion section is removed', false === strpos( $power, 'Claim your free artist profile' ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All homepage network hub tests passed.\n";
exit( 0 );
