<?php
/**
 * Transcription GC Cron
 *
 * Daily WP cron event that purges transcription job rows older than 30 days.
 * The hook itself is unscheduled in extrachill_studio_deactivate().
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.10.0
 */

defined( 'ABSPATH' ) || exit;

const EC_STUDIO_TRANSCRIPTION_GC_HOOK = 'ec_studio_transcription_daily_gc';

/**
 * Schedule the daily GC event if it isn't already scheduled.
 *
 * @since 0.10.0
 *
 * @return void
 */
function ec_studio_transcription_schedule_gc(): void {
	if ( ! wp_next_scheduled( EC_STUDIO_TRANSCRIPTION_GC_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', EC_STUDIO_TRANSCRIPTION_GC_HOOK );
	}
}
add_action( 'init', 'ec_studio_transcription_schedule_gc' );

/**
 * Cron handler — purges jobs older than 30 days.
 *
 * @since 0.10.0
 *
 * @return void
 */
function ec_studio_transcription_run_gc(): void {
	$purged = ec_studio_transcription_gc_old_jobs( 30 );
	if ( $purged > 0 ) {
		error_log( sprintf( '[ExtraChillStudio][Transcription] GC purged %d jobs older than 30 days', $purged ) );
	}
}
add_action( EC_STUDIO_TRANSCRIPTION_GC_HOOK, 'ec_studio_transcription_run_gc' );
