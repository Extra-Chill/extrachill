<?php
/**
 * Current-user preferences and deletion smoke test with mocked WordPress HTTP.
 * Run: php tests/member-subscriptions-smoke.php
 * Stubs the DMB provider boundary; no checkout or network access is required.
 *
 * @package ExtraChillNewsletter\Tests
 */

namespace DataMachineBusiness\Abilities\Sendy {
	class SendyAbilities {
		public static function get_campaign_config(): array {
			return array( 'api_key' => 'secret', 'sendy_url' => 'https://sendy.invalid' );
		}
	}
}

namespace DataMachineBusiness\Sendy {
	/** Test double for the only client method consumed by member preferences. */
	final class SendyClient {
		private array $config;

		public function __construct( array $config ) {
			$this->config = $config;
		}

		public function subscriber_status( string $list_id, string $email ) {
			$response = \wp_remote_post(
				$this->config['sendy_url'] . '/api/subscribers/subscription-status.php',
				array( 'body' => array( 'api_key' => $this->config['api_key'], 'list_id' => $list_id, 'email' => $email ) )
			);
			return \is_wp_error( $response ) ? $response : \wp_remote_retrieve_body( $response );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Error {
		public function __construct( ...$args ) {}
	}
	function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function __( $text, $domain = '' ) { return $text; }
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ] = $callback; }
	function wp_register_ability( $key, $definition ) { $GLOBALS['abilities'][ $key ] = $definition; }
	function is_user_logged_in() { return $GLOBALS['logged_in']; }
	function wp_get_current_user() { return (object) array( 'user_email' => 'member@extrachill.com' ); }
	function get_site_option( $key, $default = array() ) { return $GLOBALS['settings']; }
	function get_newsletter_integrations() {
		return array(
			'homepage'     => array( 'label' => 'Sunday newsletter', 'list_id_key' => 'homepage_list_id' ),
			'navigation'   => array( 'label' => 'Navigation', 'list_id_key' => 'navigation_list_id' ),
			'contact'      => array( 'label' => 'Contact newsletter', 'list_id_key' => 'contact_list_id' ),
			'registration' => array( 'label' => 'Registration', 'list_id_key' => 'registration_list_id' ),
			'admin'        => array( 'label' => 'Internal', 'list_id_key' => 'admin_list_id' ),
		);
	}
	function wp_remote_post( $url, $args ) {
		$GLOBALS['requests'][] = array( $url, $args['body'] );
		if ( $GLOBALS['throw'] ) { throw new \RuntimeException( 'provider crashed' ); }
		if ( $GLOBALS['fail'] ) { return new WP_Error( 'http_failed' ); }
		$body = str_contains( $url, 'subscription-status' ) ? $GLOBALS['status'] : '1';
		return array( 'body' => $body, 'response' => array( 'code' => $GLOBALS['http_code'] ) );
	}
	function wp_remote_retrieve_body( $response ) { return $response['body']; }
	function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
	function wp_has_ability( $key ) { return 'datamachine/sendy-subscribe' === $key; }
	function wp_get_ability( $key ) {
		return new class() {
			public function execute( $input ) {
				$config = extrachill_newsletter_subscription_config();
				$response = wp_remote_post(
					$config['sendy_url'] . '/subscribe',
					array( 'body' => array( 'api_key' => $config['api_key'], 'list' => $input['list_id'], 'email' => $input['email'] ) )
				);
				return is_wp_error( $response ) ? $response : array( 'success' => true, 'status' => 'subscribed' );
			}
		};
	}
	function check( $condition, $message ) {
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}

	require dirname( __DIR__ ) . '/inc/core/sendy-api.php';
	require dirname( __DIR__ ) . '/inc/core/member-subscriptions.php';
	require dirname( __DIR__ ) . '/inc/core/abilities/subscribe.php';
	require dirname( __DIR__ ) . '/inc/core/abilities/member-subscriptions.php';

	$GLOBALS['settings'] = array(
		'homepage_list_id' => 'public', 'navigation_list_id' => 'public',
		'contact_list_id' => 'second-public', 'registration_list_id' => 'registration', 'admin_list_id' => 'internal',
	);
	$GLOBALS['logged_in'] = true;
	$GLOBALS['fail'] = false;
	$GLOBALS['throw'] = false;
	$GLOBALS['http_code'] = 200;
	$GLOBALS['status'] = 'Subscribed';
	$GLOBALS['requests'] = array();

	call_user_func( $GLOBALS['hooks']['extrachill_user_self_deleted'], 19, 'deleted@extrachill.com' );
	check( 4 === count( $GLOBALS['requests'] ), 'Deletion must unsubscribe every unique list, including private lists.' );
	foreach ( $GLOBALS['requests'] as [ $url, $body ] ) {
		check( 'https://sendy.invalid/unsubscribe' === $url && 'deleted@extrachill.com' === $body['email'] && 'secret' === $body['api_key'], 'Deletion HTTP request mismatch.' );
	}
	foreach ( array( 'fail', 'throw' ) as $mode ) {
		$GLOBALS['requests'] = array();
		$GLOBALS[ $mode ] = true;
		call_user_func( $GLOBALS['hooks']['extrachill_user_self_deleted'], 19, 'deleted@extrachill.com' );
		check( 4 === count( $GLOBALS['requests'] ), 'Failure must not throw or skip subsequent lists.' );
		$GLOBALS[ $mode ] = false;
	}

