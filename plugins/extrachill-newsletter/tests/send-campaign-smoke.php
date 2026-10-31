<?php
/**
 * Smoke coverage for extrachill/send-campaign policy.
 *
 * Covers the dry-run default, list resolution against Sendy's encrypted IDs,
 * the double-send guard, schedule defaults, and the request handed to the
 * datamachine/sendy-send-campaign transport.
 *
 * Run with: php tests/send-campaign-smoke.php
 */

define( 'ABSPATH', __DIR__ );

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

final class WP_Post {
	public $ID;
	public $post_type    = 'newsletter';
	public $post_status  = 'publish';
	public $post_title   = 'Under the Hood';
	public $post_content = '<p>Hello scene</p>';

	public function __construct( $id ) {
		$this->ID = $id;
	}
}

final class SendAbility {
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

$GLOBALS['send_abilities']  = array();
$GLOBALS['send_registered'] = array();
$GLOBALS['send_posts']      = array( 69 => new WP_Post( 69 ) );
$GLOBALS['send_meta']       = array();
$GLOBALS['send_stats']      = array(
	'lists'  => array(
		array( 'id' => 'MainHash', 'name' => 'Main', 'active' => 3000 ),
		array( 'id' => 'BoogieA', 'name' => 'Boogieman A', 'active' => 336 ),
		array( 'id' => 'BoogieB', 'name' => 'Boogieman B', 'active' => 312 ),
	),
	'source' => 'api',
);
$GLOBALS['send_stats_calls'] = array();

function __( $text, $domain = '' ) {
	unset( $domain );
	return $text;
}

function add_action( $hook, $callback ) {
	unset( $hook, $callback );
}

function wp_register_ability( $name, $definition ) {
	$GLOBALS['send_registered'][ $name ] = $definition;
}

function current_user_can( $capability ) {
	unset( $capability );
	return true;
}

function wp_get_ability( $name ) {
	return $GLOBALS['send_abilities'][ $name ] ?? null;
}

function wp_has_ability( $name ) {
	return isset( $GLOBALS['send_abilities'][ $name ] );
}

function absint( $value ) {
	return abs( (int) $value );
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function get_post( $id ) {
	return $GLOBALS['send_posts'][ $id ] ?? null;
}

function get_the_title( $post ) {
	return $post->post_title;
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( $text ) );
}

function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $item ) use ( $field ) {
			return $item[ $field ];
		},
		$list
	);
}

function get_post_meta( $id, $key, $single = false ) {
	unset( $single );
	return $GLOBALS['send_meta'][ $id ][ $key ] ?? '';
}

function update_post_meta( $id, $key, $value ) {
	$GLOBALS['send_meta'][ $id ][ $key ] = $value;
}

function get_sendy_config() {
	return array(
		'api_key'    => 'api-key-secret',
		'sendy_url'  => 'https://sendy.example.test',
		'from_name'  => 'Extra Chill',
		'from_email' => 'newsletter@extrachill.com',
		'reply_to'   => 'chubes@extrachill.com',
		'brand_id'   => '1',
	);
}

function prepare_newsletter_email_content( $post ) {
	return array(
		'subject'       => $post->post_title,
		'html_template' => '<html>' . $post->post_content . '</html>',
		'plain_text'    => wp_strip_all_tags( $post->post_content ),
	);
}

function extrachill_newsletter_ability_subscriber_stats( $input = array() ) {
	$GLOBALS['send_stats_calls'][] = $input;
	return $GLOBALS['send_stats'];
}

function extrachill_newsletter_get_sendy_ability( $ability ) {
	return wp_has_ability( $ability ) ? wp_get_ability( $ability ) : null;
}

require_once dirname( __DIR__ ) . '/inc/core/abilities/campaign-management.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/send-campaign.php';

$failures = array();
$passes   = 0;
$assert   = static function ( $condition, $message ) use ( &$failures, &$passes ) {
	if ( $condition ) {
		++$passes;
		return;
	}
	$failures[] = $message;
};

extrachill_newsletter_register_send_campaign_ability();
$definition = $GLOBALS['send_registered']['extrachill/send-campaign'] ?? null;
$assert( null !== $definition, 'send ability is registered' );
$assert( true === $definition['meta']['annotations']['destructive'], 'send ability is destructive' );
$assert( false === $definition['meta']['show_in_rest'], 'send ability is hidden from REST' );
$assert( true === $definition['input_schema']['properties']['dry_run']['default'], 'dry_run defaults to true in the schema' );

