<?php
/** Retire empty site-scoped onboarding storage. @package ExtraChillEvents */
namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** CLI-only retirement; rows are never copied or discarded. */
final class NetworkTeamInvitationsMigration {
	/** Read-only inventory of the Events blog's old tables. */
	public static function inspect(): array {
		global $wpdb;
		$blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 0;
		if ( ! $blog_id ) {
			return array(
				'status' => 'error',
				'error'  => 'Events blog is unavailable.',
			);
		}
		$prefix = $wpdb->get_blog_prefix( $blog_id );
		if ( $prefix === $wpdb->base_prefix ) {
			return array(
				'status' => 'error',
				'error'  => 'Legacy and network table prefixes overlap.',
			);
		}
		$tables = array();
		$status = 'complete';
		foreach ( array( 'ec_team_invitations', 'ec_team_claims' ) as $suffix ) {
			$table  = $prefix . $suffix;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
			if ( $wpdb->last_error ) {
				return array(
					'status' => 'error',
					'error'  => $wpdb->last_error,
				);
			}
			$count = $exists ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI migration inventory.
			if ( $wpdb->last_error || null === $count ) {
				return array(
					'status' => 'error',
					'error'  => ( '' !== (string) $wpdb->last_error ? $wpdb->last_error : 'Could not count legacy rows.' ),
				);
			}
			$tables[ $table ] = array(
				'exists' => $exists,
				'rows'   => (int) $count,
			);
			if ( $exists ) {
				$status = 'pending';
			}
		}
		foreach ( $tables as $state ) {
			if ( $state['rows'] ) {
				$status = 'blocked';
			}
		}
		return array(
			'status' => $status,
			'tables' => $tables,
		);
	}

	/** Refuse the whole operation if either legacy table contains rows. */
	public static function apply(): array {
		global $wpdb;
		if ( true !== ( defined( 'WP_CLI' ) ? constant( 'WP_CLI' ) : false ) ) {
			return array(
				'status'   => 'blocked',
				'success'  => false,
				'complete' => false,
				'error'    => 'Run this migration from WP-CLI.',
			);
		}
		$state = self::inspect();
		if ( in_array( $state['status'], array( 'error', 'blocked' ), true ) ) {
			return array_merge( $state, array(
				'success'  => false,
				'complete' => false,
			) );
		}
		foreach ( $state['tables'] as $table => $info ) {
			if ( $info['exists'] && false === $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) ) ) {
				return array(
					'status'   => 'error',
					'success'  => false,
					'complete' => false,
					'error'    => $wpdb->last_error,
				);
			}
		}
		$state    = self::inspect();
		$complete = 'complete' === $state['status'];
		return array_merge( $state, array(
			'success'  => $complete,
			'complete' => $complete,
		) );
	}

	/** Both old tables must be absent. */
	public static function isComplete(): bool {
		return 'complete' === self::inspect()['status'];
	}
}

add_filter( 'datamachine_migrations', static function ( array $migrations ): array {
	if ( ! class_exists( \DataMachine\Core\Database\Migrations\Migration::class ) ) {
		return $migrations;
	}
	$migrations[] = new class() extends \DataMachine\Core\Database\Migrations\Migration {
		public function id(): string { return 'extrachill-events.network-team-invitations'; }
		public function description(): string { return 'Retire empty Events-site team invitation and claim tables.'; }
		public function inspect(): array { return NetworkTeamInvitationsMigration::inspect(); }
		public function apply( int $limit ): array { return NetworkTeamInvitationsMigration::apply(); } // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Migration interface signature; retirement is a single pass.
		public function isComplete(): bool { return NetworkTeamInvitationsMigration::isComplete(); }
	};
	return $migrations;
} );
