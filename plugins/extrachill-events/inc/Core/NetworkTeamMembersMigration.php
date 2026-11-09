<?php
/** Network membership adoption and operator retirement. @package ExtraChillEvents\Core */
namespace ExtraChillEvents\Core;

use ExtraChill\Users\Teams\TeamMembershipRepository;

defined( 'ABSPATH' ) || exit;

/** Copies legacy membership without changing its public identity. */
final class NetworkTeamMembersMigration {
	/** Fixed production source, independent of the current blog. */
	public static function legacy_table(): string {
		global $wpdb;
		return $wpdb->get_blog_prefix( 7 ) . 'ec_team_members';
	}

	/** Whether the source still exists. */
	public static function exists(): bool {
		global $wpdb;
		$table = self::legacy_table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB -- Uncached migration state.
	}

	/** Network option recording that the deploy-time copy has completed. */
	public const COPIED_OPTION = 'extrachill_events_network_team_members_copied';

	/**
	 * Copy once per deploy rather than on every request.
	 *
	 * Nothing writes the legacy table any more, so one successful copy is
	 * complete; the operator migration re-copies under lock before dropping it.
	 */
	public static function maybe_copy_legacy(): void {
		if ( get_site_option( self::COPIED_OPTION ) ) {
			return;
		}
		if ( true === self::copy_legacy() ) {
			update_site_option( self::COPIED_OPTION, 1 );
		}
	}

	/** Idempotent deploy-time copy; never drops storage. */
	public static function copy_legacy() {
		global $wpdb;
		if ( ! self::exists() ) {
			return true;
		}
		$source  = self::legacy_table();
		$target  = TeamMembershipRepository::table();
		$columns = 'team_type, team_id, user_id, is_owner, status, version, created_by_user_id, created_at, updated_at, revoked_by_user_id, revoked_at';
		$select  = str_replace( 'team_id', 'team_term_id', $columns );
		if ( false === $wpdb->query( "INSERT IGNORE INTO {$target} ({$columns}) SELECT {$select} FROM {$source}" ) ) { // phpcs:ignore WordPress.DB -- Fixed schema identifiers; natural keys preserve identity.
			return new \WP_Error( 'team_members_copy_failed', $wpdb->last_error );
		}
		return true;
	}

	/** Read-only verification of every persisted value, not merely the natural key. */
	public static function inspect(): array {
		global $wpdb;
		if ( ! self::exists() ) {
			return array(
				'status'  => 'complete',
				'missing' => 0,
			);
		}
		$source  = self::legacy_table();
		$target  = TeamMembershipRepository::table();
		$matches = array( 'target.team_id = source.team_term_id' );
		foreach ( array( 'team_type', 'user_id', 'is_owner', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_at', 'revoked_by_user_id', 'revoked_at' ) as $column ) {
			$matches[] = "target.{$column} <=> source.{$column}";
		}
		$on      = implode( ' AND ', $matches );
		$missing = $wpdb->get_var( "SELECT COUNT(*) FROM {$source} AS source LEFT JOIN {$target} AS target ON {$on} WHERE target.id IS NULL" ); // phpcs:ignore WordPress.DB -- Fixed identifiers; inspection never writes.
		if ( '' !== (string) $wpdb->last_error || null === $missing ) {
			return array(
				'status' => 'error',
				'error'  => $wpdb->last_error,
			);
		}
		return array(
			'status'  => 'pending',
			'missing' => (int) $missing,
		);
	}

	/** CLI-only retirement, with writes excluded between copy, verification and drop. */
	public static function apply(): array {
		global $wpdb;
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return array(
				'success'  => false,
				'complete' => false,
				'error'    => 'Run this migration from WP-CLI.',
			);
		}
		if ( ! self::exists() ) {
			return array(
				'success'  => true,
				'complete' => true,
			);
		}
		$source = self::legacy_table();
		$target = TeamMembershipRepository::table();
		// MySQL requires each alias used by verification to be locked explicitly.
		if ( false === $wpdb->query( "LOCK TABLES {$source} WRITE, {$target} WRITE, {$source} AS source WRITE, {$target} AS target WRITE" ) ) { // phpcs:ignore WordPress.DB -- Operator-only exclusive cutover.
			return array(
				'success'  => false,
				'complete' => false,
				'error'    => $wpdb->last_error,
			);
		}
		try {
			$copy  = self::copy_legacy();
			$state = self::inspect();
			if ( is_wp_error( $copy ) || 'pending' !== $state['status'] || 0 !== $state['missing'] ) {
				return array(
					'success'  => false,
					'complete' => false,
					'state'    => $state,
				);
			}
			$dropped = false !== $wpdb->query( "DROP TABLE {$source}" ); // phpcs:ignore WordPress.DB -- Every source value verified under exclusive lock.
			return array(
				'success'  => $dropped,
				'complete' => $dropped,
			);
		} finally {
			$wpdb->query( 'UNLOCK TABLES' ); // phpcs:ignore WordPress.DB -- Release operator cutover locks on every path.
		}
	}
}
