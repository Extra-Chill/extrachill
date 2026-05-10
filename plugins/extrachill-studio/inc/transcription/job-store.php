<?php
/**
 * Transcription Job Store
 *
 * Per-site option-backed persistence for transcription job rows. Index lives in
 * `ec_studio_transcription_jobs`, keyed by sweatpants job UUID.
 *
 * Concurrency note: option writes can race under high concurrency. Acceptable
 * for v1 (max ~5 users, ~1-2 jobs/month). If volume grows substantially, swap
 * to a custom table. The public function signatures are stable so the swap is
 * a backend-only refactor.
 *
 * Job row schema:
 *   job_id, attachment_id, attachment_url, user_id, model, diarize,
 *   remove_fillers, target_blog_id, status, created_at, completed_at,
 *   draft_post_id, draft_post_url, error
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.10.0
 */

defined( 'ABSPATH' ) || exit;

const EC_STUDIO_TRANSCRIPTION_JOBS_OPTION = 'ec_studio_transcription_jobs';

/**
 * Read the full jobs index from the option.
 *
 * @since 0.10.0
 *
 * @return array Map of job_id => row.
 */
function ec_studio_transcription_get_all_jobs(): array {
	$jobs = get_option( EC_STUDIO_TRANSCRIPTION_JOBS_OPTION, array() );
	return is_array( $jobs ) ? $jobs : array();
}

/**
 * Upsert a job row. Existing rows are merged with new data.
 *
 * @since 0.10.0
 *
 * @param string $job_id Sweatpants job UUID.
 * @param array  $data   Partial or full row data.
 * @return void
 */
function ec_studio_transcription_save_job( string $job_id, array $data ): void {
	if ( '' === $job_id ) {
		return;
	}

	$jobs            = ec_studio_transcription_get_all_jobs();
	$existing        = isset( $jobs[ $job_id ] ) && is_array( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : array();
	$merged          = array_merge( $existing, $data );
	$merged['job_id'] = $job_id;

	$jobs[ $job_id ] = $merged;

	update_option( EC_STUDIO_TRANSCRIPTION_JOBS_OPTION, $jobs, false );
}

/**
 * Fetch a single job row by job_id.
 *
 * @since 0.10.0
 *
 * @param string $job_id Sweatpants job UUID.
 * @return array|null Job row or null if missing.
 */
function ec_studio_transcription_get_job( string $job_id ): ?array {
	$jobs = ec_studio_transcription_get_all_jobs();
	if ( isset( $jobs[ $job_id ] ) && is_array( $jobs[ $job_id ] ) ) {
		return $jobs[ $job_id ];
	}
	return null;
}

/**
 * List a user's jobs, newest first.
 *
 * @since 0.10.0
 *
 * @param int $user_id User ID.
 * @param int $limit   Max rows to return (hard-capped at 100).
 * @return array List of job rows.
 */
function ec_studio_transcription_list_user_jobs( int $user_id, int $limit = 20 ): array {
	$limit = max( 1, min( 100, $limit ) );
	$jobs  = ec_studio_transcription_get_all_jobs();
	$mine  = array();

	foreach ( $jobs as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		if ( (int) ( $row['user_id'] ?? 0 ) === $user_id ) {
			$mine[] = $row;
		}
	}

	usort(
		$mine,
		static function ( $a, $b ) {
			$at = strtotime( $a['created_at'] ?? '' ) ?: 0;
			$bt = strtotime( $b['created_at'] ?? '' ) ?: 0;
			return $bt <=> $at;
		}
	);

	return array_slice( $mine, 0, $limit );
}

/**
 * Delete a job row.
 *
 * @since 0.10.0
 *
 * @param string $job_id Sweatpants job UUID.
 * @return bool True if a row was removed.
 */
function ec_studio_transcription_delete_job( string $job_id ): bool {
	$jobs = ec_studio_transcription_get_all_jobs();
	if ( ! isset( $jobs[ $job_id ] ) ) {
		return false;
	}

	unset( $jobs[ $job_id ] );
	update_option( EC_STUDIO_TRANSCRIPTION_JOBS_OPTION, $jobs, false );
	return true;
}

/**
 * Garbage-collect job rows older than $age_days.
 *
 * @since 0.10.0
 *
 * @param int $age_days Maximum age in days.
 * @return int Count of rows purged.
 */
function ec_studio_transcription_gc_old_jobs( int $age_days = 30 ): int {
	$age_days = max( 1, $age_days );
	$cutoff   = time() - ( $age_days * DAY_IN_SECONDS );
	$jobs     = ec_studio_transcription_get_all_jobs();
	$purged   = 0;

	foreach ( $jobs as $job_id => $row ) {
		if ( ! is_array( $row ) ) {
			unset( $jobs[ $job_id ] );
			$purged++;
			continue;
		}
		$created = strtotime( $row['created_at'] ?? '' );
		if ( false === $created || $created < $cutoff ) {
			unset( $jobs[ $job_id ] );
			$purged++;
		}
	}

	if ( $purged > 0 ) {
		update_option( EC_STUDIO_TRANSCRIPTION_JOBS_OPTION, $jobs, false );
	}

	return $purged;
}
