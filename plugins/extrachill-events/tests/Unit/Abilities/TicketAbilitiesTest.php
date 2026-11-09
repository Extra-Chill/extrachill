<?php
/** Plain-PHP ticket lifecycle regression tests using the plugin bootstrap. */
use ExtraChillEvents\Abilities\TicketAbilities;
use ExtraChillEvents\Core\RsvpPassesTable;

require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/TicketAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/priority-boost-service-authority.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/ticket-service-authority.php';

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
function ec_cross_site_verified_service_context( $request ) { return $GLOBALS['ticket_claims'] ?? array(); }
function extrachill_events_guest_list_pass_link( $id, $code, $name = '' ) { return 'https://events.test/' . $id . '/guest-list/?pass=' . $code; }
function extrachill_events_send_guest_list_pass_email( $id, $email, $code, $name = '' ) { $GLOBALS['ticket_emails'][] = $code; return true; }
function datamachine_get_event_timing( int $id ): string { return $GLOBALS['ticket_timing'][ $id ] ?? 'past'; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['ticket_meta'][$id][$key] ?? ''; }
function get_post( $id ) { return (object) array( 'ID' => (int) $id, 'post_type' => 'data_machine_events', 'post_status' => 'publish', 'post_title' => 'Test show' ); }
function get_permalink( $id ) { return 'https://events.test/' . $id; }
function extrachill_events_get_perk_capacity( $id ) { return 3; }
function ec_get_blog_id( $site ) { return 'events' === $site ? 7 : 8; }
function ec_get_site_url( $site ) { return 'https://' . $site . '.test'; }
function get_current_user_id() { return 1; }
function extrachill_events_user_can_manage_event( $user, $event ) { return false; }
function get_the_title( $id ) { return 'Test show'; }
foreach ( array( '_n' => static fn( $one, $many, $n ) => 1 === $n ? $one : $many, 'esc_html' => static fn( $t ) => $t, 'esc_html__' => static fn( $t ) => $t ) as $name => $impl ) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS['ticket_impl'][ $name ] = $impl;
		eval( "function {$name}( ...\$a ) { return ( \$GLOBALS['ticket_impl']['{$name}'] )( ...\$a ); }" );
	}
}
function ec_send_email_queued( array $args ) { $GLOBALS['dispute_mail'][] = $args; return array( 'success' => true ); }
// Recipient lookup is Events-internal and covered by its own tests; record the seller asked for.
eval( 'namespace ExtraChillEvents\Abilities; class CommerceSellerNotifications { public static function get_data( string $type, int $id ): array { $GLOBALS["dispute_seller"] = array( $type, $id ); return array( "name" => "Lo-Fi", "recipients" => array( "owner@example.com" ) ); } }' );
function ec_cross_site_rest_request( $site, $method, $path, $args = array() ) {
	$GLOBALS['shop_calls'][] = array( $site, $method, $path, $args['query'] ?? array() );
	return $GLOBALS['shop_status'];
}

/** Query-shape fake: transactions retain a snapshot for rollback tests. */
final class TicketWpdb {
	public $prefix = 'wp_';
	public $rows = array();
	public $fail_insert = false;
	private $snapshot = array();
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) { $sql = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( $arg ) . "'", $sql, 1 ); }
		return $sql;
	}
	public function get_var( $sql ) {
		if ( strpos( $sql, 'GET_LOCK' ) !== false || strpos( $sql, 'RELEASE_LOCK' ) !== false ) { return 1; }
		return count( array_filter( $this->rows, static function ( $row ) { return in_array( $row['status'], array( 'active', 'redeemed' ), true ); } ) );
	}
	public function get_row( $sql, $format ) { return array( 'claimed' => $this->get_var( $sql ), 'checked_in' => 0 ); }
	public function get_results( $sql, $format ) {
		preg_match( "/WHERE (idempotency_key|order_reference) = '([^']+)'/", $sql, $match );
		return array_values( array_filter( $this->rows, static function ( $row ) use ( $match ) { return $row[$match[1]] === $match[2]; } ) );
	}
	public function query( $sql ) {
		if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $this->rows; }
		if ( 'ROLLBACK' === $sql ) { $this->rows = $this->snapshot; }
		return 1;
	}
	public function insert( $table, $data ) {
		if ( $this->fail_insert && count( $this->rows ) === 1 ) { return false; }
		$data['id'] = count( $this->rows ) + 1;
		$this->rows[] = $data;
		return 1;
	}
	public function update( $table, $data, $where ) {
		$count = 0;
		foreach ( $this->rows as &$row ) {
			if ( ! array_diff_assoc( $where, $row ) ) { $row = array_merge( $row, $data ); ++$count; }
		}
		return $count;
	}
}

