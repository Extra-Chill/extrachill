<?php
/** CLI adapter for persisted venue expansion reports. */

namespace ExtraChillEvents\Cli;

defined( 'ABSPATH' ) || exit;

class VenueExpansionReportCommand {
	/** Read machine-readable aggregate and per-city reports. */
	public function __invoke( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'extrachill/venue-expansion-report' );
		if ( ! $ability ) {
			\WP_CLI::error( 'extrachill/venue-expansion-report ability is unavailable.' );
		}
		$input = array();
		if ( ! empty( $assoc_args['batch-job-id'] ) ) {
			$input['batch_job_id'] = (int) $assoc_args['batch-job-id'];
		}
		if ( ! empty( $assoc_args['job-ids'] ) ) {
			$input['job_ids'] = array_map( 'intval', explode( ',', (string) $assoc_args['job-ids'] ) );
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
