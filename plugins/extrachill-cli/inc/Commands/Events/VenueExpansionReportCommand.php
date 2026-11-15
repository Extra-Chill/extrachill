<?php
/**
 * `wp extrachill venues expand-report`
 *
 * Thin adapter over the extrachill/venue-expansion-report ability
 * (extrachill-events). Replaces the non-compliant
 * `wp extrachill-events expand-report` root command.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueExpansionReportCommand {

	/**
	 * Read machine-readable aggregate and per-city expansion reports.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-job-id=<id>]
	 * : Report on one scheduled expansion batch.
	 *
	 * [--job-ids=<ids>]
	 * : Comma-separated per-city job IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues expand-report --batch-job-id=123 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$input = array();
		if ( ! empty( $assoc_args['batch-job-id'] ) ) {
			$input['batch_job_id'] = (int) $assoc_args['batch-job-id'];
		}
		if ( ! empty( $assoc_args['job-ids'] ) ) {
			$input['job_ids'] = array_map( 'intval', explode( ',', (string) $assoc_args['job-ids'] ) );
		}

		$result = OwnerSiteAbility::get( 'events', 'Events', 'extrachill/venue-expansion-report' )->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
