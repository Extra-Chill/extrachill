<?php
/** Paid ticket offers and service-only fulfillment. */
namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\RsvpPassesTable;

defined( 'ABSPATH' ) || exit;

class TicketAbilities {
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public static function fields(): array {
		return array(
			'tickets_enabled' => array( 'type' => 'boolean' ),
			'ticket_price_minor' => array( 'type' => 'integer', 'minimum' => 0 ),
			'ticket_currency' => array( 'type' => 'string', 'pattern' => '^[a-z]{3}$' ),
			'ticket_quantity' => array( 'type' => 'integer', 'minimum' => 0 ),
			'ticket_seller' => array( 'type' => 'object', 'properties' => array( 'type' => array( 'type' => 'string', 'enum' => array( 'venue', 'promoter' ) ), 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'required' => array( 'type', 'id' ), 'additionalProperties' => false ),
		);
	}

	public static function settings( int $id ): array {
		$stored = get_post_meta( $id, '_extrachill_ticket_offer', true );
		return array_merge( array( 'tickets_enabled' => false, 'ticket_price_minor' => 0, 'ticket_currency' => 'usd', 'ticket_quantity' => 0, 'ticket_seller' => array( 'type' => 'venue', 'id' => 0 ) ), is_array( $stored ) ? $stored : array() );
	}

	/** Validate before any perk writes, including finance authority on seller changes. */
	public static function validate_settings( int $id, array $input ) {
		$settings = array_merge( self::settings( $id ), array_intersect_key( $input, self::fields() ) );
		if ( isset( $input['ticket_seller'] ) ) {
			if ( ! extrachill_events_user_can_manage_event( get_current_user_id(), $id ) ) {
				return new \WP_Error( 'ticket_seller_forbidden', 'Event management is required.', array( 'status' => 403 ) );
			}
			$seller = ( new CommerceSellerAbilities( null, null, false ) )->execute( $input['ticket_seller'] );
			if ( is_wp_error( $seller ) ) { return $seller; }
			if ( empty( $seller['can_manage_finances'] ) ) {
				return new \WP_Error( 'ticket_seller_forbidden', 'Seller finance authority is required.', array( 'status' => 403 ) );
			}
		}
		if ( $settings['ticket_price_minor'] < 0 || $settings['ticket_quantity'] < 0 || ! preg_match( '/^[a-z]{3}$/', $settings['ticket_currency'] ) || ( $settings['tickets_enabled'] && ( $settings['ticket_price_minor'] <= 0 || empty( $settings['ticket_seller']['id'] ) ) ) ) {
			return new \WP_Error( 'invalid_ticket_offer', 'Enabled tickets require a positive price and a seller.', array( 'status' => 400 ) );
		}
		return $settings;
	}

	public function register(): void {
		$string = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 );
		$event = array( 'event' => array( 'type' => array( 'string', 'integer' ) ) );
		$definitions = array(
			'get-event-ticket-offer' => array( 'offer', $event, array( 'event' ), true ),
			'issue-ticket-passes' => array( 'issue', $event + array( 'quantity' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ), 'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ), 'email' => array( 'type' => 'string', 'format' => 'email' ), 'idempotency_key' => $string, 'order_reference' => $string, 'payment_reference' => $string ), array( 'event', 'quantity', 'name', 'email', 'idempotency_key', 'order_reference', 'payment_reference' ), false ),
			'get-ticket-order-status' => array( 'order_status', array( 'order_reference' => $string ), array( 'order_reference' ), false ),
			'revoke-ticket-passes' => array( 'revoke', array( 'order_reference' => $string, 'reason' => $string ), array( 'order_reference', 'reason' ), false ),
		);
		$pass = array( 'type' => 'object', 'properties' => array( 'code' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ) ), 'required' => array( 'code', 'url' ) );
		$outputs = array(
			'offer' => array( 'event_id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ), 'enabled' => array( 'type' => 'boolean' ), 'price_minor' => array( 'type' => 'integer' ), 'currency' => array( 'type' => 'string' ), 'remaining' => array( 'type' => array( 'integer', 'null' ) ), 'sale_open' => array( 'type' => 'boolean' ), 'seller' => self::fields()['ticket_seller'] ),
			'issue' => array( 'status' => array( 'type' => 'string', 'enum' => array( 'issued', 'sold_out' ) ), 'passes' => array( 'type' => 'array', 'items' => $pass ) ),
			'order_status' => array( 'event_id' => array( 'type' => 'integer' ), 'passes' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'code' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string', 'enum' => array( 'active', 'redeemed', 'revoked' ) ) ), 'required' => array( 'code', 'status' ) ) ), 'any_redeemed' => array( 'type' => 'boolean' ), 'refundable' => array( 'type' => 'boolean' ) ),
			'revoke' => array( 'revoked' => array( 'type' => 'integer' ) ),
		);
		// An unconfigured offer has seller ID zero, not a selectable seller.
		$outputs['offer']['seller']['properties']['id']['minimum'] = 0;
		foreach ( $definitions as $name => $definition ) {
			wp_register_ability( 'extrachill/' . $name, array(
				'label' => $name, 'description' => $name, 'category' => 'extrachill-events',
				'input_schema' => array( 'type' => 'object', 'properties' => $definition[1], 'required' => $definition[2], 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'properties' => $outputs[$definition[0]], 'required' => array_keys( $outputs[$definition[0]] ) ),
				'execute_callback' => array( $this, $definition[0] ),
				'permission_callback' => $definition[3] ? '__return_true' : array( $this, 'authorize' ),
				'meta' => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => in_array( $definition[0], array( 'offer', 'order_status' ), true ), 'idempotent' => true, 'destructive' => 'revoke' === $definition[0] ) ),
			) );
		}
	}

