<?php
// phpcs:ignoreFile -- Standalone test doubles for the WordPress URL and user APIs.
/**
 * Run with: php tests/notification-unsubscribe-smoke.php
 *
 * @package ExtraChill\Users
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function add_action( ...$args ) {}
function wp_salt( $scheme ) { return 'unsubscribe-test-secret'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_userdata( $id ) { return 42 === $id ? (object) array( 'ID' => 42 ) : false; }
function rest_url( $path ) { return 'https://example.com/wp-json/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
class WP_Error {}

require_once dirname( __DIR__, 2 ) . '/extrachill-network/inc/core/signed-token.php';
require_once dirname( __DIR__ ) . '/inc/notifications/unsubscribe.php';

function unsubscribe_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

parse_str( parse_url( ec_notifications_unsubscribe_url( 42 ), PHP_URL_QUERY ), $params );
unsubscribe_check( array( 'token' ) === array_keys( $params ), 'new URLs contain only token' );
unsubscribe_check( 42 === ec_notifications_unsubscribe_verify( 0, 0, '', $params['token'] ), 'new link round trip' );
unsubscribe_check( ec_notifications_unsubscribe_verify( 0, 0, '', $params['token'], time() + EC_NOTIFICATIONS_UNSUBSCRIBE_TTL + 1 ) instanceof WP_Error, 'expiry' );
$issued = time();
$sig    = ec_notifications_unsubscribe_signature( 42, $issued );
unsubscribe_check( 42 === ec_notifications_unsubscribe_verify( 42, $issued, $sig, '', EXTRACHILL_SIGN_LEGACY_UNTIL - 1 ), 'legacy before cutoff' );
unsubscribe_check( ec_notifications_unsubscribe_verify( 42, $issued, $sig, '', EXTRACHILL_SIGN_LEGACY_UNTIL ) instanceof WP_Error, 'legacy at cutoff' );
unsubscribe_check( ec_notifications_unsubscribe_verify( 42, $issued, $sig, '', EXTRACHILL_SIGN_LEGACY_UNTIL + 1 ) instanceof WP_Error, 'legacy after cutoff' );
$token = extrachill_sign( 'users.notification_read', array( 'user_id' => 42 ), 3600 );
unsubscribe_check( ec_notifications_unsubscribe_verify( 0, 0, '', $token ) instanceof WP_Error, 'purpose separation' );
$token = extrachill_sign( 'users.notifications_unsubscribe', array( 'user_id' => 99 ), 3600 );
unsubscribe_check( ec_notifications_unsubscribe_verify( 0, 0, '', $token ) instanceof WP_Error, 'missing user' );
$issued = time() - EC_NOTIFICATIONS_UNSUBSCRIBE_TTL - 1;
$sig    = ec_notifications_unsubscribe_signature( 42, $issued );
unsubscribe_check( ec_notifications_unsubscribe_verify( 42, $issued, $sig, '', EXTRACHILL_SIGN_LEGACY_UNTIL - 1 ) instanceof WP_Error, 'legacy original TTL' );
echo "PASS: notification unsubscribe signed tokens, legacy cutoff, purpose separation and user checks.\n";
