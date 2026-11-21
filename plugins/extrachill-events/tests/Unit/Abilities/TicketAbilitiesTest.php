<?php
/** Plain-PHP ticket lifecycle regression tests using the plugin bootstrap. */
use ExtraChillEvents\Abilities\TicketAbilities;
use ExtraChillEvents\Core\RsvpPassesTable;

require_once dirname( __DIR__, 3 ) . '/inc/core/ticket-checkout.php';
class WP_Term {
	public $slug = 'lofi';
}
function get_term( $id, $taxonomy ) { return 12 === $id ? new WP_Term() : null; }
function extrachill_events_digest_scope( $scope ) { return array(
	'entity'   => 'venue_show_updates',
	'taxonomy' => 'venue',
); }
function extrachill_users_subscribe_to_entity( ...$args ) { $GLOBALS['ticket_subscriptions'][] = $args; }

require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/TicketAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/priority-boost-service-authority.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/ticket-service-authority.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' ); }
// Network contract double: verified claims on the active request must match a registered target grant.
function ec_cross_site_request_has_service_grant( string $service_id, string $scope ): bool {
	$request = $GLOBALS['ticket_request'] ?? null;
	$claims  = $GLOBALS['ticket_claims'] ?? array();
	if ( ! $request || $service_id !== ( $claims['service_id'] ?? '' ) || $scope !== ( $claims['scope'] ?? '' ) ) {
		return false; }
	foreach ( extrachill_events_ticket_target_grants() as $grant ) {
		$matches = $grant['method'] === $request['method'] && $grant['route'] === $request['route'];
		foreach ( array( 'service_id', 'scope', 'source_site_id', 'target_site_id', 'target_host' ) as $field ) {
			$matches = $matches && (string) $grant[ $field ] === (string) ( $claims[ $field ] ?? '' );
		}
		if ( $matches ) {
			return true; }
	}
	return false;
}
function extrachill_events_guest_list_pass_link( $id, $code, $name = '' ) { return 'https://events.test/' . $id . '/guest-list/?pass=' . $code; }
function extrachill_events_send_guest_list_pass_email( $id, $email, $code, $name = '', $subject = '' ) {
	$GLOBALS['ticket_emails'][]     = $code;
	$GLOBALS['ticket_email_args'][] = array( $email, $code, $subject );
	return empty( $GLOBALS['ticket_email_fail'] );
}
function datamachine_get_event_timing( int $id ): string { return $GLOBALS['ticket_timing'][ $id ] ?? 'past'; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['ticket_meta'][ $id ][ $key ] ?? ''; }
function get_post( $id ) { return (object) array(
	'ID'          => (int) $id,
	'post_type'   => 'data_machine_events',
	'post_status' => 'publish',
	'post_title'  => 'Test show',
); }
function get_permalink( $id ) { return 'https://events.test/' . $id; }
function extrachill_events_get_perk_capacity( $id ) { return 3; }
function ec_get_blog_id( $site ) { return 'events' === $site ? 7 : 8; }
function ec_get_site_url( $site ) { return 'https://' . $site . '.test'; }
function get_current_user_id() { return 1; }
function extrachill_events_user_can_manage_event( $user, $event ) { return false; }
function get_the_title( $id ) { return 'Test show'; }
foreach ( array(
	'_n'         => static fn( $one, $many, $n ) => 1 === $n ? $one : $many,
	'esc_html'   => static fn( $t ) => $t,
	'esc_html__' => static fn( $t ) => $t,
) as $name => $impl ) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS['ticket_impl'][ $name ] = $impl;
		eval( "function {$name}( ...\$a ) { return ( \$GLOBALS['ticket_impl']['{$name}'] )( ...\$a ); }" );
	}
}
function ec_send_email_queued( array $args ) { $GLOBALS['dispute_mail'][] = $args;
	return array( 'success' => true ); }