	extrachill_newsletter_register_member_abilities();
	foreach ( $GLOBALS['abilities'] as $definition ) {
		check( true === $definition['meta']['show_in_rest'], 'Must be REST-visible.' );
		check( false === $definition['input_schema']['additionalProperties'], 'Must reject identity and unknown fields.' );
	}
	$get = $GLOBALS['abilities']['extrachill/newsletter-get-my-subscriptions'];
	$set = $GLOBALS['abilities']['extrachill/newsletter-set-my-subscription'];
	check( array( 'list', 'subscribed' ) === $set['input_schema']['required'], 'Set requires both fields.' );
	check( 'boolean' === $set['input_schema']['properties']['subscribed']['type'], 'Flag must be boolean.' );
	$GLOBALS['logged_in'] = false;
	check( false === call_user_func( $get['permission_callback'] ), 'Anonymous ability permission must fail.' );
	check( is_wp_error( extrachill_newsletter_get_my_subscriptions() ), 'Direct anonymous reads must fail.' );
	check( is_wp_error( extrachill_newsletter_set_my_subscription( array( 'list' => 'newsletter', 'subscribed' => true ) ) ), 'Anonymous writes must fail.' );
	$GLOBALS['logged_in'] = true;
	$GLOBALS['requests'] = array();
	foreach ( array(
		array( 'email' => 'victim@extrachill.com' ), array( 'user_id' => 20 ),
	) as $input ) {
		check( is_wp_error( extrachill_newsletter_get_my_subscriptions( $input ) ), 'Get must reject identity input.' );
		check( is_wp_error( extrachill_newsletter_set_my_subscription( $input + array( 'list' => 'newsletter', 'subscribed' => true ) ) ), 'Set must reject identity input.' );
	}
	foreach ( array( array(), array( 'list' => 'newsletter' ), array( 'list' => 'newsletter', 'subscribed' => 'false' ), array( 'list' => 'newsletter', 'subscribed' => 1 ), array( 'list' => 'homepage', 'subscribed' => true ), array( 'list' => 'registration', 'subscribed' => false ), array( 'list' => 'public', 'subscribed' => false ) ) as $input ) {
		check( is_wp_error( extrachill_newsletter_set_my_subscription( $input ) ), 'Malformed input or per-form list keys must fail.' );
	}
	check( array() === $GLOBALS['requests'], 'Rejected input must not make HTTP calls.' );
	$result = extrachill_newsletter_get_my_subscriptions();
	check( 1 === count( $result['lists'] ) && 'newsletter' === $result['lists'][0]['key'] && true === $result['lists'][0]['subscribed'], 'Members see one newsletter preference.' );
	check( ! isset( $result['lists'][0]['list_id'] ), 'Do not expose provider IDs.' );
	$GLOBALS['requests'] = array();
	$GLOBALS['status']   = 'Unsubscribed';
	check( false === extrachill_newsletter_get_my_subscriptions()['lists'][0]['subscribed'], 'Unsubscribed on every list maps to false.' );
	check( 4 === count( $GLOBALS['requests'] ), 'Status checks every unique network list.' );
	$GLOBALS['status'] = 'Email does not exist in list';
	check( false === extrachill_newsletter_get_my_subscriptions()['lists'][0]['subscribed'], 'Absent subscriber maps to false.' );
	$GLOBALS['status'] = 'Invalid API key';
	check( is_wp_error( extrachill_newsletter_get_my_subscriptions() ), 'Provider errors must not appear as unsubscribed.' );

	$GLOBALS['requests'] = array();
	$result              = extrachill_newsletter_set_my_subscription( array( 'list' => 'newsletter', 'subscribed' => true ) );
	check( true === $result['subscribed'], 'Set on confirms the requested state.' );
	check( 1 === count( $GLOBALS['requests'] ) && 'registration' === $GLOBALS['requests'][0][1]['list'], 'Turning on subscribes to the member registration list.' );
	$GLOBALS['requests'] = array();
	$result              = extrachill_newsletter_set_my_subscription( array( 'list' => 'newsletter', 'subscribed' => false ) );
	check( false === $result['subscribed'], 'Set off confirms the requested state.' );
	check( 4 === count( $GLOBALS['requests'] ), 'Turning off unsubscribes from every unique network list.' );
	foreach ( $GLOBALS['requests'] as [ $url, $body ] ) {
		check( 'https://sendy.invalid/unsubscribe' === $url && 'member@extrachill.com' === $body['email'], 'Off uses the unsubscribe endpoint with the account email.' );
	}
	$GLOBALS['http_code'] = 500;
	check( is_wp_error( extrachill_newsletter_set_my_subscription( array( 'list' => 'newsletter', 'subscribed' => false ) ) ), 'HTTP failure must surface.' );
	echo "Member subscription smoke checks passed.\n";
}
