<?php
/**
 * `wp extrachill shop fees` — over extrachill/get-commerce-fee-override,
 * extrachill/set-commerce-fee-override and extrachill/clear-commerce-fee-override.
 *
 * @package ExtraChill\CLI\Commands\Shop
 */

namespace ExtraChill\CLI\Commands\Shop;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seller and event commerce fee overrides.
 *
 * The network fee settings are the default. A seller (type:id) or an event
 * (event:<id>) can override some fields; an event wins over its seller.
 * Network admins only.
 */
class FeesCommand extends ShopCommandBase {

	/**
	 * Show a seller or event fee override with the global and resolved rates.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : event:<event ID>, or a seller as type:id (artist:12, venue:1524, promoter:8).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shop fees get event:4521 --url=shop.extrachill.com --user=1
	 *     wp extrachill shop fees get venue:1524 --url=shop.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->show( $this->run( 'extrachill/get-commerce-fee-override', $this->target( $args[0] ?? '' ) ), $assoc_args );
	}

	/**
	 * Override some fee rates for a seller or an event.
	 *
	 * Fields not given keep their current override. Pass `inherit` to stop
	 * overriding a field. Percentages are percent (2.9); `*_minor` fields are
	 * cents. Applies to checkouts started afterwards; placed orders keep their fee.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : event:<event ID>, or a seller as type:id (artist:12, venue:1524, promoter:8).
	 *
	 * [--zero]
	 * : Set every fee field to 0 (Extra Chill takes nothing). Other flags still apply on top.
	 *
	 * [--<field>=<value>]
	 * : A fee field (merch_percent, ticket_percent, ticket_buyer_fee_per_ticket_minor,
	 * ticket_buyer_fee_flat_minor, ticket_buyer_fee_percent, processing_percent,
	 * processing_fixed_minor) and a number, or `inherit`.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shop fees set event:4521 --zero --url=shop.extrachill.com --user=1
	 *     wp extrachill shop fees set venue:1524 --ticket_percent=2 --merch_percent=inherit --url=shop.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function set( $args, $assoc_args ) {
		$input = $this->target( $args[0] ?? '' );
		$known = array_keys( (array) ( $this->ability( 'extrachill/set-commerce-fee-override' )->get_input_schema()['properties']['fees']['properties'] ?? array() ) );
		$fees  = Utils\get_flag_value( $assoc_args, 'zero', false ) ? array_fill_keys( $known, 0 ) : array();
		foreach ( array_diff_key( $assoc_args, array_flip( array( 'zero', 'yes', 'format' ) ) ) as $field => $value ) {
			if ( ! in_array( $field, $known, true ) ) {
				WP_CLI::error( sprintf( 'Unknown fee field --%s. Fields: %s.', $field, implode( ', ', $known ) ) );
			}
			$is_minor = '_minor' === substr( $field, -6 );
			if ( 'inherit' === $value ) {
				$fees[ $field ] = null;
			} elseif ( ! is_numeric( $value ) || (float) $value < 0 ) {
				WP_CLI::error( sprintf( '--%s must be a number of at least 0, or inherit.', $field ) );
			} elseif ( $is_minor && (string) (int) $value !== (string) $value ) {
				WP_CLI::error( sprintf( '--%s is in cents: give a whole number.', $field ) );
			} else {
				$fees[ $field ] = $is_minor ? (int) $value : (float) $value;
			}
		}
		if ( array() === $fees ) {
			WP_CLI::error( 'Give --zero or at least one --<field>=<value>.' );
		}
		$input['fees'] = $fees;

		$changes = array();
		foreach ( $fees as $field => $value ) {
			$changes[] = $field . '=' . ( null === $value ? 'inherit' : $value );
		}
		WP_CLI::confirm( sprintf( 'Set the %s fee override: %s?', $args[0], implode( ', ', $changes ) ), $assoc_args );
		$this->show( $this->run( 'extrachill/set-commerce-fee-override', $input ), $assoc_args );
		WP_CLI::success( sprintf( 'Fee override saved for %s.', $args[0] ) );
	}

	/**
	 * Remove a seller or event fee override, so every rate inherits again.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : event:<event ID>, or a seller as type:id (artist:12, venue:1524, promoter:8).
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shop fees clear event:4521 --url=shop.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ) {
		$input = $this->target( $args[0] ?? '' );
		WP_CLI::confirm( sprintf( 'Clear the %s fee override so every rate inherits?', $args[0] ), $assoc_args );
		$this->show( $this->run( 'extrachill/clear-commerce-fee-override', $input ), $assoc_args );
		WP_CLI::success( sprintf( 'Fee override cleared for %s.', $args[0] ) );
	}

	/**
	 * Parse `event:<id>` or a `type:id` seller into ability input.
	 *
	 * @param string $value Target.
	 * @return array
	 */
	private function target( $value ) {
		if ( preg_match( '/^event:(.*)$/', (string) $value, $match ) ) {
			return array(
				'scope'    => 'event',
				'event_id' => $this->positive_int( $match[1], 'Event ID' ),
			);
		}
		return array(
			'scope'  => 'seller',
			'seller' => $this->parse_seller( $value ),
		);
	}

	/**
	 * Print the override, the global and the resolved rates.
	 *
	 * @param array $result     Ability output.
	 * @param array $assoc_args Associative args.
	 */
	private function show( array $result, array $assoc_args ) {
		if ( $this->wants_json( $assoc_args ) ) {
			$this->print_json( $result );
			return;
		}
		if ( 'event' === ( $result['scope'] ?? '' ) ) {
			$seller = (array) ( $result['seller'] ?? array() );
			WP_CLI::log( sprintf( 'Resolved for event %d with ticket seller %s.', (int) ( $result['event_id'] ?? 0 ), $seller ? $seller['type'] . ':' . $seller['id'] : '(none)' ) );
		}
		$override = (array) ( $result['override'] ?? array() );
		$global   = (array) ( $result['global'] ?? array() );
		$resolved = (array) ( $result['resolved'] ?? array() );
		$rows     = array();
		foreach ( $resolved as $field => $value ) {
			$rows[] = array(
				'field'    => $field,
				'global'   => (string) ( $global[ $field ] ?? '' ),
				'override' => array_key_exists( $field, $override ) ? (string) $override[ $field ] : 'inherit',
				'resolved' => (string) $value,
			);
		}
		Utils\format_items( 'table', $rows, array( 'field', 'global', 'override', 'resolved' ) );
	}
}