// Recipient lookup is Events-internal and covered by its own tests; record the seller asked for.
eval( 'namespace ExtraChillEvents\Abilities; class CommerceSellerNotifications { public static function get_data( string $type, int $id ): array { $GLOBALS["dispute_seller"] = array( $type, $id ); return array( "name" => "Lo-Fi", "recipients" => array( "owner@example.com" ) ); } }' );
function ec_cross_site_rest_request( $site, $method, $path, $args = array() ) {
	$GLOBALS['shop_calls'][]     = array( $site, $method, $path, $args['query'] ?? array() );
	$GLOBALS['shop_call_args'][] = $args;
	return $GLOBALS['shop_status'];
}

/** Query-shape fake: transactions retain a snapshot for rollback tests. */
final class TicketWpdb {
	public $prefix      = 'wp_';
	public $rows        = array();
	public $fail_insert = false;
	public $before_key_lock;
	public $key_locked = false;
	public $capacity_locked = false;
	public $in_transaction = false;
	private $snapshot = array();
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( $arg ) . "'", $sql, 1 ); }
		return $sql;
	}
	public function get_var( $sql ) {
		if ( strpos( $sql, 'GET_LOCK' ) !== false ) {
			if ( strpos( $sql, 'ec_ticket_' ) !== false ) {
				if ( $this->before_key_lock ) {
					$callback = $this->before_key_lock;
					$this->before_key_lock = null;
					$callback(); // Another delivery commits before this waiter acquires the key lock.
				}
				$this->key_locked = true;
			} else { $this->capacity_locked = true; }
			return 1;
		}
		if ( strpos( $sql, 'RELEASE_LOCK' ) !== false ) {
			if ( strpos( $sql, 'ec_ticket_' ) !== false ) { $this->key_locked = false; }
			else { $this->capacity_locked = false; }
			return 1;
		}
		return count( array_filter( $this->rows, static function ( $row ) { return in_array( $row['status'], array( 'active', 'redeemed' ), true ); } ) );
	}
	public function get_row( $sql, $format ) { return array(
		'claimed'    => $this->get_var( $sql ),
		'checked_in' => 0,
	); }
	public function get_results( $sql, $format ) {
		if ( strpos( $sql, 'idempotency_key' ) !== false && ( ! $this->key_locked || ! $this->capacity_locked ) ) {
			throw new \RuntimeException( 'Replay lookup must hold both locks.' );
		}
		preg_match( "/WHERE (idempotency_key|order_reference) = '([^']+)'/", $sql, $match );
		return array_values( array_filter( $this->rows, static function ( $row ) use ( $match ) { return $row[ $match[1] ] === $match[2];
		} ) );
	}
	public function query( $sql ) {
		if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $this->rows; $this->in_transaction = true; }
		if ( 'ROLLBACK' === $sql ) { $this->rows = $this->snapshot; }
		if ( in_array( $sql, array( 'COMMIT', 'ROLLBACK' ), true ) ) { $this->in_transaction = false; }
		return 1;
	}
	public function insert( $table, $data ) {
		if ( $this->fail_insert && count( $this->rows ) === 1 ) {
			return false; }
		$data['id']   = count( $this->rows ) + 1;
		$this->rows[] = $data;
		return 1;
	}
	public function update( $table, $data, $where ) {
		$count = 0;
		foreach ( $this->rows as &$row ) {
			if ( ! array_diff_assoc( $where, $row ) ) {
				$row = array_merge( $row, $data );
				++$count; }
		}
		return $count;
	}
}

final class TicketAbilitiesTest extends \PHPUnit\Framework\TestCase {
	public function test_venue_subscription_requires_explicit_consent_and_a_venue_seller(): void {
		$GLOBALS['ticket_subscriptions'] = array();
		extrachill_events_ticket_subscribe_venue( array(
			'type' => 'venue',
			'id'   => 12,
		), 9, false );
		extrachill_events_ticket_subscribe_venue( array(
			'type' => 'promoter',
			'id'   => 12,
		), 9, true );
		$this->assertSame( array(), $GLOBALS['ticket_subscriptions'] );
		extrachill_events_ticket_subscribe_venue( array(
			'type' => 'venue',
			'id'   => 12,
		), 9, true );
		$this->assertSame( array( array( 9, 'venue_show_updates', 'venue', 'lofi' ) ), $GLOBALS['ticket_subscriptions'] );
	}

