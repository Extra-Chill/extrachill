<?php
/**
 * Extra Chill configuration for Data Machine Events.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Point cross-site event queries at the canonical events site.
 *
 * @param int $blog_id Data Machine Events default blog ID.
 * @return int Canonical events blog ID when available.
 */
function extrachill_events_configure_events_blog_id( int $blog_id ): int {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return $blog_id;
	}

	$events_blog_id = (int) ec_get_blog_id( 'events' );
	return $events_blog_id > 0 ? $events_blog_id : $blog_id;
}
add_filter( 'data_machine_events_events_blog_id', 'extrachill_events_configure_events_blog_id' );

/**
 * Attribute automated imports to the network bot account.
 *
 * @param int   $author_id      Data Machine Events fallback author ID.
 * @param array $handler_config Event handler configuration.
 * @param mixed $engine         Data Machine engine snapshot helper.
 * @return int Network bot user ID when available.
 */
function extrachill_events_configure_import_author( int $author_id, array $handler_config, $engine ): int {
	unset( $handler_config, $engine );

	if ( ! function_exists( 'ec_get_network_bot_user_id' ) ) {
		return $author_id;
	}

	$bot_user_id = (int) ec_get_network_bot_user_id();
	return $bot_user_id > 0 ? $bot_user_id : $author_id;
}
add_filter( 'data_machine_events_fallback_author_id', 'extrachill_events_configure_import_author', 10, 3 );

/**
 * Keep high-volume events automation retention bounded.
 *
 * @param int $days Data Machine default retention period.
 * @return int Retention period for the events workload.
 */
function extrachill_events_configure_action_retention( int $days ): int {
	unset( $days );

	return 2;
}
add_filter( 'datamachine_as_actions_max_age_days', 'extrachill_events_configure_action_retention' );
add_filter( 'datamachine_log_max_age_days', 'extrachill_events_configure_action_retention' );

/**
 * Keep completed and failed event-job history for two weeks.
 *
 * @param int $days Data Machine default retention period.
 * @return int Retention period for the events workload.
 */
function extrachill_events_configure_job_retention( int $days ): int {
	unset( $days );

	return 14;
}
add_filter( 'datamachine_completed_jobs_max_age_days', 'extrachill_events_configure_job_retention' );
add_filter( 'datamachine_failed_jobs_max_age_days', 'extrachill_events_configure_job_retention' );
add_filter( 'datamachine_processed_items_max_age_days', 'extrachill_events_configure_job_retention' );
