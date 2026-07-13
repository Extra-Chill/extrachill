<?php
/**
 * Verify topic replies use bbPress's paginated flat display.
 *
 * Run with: wp eval-file scripts/smoke-topic-reply-pagination.php
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( false !== bbp_thread_replies() ) {
	WP_CLI::error( 'Threaded reply rendering is still enabled.' );
}

if ( bbp_get_replies_per_page() < 1 ) {
	WP_CLI::error( 'The bbPress replies-per-page setting is invalid.' );
}

if ( false !== apply_filters( 'bbp_thread_replies', true, 2, true ) ) {
	WP_CLI::error( 'The community pagination filter does not override threaded rendering.' );
}

WP_CLI::success( 'Topic reply pagination smoke check passed.' );
