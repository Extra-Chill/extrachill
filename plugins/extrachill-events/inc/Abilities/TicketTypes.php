<?php
/**
 * Ticket types (tiers) and codes on an event's ticket offer (extrachill#1716).
 *
 * A show sells one or more ticket types, each with its own name, price and
 * optional quantity, inside the show's overall capacity. Codes either take
 * money off (a percent or a fixed amount per ticket) or unlock hidden types
 * (an access code is a code with no discount that lists a hidden type), with
 * an optional cap on how many tickets a code can buy.
 *
 * Offers saved before types existed have none stored. They read back as one
 * DEFAULT type at the single price, so old shows and their passes keep
 * working without a migration.
 *
 * @package ExtraChillEvents
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\RsvpPassesTable;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for ticket types and codes. Storage stays the ticket offer meta.
 */
final class TicketTypes {

	/** Most ticket types one show may sell. */
	const MAX_TYPES = 10;

	/** Most codes one show may carry. */
	const MAX_CODES = 50;

	/** Allowed ticket type ID. */
	const TYPE_ID_PATTERN = '^[a-z0-9][a-z0-9-]{0,39}$';

	/** Allowed code, after it is upper-cased. */
	const CODE_PATTERN = '^[A-Z0-9][A-Z0-9_-]{2,31}$';