$transport = new SendAbility(
	array(
		'success'     => true,
		'campaign_id' => null,
		'message'     => 'Campaign created and now sending',
		'scheduled'   => false,
	)
);
$GLOBALS['send_abilities']['datamachine/sendy-send-campaign'] = $transport;

// ── Validation ──
$missing = extrachill_newsletter_ability_send_campaign( array( 'list_ids' => array( 'MainHash' ) ) );
$assert( is_wp_error( $missing ) && 'missing_post_id' === $missing->get_error_code(), 'post_id is required' );

$GLOBALS['send_posts'][70]            = new WP_Post( 70 );
$GLOBALS['send_posts'][70]->post_type = 'post';
$wrong_type                           = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 70, 'all_lists' => true ) );
$assert( is_wp_error( $wrong_type ) && 'invalid_post_type' === $wrong_type->get_error_code(), 'non-newsletter posts are rejected' );

$GLOBALS['send_posts'][71]               = new WP_Post( 71 );
$GLOBALS['send_posts'][71]->post_content = '  ';
$empty                                   = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 71, 'all_lists' => true ) );
$assert( is_wp_error( $empty ) && 'empty_newsletter' === $empty->get_error_code(), 'empty newsletters are rejected' );

$GLOBALS['send_posts'][72]              = new WP_Post( 72 );
$GLOBALS['send_posts'][72]->post_status = 'trash';
$trashed                                = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 72, 'all_lists' => true ) );
$assert( is_wp_error( $trashed ) && 'invalid_post_status' === $trashed->get_error_code(), 'trashed newsletters are rejected' );

$neither = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69 ) );
$assert( is_wp_error( $neither ) && 'invalid_targets' === $neither->get_error_code(), 'a target is required' );

$both = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'all_lists' => true, 'list_ids' => array( 'MainHash' ) ) );
$assert( is_wp_error( $both ) && 'invalid_targets' === $both->get_error_code(), 'list_ids and all_lists are exclusive' );

$unknown = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'list_ids' => array( 'Nope' ) ) );
$assert( is_wp_error( $unknown ) && 'unknown_list_ids' === $unknown->get_error_code(), 'unknown list IDs are rejected' );

$tz_only = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'all_lists' => true, 'schedule_timezone' => 'UTC' ) );
$assert( is_wp_error( $tz_only ), 'timezone without a date is rejected' );

$assert( array() === $transport->calls, 'validation failures never reach the transport' );

// ── Dry run is the default ──
$preview = extrachill_newsletter_ability_send_campaign(
	array(
		'post_id'          => 69,
		'all_lists'        => true,
		'exclude_list_ids' => array( 'BoogieA', 'BoogieB' ),
	)
);
$assert( ! is_wp_error( $preview ) && true === $preview['dry_run'] && false === $preview['sent'], 'omitting dry_run previews' );
$assert( array( 'MainHash' ) === wp_list_pluck( $preview['lists'], 'id' ), 'all_lists minus exclusions resolves correctly' );
$assert( 3000 === $preview['total_active'], 'audience size sums targeted lists' );
$assert( 2 === count( $preview['excluded'] ) && 'Boogieman A' === $preview['excluded'][0]['name'], 'excluded lists are named in the preview' );
$assert( 'Under the Hood' === $preview['subject'] && 'newsletter@extrachill.com' === $preview['from_email'], 'preview shows subject and sender' );
$assert( 'api' === $GLOBALS['send_stats_calls'][0]['source'], 'lists are resolved from the API path (encrypted IDs)' );
$assert( array() === $transport->calls, 'dry run never reaches the transport' );
$assert( array() === ( $GLOBALS['send_meta'][69] ?? array() ), 'dry run writes no meta' );

$all_excluded = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'list_ids' => array( 'BoogieA' ), 'exclude_list_ids' => array( 'BoogieA' ) ) );
$assert( is_wp_error( $all_excluded ) && 'no_target_lists' === $all_excluded->get_error_code(), 'excluding every target is rejected' );

// ── Integer DB keys are never sendable ──
$saved_stats              = $GLOBALS['send_stats'];
$GLOBALS['send_stats']    = array( 'lists' => array( array( 'id' => 4, 'name' => 'Main', 'active' => 10 ) ) );
$db_only                  = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'all_lists' => true ) );
$GLOBALS['send_stats']    = $saved_stats;
$assert( is_wp_error( $db_only ) && 'no_sendy_lists' === $db_only->get_error_code(), 'integer DB list keys are not used as send targets' );

