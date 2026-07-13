<?php
/**
 * Orphaned usermeta cleanup tooling.
 *
 * On multisite a single shared usermeta table backs every site. WordPress core
 * only sweeps a deleted user's usermeta rows on the single-site code path
 * (see wp_delete_user() in wp-admin/includes/user.php). The multisite path
 * delegates to remove_user_from_blog(), which removes per-blog meta keys but
 * leaves the global usermeta rows in place, and a row deleted directly from
 * the users table (or via a failed/legacy import) leaves its usermeta behind.
 * This tool finds and optionally removes those orphaned rows.
 *
 * @package ExtraChill\Users
 */

/**
 * Find and optionally delete usermeta rows whose user_id no longer exists.
 *
 * User ID 0 is excluded on purpose: WordPress and several plugins use 0 as a
 * legitimate sentinel (anonymous/session-scoped meta), so rows for it are not
 * ghosts even though 0 is never present in the users table.
 *
 * @param bool          $apply       Whether to delete orphaned rows. Defaults to false (dry run).
 * @param int           $batch_size  Rows deleted per batch when applying. Clamped to a minimum of 1.
 * @param callable|null $on_batch    Optional progress reporter invoked as `$on_batch( $batch, $deleted, $running_total )`.
 * @return array|WP_Error Summary on success, or WP_Error on a database failure.
 */
function extrachill_users_cleanup_orphan_usermeta( bool $apply = false, int $batch_size = 500, ?callable $on_batch = null ) {
	global $wpdb;

	$batch_size = max( 1, $batch_size );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are interpolated per house style.
	$orphan_condition = "user_id <> 0 AND user_id NOT IN ( SELECT ID FROM {$wpdb->users} )";

	$ghosts = $wpdb->get_results(
		"SELECT user_id, COUNT( * ) AS row_count FROM {$wpdb->usermeta} WHERE {$orphan_condition} GROUP BY user_id ORDER BY row_count DESC",
		ARRAY_A
	);
	if ( ! is_array( $ghosts ) ) {
		return new WP_Error( 'extrachill_users_orphan_meta_query_failed', 'Failed to query orphaned usermeta.' );
	}

	$ghost_count    = count( $ghosts );
	$orphan_rows    = array_sum( array_map( 'intval', wp_list_pluck( $ghosts, 'row_count' ) ) );
	$ghosts_display = array_slice( $ghosts, 0, 100 );
	array_walk(
		$ghosts_display,
		static function ( &$ghost ) {
			$ghost['user_id']   = absint( $ghost['user_id'] );
			$ghost['row_count'] = absint( $ghost['row_count'] );
		}
	);

	$top_meta_keys = $wpdb->get_results(
		"SELECT meta_key, COUNT( * ) AS count FROM {$wpdb->usermeta} WHERE {$orphan_condition} GROUP BY meta_key ORDER BY count DESC LIMIT 20",
		ARRAY_A
	);
	if ( ! is_array( $top_meta_keys ) ) {
		return new WP_Error( 'extrachill_users_orphan_meta_query_failed', 'Failed to query orphaned meta keys.' );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared.

	$result = array(
		'applied'          => $apply,
		'ghost_count'      => $ghost_count,
		'orphan_rows'      => $orphan_rows,
		'ghosts'           => $ghosts_display,
		'ghosts_truncated' => $ghost_count > count( $ghosts_display ),
		'top_meta_keys'    => $top_meta_keys,
		'deleted'          => 0,
		'batches'          => 0,
		'batch_size'       => $batch_size,
		'excluded_user_id' => '0 (legitimate WordPress sentinel, not a ghost)',
	);

	if ( ! $apply || $orphan_rows < 1 ) {
		return $result;
	}

	// Batched delete. The condition is re-evaluated each batch so a user
	// re-created with a reclaimed ID mid-run keeps its rows, and new ghosts
	// surface only on the next run (keeps the operation idempotent).
	$deleted_total = 0;
	$batches       = 0;
	do {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orphan_condition is a static SQL fragment; table name interpolated per house style.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE {$orphan_condition} LIMIT %d", $batch_size ) );
		if ( false === $deleted ) {
			$message = $wpdb->last_error ? $wpdb->last_error : 'Failed to delete orphaned usermeta rows.';
			return new WP_Error(
				'extrachill_users_orphan_meta_delete_failed',
				$message
			);
		}
		$deleted_total += (int) $deleted;
		++$batches;
		if ( $deleted > 0 && null !== $on_batch ) {
			$on_batch( $batches, (int) $deleted, $deleted_total );
		}
	} while ( $deleted > 0 );

	$result['deleted'] = $deleted_total;
	$result['batches'] = $batches;

	return $result;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Audit (dry-run) or delete orphaned usermeta rows from deleted users.
	 *
	 * Network-wide: targets the shared {$wpdb->usermeta} table, finding rows
	 * whose user_id has no matching entry in {$wpdb->users}. Dry-run by default.
	 */
	$extrachill_users_cleanup_orphan_meta_command = static function ( $args, $assoc_args ) {
		$apply      = isset( $assoc_args['apply'] );
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 500;
		if ( $batch_size < 1 ) {
			$batch_size = 500;
		}

		$result = extrachill_users_cleanup_orphan_usermeta(
			$apply,
			$batch_size,
			static function ( $batch, $deleted, $running_total ) {
				WP_CLI::log( sprintf( 'Deleted batch %d: %d rows (running total %d).', $batch, $deleted, $running_total ) );
			}
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( $result['ghost_count'] > 0 ) {
			WP_CLI\Utils\format_items(
				'table',
				$result['ghosts'],
				array( 'user_id', 'row_count' )
			);

			WP_CLI\Utils\format_items(
				'table',
				$result['top_meta_keys'],
				array( 'meta_key', 'count' )
			);
		}

		if ( $result['ghosts_truncated'] ) {
			WP_CLI::warning( sprintf( 'Showing top 100 of %d ghost user IDs.', $result['ghost_count'] ) );
		}

		$headline = $result['applied']
			? sprintf( 'Apply complete: deleted %d row(s) across %d batch(es).', $result['deleted'], $result['batches'] )
			: 'Dry run — no rows deleted. Re-run with --apply to delete.';

		WP_CLI::success(
			sprintf(
				'%s Ghost user IDs: %d. Orphaned usermeta rows: %d. Excluded user_id 0 (legitimate WordPress sentinel).',
				$headline,
				$result['ghost_count'],
				$result['orphan_rows']
			)
		);
	};

	WP_CLI::add_command(
		'extrachill-users cleanup-orphan-meta',
		$extrachill_users_cleanup_orphan_meta_command,
		array(
			'shortdesc' => 'Audit (dry-run) or delete orphaned usermeta rows from deleted users. Network-wide.',
			'synopsis'  => array(
				array(
					'type'        => 'flag',
					'name'        => 'apply',
					'description' => 'Delete orphaned rows in batches. Omit for a read-only dry run.',
					'optional'    => true,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'batch-size',
					'description' => 'Rows deleted per batch when --apply is used. Default 500.',
					'optional'    => true,
					'default'     => '500',
				),
			),
		)
	);
}
