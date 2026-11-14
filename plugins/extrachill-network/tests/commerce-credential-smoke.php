<?php
/**
 * ec_commerce_credential(): one network read path for commerce credentials (#1402).
 *
 * Run: php tests/commerce-credential-smoke.php
 *
 * @package ExtraChillNetwork
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ec_test_actions'] = array();
$GLOBALS['ec_test_filters'] = array();

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['ec_test_actions'][ $hook ][] = array( $callback, $priority );
}
function add_filter( $hook, $callback ) {
	$GLOBALS['ec_test_filters'][ $hook ][] = $callback;
}
function __( $text ) {
	return $text;
}

$log_file = tempnam( sys_get_temp_dir(), 'ec-commerce-credential-' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $log_file );

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
	echo 'PASS: ' . $message . PHP_EOL;
};
$log    = static function () use ( $log_file ) {
	clearstatcache();
	return (string) file_get_contents( $log_file );
};

require_once dirname( __DIR__ ) . '/inc/commerce/auth/bootstrap.php';

$assert(
	array( array( 'extrachill_network_load_commerce_auth_providers', 30 ) ) === $GLOBALS['ec_test_actions']['plugins_loaded'],
	'providers load on plugins_loaded priority 30'
);

// 1. Provider layer missing: empty value, loud log, once per message.
$assert( '' === ec_commerce_credential( 'stripe', 'secret_key' ), 'missing Data Machine reads as empty' );
ec_commerce_credential( 'stripe', 'secret_key' );
$assert( 1 === substr_count( $log(), 'CRITICAL commerce credentials' ), 'missing provider layer logs once per message' );
$assert( false !== strpos( $log(), 'stripe.secret_key' ) && false !== strpos( $log(), 'BaseAuthProvider' ), 'log names the credential and the missing provider layer' );
$assert( false === class_exists( '\\ExtraChillNetwork\\Commerce\\Auth\\StripeAuthProvider', false ), 'provider classes are not loaded without Data Machine' );

// 2. Data Machine arrives later (autoloader registered after this file): the
// lazy read loads the providers. Stub storage mirrors datamachine_auth_data.
eval(
	'namespace DataMachine\Core\OAuth;
	abstract class BaseAuthProvider {
		protected $provider_slug;
		public function __construct( string $provider_slug ) { $this->provider_slug = $provider_slug; }
		abstract public function get_config_fields(): array;
		abstract public function is_authenticated(): bool;
		public function get_config( array $context = array() ): array {
			return $GLOBALS["datamachine_auth_data"][ $this->provider_slug ]["config"] ?? array();
		}
		public function save_config( array $data, array $context = array() ): bool {
			$GLOBALS["datamachine_auth_data"][ $this->provider_slug ]["config"] = $data;
			return true;
		}
	}'
);

$GLOBALS['datamachine_auth_data'] = array(
	'ec_stripe' => array(
		'config' => array(
			'secret_key'             => " sk_test_123\n",
			'publishable_key'        => 'pk_test_123',
			'webhook_secret'         => 'whsec_platform',
			'connect_webhook_secret' => 'whsec_connect',
		),
	),
	'ec_shippo' => array( 'config' => array( 'api_key' => 'shippo_test_123' ) ),
);

$assert( 'sk_test_123' === ec_commerce_credential( 'stripe', 'secret_key' ), 'stripe secret key is read from the ec_stripe slot, trimmed' );
$assert( 'pk_test_123' === ec_commerce_credential( 'stripe', 'publishable_key' ), 'stripe publishable key' );
$assert( 'whsec_platform' === ec_commerce_credential( 'stripe', 'webhook_secret' ), 'stripe platform webhook secret' );
$assert( 'whsec_connect' === ec_commerce_credential( 'stripe', 'connect_webhook_secret' ), 'stripe connect webhook secret' );
$assert( 'shippo_test_123' === ec_commerce_credential( 'shippo', 'api_key' ), 'shippo api key is read from the ec_shippo slot' );
$assert( '' === ec_commerce_credential( 'stripe', 'not_a_field' ), 'unset field reads as empty' );
$assert( isset( $GLOBALS['ec_test_filters']['datamachine_auth_providers'], $GLOBALS['ec_test_filters']['datamachine_auth_encrypted_fields'] ), 'providers register with Data Machine (encrypted fields keep decrypting)' );
$assert( 1 === count( $GLOBALS['ec_test_filters']['datamachine_auth_providers'] ), 'provider registration is idempotent across reads' );

// Stored format: the encrypted-field list is unchanged.
$encrypted = $GLOBALS['ec_test_filters']['datamachine_auth_encrypted_fields'][0];
$stripe_encrypted = $encrypted( array(), 'ec_stripe' );
$assert(
	! array_diff( array( 'secret_key', 'webhook_secret', 'connect_webhook_secret' ), $stripe_encrypted ) && ! in_array( 'publishable_key', $stripe_encrypted, true ),
	'stripe encrypted-at-rest fields are unchanged'
);
$assert( array( 'api_key' ) === $encrypted( array(), 'ec_shippo' ), 'shippo encrypted-at-rest fields are unchanged' );

// 3. Unknown provider: empty and logged.
$assert( '' === ec_commerce_credential( 'paypal', 'secret_key' ), 'unknown provider reads as empty' );
$assert( false !== strpos( $log(), 'unknown commerce credential provider "paypal"' ), 'unknown provider is logged' );

// 4. The filter indirection is gone.
foreach ( array( 'extrachill_stripe_secret_key', 'extrachill_stripe_publishable_key', 'extrachill_stripe_webhook_secret', 'extrachill_stripe_connect_webhook_secret', 'extrachill_stripe_connect_client_id', 'extrachill_shippo_api_key' ) as $hook ) {
	$assert( ! isset( $GLOBALS['ec_test_filters'][ $hook ] ), "no {$hook} read filter is registered" );
}

unlink( $log_file );
