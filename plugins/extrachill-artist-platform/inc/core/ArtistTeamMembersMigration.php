<?php
/**
 * Non-destructive adoption of legacy artist membership meta.
 *
 * @package ExtraChillArtistPlatform
 */

namespace ExtraChill\ArtistPlatform;

use ExtraChill\Users\Teams\TeamMembershipRepository;

defined( 'ABSPATH' ) || exit;

/** Copies the union of the two legacy directions without retiring meta. */
final class ArtistTeamMembersMigration {
	public const COPIED_OPTION = 'extrachill_artist_platform_artist_team_members_copied';

	/** Copy only once after deployment; failed copies remain retryable. */
	public static function maybe_copy_legacy(): void {
		if ( ! get_site_option( self::COPIED_OPTION ) && true === self::copy_legacy() ) {
			update_site_option( self::COPIED_OPTION, 1 );
		}
	}

	/** Read both directions, including orphan pairs, without modifying meta. */
	private static function pairs() {
		global $wpdb;
		$blog_id = ec_get_blog_id( 'artist' );
		if ( ! $blog_id || ! class_exists( TeamMembershipRepository::class ) ) {
			return new \WP_Error( 'artist_membership_dependency_missing', 'Artist membership storage is unavailable.' );
		}
		$postmeta = $wpdb->get_blog_prefix( $blog_id ) . 'postmeta';
		$directions = array();
		foreach ( array( 'user' => array( $wpdb->usermeta, 'user_id', '_artist_profile_ids' ), 'artist' => array( $postmeta, 'post_id', '_artist_member_ids' ) ) as $direction => $source ) {
			list( $table, $column, $key ) = $source;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$column} AS object_id, meta_value FROM {$table} WHERE meta_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB -- Fixed schema identifiers and prepared key; read-only migration.
			if ( $wpdb->last_error ) {
				return new \WP_Error( 'artist_membership_read_failed', $wpdb->last_error );
			}
			foreach ( (array) $rows as $row ) {
				$ids = maybe_unserialize( $row['meta_value'] );
				foreach ( is_array( $ids ) ? $ids : array() as $id ) {
					$user_id   = absint( 'user' === $direction ? $row['object_id'] : $id );
					$artist_id = absint( 'artist' === $direction ? $row['object_id'] : $id );
					if ( $user_id && $artist_id ) {
						$pair_key = $artist_id . ':' . $user_id;
						$directions[ $pair_key ]['artist_id'] = $artist_id;
						$directions[ $pair_key ]['user_id'] = $user_id;
						$directions[ $pair_key ][ $direction ] = true;
					}
				}
			}
		}
		ksort( $directions );
		return $directions;
	}

	/** INSERT IGNORE preserves existing decisions, including revoked rows. */
	public static function copy_legacy() {
		global $wpdb;
		$pairs = self::pairs();
		if ( is_wp_error( $pairs ) ) {
			return $pairs;
		}
		$table = TeamMembershipRepository::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( $pairs as $pair ) {
			$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (team_type, team_id, user_id, is_owner, status, version, created_by_user_id, created_at, updated_at) VALUES ('artist', %d, %d, 1, 'active', 1, 0, %s, %s)", $pair['artist_id'], $pair['user_id'], $now, $now ) ); // phpcs:ignore WordPress.DB -- Natural key makes rollout idempotent.
			if ( false === $result ) {
				return new \WP_Error( 'artist_membership_copy_failed', $wpdb->last_error );
			}
		}
		return true;
	}

	/** Read-only parity report. */
	public static function inspect(): array {
		global $wpdb;
		$pairs = self::pairs();
		if ( is_wp_error( $pairs ) ) {
			return array( 'status' => 'error', 'error' => $pairs->get_error_message() );
		}
		$repository = new TeamMembershipRepository();
		$mismatches = array();
		$missing    = 0;
		foreach ( $pairs as $pair ) {
			if ( empty( $pair['user'] ) || empty( $pair['artist'] ) ) {
				$mismatches[] = $pair;
			}
			$row = $repository->get( 'artist', $pair['artist_id'], $pair['user_id'] );
			if ( is_wp_error( $row ) ) {
				return array( 'status' => 'error', 'error' => $row->get_error_message() );
			}
			if ( ! $row || 'active' !== $row['status'] ) {
				++$missing;
			}
		}
		$table = TeamMembershipRepository::table();
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE team_type = 'artist'" ); // phpcs:ignore WordPress.DB -- Read-only migration count.
		if ( $wpdb->last_error ) {
			return array( 'status' => 'error', 'error' => $wpdb->last_error );
		}
		return array( 'status' => $missing ? 'pending' : 'complete', 'meta_pairs' => count( $pairs ), 'rows' => (int) $count, 'one_direction_pairs' => $mismatches, 'missing' => $missing );
	}

	/** Operators must explicitly run from CLI; never delete legacy meta. */
	public static function apply(): array {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return array( 'success' => false, 'complete' => false, 'error' => 'Run this migration from WP-CLI.' );
		}
		$copy = self::copy_legacy();
		if ( is_wp_error( $copy ) ) {
			return array( 'success' => false, 'complete' => false, 'error' => $copy->get_error_message() );
		}
		$state    = self::inspect();
		$complete = 'complete' === $state['status'];
		return array( 'success' => $complete, 'complete' => $complete, 'state' => $state );
	}
}

add_action( 'init', array( ArtistTeamMembersMigration::class, 'maybe_copy_legacy' ), 0 );
add_filter( 'datamachine_migrations', static function ( array $migrations ): array {
	if ( ! class_exists( \DataMachine\Core\Database\Migrations\Migration::class ) ) {
		return $migrations;
	}
	$migrations[] = new class() extends \DataMachine\Core\Database\Migrations\Migration {
		public function id(): string { return 'extrachill-artist-platform.artist-team-members'; }
		public function description(): string { return 'Copy and verify legacy artist memberships without deleting meta.'; }
		public function inspect(): array { return ArtistTeamMembersMigration::inspect(); }
		public function apply( int $limit ): array { return ArtistTeamMembersMigration::apply(); }
		public function isComplete(): bool { return 'complete' === ArtistTeamMembersMigration::inspect()['status']; }
	};
	return $migrations;
} );
