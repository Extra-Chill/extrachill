<?php
/** Focused contract test for the Gardner provider network boundary. */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	private string $code;

	public function __construct( string $code ) {
		$this->code = $code;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

$GLOBALS['ec_test_actions_removed'] = array();
$GLOBALS['ec_test_filters']         = array();
$GLOBALS['ec_test_options']         = array();

function remove_action( string $hook, string $callback ): void {
	$GLOBALS['ec_test_actions_removed'][] = $hook . ':' . $callback;
}

function add_filter( string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['ec_test_filters'][ $hook ] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function __return_true(): bool {
	return true;
}

function wp_parse_url( string $url, int $component = -1 ): array|string|int|null|false {
	return parse_url( $url, $component );
}

function home_url(): string {
	return 'https://fixture.example.test/';
}

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['ec_test_options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value ): bool {
	$GLOBALS['ec_test_options'][ $name ] = $value;
	return true;
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

function provider_contract_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function provider_contract_blocked_count(): int {
	$ledger = get_option( 'ec_studio_operator_provider_ledger', array() );
	return count( array_filter( $ledger, static fn( array $entry ): bool => 'blocked-unexpected' === $entry['provider_call'] ) );
}

require __DIR__ . '/chris-gardner-social-operator-provider-stub.php';

$expected_update_removals = array(
	'admin_init:_maybe_update_core',
	'admin_init:_maybe_update_plugins',
	'admin_init:_maybe_update_themes',
	'wp_version_check:wp_version_check',
	'wp_update_plugins:wp_update_plugins',
	'wp_update_themes:wp_update_themes',
	'init:wp_schedule_update_checks',
);
provider_contract_assert( array() === array_diff( $expected_update_removals, $GLOBALS['ec_test_actions_removed'] ), 'all exact WordPress update callbacks are disabled' );

$ping_filter = $GLOBALS['ec_test_filters']['wp_should_disable_pings_for_environment']['callback'] ?? null;
provider_contract_assert( is_callable( $ping_filter ) && true === $ping_filter(), 'WordPress ping traffic is disabled through the Core environment filter' );
provider_contract_assert( 0 === provider_contract_blocked_count(), 'suppressed WordPress update and ping traffic never enters the provider ledger' );

$http_filter = $GLOBALS['ec_test_filters']['pre_http_request']['callback'] ?? null;
provider_contract_assert( is_callable( $http_filter ), 'provider HTTP boundary is registered' );

$unknown = $http_filter( false, array( 'method' => 'GET' ), 'https://unknown.example.test/write' );
provider_contract_assert( $unknown instanceof WP_Error && 'ec_studio_operator_unexpected_network' === $unknown->get_error_code(), 'unknown external hosts fail closed' );

$unexpected_path = $http_filter( false, array( 'method' => 'POST' ), 'https://graph.facebook.com/v20.0/fixture/not-supported' );
provider_contract_assert( $unexpected_path instanceof WP_Error, 'unexpected provider paths fail closed' );

$unexpected_method = $http_filter( false, array( 'method' => 'GET' ), 'https://bsky.social/xrpc/com.atproto.repo.createRecord' );
provider_contract_assert( $unexpected_method instanceof WP_Error, 'unexpected provider methods fail closed' );
provider_contract_assert( 3 === provider_contract_blocked_count(), 'each unknown provider request increments blocked-unexpected' );

$instagram = $http_filter( false, array( 'method' => 'POST', 'body' => array( 'caption' => 'fixture' ) ), 'https://graph.facebook.com/v20.0/fixture/media' );
$bluesky  = $http_filter( false, array( 'method' => 'POST', 'body' => '{}' ), 'https://bsky.social/xrpc/com.atproto.repo.createRecord' );
provider_contract_assert( is_array( $instagram ) && is_array( $bluesky ), 'known provider writes are satisfied by fixtures' );
provider_contract_assert( 3 === provider_contract_blocked_count(), 'known provider fixtures do not hide prior blocking findings' );

$internal = $http_filter( 'internal-request', array( 'method' => 'POST' ), 'https://fixture.example.test/wp-cron.php' );
provider_contract_assert( 'internal-request' === $internal, 'same-site WordPress requests remain internal' );

fwrite( STDOUT, "Gardner provider boundary assertions passed.\n" );
