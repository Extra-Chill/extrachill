<?php
/**
 * Shared plumbing for `wp extrachill shop` commands.
 *
 * Every shop command is a thin adapter: resolve a Shop-owned ability on the
 * Shop site (OwnerSiteAbility, so run with --url=shop.extrachill.com and an
 * operator --user), execute it, print the result.
 *
 * @package ExtraChill\CLI\Commands\Shop
 */

namespace ExtraChill\CLI\Commands\Shop;

use ExtraChill\CLI\OwnerSiteAbility;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Base for the shop noun commands. */
abstract class ShopCommandBase {

	/**
	 * Resolve a Shop ability.
	 *
	 * @param string $name Ability name.
	 * @return \WP_Ability
	 */
	protected function ability( $name ) {
		return OwnerSiteAbility::get( 'shop', 'Shop', $name );
	}

	/**
	 * Execute a Shop ability, erroring out on WP_Error.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return array
	 */
	protected function run( $name, array $input = array() ) {
		$result = $this->ability( $name )->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Parse a `type:id` seller reference.
	 *
	 * @param string $value Seller reference, e.g. artist:12.
	 * @return array{type: string, id: int}
	 */
	protected function parse_seller( $value ) {
		if ( ! preg_match( '/^(artist|venue|promoter):([1-9][0-9]*)$/', (string) $value, $match ) ) {
			WP_CLI::error( sprintf( 'Invalid seller "%s". Use type:id, e.g. artist:12, venue:1524, promoter:8.', $value ) );
		}
		return array(
			'type' => $match[1],
			'id'   => (int) $match[2],
		);
	}

	/**
	 * Parse a positive integer argument.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $label Label for the error message.
	 * @return int
	 */
	protected function positive_int( $value, $label ) {
		if ( ! is_numeric( $value ) || (int) $value < 1 || (string) (int) $value !== (string) $value ) {
			WP_CLI::error( sprintf( '%s must be a positive integer.', $label ) );
		}
		return (int) $value;
	}

	/**
	 * Whether --format=json was requested.
	 *
	 * @param array  $assoc_args Associative args.
	 * @param string $fallback   Default format.
	 * @return bool
	 */
	protected function wants_json( array $assoc_args, $fallback = 'table' ) {
		return 'json' === Utils\get_flag_value( $assoc_args, 'format', $fallback );
	}

	/**
	 * Print a value as pretty JSON.
	 *
	 * @param mixed $value Value.
	 */
	protected function print_json( $value ) {
		WP_CLI::line( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Print key/value rows as a two-column table.
	 *
	 * @param array $pairs Field => value.
	 */
	protected function print_fields( array $pairs ) {
		$rows = array();
		foreach ( $pairs as $field => $value ) {
			$rows[] = array(
				'field' => $field,
				'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value,
			);
		}
		Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}
