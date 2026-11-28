<?php
/**
 * Entity-scoped partner credentials. Never add this meta to the public venue profile map.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

final class VenuePartnerKey {

	public const META_KEY   = '_ec_venue_partner_key';
	public const META_USAGE = '_ec_venue_partner_key_usage';

	/** Manage credentials on the events site, restoring the caller's blog on every path. */
	public static function manage( int $venue_id, string $operation ) {
		return self::on_events_site( static function () use ( $venue_id, $operation ) {
			if ( ! in_array( $operation, array( 'issue', 'rotate', 'revoke', 'status' ), true ) ) {
				return new \WP_Error( 'extrachill_partner_key_operation', 'Unknown operation.', array( 'status' => 400 ) );
			}
			$authorized = ( new TeamAuthorization() )->authorize( TeamAuthorization::effective_user_id(), 'venue', $venue_id, TeamAuthorization::ACTION_MANAGE_MEMBERS );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}
			$record = get_term_meta( $venue_id, self::META_KEY, true );
			if ( 'status' === $operation ) {
				return self::public_status( $venue_id, $record );
			}
			if ( 'revoke' === $operation ) {
				if ( ! delete_term_meta( $venue_id, self::META_KEY ) && ! empty( $record ) ) {
					return self::storage_error();
				}
				return self::public_status( $venue_id, array() );
			}
			if ( 'issue' === $operation && ! empty( $record['hash'] ) ) {
				return new \WP_Error( 'extrachill_partner_key_exists', 'An active key exists. Rotate it explicitly.', array( 'status' => 409 ) );
			}
			$key    = 'ecpk_' . $venue_id . '_' . bin2hex( random_bytes( 32 ) );
			$record = array(
				'hash'         => self::digest( $key ),
				'created_at'   => gmdate( 'c' ),
				'issued_by'    => TeamAuthorization::effective_user_id(),
				'last_four'    => substr( $key, -4 ),
				'last_used_at' => null,
			);
			$saved  = update_term_meta( $venue_id, self::META_KEY, $record );
			if ( false === $saved || is_wp_error( $saved ) ) {
				return self::storage_error();
			}
			return array_merge( self::public_status( $venue_id, $record ), array( 'key' => $key ) );
		} );
	}

	/** Verify the entire credential, including its venue prefix, with a timing-safe comparison. */
	public static function verify( string $key ) {
		if ( '' === $key ) {
			return self::invalid();
		}
		if ( ! preg_match( '/^ecpk_([1-9][0-9]*)_([a-f0-9]{64})$/D', $key, $matches ) ) {
			// Partner contract: every unusable key, malformed included, is one 401.
			return self::invalid();
		}
		$venue_id = (int) $matches[1];
		return self::on_events_site( static function () use ( $key, $venue_id ) {
			$record   = get_term_meta( $venue_id, self::META_KEY, true );
			$expected = $record['hash'] ?? str_repeat( '0', 64 );
			if ( ! hash_equals( $expected, self::digest( $key ) ) || empty( $record['hash'] ) ) {
				return self::invalid();
			}
			$venue = get_term( $venue_id, 'venue' );
			if ( ! $venue || is_wp_error( $venue ) ) {
				return self::invalid();
			}
			// Usage has separate storage: a concurrent revoke/rotate must never be
			// undone by a last-used write carrying a stale credential hash.
			$usage = get_term_meta( $venue_id, self::META_USAGE, true );
			if ( ( $usage['hash'] ?? '' ) !== $expected || strtotime( $usage['last_used_at'] ?? '' ) <= time() - 300 ) {
				update_term_meta( $venue_id, self::META_USAGE, array(
					'hash'         => $expected,
					'last_used_at' => gmdate( 'c' ),
				) );
			}
			return $venue_id;
		} );
	}

	private static function digest( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	private static function public_status( int $venue_id, $record ): array {
		$usage     = get_term_meta( $venue_id, self::META_USAGE, true );
		$last_used = ! empty( $record['hash'] ) && ( $usage['hash'] ?? '' ) === $record['hash'] ? ( $usage['last_used_at'] ?? null ) : null;
		return array(
			'venue_term_id' => $venue_id,
			'active'        => ! empty( $record['hash'] ),
			'created_at'    => $record['created_at'] ?? null,
			'issued_by'     => $record['issued_by'] ?? null,
			'last_four'     => $record['last_four'] ?? null,
			'last_used_at'  => $last_used,
		);
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'extrachill_partner_key_invalid', 'Invalid partner key.', array( 'status' => 401 ) );
	}

	private static function storage_error(): \WP_Error {
		return new \WP_Error( 'extrachill_partner_key_storage', 'Could not persist credential change.', array( 'status' => 500 ) );
	}

	private static function on_events_site( callable $callback ) {
		$blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 7;
		$switch  = get_current_blog_id() !== $blog_id;
		if ( $switch ) {
			switch_to_blog( $blog_id );
		}
		try {
			return $callback();
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}
}
