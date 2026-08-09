<?php
/**
 * Smoke coverage for Newsletter's public Sendy provider boundaries.
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

final class NewsletterSyncAbility {
	public $calls = array();
	private $results;

	public function __construct( $results = array() ) {
		$this->results = $results;
	}

	public function execute( $input ) {
		$this->calls[] = $input;
		return array_shift( $this->results );
	}
}

$GLOBALS['newsletter_sendy_abilities'] = array();
$GLOBALS['newsletter_registered_abilities'] = array();
$GLOBALS['newsletter_sync_settings'] = array( 'main_list_id' => 'list-123' );

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

function get_newsletter_integrations() {
	return array(
		'main' => array( 'list_id_key' => 'main_list_id' ),
	);
}

function get_site_option( $name, $default = array() ) {
	unset( $name, $default );
	return $GLOBALS['newsletter_sync_settings'];
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

require_once dirname( __DIR__ ) . '/inc/core/sendy-api.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/campaign-management.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/campaign.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/sync.php';

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

$subscribe = new NewsletterSyncAbility();
$GLOBALS['newsletter_sendy_abilities']['extrachill/subscribe'] = $subscribe;

$dry_run = extrachill_newsletter_ability_sync_subscribers(
	array(
		'context' => 'main',
		'emails'  => array( 'dry-run@example.com' ),
		'dry_run' => true,
	)
);
$assert( ! is_wp_error( $dry_run ) && true === $dry_run['dry_run'], 'subscriber dry run succeeds without the DMB provider' );
$assert( 1 === $dry_run['total'] && 0 === $dry_run['synced'], 'subscriber dry run preserves counts without subscribing' );
$assert( array() === $subscribe->calls, 'subscriber dry run does not execute the subscribe ability' );

$unavailable = extrachill_newsletter_ability_sync_subscribers(
	array(
		'context' => 'main',
		'emails'  => array( 'unavailable@example.com' ),
	)
);
$assert( is_wp_error( $unavailable ), 'non-dry-run provider absence returns an error' );
$assert( 'sendy_sync_provider_unavailable' === $unavailable->get_error_code(), 'subscriber provider absence uses a stable error code' );
$assert( array() === $subscribe->calls, 'subscriber provider absence fails before processing addresses' );

$GLOBALS['newsletter_sendy_abilities']['datamachine/sendy-subscribe'] = new NewsletterSyncAbility();
$subscribe = new NewsletterSyncAbility(
	array(
		array( 'success' => true, 'status' => 'subscribed' ),
		array( 'success' => false, 'status' => 'already_subscribed' ),
		array( 'success' => false, 'status' => 'failed', 'message' => 'Email is suppressed.' ),
		new WP_Error( 'sendy_timeout', 'The Sendy provider timed out.' ),
	)
);
$GLOBALS['newsletter_sendy_abilities']['extrachill/subscribe'] = $subscribe;

$executed = extrachill_newsletter_ability_sync_subscribers(
	array(
		'context' => 'main',
		'emails'  => array(
			'new@example.com',
			'existing@example.com',
			'suppressed@example.com',
			'timeout@example.com',
		),
	)
);
$assert( ! is_wp_error( $executed ), 'non-dry-run executes through the public subscribe policy' );
$assert( 1 === $executed['synced'], 'successful subscriber provider result is counted as synced' );
$assert( 1 === $executed['already_subscribed'], 'existing subscriber is counted without failing' );
$assert( 2 === $executed['failed'] && 0 === $executed['skipped'], 'subscriber provider failures are counted without a private status preflight' );
$assert( 4 === $executed['total'] && 2 === count( $executed['errors'] ), 'subscriber execution preserves totals and bounded errors' );
$assert(
	array(
		'email'   => 'new@example.com',
		'list_id' => 'list-123',
		'context' => 'main',
	) === $subscribe->calls[0],
	'non-dry-run passes policy input through the Newsletter subscribe ability'
);
$assert( ! function_exists( 'extrachill_newsletter_dmb_sendy_client' ), 'removed DMB client helper is not reintroduced' );
$assert( ! function_exists( 'extrachill_newsletter_check_subscriber_status' ), 'unsupported subscriber-status helper is removed' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Sendy consumer smoke checks passed ({$passes} assertions).\n";
