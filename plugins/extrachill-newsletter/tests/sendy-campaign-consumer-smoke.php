<?php
/**
 * Smoke coverage for Newsletter's credential-free Sendy campaign boundary.
 *
 * Run with: php tests/sendy-campaign-consumer-smoke.php
 */

define( 'ABSPATH', __DIR__ );

final class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

final class NewsletterSendyAbility {
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

$GLOBALS['newsletter_sendy_abilities'] = array();
$GLOBALS['newsletter_registered_abilities'] = array();

function __( $text, $domain = '' ) {
	unset( $domain );
	return $text;
}

function add_action( $hook, $callback ) {
	unset( $hook, $callback );
}

function wp_register_ability( $name, $definition ) {
	$GLOBALS['newsletter_registered_abilities'][ $name ] = $definition;
}

function current_user_can( $capability ) {
	unset( $capability );
	return true;
}

function wp_get_ability( $name ) {
	return isset( $GLOBALS['newsletter_sendy_abilities'][ $name ] )
		? $GLOBALS['newsletter_sendy_abilities'][ $name ]
		: null;
}

function wp_has_ability( $name ) {
	return isset( $GLOBALS['newsletter_sendy_abilities'][ $name ] );
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

require_once dirname( __DIR__ ) . '/inc/core/sendy-api.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/campaign-management.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/campaign.php';

$failures = array();
$passes   = 0;
$assert   = static function ( $condition, $message ) use ( &$failures, &$passes ) {
	if ( $condition ) {
		++$passes;
		return;
	}
	$failures[] = $message;
};

extrachill_newsletter_register_campaign_management_abilities();
$assert( isset( $GLOBALS['newsletter_registered_abilities']['extrachill/list-campaigns'] ), 'campaign list ability remains registered' );
$assert( isset( $GLOBALS['newsletter_registered_abilities']['extrachill/get-campaign'] ), 'campaign get ability remains registered' );
$assert( isset( $GLOBALS['newsletter_registered_abilities']['extrachill/delete-campaign'] ), 'campaign delete ability remains registered' );
$assert( ! isset( $GLOBALS['newsletter_registered_abilities']['extrachill/subscriber-status'] ), 'unsupported direct subscriber-status boundary is removed' );

$absent = extrachill_newsletter_ability_list_campaigns( array() );
$assert( is_wp_error( $absent ), 'provider absence returns an error' );
$assert( 'sendy_campaign_provider_unavailable' === $absent->get_error_code(), 'provider absence uses the stable campaign error code' );
$assert( false === strpos( $absent->get_error_message(), 'api-key-secret' ), 'provider absence does not leak credentials' );
$absent_push = extrachill_newsletter_sendy_push_campaign( array() );
$assert( is_wp_error( $absent_push ) && 'sendy_campaign_provider_unavailable' === $absent_push->get_error_code(), 'campaign push uses the same provider absence contract' );

$list_result = array( 'total' => 1, 'per_page' => 10, 'offset' => 2, 'campaigns' => array( array( 'id' => 31 ) ) );
$list = new NewsletterSendyAbility( $list_result );
$GLOBALS['newsletter_sendy_abilities']['datamachine/sendy-list-campaigns'] = $list;
$actual_list = extrachill_newsletter_ability_list_campaigns(
	array(
		'per_page' => 10,
		'offset'   => 2,
		'status'   => 'draft',
	)
);
$assert( $list_result === $actual_list, 'provider list result is preserved' );
$assert( array( 'per_page' => 10, 'offset' => 2, 'status' => 'draft' ) === $list->calls[0], 'list sends only the public ability input' );

$get_result = array( 'id' => 31, 'title' => 'Campaign' );
$get = new NewsletterSendyAbility( $get_result );
$GLOBALS['newsletter_sendy_abilities']['datamachine/sendy-get-campaign'] = $get;
$assert( $get_result === extrachill_newsletter_ability_get_campaign( array( 'campaign_id' => 31 ) ), 'provider get result is preserved' );
$assert( array( 'campaign_id' => 31 ) === $get->calls[0], 'get sends only the campaign identifier' );

$delete_result = array( 'success' => true, 'message' => 'Campaign deleted.' );
$delete = new NewsletterSendyAbility( $delete_result );
$GLOBALS['newsletter_sendy_abilities']['datamachine/sendy-delete-campaign'] = $delete;
$assert( $delete_result === extrachill_newsletter_ability_delete_campaign( array( 'campaign_id' => 31 ) ), 'provider delete result is preserved' );
$assert( array( 'campaign_id' => 31 ) === $delete->calls[0], 'delete sends only the campaign identifier' );

$provider_error = new WP_Error( 'sendy_timeout', 'The Sendy provider timed out.' );
$GLOBALS['newsletter_sendy_abilities']['datamachine/sendy-list-campaigns'] = new NewsletterSendyAbility( $provider_error );
$actual_error = extrachill_newsletter_ability_list_campaigns( array() );
$assert( $provider_error === $actual_error, 'bounded provider errors remain useful and unchanged' );
$assert( false === strpos( $actual_error->get_error_message(), 'api-key-secret' ), 'provider errors do not leak credentials' );

$push_result = array( 'success' => true, 'campaign_id' => '31', 'created' => true, 'message' => 'Created.' );
$push = new NewsletterSendyAbility( $push_result );
$GLOBALS['newsletter_sendy_abilities']['datamachine/sendy-push-campaign'] = $push;
$campaign = array(
	'from_name'  => 'Extra Chill',
	'from_email' => 'newsletter@example.com',
	'reply_to'   => 'reply@example.com',
	'subject'    => 'Campaign',
	'html_text'  => '<p>Campaign</p>',
	'plain_text' => 'Campaign',
	'brand_id'   => '1',
);
$assert( $push_result === extrachill_newsletter_sendy_push_campaign( $campaign ), 'provider push result is preserved' );
$assert( $campaign === $push->calls[0] && ! isset( $push->calls[0]['config'] ), 'push never crosses credentials into the provider ability' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Sendy campaign consumer smoke checks passed ({$passes} assertions).\n";