	/**
	 * Input schema for the stored ticket types.
	 *
	 * @return array
	 */
	public static function types_schema(): array {
		return array(
			'type'     => 'array',
			'maxItems' => self::MAX_TYPES,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					// Optional on input: a new type takes an ID from its name.
					'id'          => array(
						'type'    => 'string',
						'pattern' => self::TYPE_ID_PATTERN,
					),
					'name'        => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 60,
					),
					'price_minor' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					// 0 means no separate cap: the show's ticket quantity and capacity still apply.
					'quantity'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					// Hidden types only show to buyers who enter a code that lists them.
					'hidden'      => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'name', 'price_minor' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Input schema for the stored codes.
	 *
	 * @return array
	 */
	public static function codes_schema(): array {
		return array(
			'type'     => 'array',
			'maxItems' => self::MAX_CODES,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'code'             => array(
						'type'      => 'string',
						'minLength' => 3,
						'maxLength' => 32,
					),
					// Use at most one of percent_off and amount_off_minor. Neither makes an access code.
					'percent_off'      => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 99,
					),
					'amount_off_minor' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					// Tickets the code may buy in total; 0 for no limit.
					'max_uses'         => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					// Type IDs the code works on. Empty means every visible type.
					'ticket_types'     => array(
						'type'  => 'array',
						'items' => array(
							'type'    => 'string',
							'pattern' => self::TYPE_ID_PATTERN,
						),
					),
				),
				'required'             => array( 'code' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * The ticket types a show sells, legacy single-price offers included.
	 *
	 * @param array $settings Ticket settings.
	 * @return array[] Each `{ id, name, price_minor, quantity, hidden }`.
	 */
	public static function effective( array $settings ): array {
		$types = is_array( $settings['ticket_types'] ?? null ) ? $settings['ticket_types'] : array();
		if ( $types ) {
			return array_values( $types );
		}
		$price = (int) ( $settings['ticket_price_minor'] ?? 0 );
		if ( $price < 1 ) {
			return array();
		}
		return array(
			array(
				'id'          => RsvpPassesTable::DEFAULT_TICKET_TYPE,
				'name'        => 'General admission',
				'price_minor' => $price,
				'quantity'    => 0,
				'hidden'      => false,
			),
		);
	}

	/**
	 * One type by ID.
	 *
	 * @param array  $settings Ticket settings.
	 * @param string $id       Type ID.
	 * @return array|null
	 */
	public static function find( array $settings, string $id ): ?array {
		foreach ( self::effective( $settings ) as $type ) {
			if ( $type['id'] === $id ) {
				return $type;
			}
		}
		return null;
	}

	/**
	 * Upper-case and trim a code the way it is stored.
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	public static function normalize_code( $code ): string {
		return strtoupper( trim( is_string( $code ) ? $code : '' ) );
	}

	/**
	 * A stored code by its text, matched case-insensitively.
	 *
	 * @param array $settings Ticket settings.
	 * @param mixed $code     Code the buyer entered.
	 * @return array|null
	 */
	public static function find_code( array $settings, $code ): ?array {
		$code = self::normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		foreach ( (array) ( $settings['ticket_codes'] ?? array() ) as $stored ) {
			if ( is_array( $stored ) && hash_equals( (string) $stored['code'], $code ) ) {
				return $stored;
			}
		}
		return null;
	}

	/**
	 * Whether a code works on a type. Hidden types need the code to name them.
	 *
	 * @param array $code Stored code.
	 * @param array $type Ticket type.
	 * @return bool
	 */
	public static function code_applies( array $code, array $type ): bool {
		$listed = (array) ( $code['ticket_types'] ?? array() );
		if ( ! $listed ) {
			return empty( $type['hidden'] );
		}
		return in_array( $type['id'], $listed, true );
	}

	/**
	 * Price of one ticket of a type after a code, never below zero.
	 *
	 * @param array      $type Ticket type.
	 * @param array|null $code Stored code, or null for none.
	 * @return int
	 */
	public static function unit_price( array $type, ?array $code ): int {
		$price = (int) $type['price_minor'];
		if ( ! $code || ! self::code_applies( $code, $type ) ) {
			return $price;
		}
		$percent = (int) ( $code['percent_off'] ?? 0 );
		$amount  = (int) ( $code['amount_off_minor'] ?? 0 );
		if ( $percent > 0 ) {
			$price -= (int) round( $price * $percent / 100 );
		} elseif ( $amount > 0 ) {
			$price -= $amount;
		}
		return max( 0, $price );
	}

	/**
	 * Types a buyer can see: public ones, plus hidden ones the code unlocks.
	 *
	 * @param array      $settings Ticket settings.
	 * @param array|null $code     Stored code, or null.
	 * @return array[]
	 */
	public static function visible( array $settings, ?array $code ): array {
		return array_values(
			array_filter(
				self::effective( $settings ),
				static function ( array $type ) use ( $code ): bool {
					return empty( $type['hidden'] ) || ( $code && self::code_applies( $code, $type ) );
				}
			)
		);
	}

	/**
	 * Validate and normalize submitted types.
	 *
	 * New types take an ID from their name. Prices must be positive; a
	 * hidden type needs a code to be bought, which validate_codes() allows.
	 *
	 * @param mixed $types Submitted types.
	 * @return array[]|\WP_Error
	 */
	public static function validate_types( $types ) {
		if ( ! is_array( $types ) || count( $types ) > self::MAX_TYPES ) {
			return self::error( 'invalid_ticket_types', sprintf( 'A show can sell up to %d ticket types.', self::MAX_TYPES ) );
		}
		$out = array();
		foreach ( array_values( $types ) as $type ) {
			$name  = is_array( $type ) ? trim( sanitize_text_field( (string) ( $type['name'] ?? '' ) ) ) : '';
			$price = is_array( $type ) ? ( $type['price_minor'] ?? null ) : null;
			$qty   = is_array( $type ) ? ( $type['quantity'] ?? 0 ) : 0;
			if ( '' === $name || mb_strlen( $name ) > 60 || ! is_int( $price ) || $price < 1 || ! is_int( $qty ) || $qty < 0 ) {
				return self::error( 'invalid_ticket_types', 'Each ticket type needs a name and a price above zero.' );
			}
			$id = (string) ( $type['id'] ?? '' );
			if ( '' === $id ) {
				$id = self::id_from_name( $name, array_keys( $out ) );
			}
			if ( ! preg_match( '/' . self::TYPE_ID_PATTERN . '/', $id ) || isset( $out[ $id ] ) ) {
				return self::error( 'invalid_ticket_types', 'Each ticket type needs its own ID.' );
			}
			$out[ $id ] = array(
				'id'          => $id,
				'name'        => $name,
				'price_minor' => $price,
				'quantity'    => $qty,
				'hidden'      => ! empty( $type['hidden'] ),
			);
		}
		return array_values( $out );
	}

	/**
	 * Validate and normalize submitted codes against the show's types.
	 *
	 * @param mixed   $codes Submitted codes.
	 * @param array[] $types Effective ticket types.
	 * @return array[]|\WP_Error
	 */
	public static function validate_codes( $codes, array $types ) {
		if ( ! is_array( $codes ) || count( $codes ) > self::MAX_CODES ) {
			return self::error( 'invalid_ticket_codes', sprintf( 'A show can have up to %d codes.', self::MAX_CODES ) );
		}
		$type_ids = array_column( $types, 'id' );
		$out      = array();
		foreach ( array_values( $codes ) as $code ) {
			$text = self::normalize_code( is_array( $code ) ? ( $code['code'] ?? '' ) : '' );
			if ( ! preg_match( '/' . self::CODE_PATTERN . '/', $text ) ) {
				return self::error( 'invalid_ticket_codes', 'Codes are 3 to 32 letters, numbers, dashes or underscores.' );
			}
			if ( isset( $out[ $text ] ) ) {
				return self::error( 'invalid_ticket_codes', sprintf( 'The code %s is listed twice.', $text ) );
			}
			$percent = $code['percent_off'] ?? 0;
			$amount  = $code['amount_off_minor'] ?? 0;
			$uses    = $code['max_uses'] ?? 0;
			if ( ! is_int( $percent ) || $percent < 0 || $percent > 99 || ! is_int( $amount ) || $amount < 0 || ! is_int( $uses ) || $uses < 0 ) {
				return self::error( 'invalid_ticket_codes', sprintf( 'The code %s has an invalid discount or limit. Percent discounts go up to 99; free entry goes on the guest list.', $text ) );
			}
			if ( $percent > 0 && $amount > 0 ) {
				return self::error( 'invalid_ticket_codes', sprintf( 'The code %s can take a percent or an amount off, not both.', $text ) );
			}
			$listed = array_values( array_unique( array_map( 'strval', (array) ( $code['ticket_types'] ?? array() ) ) ) );
			if ( array_diff( $listed, $type_ids ) ) {
				return self::error( 'invalid_ticket_codes', sprintf( 'The code %s names a ticket type this show does not sell.', $text ) );
			}
			$out[ $text ] = array(
				'code'             => $text,
				'percent_off'      => $percent,
				'amount_off_minor' => $amount,
				'max_uses'         => $uses,
				'ticket_types'     => $listed,
			);
		}
		return array_values( $out );
	}

	/**
	 * Ticket type ID from its name, unique among the IDs taken so far.
	 *
	 * @param string   $name  Type name.
	 * @param string[] $taken IDs already used.
	 * @return string
	 */
	public static function id_from_name( string $name, array $taken ): string {
		$base = trim( substr( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $name ) ) ), 0, 36 ), '-' );
		$base = '' === $base ? 'tickets' : $base;
		$id   = $base;
		$n = 2;
		while ( in_array( $id, $taken, true ) ) {
			$id = $base . '-' . $n;
			++$n;
		}
		return $id;
	}

	/**
	 * A 400 error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
