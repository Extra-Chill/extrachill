<?php
/**
 * Backfill artist Link Page identity snapshots.
 *
 * @package ExtraChillArtistPlatform
 */

namespace ExtraChillArtistPlatform;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the Artist_Link_Pages snapshot for every artist-owned Link Page
 * that predates the owner definition. CLI-only; idempotent; never touches a
 * page that already has a snapshot.
 */
final class Artist_Link_Page_Snapshot_Migration {

	/** Data Machine migration ID. */
	public const ID = 'extrachill-artist-platform.link-page-snapshots';

	/** Read-only inventory of artist pages and missing snapshots. */
	public static function inspect(): array {
		$missing = self::missing_link_page_ids();
		if ( is_wp_error( $missing ) ) {
			return array(
				'status' => 'error',
				'error'  => $missing->get_error_message(),
			);
		}
		return array(
			'status'  => $missing ? 'pending' : 'complete',
			'missing' => count( $missing ),
		);
	}

	/**
	 * Backfill from WP-CLI; any other context is refused without writes.
	 *
	 * @param int $limit Maximum pages to backfill; 0 means all.
	 */
	public static function apply( int $limit = 0 ): array {
		if ( true !== ( defined( 'WP_CLI' ) ? constant( 'WP_CLI' ) : false ) ) {
			return array(
				'status'   => 'blocked',
				'success'  => false,
				'complete' => false,
				'error'    => 'Run this migration from WP-CLI.',
			);
		}
		return self::backfill( $limit );
	}

	/**
	 * Refresh snapshots for up to `$limit` pages lacking one.
	 *
	 * @param int $limit Maximum pages to backfill; 0 means all.
	 */
	private static function backfill( int $limit ): array {
		$missing = self::missing_link_page_ids();
		if ( is_wp_error( $missing ) ) {
			return array(
				'status'   => 'error',
				'success'  => false,
				'complete' => false,
				'error'    => $missing->get_error_message(),
			);
		}
		$batch     = $limit > 0 ? array_slice( $missing, 0, $limit ) : $missing;
		$refreshed = array();
		$failed    = array();
		foreach ( $batch as $link_page_id ) {
			$result = self::refresh( $link_page_id );
			if ( true === $result ) {
				$refreshed[] = $link_page_id;
			} else {
				$failed[ $link_page_id ] = $result;
			}
		}
		$state    = self::inspect();
		$complete = 'complete' === $state['status'];
		return array_merge(
			$state,
			array(
				'success'   => ! $failed,
				'complete'  => $complete,
				'refreshed' => $refreshed,
				'failed'    => $failed,
			)
		);
	}

	/** Every artist page has a snapshot. */
	public static function isComplete(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Data Machine migration contract.
		return 'complete' === self::inspect()['status'];
	}

	/**
	 * Refresh one page's snapshot through the definition's authorized path.
	 *
	 * @param int $link_page_id Canonical Link Page ID.
	 * @return true|string True, or the failure code.
	 */
	private static function refresh( int $link_page_id ) {
		$owner = ec_with_link_page_storage_blog(
			static function () use ( $link_page_id ) {
				return ec_get_link_page_owner( $link_page_id );
			}
		);
		if ( is_wp_error( $owner ) ) {
			return (string) $owner->get_error_code();
		}
		$result = Artist_Link_Pages::refresh_snapshot( (int) $owner['object_id'] );
		if ( is_wp_error( $result ) ) {
			return (string) $result->get_error_code();
		}
		return is_array( $result ) ? true : 'artist_link_page_snapshot_refresh_failed';
	}

	/**
	 * Artist-owned Link Pages on the storage blog that lack a snapshot.
	 *
	 * @return int[]|\WP_Error
	 */
	private static function missing_link_page_ids() {
		if ( ! class_exists( Artist_Link_Pages::class ) || ! function_exists( 'ec_with_link_page_storage_blog' ) ) {
			return new \WP_Error( 'link_pages_runtime_unavailable', 'The Link Pages runtime is not loaded.' );
		}
		$blog_id = (int) ec_get_blog_id( 'artist' );
		if ( ! $blog_id ) {
			return new \WP_Error( 'artist_blog_unavailable', 'The Artist site is unavailable.' );
		}
		$prefix = 'post:' . $blog_id . ':' . Artist_Link_Pages::KIND . ':';
		return ec_with_link_page_storage_blog(
			static function () use ( $prefix ) {
				$ids     = get_posts(
					array(
						'post_type'              => ec_link_page_post_type(),
						'post_status'            => 'any',
						'posts_per_page'         => -1,
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_term_cache' => false,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-off CLI backfill inventory.
						'meta_query'             => array(
							'relation' => 'AND',
							array(
								'key'     => EC_LINK_PAGE_OWNER_META_KEY,
								'value'   => $prefix,
								'compare' => 'LIKE',
							),
							array(
								'key'     => Artist_Link_Pages::SNAPSHOT_META_KEY,
								'compare' => 'NOT EXISTS',
							),
						),
					)
				);
				$missing = array();
				foreach ( $ids as $id ) {
					$reference = (string) get_post_meta( (int) $id, EC_LINK_PAGE_OWNER_META_KEY, true );
					if ( 0 === strpos( $reference, $prefix ) ) {
						$missing[] = (int) $id;
					}
				}
				sort( $missing );
				return $missing;
			}
		);
	}
}

add_filter(
	'datamachine_migrations',
	static function ( array $migrations ): array {
		if ( ! class_exists( \DataMachine\Core\Database\Migrations\Migration::class ) ) {
			return $migrations;
		}
		$migrations[] = new class() extends \DataMachine\Core\Database\Migrations\Migration {
			public function id(): string {
				return Artist_Link_Page_Snapshot_Migration::ID;
			}
			public function description(): string {
				return 'Write the identity snapshot for every artist Link Page that predates Artist_Link_Pages.';
			}
			public function inspect(): array {
				return Artist_Link_Page_Snapshot_Migration::inspect();
			}
			public function apply( int $limit ): array {
				return Artist_Link_Page_Snapshot_Migration::apply( $limit );
			}
			public function isComplete(): bool {
				return Artist_Link_Page_Snapshot_Migration::isComplete();
			}
		};
		return $migrations;
	}
);