final class TicketAbilitiesTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		extrachill_events_ticket_active_request( null, true );
		$GLOBALS['ticket_emails'] = array();
		$GLOBALS['dispute_mail'] = array();
		$GLOBALS['wpdb'] = new TicketWpdb();
		$GLOBALS['ticket_timing'] = array( 10 => 'upcoming' );
		$GLOBALS['shop_calls'] = array();
		$GLOBALS['shop_status'] = array( 'can_receive_payments' => true );
		$GLOBALS['ticket_meta'] = array( 10 => array( '_extrachill_ticket_offer' => array( 'tickets_enabled' => true, 'ticket_price_minor' => 1500, 'ticket_quantity' => 0, 'ticket_seller' => array( 'type' => 'venue', 'id' => 2 ) ) ) );
	}
	private function issue( $quantity = 2, $key = 'key', $order = 'cs_123' ) {
		return RsvpPassesTable::issue_tickets( 10, $quantity, 3, 0, 'Guest', $key, $order, 'pi_123' );
	}
	public function test_offer_and_defaults(): void {
		$offer = ( new TicketAbilities() )->offer( array( 'event' => 10 ) );
		$this->assertSame( 1500, $offer['price_minor'] );
		$this->assertSame( 'usd', $offer['currency'] );
		$this->assertSame( 3, $offer['remaining'] );
		$this->assertTrue( $offer['sale_open'] );
		$this->assertFalse( TicketAbilities::settings( 11 )['tickets_enabled'] );
	}
	public function test_sale_follows_event_timing(): void {
		$GLOBALS['ticket_timing'][10] = 'ongoing';
		$this->assertTrue( ( new TicketAbilities() )->offer( array( 'event' => 10 ) )['sale_open'] );
		$GLOBALS['ticket_timing'][10] = 'past';
		$this->assertFalse( ( new TicketAbilities() )->offer( array( 'event' => 10 ) )['sale_open'] );
	}
	public function test_invalid_offer_and_seller_authority(): void {
		$this->assertSame( 'invalid_ticket_offer', TicketAbilities::validate_settings( 10, array( 'ticket_price_minor' => 0 ) )->get_error_code() );
		$this->assertSame( 'ticket_seller_forbidden', TicketAbilities::validate_settings( 10, array( 'ticket_seller' => array( 'type' => 'venue', 'id' => 4 ) ) )->get_error_code() );
	}
	private function off_sale(): array {
		$GLOBALS['ticket_meta'][10]['_extrachill_ticket_offer']['tickets_enabled'] = false;
		return array( 'tickets_enabled' => true );
	}
	public function test_enabling_with_ready_seller_asks_shop_and_succeeds(): void {
		$settings = TicketAbilities::validate_settings( 10, $this->off_sale() );
		$this->assertTrue( $settings['tickets_enabled'] );
		$this->assertSame( array( array( 'shop', 'GET', '/shop/stripe-connect/status', array( 'seller_type' => 'venue', 'seller_id' => 2 ) ) ), $GLOBALS['shop_calls'] );
	}
	public function test_enabling_with_unready_seller_is_refused(): void {
		$GLOBALS['shop_status'] = array( 'can_receive_payments' => false );
		$error = TicketAbilities::validate_settings( 10, $this->off_sale() );
		$this->assertSame( 'ticket_seller_payments_unavailable', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}
	public function test_lookup_failure_refuses(): void {
		$input = $this->off_sale();
		foreach ( array( new \WP_Error( 'http', 'down' ), array(), 'garbage' ) as $status ) {
			$GLOBALS['shop_status'] = $status;
			$this->assertSame( 'ticket_seller_payments_unknown', TicketAbilities::validate_settings( 10, $input )->get_error_code() );
		}
	}
	public function test_disabling_and_unrelated_saves_skip_shop(): void {
		$GLOBALS['shop_status'] = array( 'can_receive_payments' => false );
		$this->assertFalse( TicketAbilities::validate_settings( 10, array( 'tickets_enabled' => false ) )['tickets_enabled'] );
		$this->assertTrue( TicketAbilities::validate_settings( 10, array( 'ticket_quantity' => 50 ) )['tickets_enabled'], 'Already on sale with the same seller.' );
		$this->off_sale();
		$this->assertFalse( TicketAbilities::validate_settings( 10, array( 'ticket_price_minor' => 2000 ) )['tickets_enabled'] );
		$this->assertSame( array(), $GLOBALS['shop_calls'] );
	}
	public function test_replay_and_conflicting_key(): void {
		$first = $this->issue();
		$this->assertSame( $first, $this->issue() );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows );
		$this->assertSame( 'ticket_idempotency_conflict', $this->issue( 1 )->get_error_code() );
	}
	public function test_capacity_edge_is_all_or_nothing(): void {
		$this->issue();
		$this->assertNull( $this->issue( 2, 'second', 'cs_second' ) );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows );
		$this->assertCount( 1, $this->issue( 1, 'third', 'cs_third' ) );
		$this->assertNull( $this->issue( 1, 'fourth', 'cs_fourth' ) );
	}
	public function test_ticket_quantity_is_separate_cap(): void {
		$this->assertNull( RsvpPassesTable::issue_tickets( 10, 2, 3, 1, 'Guest', 'key', 'cs_123', 'pi_123' ) );
		$this->assertCount( 0, $GLOBALS['wpdb']->rows );
	}
	public function test_partial_insert_rolls_back(): void {
		$GLOBALS['wpdb']->fail_insert = true;
		$this->assertSame( 'ticket_storage_failed', $this->issue()->get_error_code() );
		$this->assertCount( 0, $GLOBALS['wpdb']->rows );
	}
	public function test_revoke_is_idempotent_and_preserves_redeemed_order(): void {
		$this->issue();
		$GLOBALS['wpdb']->rows[1]['status'] = 'redeemed';
		$this->assertSame( 'ticket_already_redeemed', RsvpPassesTable::revoke_ticket_order( 'cs_123' )->get_error_code() );
		$this->assertSame( 'active', $GLOBALS['wpdb']->rows[0]['status'] );
		$GLOBALS['wpdb']->rows[1]['status'] = 'active';
		$this->assertSame( array( 'revoked' => 2 ), RsvpPassesTable::revoke_ticket_order( 'cs_123' ) );
		$this->assertSame( array( 'revoked' => 0 ), RsvpPassesTable::revoke_ticket_order( 'cs_123' ) );
	}
	private function service_context(): void {
		$GLOBALS['ticket_claims'] = array( 'service_id' => 'extrachill.events.tickets', 'scope' => 'extrachill/events:tickets', 'source_site_id' => 8, 'target_site_id' => 7, 'target_host' => 'events.test' );
		add_filter( 'extrachill_events_ticket_service_assertion_keys', static function () { return array( 'test-key' => 'test-secret' ); } );
		extrachill_events_ticket_active_request( new class {
			public function get_method() { return 'POST'; }
			public function get_route() { return '/wp-abilities/v1/abilities/extrachill/issue-ticket-passes/run'; }
		}, true );
	}
	public function test_ability_issuance_email_replay_and_order_status(): void {
		$this->service_context();
		$abilities = new TicketAbilities();
		$input = array( 'event' => 10, 'quantity' => 2, 'name' => 'Guest', 'email' => 'guest@example.com', 'idempotency_key' => 'key', 'order_reference' => 'cs_123', 'payment_reference' => 'pi_123' );
		$first = $abilities->issue( $input );
		$this->assertSame( 'issued', $first['status'] );
		$this->assertCount( 2, $first['passes'] );
		$this->assertSame( $first, $abilities->issue( $input ) );
		$this->assertCount( 2, $GLOBALS['ticket_emails'] );
		$status = $abilities->order_status( array( 'order_reference' => 'cs_123' ) );
		$this->assertSame( 10, $status['event_id'] );
		$this->assertTrue( $status['refundable'] );
		$GLOBALS['wpdb']->rows[0]['status'] = 'redeemed';
		$status = $abilities->order_status( array( 'order_reference' => 'cs_123' ) );
		$this->assertTrue( $status['any_redeemed'] );
		$this->assertFalse( $status['refundable'] );
		$input['idempotency_key'] = 'second';
		$this->assertSame( array( 'status' => 'sold_out', 'passes' => array() ), $abilities->issue( $input ) );
	}
	public function test_verified_claims_require_exact_service_and_scope(): void {
		$this->service_context();
		$this->assertTrue( ( new TicketAbilities() )->authorize() );
		$GLOBALS['ticket_claims']['scope'] = 'extrachill/events:priority-boost';
		$this->assertSame( 'ticket_service_required', ( new TicketAbilities() )->authorize()->get_error_code() );
	}
	public function test_normal_users_cannot_fulfill_or_read_orders(): void {
		$abilities = new TicketAbilities();
		$this->assertSame( 'ticket_service_required', $abilities->authorize()->get_error_code() );
		$this->assertSame( 'ticket_service_required', $abilities->order_status( array( 'order_reference' => 'cs_123' ) )->get_error_code() );
		$this->assertSame( 'ticket_service_required', $abilities->issue( array() )->get_error_code() );
		$this->assertSame( 'ticket_service_required', $abilities->revoke( array() )->get_error_code() );
	}
	public function test_dispute_revokes_and_emails_the_seller(): void {
		$this->service_context();
		$this->issue();
		$result = ( new TicketAbilities() )->revoke( array( 'order_reference' => 'cs_123', 'reason' => 'dispute' ) );
		$this->assertSame( array( 'revoked' => 2 ), $result );
		$this->assertSame( array( 'venue', 2 ), $GLOBALS['dispute_seller'] );
		$this->assertCount( 1, $GLOBALS['dispute_mail'] );
		$this->assertSame( 'owner@example.com', $GLOBALS['dispute_mail'][0]['to'] );
		$this->assertStringContainsString( '2 tickets to Test show', $GLOBALS['dispute_mail'][0]['context']['body_html'] );
		$this->assertStringContainsString( 'passes are cancelled', $GLOBALS['dispute_mail'][0]['context']['body_html'] );
	}
	public function test_dispute_after_check_in_keeps_check_in_and_still_emails(): void {
		$this->service_context();
		$this->issue();
		$GLOBALS['wpdb']->rows[0]['status'] = 'redeemed';
		$result = ( new TicketAbilities() )->revoke( array( 'order_reference' => 'cs_123', 'reason' => 'dispute' ) );
		$this->assertSame( array( 'revoked' => 0 ), $result );
		$this->assertSame( 'redeemed', $GLOBALS['wpdb']->rows[0]['status'] );
		$this->assertStringContainsString( 'already checked in', $GLOBALS['dispute_mail'][0]['context']['body_html'] );
	}
	public function test_seller_refund_after_check_in_is_refused_without_email(): void {
		$this->service_context();
		$this->issue();
		$GLOBALS['wpdb']->rows[0]['status'] = 'redeemed';
		$result = ( new TicketAbilities() )->revoke( array( 'order_reference' => 'cs_123', 'reason' => 'seller_refund' ) );
		$this->assertSame( 'ticket_already_redeemed', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['dispute_mail'] );
	}
	public function test_notify_seller_is_service_only_and_emails_finance_owners(): void {
		$input = array( 'seller' => array( 'type' => 'promoter', 'id' => 5 ), 'subject' => 'New Order #1 - Lo-Fi', 'body_html' => '<p>Order</p>', 'cta_url' => 'https://shop.test/manage/' );
		$this->assertSame( 'ticket_service_required', ( new TicketAbilities() )->notify_seller( $input )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['dispute_mail'] );
		$this->service_context();
		$this->assertSame( array( 'sent' => 1 ), ( new TicketAbilities() )->notify_seller( $input ) );
		$this->assertSame( array( 'promoter', 5 ), $GLOBALS['dispute_seller'] );
		$mail = $GLOBALS['dispute_mail'][0];
		$this->assertSame( 'owner@example.com', $mail['to'] );
		$this->assertSame( 'New Order #1 - Lo-Fi', $mail['subject'] );
		$this->assertSame( '<p>Order</p>', $mail['context']['body_html'] );
		$this->assertSame( 'https://shop.test/manage/', $mail['context']['cta_url'] );
		$this->assertArrayNotHasKey( 'preheader', $mail['context'] );
	}
}
