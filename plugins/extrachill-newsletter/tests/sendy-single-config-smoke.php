<?php
/**
 * Smoke coverage for the single Sendy configuration and push error surfacing.
 *
 * - Push to Sendy returns the provider's real error (with a REST status)
 *   instead of a generic success:false.
 * - Sendy credentials live only in Data Machine Business: newsletter settings
 *   report status, refuse credential writes, and delete the legacy copy.
 * - Subscribe/metrics calls no longer pass a config payload.
 *
 * Run with: php tests/sendy-single-config-smoke.php
 */

namespace DataMachineBusiness\Abilities\Sendy {
	class SendyAbilities {
		public static $config = array();

		public static function get_campaign_config(): array {
			return self::$config;
		}
	}
}

namespace {
	use DataMachineBusiness\Abilities\Sendy\SendyAbilities;

	define( 'ABSPATH', __DIR__ );
	define( 'HOUR_IN_SECONDS', 3600 );

	final class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	final class SingleConfigAbility {
		public $calls = array();
		public $result;

		public function __construct( $result ) {
			$this->result = $result;
		}

		public function execute( $input ) {
			$this->calls[] = $input;
			return $this->result;
		}
	}

	$GLOBALS['sc_abilities'] = array();
	$GLOBALS['sc_options']   = array();
	$GLOBALS['sc_meta']      = array();

	function __( $text, $domain = '' ) {
		unset( $domain );
		return $text;
	}

	function add_action( $hook, $callback ) {
		unset( $hook, $callback );
	}

	function wp_register_ability( $name, $definition ) {
		unset( $name, $definition );
	}

	function current_user_can( $capability ) {
		unset( $capability );
		return true;
	}

	function wp_get_ability( $name ) {
		return $GLOBALS['sc_abilities'][ $name ] ?? null;
	}

	function wp_has_ability( $name ) {
		return isset( $GLOBALS['sc_abilities'][ $name ] );
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}

	function sanitize_email( $value ) {
		return trim( (string) $value );
	}

	function esc_url_raw( $value ) {
		return trim( (string) $value );
	}

