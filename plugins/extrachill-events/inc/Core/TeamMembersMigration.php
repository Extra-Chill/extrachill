<?php
/**
 * Retire the per-type venue and promoter membership tables.
 *
 * Run from WP-CLI on the Events site:
 * `wp datamachine migrate run --id=extrachill-events.team-members --url=<events site>`.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use DataMachine\Core\Database\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

/** Re-copies any legacy rows, proves none are missing, then drops the legacy tables. */
final class TeamMembersMigration extends Migration {

	/** Stable migration identifier. */
	public function id(): string {
		return 'extrachill-events.team-members';
	}

	/** One-line operator description. */
	public function description(): string {
		return 'Drop the retired per-type (venue, promoter) membership tables once every row is in the shared team table.';
	}

	/** Report legacy row counts and rows not yet in the team table, without writing. */
	public function inspect( bool $copy = false ): array {
		if ( ! VenueSchema::is_ready() ) {
			return array(
				'status' => 'blocked',
				'error'  => 'The venue schema and its team table are not installed yet.',
			);
		}
		$tables = VenueSchema::adopt_legacy_memberships( $copy );
		if ( is_wp_error( $tables ) ) {
			return array(
				'status' => 'error',
				'error'  => $tables->get_error_message(),
			);
		}
		$remaining = count( array_filter( array_column( $tables, 'exists' ) ) );
		return array(
			'status'    => $remaining ? 'pending' : 'complete',
			'remaining' => $remaining,
			'tables'    => $tables,
		);
	}

	/** Drop each legacy table whose every row is present in the team table. */
	public function apply( int $limit ): array {
		global $wpdb;
		unset( $limit );
		$inspect = $this->inspect( true );
		if ( 'pending' !== $inspect['status'] ) {
			return $inspect + array(
				'success'  => 'complete' === $inspect['status'],
				'complete' => 'complete' === $inspect['status'],
			);
		}
		$dropped = array();
		foreach ( $inspect['tables'] as $table => $state ) {
			if ( ! $state['exists'] ) {
				continue;
			}
			if ( $state['missing'] > 0 ) {
				return array(
					'success'  => false,
					'complete' => false,
					'error'    => sprintf( '%d row(s) from %s are not in the team table; nothing was dropped.', $state['missing'], $table ),
				);
			}
			if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed legacy identifier, verified fully copied.
				return array(
					'success'  => false,
					'complete' => false,
					'error'    => $wpdb->last_error,
					'dropped'  => $dropped,
				);
			}
			$dropped[] = $table;
		}
		return array(
			'success'  => true,
			'complete' => true,
			'dropped'  => $dropped,
		);
	}

	/** Complete once neither legacy table exists. */
	public function isComplete(): bool {
		global $wpdb;
		foreach ( VenueSchema::legacy_membership_tables() as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration state cannot be cached.
				return false;
			}
		}
		return true;
	}
}
