<?php
/**
 * Standalone smoke test for breadcrumb network dropdown tracking.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- WordPress standalone mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );

function add_filter( $hook, $callback ) {}
function apply_filters( $hook, $value ) { return $value; }
function ec_get_site_url( $site ) { return 'https://' . $site . '.example.com'; }
function ec_icon( $name ) { return '<svg aria-hidden="true"></svg>'; }
function wp_enqueue_script( $handle ) {}
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function ec_is_team_member() { return $GLOBALS['is_team_member']; }

require dirname( __DIR__ ) . '/inc/theme/network-dropdown.php';

$failures = 0;
function check( bool $ok, string $label ): void {
	global $failures;
	if ( ! $ok ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

foreach ( array( false, true ) as $is_team_member ) {
	$GLOBALS['is_team_member'] = $is_team_member;
	$html                     = extrachill_network_dropdown( 'Blog' );
	$context                  = $is_team_member ? 'team member' : 'visitor';

	check( 1 === preg_match( '/<span\b[^>]*class="ec-mini-dropdown"[^>]*data-ec-track-placement="network-dropdown"[^>]*>/', $html ), "Wrapper declares tracking placement ({$context})." );
	check( 1 === preg_match( '/<button\b[^>]*class="ec-mini-dropdown-toggle network-dropdown-toggle"[^>]*data-ec-track="network-dropdown-open"[^>]*>/', $html ), "Toggle declares open tracking ({$context})." );

	$link_count = preg_match_all( '/<a\b[^>]*>/', $html, $links );
	check( ( $is_team_member ? 8 : 7 ) === $link_count, "Renders every other network site ({$context})." );
	$tracked_count = preg_match_all( '/<a\b[^>]*data-ec-track="network-dropdown-link"[^>]*>/', $html );
	check( $link_count > 0 && $link_count === $tracked_count, "Every menu link declares link tracking ({$context})." );
}

if ( $failures ) {
	exit( 1 );
}
echo "network dropdown tracking smoke: PASS\n";