	protected function setUp(): void {
		$GLOBALS['ticket_request']    = null;
		$GLOBALS['ticket_claims']     = array();
		$GLOBALS['ticket_emails']     = array();
		$GLOBALS['ticket_email_args'] = array();
		$GLOBALS['ticket_email_fail'] = false;
		$GLOBALS['dispute_mail']      = array();
		$GLOBALS['wpdb']              = new TicketWpdb();
		$GLOBALS['ticket_timing']     = array( 10 => 'upcoming' );
		$GLOBALS['shop_calls']        = array();
		$GLOBALS['shop_call_args']    = array();
		$GLOBALS['shop_status']       = array( 'can_receive_payments' => true );
		$GLOBALS['ticket_meta']       = array(
			10 => array(
				'_extrachill_ticket_offer' => array(
					'tickets_enabled'    => true,
					'ticket_price_minor' => 1500,
					'ticket_quantity'    => 0,
					'ticket_seller'      => array(
						'type' => 'venue',
						'id'   => 2,
					),
				),
			),
		);
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
	public function test_output_reports_a_missing_seller_as_null_and_keeps_input_strict(): void {
		$unset = TicketAbilities::output_settings( TicketAbilities::settings( 11 ) );
		$this->assertNull( $unset['ticket_seller'] );
		$this->assertNull( TicketAbilities::output_settings( array(
			'ticket_seller' => array(
				'type' => 'venue',
				'id'   => 0,
			),
		) )['ticket_seller'] );
		$this->assertNull( TicketAbilities::output_settings( array() )['ticket_seller'] );
		$this->assertSame( array(
			'type' => 'venue',
			'id'   => 2,
		), TicketAbilities::output_settings( TicketAbilities::settings( 10 ) )['ticket_seller'] );
		$this->assertSame( 0, TicketAbilities::settings( 11 )['ticket_seller']['id'], 'Storage keeps the internal ID-0 default.' );
		$this->assertSame( array( 'object', 'null' ), TicketAbilities::output_fields()['ticket_seller']['type'] );
		$this->assertSame( 'object', TicketAbilities::fields()['ticket_seller']['type'] );
		$this->assertSame( 1, TicketAbilities::output_fields()['ticket_seller']['properties']['id']['minimum'] );
	}
	public function test_sale_follows_event_timing(): void {
		$GLOBALS['ticket_timing'][10] = 'ongoing';
		$this->assertTrue( ( new TicketAbilities() )->offer( array( 'event' => 10 ) )['sale_open'] );
		$GLOBALS['ticket_timing'][10] = 'past';
		$this->assertFalse( ( new TicketAbilities() )->offer( array( 'event' => 10 ) )['sale_open'] );
	}
	public function test_invalid_offer_and_seller_authority(): void {
		$this->assertSame( 'invalid_ticket_offer', TicketAbilities::validate_settings( 10, array( 'ticket_price_minor' => 0 ) )->get_error_code() );
		$this->assertSame( 'ticket_seller_forbidden', TicketAbilities::validate_settings( 10, array(
			'ticket_seller' => array(
				'type' => 'venue',
				'id'   => 4,
			),
		) )->get_error_code() );
	}
	private function off_sale(): array {
		$GLOBALS['ticket_meta'][10]['_extrachill_ticket_offer']['tickets_enabled'] = false;
		return array( 'tickets_enabled' => true );
	}
	public function test_enabling_with_ready_seller_asks_shop_and_succeeds(): void {
		$settings = TicketAbilities::validate_settings( 10, $this->off_sale() );
		$this->assertTrue( $settings['tickets_enabled'] );
		$this->assertSame( array(
			array(
				'shop',
				'GET',
				'/wp-abilities/v1/abilities/extrachill/shop-stripe-status/run',
				array(
					'input' => array(
						'seller' => array(
							'type' => 'venue',
							'id'   => 2,
						),
					),
				),
			),
		), $GLOBALS['shop_calls'] );
	}
	public function test_seller_status_runs_the_shop_owned_ability_as_the_current_user(): void {
		$this->assertTrue( TicketAbilities::seller_can_receive_payments( array(
			'type' => 'promoter',
			'id'   => 9,
		) ) );
		// The Shop ability's run path, never the extrachill-api adapter route,
		// whose wrapped ability is not registered in an Events request.
		$this->assertSame( 'shop', $GLOBALS['shop_calls'][0][0] );
		$this->assertSame( 'GET', $GLOBALS['shop_calls'][0][1], 'The ability is readonly, so core requires GET.' );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/shop-stripe-status/run', $GLOBALS['shop_calls'][0][2] );
		$this->assertSame( array(
			'input' => array(
				'seller' => array(
					'type' => 'promoter',
					'id'   => 9,
				),
			),
		), $GLOBALS['shop_call_args'][0]['query'] );
		$this->assertSame( 1, $GLOBALS['shop_call_args'][0]['user_id'] );
		$this->assertArrayNotHasKey( 'body', $GLOBALS['shop_call_args'][0] );
	}
	public function test_enabling_with_unready_seller_is_refused(): void {
		$GLOBALS['shop_status'] = array( 'can_receive_payments' => false );
		$error                  = TicketAbilities::validate_settings( 10, $this->off_sale() );
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
		$this->assertFalse( $first[0]['pre_existing'] );
		$replay = $this->issue();
		$this->assertTrue( $replay[0]['pre_existing'] );
		$this->assertSame( array_column( $first, 'code' ), array_column( $replay, 'code' ) );
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
		$GLOBALS['ticket_claims'] = array(
			'service_id'     => 'extrachill.events.tickets',
			'scope'          => 'extrachill/events:tickets',
			'source_site_id' => 8,
			'target_site_id' => 7,
			'target_host'    => 'events.test',
		);
		add_filter( 'extrachill_events_ticket_service_assertion_keys', static function () { return array( 'test-key' => 'test-secret' );
		} );
		$GLOBALS['ticket_request'] = array(
			'method' => 'POST',
			'route'  => '/wp-abilities/v1/abilities/extrachill/issue-ticket-passes/run',
		);
	}
	public function test_paid_issuance_subscribes_once_and_sold_out_does_not(): void {
		$this->service_context();
		$GLOBALS['ticket_subscriptions'] = array();
		$abilities                       = new TicketAbilities();
		$input                           = array(
			'event'              => 10,
			'quantity'           => 2,
			'name'               => 'Buyer',
			'email'              => 'buyer@example.com',
			'idempotency_key'    => 'consent',
			'order_reference'    => 'cs_consent',
			'payment_reference'  => 'pi_consent',
			'buyer_user_id'      => 9,
			'seller'             => array(
				'type' => 'venue',
				'id'   => 12,
			),
			'venue_show_updates' => true,
		);
		$this->assertSame( 'issued', $abilities->issue( $input )['status'] );
		$this->assertSame( 'issued', $abilities->issue( $input )['status'] );
		$this->assertSame( array( array( 9, 'venue_show_updates', 'venue', 'lofi' ) ), $GLOBALS['ticket_subscriptions'] );
		$input['idempotency_key'] = 'sold-out';
		$this->assertSame( 'sold_out', $abilities->issue( $input )['status'] );
		$this->assertCount( 1, $GLOBALS['ticket_subscriptions'] );
	}

	public function test_ability_issuance_email_replay_and_order_status(): void {
		$this->service_context();
		$abilities = new TicketAbilities();
		$input     = array(
			'event'             => 10,
			'quantity'          => 2,
			'name'              => 'Guest',
			'email'             => 'guest@example.com',
			'idempotency_key'   => 'key',
			'order_reference'   => 'cs_123',
			'payment_reference' => 'pi_123',
		);
		$first     = $abilities->issue( $input );
		$this->assertSame( 'issued', $first['status'] );
		$this->assertCount( 2, $first['passes'] );
		$this->assertSame( 0, $first['emails_failed'] );
		$this->assertSame( $first, $abilities->issue( $input ) );
		$this->assertCount( 2, $GLOBALS['ticket_emails'] );
		$status = $abilities->order_status( array( 'order_reference' => 'cs_123' ) );
		$this->assertSame( 10, $status['event_id'] );
		$this->assertTrue( $status['refundable'] );
		$this->assertSame( 'https://events.test/10/guest-list/?pass=' . $status['passes'][0]['code'], $status['passes'][0]['url'], 'Order status carries the buyer pass link for My Account.' );
		$GLOBALS['wpdb']->rows[0]['status'] = 'redeemed';
		$status                             = $abilities->order_status( array( 'order_reference' => 'cs_123' ) );
		$this->assertTrue( $status['any_redeemed'] );
		$this->assertFalse( $status['refundable'] );
		$input['idempotency_key'] = 'second';
		$this->assertSame( array( 'status' => 'sold_out', 'passes' => array(), 'emails_failed' => 0 ), $abilities->issue( $input ) );
	}
	public function test_failed_pass_emails_are_reported_and_resent_for_active_passes_only(): void {
		$this->service_context();
		$abilities = new TicketAbilities();
		$input = array( 'event' => 10, 'quantity' => 2, 'name' => 'Guest', 'email' => 'guest@example.com', 'idempotency_key' => 'key', 'order_reference' => 'cs_123', 'payment_reference' => 'pi_123' );
		$GLOBALS['ticket_email_fail'] = true;
		$this->assertSame( 2, $abilities->issue( $input )['emails_failed'] );
		$this->assertSame( 0, $abilities->issue( $input )['emails_failed'], 'A replay sends nothing, so nothing fails.' );
		$this->assertSame( array( 'emailed' => 0, 'failed' => 2 ), $abilities->resend( array( 'order_reference' => 'cs_123', 'email' => 'guest@example.com' ) ) );
		$GLOBALS['ticket_email_fail'] = false;
		$GLOBALS['ticket_emails']     = array();
		$GLOBALS['wpdb']->rows[1]['status'] = 'revoked';
		$this->assertSame( array( 'emailed' => 1, 'failed' => 0 ), $abilities->resend( array( 'order_reference' => 'cs_123', 'email' => 'guest@example.com' ) ) );
		$this->assertSame( array( $GLOBALS['wpdb']->rows[0]['code'] ), $GLOBALS['ticket_emails'] );
	}
	public function test_concurrent_duplicate_waiting_for_last_tickets_sends_no_second_email(): void {
		$this->service_context();
		$abilities = new TicketAbilities();
		$input = array( 'event' => 10, 'quantity' => 3, 'name' => 'Guest', 'email' => 'guest@example.com', 'idempotency_key' => 'key', 'order_reference' => 'cs_123', 'payment_reference' => 'pi_123' );
		$first = null;
		$GLOBALS['wpdb']->before_key_lock = function () use ( $abilities, $input, &$first ) {
			$first = $abilities->issue( $input );
		};
		$waiting = $abilities->issue( $input );
		$this->assertSame( 'issued', $waiting['status'] );
		$this->assertSame( $first, $waiting );
		$this->assertCount( 3, $GLOBALS['wpdb']->rows );
		$this->assertCount( 3, $GLOBALS['ticket_emails'] );
		$this->assertSame( $first, $abilities->issue( $input ) );
		$this->assertCount( 3, $GLOBALS['ticket_emails'] );
		$this->assertFalse( $GLOBALS['wpdb']->key_locked );
		$this->assertFalse( $GLOBALS['wpdb']->capacity_locked );
		$this->assertFalse( $GLOBALS['wpdb']->in_transaction );
	}
	public function test_paid_fulfilment_after_event_end_and_disabling_sales(): void {
		$this->service_context();
		$GLOBALS['ticket_timing'][10] = 'past';
		$GLOBALS['ticket_meta'][10]['_extrachill_ticket_offer']['tickets_enabled'] = false;
		$abilities = new TicketAbilities();
		$this->assertFalse( $abilities->offer( array( 'event' => 10 ) )['sale_open'] );
		$input = array( 'event' => 10, 'quantity' => 2, 'name' => 'Guest', 'email' => 'guest@example.com', 'idempotency_key' => 'key', 'order_reference' => 'cs_123', 'payment_reference' => 'pi_123' );
		$this->assertSame( 'issued', $abilities->issue( $input )['status'] );
		$this->assertSame( 'issued', $abilities->issue( $input )['status'] );
		$this->assertCount( 2, $GLOBALS['ticket_emails'] );
	}
	public function test_verified_claims_require_exact_service_and_scope(): void {
		$this->service_context();
		$this->assertTrue( ( new TicketAbilities() )->authorize() );
		$GLOBALS['ticket_claims']['scope'] = 'extrachill/events:priority-boost';
		$this->assertSame( 'ticket_service_required', ( new TicketAbilities() )->authorize()->get_error_code() );
	}
	public function test_service_grant_covers_every_ticket_route_and_nothing_else(): void {
		$this->service_context();
		foreach ( array( 'issue-ticket-passes', 'get-ticket-order-status', 'revoke-ticket-passes', 'restore-ticket-passes', 'record-checkout-ticket-sales', 'notify-commerce-seller', 'resend-ticket-pass-emails' ) as $ability ) {
			$GLOBALS['ticket_request']['route'] = '/wp-abilities/v1/abilities/extrachill/' . $ability . '/run';
			$this->assertTrue( extrachill_events_ticket_has_service_authority(), $ability );
		}
		$GLOBALS['ticket_request']['route'] = '/wp-abilities/v1/abilities/extrachill/grant-event-priority-boost/run';
		$this->assertFalse( extrachill_events_ticket_has_service_authority(), 'priority route' );
		$GLOBALS['ticket_request'] = array(
			'method' => 'GET',
			'route'  => '/wp-abilities/v1/abilities/extrachill/issue-ticket-passes/run',
		);
		$this->assertFalse( extrachill_events_ticket_has_service_authority(), 'GET method' );
		$GLOBALS['ticket_request']                  = array(
			'method' => 'POST',
			'route'  => '/wp-abilities/v1/abilities/extrachill/issue-ticket-passes/run',
		);
		$GLOBALS['ticket_claims']['source_site_id'] = 3;
		$this->assertFalse( extrachill_events_ticket_has_service_authority(), 'wrong source site' );
	}
	public function test_normal_users_cannot_fulfill_or_read_orders(): void {
		$abilities = new TicketAbilities();
		$this->assertSame( 'ticket_service_required', $abilities->authorize()->get_error_code() );
		$this->assertSame( 'ticket_service_required', $abilities->order_status( array( 'order_reference' => 'cs_123' ) )->get_error_code() );
		$this->assertSame( 'ticket_service_required', $abilities->issue( array() )->get_error_code() );
		$this->assertSame( 'ticket_service_required', $abilities->revoke( array() )->get_error_code() );
		$this->assertSame( 'ticket_service_required', $abilities->restore( array() )->get_error_code() );
	}
	public function test_dispute_revokes_and_emails_the_seller(): void {
		$this->service_context();
		$this->issue();
		$result = ( new TicketAbilities() )->revoke( array(
			'order_reference' => 'cs_123',
			'reason'          => 'dispute',
		) );
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
		$result                             = ( new TicketAbilities() )->revoke( array(
			'order_reference' => 'cs_123',
			'reason'          => 'dispute',
		) );
		$this->assertSame( array( 'revoked' => 0 ), $result );
		$this->assertSame( 'redeemed', $GLOBALS['wpdb']->rows[0]['status'] );
		$this->assertStringContainsString( 'already checked in', $GLOBALS['dispute_mail'][0]['context']['body_html'] );
	}
	public function test_seller_refund_after_check_in_is_refused_without_email(): void {
		$this->service_context();
		$this->issue();
		$GLOBALS['wpdb']->rows[0]['status'] = 'redeemed';
		$result                             = ( new TicketAbilities() )->revoke( array(
			'order_reference' => 'cs_123',
			'reason'          => 'seller_refund',
		) );
		$this->assertSame( 'ticket_already_redeemed', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['dispute_mail'] );
	}
	public function test_notify_seller_is_service_only_and_emails_finance_owners(): void {
		$input = array(
			'seller'    => array(
				'type' => 'promoter',
				'id'   => 5,
			),
			'subject'   => 'New Order #1 - Lo-Fi',
			'body_html' => '<p>Order</p>',
			'cta_url'   => 'https://shop.test/manage/',
		);
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

	private function restore( string $email = 'buyer@example.com' ) {
		$input = array(
			'order_reference' => 'cs_123',
			'reason'          => 'dispute',
		);
		if ( '' !== $email ) {
			$input['email'] = $email;
		}
		return ( new TicketAbilities() )->restore( $input );
	}
	private function revoke( string $reason ) {
		return ( new TicketAbilities() )->revoke(
			array(
				'order_reference' => 'cs_123',
				'reason'          => $reason,
			)
		);
	}
	public function test_revoke_records_the_reason_on_each_pass(): void {
		$this->issue();
		RsvpPassesTable::revoke_ticket_order( 'cs_123', 'dispute' );
		$this->assertSame( array( 'dispute', 'dispute' ), array_column( $GLOBALS['wpdb']->rows, 'revoke_reason' ) );
	}
	public function test_won_dispute_restores_only_dispute_revoked_passes_and_keeps_codes(): void {
		$this->service_context();
		$this->issue( 3 );
		$codes = array_column( $GLOBALS['wpdb']->rows, 'code' );
		// A host removed the third pass by hand before the dispute.
		$GLOBALS['wpdb']->rows[2]['status'] = 'revoked';
		$this->revoke( 'dispute' );
		$this->assertSame( array( 'revoked', 'revoked', 'revoked' ), array_column( $GLOBALS['wpdb']->rows, 'status' ) );

		$this->assertSame( array( 'restored' => 2 ), $this->restore() );
		$this->assertSame( array( 'active', 'active', 'revoked' ), array_column( $GLOBALS['wpdb']->rows, 'status' ) );
		$this->assertSame( $codes, array_column( $GLOBALS['wpdb']->rows, 'code' ), 'Restored passes keep their codes.' );
		$this->assertNull( $GLOBALS['wpdb']->rows[0]['revoked_at'] );
		$this->assertNull( $GLOBALS['wpdb']->rows[0]['revoke_reason'] );
		$this->assertSame( array( $codes[0], $codes[1] ), $GLOBALS['ticket_emails'] );
		$this->assertSame( 'buyer@example.com', $GLOBALS['ticket_email_args'][0][0] );
		$this->assertStringContainsString( 'works again', $GLOBALS['ticket_email_args'][0][2] );

		// Replay: nothing left to restore, no second email.
		$this->assertSame( array( 'restored' => 0 ), $this->restore() );
		$this->assertCount( 2, $GLOBALS['ticket_emails'] );
	}
	public function test_restore_never_reverses_a_refund(): void {
		$this->service_context();
		$this->issue();
		$this->revoke( 'seller_refund' );
		$this->assertSame( array( 'restored' => 0 ), $this->restore() );
		$this->assertSame( array( 'revoked', 'revoked' ), array_column( $GLOBALS['wpdb']->rows, 'status' ) );
		$this->assertCount( 0, $GLOBALS['ticket_emails'] );
	}
	public function test_restore_after_check_in_dispute_leaves_the_check_in(): void {
		$this->service_context();
		$this->issue();
		$GLOBALS['wpdb']->rows[0]['status'] = 'redeemed';
		$this->revoke( 'dispute' );
		$this->assertSame( array( 'restored' => 0 ), $this->restore( '' ) );
		$this->assertSame( array( 'redeemed', 'active' ), array_column( $GLOBALS['wpdb']->rows, 'status' ) );
	}
}
