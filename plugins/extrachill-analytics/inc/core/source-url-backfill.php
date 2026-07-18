<?php
/**
 * Historical analytics source URL redaction.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redact stored event source URLs without exposing their values in output.
 *
 * @param array $args Backfill arguments.
 * @return array{scanned:int,redacted:int,invalid:int,batches:int,live:bool}
 */
function extrachill_analytics_redact_source_urls( $args = array() ) {
	global $wpdb;

	$args = array_merge(
		array(
			'batch_size' => 1000,
			'live'       => false,
			'progress'   => null,
		),
		(array) $args
	);

	$batch_size = max( 1, (int) $args['batch_size'] );
	$live       = (bool) $args['live'];
	$progress   = is_callable( $args['progress'] ) ? $args['progress'] : null;
	$table      = extrachill_analytics_events_table();
	$last_id    = 0;
	$totals     = array(
		'scanned'  => 0,
		'redacted' => 0,
		'invalid'  => 0,
		'batches'  => 0,
		'live'     => $live,
	);

	do {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql  = "SELECT id, source_url FROM {$table} WHERE source_url <> '' AND id > %d ORDER BY id ASC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $last_id, $batch_size ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			break;
		}

		foreach ( $rows as $row ) {
			$last_id   = (int) $row->id;
			$canonical = extrachill_analytics_canonicalize_tracked_url( (string) $row->source_url );
			++$totals['scanned'];

			if ( $canonical === (string) $row->source_url ) {
				continue;
			}

			++$totals['redacted'];
			if ( '' === $canonical ) {
				++$totals['invalid'];
			}

			if ( $live ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bounded privacy backfill.
				$wpdb->update( $table, array( 'source_url' => $canonical ), array( 'id' => $last_id ), array( '%s' ), array( '%d' ) );
			}
		}

		++$totals['batches'];
		if ( $progress ) {
			call_user_func( $progress, $totals );
		}
		$row_count = count( $rows );
	} while ( $row_count === $batch_size );

	return $totals;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Redact historical event source URLs. Dry-run unless --live is supplied.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Command flags.
	 * @return void
	 */
	function extrachill_analytics_cli_redact_source_urls( $pos_args, $assoc_args ) {
		unset( $pos_args );
		$live       = isset( $assoc_args['live'] );
		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 1000;
		$result     = extrachill_analytics_redact_source_urls(
			array(
				'live'       => $live,
				'batch_size' => $batch_size,
			)
		);

		WP_CLI::log(
			sprintf(
				'%s: scanned %d rows; %d require redaction; %d invalid values become empty.',
				$live ? 'Complete' : 'Dry run',
				$result['scanned'],
				$result['redacted'],
				$result['invalid']
			)
		);
		WP_CLI::success( $live ? 'Historical source URLs redacted.' : 'Re-run with --live to apply.' );
	}

	WP_CLI::add_command( 'extrachill-analytics redact-source-urls', 'extrachill_analytics_cli_redact_source_urls' );
}