	public function authorize() {
		return extrachill_events_ticket_has_service_authority() ? true : new \WP_Error( 'ticket_service_required', 'Verified ticket service authority is required.', array( 'status' => 403 ) );
	}

	private function resolve( $reference ) {
		$post = is_numeric( $reference ) ? get_post( (int) $reference ) : get_page_by_path( (string) $reference, OBJECT, 'data_machine_events' );
		return $post && 'data_machine_events' === $post->post_type && 'publish' === $post->post_status ? $post : null;
	}

	public function offer( array $input ) {
		$post = $this->resolve( $input['event'] ?? '' );
		if ( ! $post ) { return new \WP_Error( 'event_not_found', 'Event not found.', array( 'status' => 404 ) ); }
		$settings = self::settings( $post->ID );
		$capacity = extrachill_events_get_perk_capacity( $post->ID );
		$remaining = $capacity > 0 ? max( 0, $capacity - RsvpPassesTable::count_for_event( $post->ID )['claimed'] ) : null;
		if ( $settings['ticket_quantity'] > 0 ) {
			global $wpdb;
			$table = RsvpPassesTable::table_name();
			$paid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND order_reference IS NOT NULL AND status IN ('active','redeemed')", $post->ID ) );
			$ticket_remaining = max( 0, $settings['ticket_quantity'] - $paid );
			$remaining = null === $remaining ? $ticket_remaining : min( $remaining, $ticket_remaining );
		}
		// Event dates live in the Data Machine Events dates table, not post meta.
		// Sales stay open through an ongoing show for walk-ups.
		$upcoming = function_exists( 'datamachine_get_event_timing' ) && 'past' !== datamachine_get_event_timing( (int) $post->ID );
		return array( 'event_id' => (int) $post->ID, 'title' => $post->post_title, 'url' => get_permalink( $post->ID ), 'enabled' => (bool) $settings['tickets_enabled'], 'price_minor' => (int) $settings['ticket_price_minor'], 'currency' => $settings['ticket_currency'], 'remaining' => $remaining, 'sale_open' => $settings['tickets_enabled'] && $upcoming && ( null === $remaining || $remaining > 0 ), 'seller' => $settings['ticket_seller'] );
	}

	public function issue( array $input ) {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$offer = $this->offer( $input );
		if ( is_wp_error( $offer ) ) { return $offer; }
		$existing = RsvpPassesTable::ticket_rows( $input['idempotency_key'], true );
		if ( ! $existing && ! $offer['sale_open'] ) { return array( 'status' => 'sold_out', 'passes' => array() ); }
		$settings = self::settings( $offer['event_id'] );
		$rows = RsvpPassesTable::issue_tickets( $offer['event_id'], $input['quantity'], extrachill_events_get_perk_capacity( $offer['event_id'] ), $settings['ticket_quantity'], sanitize_text_field( $input['name'] ), $input['idempotency_key'], $input['order_reference'], $input['payment_reference'] );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$passes = array();
		foreach ( (array) $rows as $row ) {
			$url = extrachill_events_guest_list_pass_link( $offer['event_id'], $row['code'], $row['guest_name'] );
			$passes[] = array( 'code' => $row['code'], 'url' => $url );
			if ( ! $existing ) { extrachill_events_send_guest_list_pass_email( $offer['event_id'], $input['email'], $row['code'], $row['guest_name'] ); }
		}
		return array( 'status' => null === $rows ? 'sold_out' : 'issued', 'passes' => $passes );
	}

	public function order_status( array $input ) {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$rows = RsvpPassesTable::ticket_rows( $input['order_reference'] );
		$redeemed = in_array( 'redeemed', array_column( $rows, 'status' ), true );
		return array( 'event_id' => (int) ( $rows[0]['event_id'] ?? 0 ), 'passes' => array_map( static function ( $row ) { return array( 'code' => $row['code'], 'status' => $row['status'] ); }, $rows ), 'any_redeemed' => $redeemed, 'refundable' => ! $redeemed );
	}

	public function revoke( array $input ) {
		$allowed = $this->authorize();
		return is_wp_error( $allowed ) ? $allowed : RsvpPassesTable::revoke_ticket_order( $input['order_reference'] );
	}
}