// ── Real send ──
$sent = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'list_ids' => array( 'MainHash' ), 'dry_run' => false ) );
$call = $transport->calls[0] ?? array();
$assert( ! is_wp_error( $sent ) && true === $sent['sent'] && false === $sent['scheduled'], 'dry_run false sends' );
$assert( array( 'MainHash' ) === $call['list_ids'], 'resolved list IDs are passed to the transport' );
$assert( '<html><p>Hello scene</p></html>' === $call['html_text'] && 'Hello scene' === $call['plain_text'], 'email content comes from the post' );
$assert( 'chubes@extrachill.com' === $call['reply_to'] && '1' === $call['brand_id'], 'sender identity comes from settings' );
$assert( ! isset( $call['config'] ) && ! isset( $call['api_key'] ), 'no credentials are passed to the transport' );
$assert( ! isset( $call['schedule_date_time'] ), 'send-now passes no schedule' );
$assert( '' !== get_post_meta( 69, '_sendy_sent_at', true ), 'send records _sendy_sent_at' );
$assert( array( 'MainHash' ) === get_post_meta( 69, '_sendy_sent_list_ids', true ), 'send records the list IDs' );

// ── Double-send guard ──
$again = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'list_ids' => array( 'MainHash' ), 'dry_run' => false ) );
$assert( is_wp_error( $again ) && 'newsletter_already_sent' === $again->get_error_code(), 'a second send is refused' );
$assert( 1 === count( $transport->calls ), 'a refused send never reaches the transport' );

$again_preview = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'all_lists' => true ) );
$assert( ! is_wp_error( $again_preview ) && null !== $again_preview['previous'], 'dry run reports the previous send' );

$forced = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 69, 'list_ids' => array( 'MainHash' ), 'dry_run' => false, 'force' => true ) );
$assert( ! is_wp_error( $forced ) && 2 === count( $transport->calls ), 'force allows a repeat send' );

// ── Scheduling ──
$GLOBALS['send_posts'][80] = new WP_Post( 80 );
$transport->result         = array( 'success' => true, 'campaign_id' => null, 'message' => 'Campaign scheduled', 'scheduled' => true );
$scheduled                 = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 80, 'all_lists' => true, 'dry_run' => false, 'schedule_date_time' => '2030-10-19 09:00' ) );
$call                      = end( $transport->calls );
$assert( ! is_wp_error( $scheduled ) && true === $scheduled['scheduled'] && false === $scheduled['sent'], 'scheduled sends report scheduled' );
$assert( '2030-10-19 09:00' === $call['schedule_date_time'] && 'America/New_York' === $call['schedule_timezone'], 'schedule defaults to America/New_York' );
$assert( '2030-10-19 09:00 America/New_York' === get_post_meta( 80, '_sendy_scheduled_for', true ), 'schedule records _sendy_scheduled_for' );
$assert( '' === get_post_meta( 80, '_sendy_sent_at', true ), 'a schedule does not record a send time' );

$rescheduled = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 80, 'all_lists' => true, 'dry_run' => false ) );
$assert( is_wp_error( $rescheduled ) && 'newsletter_already_sent' === $rescheduled->get_error_code(), 'a scheduled post is guarded too' );

// ── Transport failures ──
$GLOBALS['send_posts'][81] = new WP_Post( 81 );
$transport->result         = new WP_Error( 'sendy_send_rejected', 'One or more list IDs are invalid' );
$rejected                  = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 81, 'all_lists' => true, 'dry_run' => false ) );
$assert( is_wp_error( $rejected ) && 'sendy_send_rejected' === $rejected->get_error_code(), 'transport errors pass through' );
$assert( '' === get_post_meta( 81, '_sendy_sent_at', true ), 'a failed send records nothing' );

unset( $GLOBALS['send_abilities']['datamachine/sendy-send-campaign'] );
$absent = extrachill_newsletter_ability_send_campaign( array( 'post_id' => 81, 'all_lists' => true, 'dry_run' => false ) );
$assert( is_wp_error( $absent ) && 'sendy_campaign_provider_unavailable' === $absent->get_error_code(), 'missing transport is explicit' );

foreach ( array( $preview, $sent, $scheduled ) as $result ) {
	$assert( false === strpos( serialize( $result ), 'api-key-secret' ), 'results never contain credentials' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Send campaign smoke checks passed ({$passes} assertions).\n";