	function is_email( $value ) {
		return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	function wp_parse_args( $args, $defaults ) {
		return array_merge( $defaults, (array) $args );
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function get_site_option( $name, $default = array() ) {
		return $GLOBALS['sc_options'][ $name ] ?? $default;
	}

	function update_site_option( $name, $value ) {
		$GLOBALS['sc_options'][ $name ] = $value;
		return true;
	}

	function get_transient( $key ) {
		unset( $key );
		return false;
	}

	function set_transient( $key, $value, $ttl ) {
		unset( $key, $value, $ttl );
		return true;
	}

	function get_post( $id ) {
		if ( 69 !== $id ) {
			return null;
		}
		$post            = new stdClass();
		$post->ID        = 69;
		$post->post_type = 'newsletter';
		return $post;
	}

	function get_post_meta( $id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['sc_meta'][ $id ][ $key ] ?? '';
	}

	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['sc_meta'][ $id ][ $key ] = $value;
		return true;
	}

	function prepare_newsletter_email_content( $post ) {
		unset( $post );
		return array(
			'subject'       => 'Subject',
			'html_template' => '<p>Hi</p>',
			'plain_text'    => 'Hi',
		);
	}

	function get_newsletter_integrations() {
		return array(
			'homepage' => array(
				'label'       => 'Homepage',
				'list_id_key' => 'homepage_list_id',
			),
		);
	}

	require_once dirname( __DIR__ ) . '/inc/core/sendy-api.php';
	require_once dirname( __DIR__ ) . '/inc/core/abilities/campaign-management.php';
	require_once dirname( __DIR__ ) . '/inc/core/abilities/campaign.php';
	require_once dirname( __DIR__ ) . '/inc/core/abilities/settings.php';
	require_once dirname( __DIR__ ) . '/inc/core/abilities/stats.php';

	$failures = array();
	$passes   = 0;
	$assert   = static function ( $condition, $message ) use ( &$failures, &$passes ) {
		if ( $condition ) {
			++$passes;
			return;
		}
		$failures[] = $message;
	};

	// ── Push surfaces the provider's real error ──
	$push = new SingleConfigAbility( new WP_Error( 'sendy_config_incomplete', 'The Sendy API configuration requires api_key and sendy_url in the datamachine_sendy_config network option.' ) );
	$GLOBALS['sc_abilities']['datamachine/sendy-push-campaign'] = $push;

	$failed = extrachill_newsletter_ability_push_campaign( array( 'post_id' => 69 ) );
	$assert( is_wp_error( $failed ), 'provider errors are returned as errors, not success:false' );
	$assert( 'sendy_config_incomplete' === $failed->get_error_code(), 'the provider error code is preserved' );
	$assert( false !== strpos( $failed->get_error_message(), 'datamachine_sendy_config' ), 'the real cause reaches the admin alert' );
	$assert( 0 === strpos( $failed->get_error_message(), 'Push to Sendy failed: ' ), 'the message says what failed' );
	$assert( 502 === ( $failed->get_error_data()['status'] ?? null ), 'REST gets a 502, not a 200' );

	$push->result = array( 'success' => false, 'message' => 'Unable to create campaign' );
	$rejected     = extrachill_newsletter_ability_push_campaign( array( 'post_id' => 69 ) );
	$assert( is_wp_error( $rejected ) && 'sendy_push_failed' === $rejected->get_error_code(), 'success:false from the provider becomes an error' );
	$assert( false !== strpos( $rejected->get_error_message(), 'Unable to create campaign' ), 'the provider message is kept' );
	$assert( '' === get_post_meta( 69, '_sendy_campaign_id', true ), 'failed pushes record no campaign' );

	$push->result = array( 'success' => true, 'campaign_id' => '321', 'created' => true );
	$ok           = extrachill_newsletter_ability_push_campaign( array( 'post_id' => 69 ) );
	$assert( ! is_wp_error( $ok ) && true === $ok['success'] && '321' === $ok['campaign_id'], 'successful pushes still succeed' );
	$assert( '321' === get_post_meta( 69, '_sendy_campaign_id', true ), 'new campaign IDs are still recorded' );
	foreach ( $push->calls as $call ) {
		$assert( ! isset( $call['config'] ) && ! isset( $call['api_key'] ), 'push passes no credentials' );
	}

	// ── Sender identity no longer carries credentials ──
	$GLOBALS['sc_options']['extrachill_newsletter_settings'] = array(
		'sendy_api_key' => 'legacy-secret',
		'sendy_url'     => 'https://mail.example.test',
		'from_name'     => 'Extra Chill',
	);
	$identity = get_sendy_config();
	$assert( ! isset( $identity['api_key'] ) && ! isset( $identity['sendy_url'] ), 'get_sendy_config returns sender identity only' );
	$assert( 'Extra Chill' === $identity['from_name'] && '1' === $identity['brand_id'], 'identity keeps stored values and defaults' );
	$assert( ! function_exists( 'extrachill_newsletter_sendy_dmb_config' ), 'the duplicate config builder is gone' );

	// ── Legacy duplicate is deleted, never copied ──
	SendyAbilities::$config = array( 'db' => array( 'host' => 'localhost' ) );
	extrachill_newsletter_remove_legacy_sendy_credentials();
	$assert( isset( $GLOBALS['sc_options']['extrachill_newsletter_settings']['sendy_api_key'] ), 'legacy copy is kept while DMB is unconfigured' );
	$assert( ! isset( SendyAbilities::$config['api_key'] ), 'the newsletter never writes DMB credentials' );

	SendyAbilities::$config = array( 'api_key' => 'dmb-secret', 'sendy_url' => 'https://dmb.example.test' );
	extrachill_newsletter_remove_legacy_sendy_credentials();
	$assert( ! isset( $GLOBALS['sc_options']['extrachill_newsletter_settings']['sendy_api_key'] ) && ! isset( $GLOBALS['sc_options']['extrachill_newsletter_settings']['sendy_url'] ), 'legacy copy is deleted once DMB is configured' );
	$assert( 'dmb-secret' === SendyAbilities::$config['api_key'], 'DMB credentials are untouched' );
	$assert( 'Extra Chill' === $GLOBALS['sc_options']['extrachill_newsletter_settings']['from_name'], 'cleanup keeps other settings' );

	// ── Settings: status only, no credential fields ──
	$settings = extrachill_newsletter_ability_get_settings( array() );
	$assert( ! isset( $settings['settings']['sendy_api_key'] ) && ! isset( $settings['settings']['sendy_url'] ), 'settings carry no credential fields' );
	$assert( array( 'configured' => true, 'sendy_url' => 'https://dmb.example.test' ) === $settings['sendy'], 'settings report DMB connection status' );
	$assert( false === strpos( serialize( $settings ), 'dmb-secret' ), 'the key appears nowhere in settings output' );

	$refused = extrachill_newsletter_ability_update_settings( array( 'sendy_api_key' => 'x', 'from_name' => 'EC' ) );
	$assert( is_wp_error( $refused ) && 'sendy_connection_not_owned' === $refused->get_error_code(), 'credential writes are refused and point at DMB' );
	$assert( 'dmb-secret' === SendyAbilities::$config['api_key'], 'a refused write changes nothing in DMB' );

	extrachill_newsletter_ability_update_settings( array( 'from_name' => 'EC' ) );
	$assert( 'EC' === get_site_option( 'extrachill_newsletter_settings' )['from_name'], 'sender identity still saves' );
	$assert( ! isset( get_site_option( 'extrachill_newsletter_settings' )['sendy_api_key'] ), 'no key is ever stored in newsletter settings' );

	SendyAbilities::$config = array();
	$warned                 = extrachill_newsletter_ability_get_settings( array() );
	$assert( false === $warned['sendy']['configured'], 'missing connection is reported' );
	$assert( (bool) array_filter( $warned['warnings'], static fn( $w ) => false !== strpos( $w, 'datamachine_sendy_config' ) ), 'the warning names the DMB option' );

	// ── Metrics no longer pass a config payload ──
	$metrics = new SingleConfigAbility( array( 'total_active' => 1, 'lists' => array() ) );
	$GLOBALS['sc_abilities']['datamachine/sendy-metrics'] = $metrics;
	extrachill_newsletter_ability_subscriber_stats( array( 'force_refresh' => true ) );
	$assert( ! isset( $metrics->calls[0]['config'] ), 'subscriber stats rely on the DMB config' );

	$subscribe_src = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/subscribe.php' );
	$analytics_src = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/analytics.php' );
	$assert( false === strpos( $subscribe_src, "'config'" ) && false === strpos( $analytics_src, "'config'" ), 'subscribe and analytics pass no config payload' );

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	echo "Sendy single-config smoke checks passed ({$passes} assertions).\n";
}
