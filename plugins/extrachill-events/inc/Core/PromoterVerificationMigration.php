<?php
/** Migrate legacy promoter verification to term state. @package ExtraChillEvents */
namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** CLI-only, restartable migration of Events-blog verification state. */
final class PromoterVerificationMigration {
	/** Read-only inventory; never installs or changes storage. */
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
		$table  = $prefix . 'ec_promoter_organizations';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		if ( $wpdb->last_error ) {
			return array(
				'status' => 'error',
				'error'  => $wpdb->last_error,
			);
		}
		$missing = $exists ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i AS organization WHERE NOT EXISTS (SELECT 1 FROM %i AS state WHERE state.term_id = organization.promoter_term_id AND state.meta_key = %s)', $table, $prefix . 'termmeta', '_ec_promoter_status' ) ) : 0;
		if ( null === $missing ) {
			return array(
				'status' => 'error',
				'error'  => 'Could not count unmigrated promoter organizations.',
			);
		}
		return array(
			'status'       => $exists ? 'pending' : 'complete',
			'blog_id'      => (int) $blog_id,
			'table'        => $table,
			'exists'       => $exists,
			'missing_meta' => (int) $missing,
		);
	}

	/** Copy audit data before publishing status; retries preserve migrated terms. */
	public static function apply(): array {
		global $wpdb;
		if ( true !== ( defined( 'WP_CLI' ) ? constant( 'WP_CLI' ) : false ) ) {
			return self::failure( 'Run this migration from WP-CLI.', 'blocked' );
		}
		$state = self::inspect();
		if ( 'error' === $state['status'] ) {
			return array_merge( $state, array(
				'success'  => false,
				'complete' => false,
			) );
		}
		if ( ! $state['exists'] ) {
			return array_merge( $state, array(
				'success'  => true,
				'complete' => true,
			) );
		}
		// Staged rollout safety: the legacy writer must be removed before retirement.
		if ( class_exists( __NAMESPACE__ . '\\PromoterAuthoritySchema' ) ) {
			return self::failure( 'Deploy the term-meta authority repository before retiring legacy storage.', 'blocked' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY promoter_term_id ASC', $state['table'] ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return self::failure( 'Could not read legacy promoter organizations.' );
		}
		switch_to_blog( $state['blog_id'] );
		try {
			foreach ( $rows as $row ) {
				$term_id = (int) $row['promoter_term_id'];
				if ( metadata_exists( 'term', $term_id, '_ec_promoter_status' ) ) {
					continue;
				}
				$term = get_term( $term_id, 'promoter' );
				if ( ! $term || is_wp_error( $term ) || ! in_array( $row['status'], array( 'active', 'revoked' ), true ) ) {
					return self::failure( 'Invalid legacy promoter identity or status; table preserved.' );
				}
				$verification = array(
					'version'             => (int) $row['version'],
					'verified_by_user_id' => (int) $row['verified_by_user_id'],
					'verified_at'         => $row['verified_at'],
					'updated_at'          => $row['updated_at'],
					'revoked_by_user_id'  => empty( $row['revoked_by_user_id'] ) ? null : (int) $row['revoked_by_user_id'],
					'revoked_at'          => empty( $row['revoked_at'] ) ? null : $row['revoked_at'],
				);
				update_term_meta( $term_id, '_ec_promoter_verification', $verification );
				if ( get_term_meta( $term_id, '_ec_promoter_verification', true ) !== $verification ) {
					return self::failure( 'Could not persist promoter verification; table preserved.' );
				}
				$result = add_term_meta( $term_id, '_ec_promoter_status', $row['status'], true );
				if ( ! $result || is_wp_error( $result ) ) {
					return self::failure( 'Could not persist promoter status; table preserved.' );
				}
			}
		} finally {
			restore_current_blog();
		}
		$remaining = self::inspect();
		if ( 'error' === $remaining['status'] || 0 !== $remaining['missing_meta'] ) {
			return self::failure( 'Unmigrated promoter rows remain; table preserved.' );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $state['table'] ) ) ) {
			return self::failure( 'Could not drop legacy promoter organizations.' );
		}
		$state    = self::inspect();
		$complete = 'complete' === $state['status'];
		return array_merge( $state, array(
			'success'  => $complete,
			'complete' => $complete,
		) );
	}

	/** Completion means the legacy table no longer exists. */
	public static function isComplete(): bool {
		return 'complete' === self::inspect()['status'];
	}

	/** Preserve legacy storage on every failure. */
	private static function failure( string $message, string $status = 'error' ): array {
		return array(
			'status'   => $status,
			'success'  => false,
			'complete' => false,
			'error'    => $message,
		);
	}
}

add_filter( 'datamachine_migrations', static function ( array $migrations ): array {
	if ( ! class_exists( \DataMachine\Core\Database\Migrations\Migration::class ) ) {
		return $migrations;
	}
	$migrations[] = new class() extends \DataMachine\Core\Database\Migrations\Migration {
		public function id(): string { return 'extrachill-events.promoter-verification'; }
		public function description(): string { return 'Move promoter verification into Events-blog term metadata and retire legacy storage.'; }
		public function inspect(): array { return PromoterVerificationMigration::inspect(); }
		public function apply( int $limit ): array { return PromoterVerificationMigration::apply(); } // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Migration interface signature; one bounded organization inventory.
		public function isComplete(): bool { return PromoterVerificationMigration::isComplete(); }
	};
	return $migrations;
} );
