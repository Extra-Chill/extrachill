<?php
/**
 * Regression guard for #1120: loopback REST calls must not share a rate bucket.
 * Run: php deploy/tests/nginx-loopback-rate-limit-smoke.php
 */
declare( strict_types=1 );

$root = dirname( __DIR__, 2 );
$conf = (string) file_get_contents( $root . '/deploy/nginx/conf.d/bot-blocking.conf' );
$site = (string) file_get_contents( $root . '/deploy/nginx/sites-enabled/extrachill' );
$checks = array(
	'only IPv4 and IPv6 loopback are exempted by geo' =>
		1 === preg_match( '/geo\s+\$is_loopback\s*\{\s*default\s+0;\s*127\.0\.0\.1\/32\s+1;\s*::1\/128\s+1;\s*\}/', $conf ),
	'anonymous external traffic keeps its IP key; loopback and logged-in traffic have empty keys' =>
		1 === preg_match( '/map\s+"\$is_loopback:\$wp_logged_in"\s+\$limit_key\s*\{\s*default\s+\$binary_remote_addr;\s*"0:1"\s+"";\s*"1:0"\s+"";\s*"1:1"\s+"";\s*\}/', $conf ),
	'logged-in cookie exemption remains' =>
		1 === preg_match( '/map\s+\$http_cookie\s+\$wp_logged_in\s*\{\s*default\s+0;\s*"~\*wordpress_logged_in_"\s+1;\s*\}/', $conf ),
	'REST zone remains 5r/s' => str_contains( $conf, 'limit_req_zone $limit_key zone=wpjson:10m rate=5r/s;' ),
	'REST burst remains 10 with no delay' => str_contains( $site, 'limit_req zone=wpjson burst=10 nodelay;' ),
	'login zone still uses the IP without exemptions' => str_contains( $conf, 'limit_req_zone $binary_remote_addr zone=wp_login:10m rate=3r/m;' ),
	'rate-limit responses remain 429' => str_contains( $conf, 'limit_req_status 429;' ),
);

foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
