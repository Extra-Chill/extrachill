<?php
namespace ExtraChillEvents\Cli;

class EventRoundupCommand {
	/**
	 * Build a shareable roundup carousel, returning JSON metadata.
	 *
	 * ## OPTIONS
	 *
	 * [--date-start=<date>]
	 * [--date-end=<date>]
	 * [--location=<slug>]
	 * [--event-type=<slugs>]
	 * [--cover]
	 * [--closing]
	 * [--background=<auto-or-ids>]
	 */
	public function __invoke( array $args, array $assoc ): void {
		$input = array();
		foreach ( array( 'date-start', 'date-end', 'location', 'background' ) as $key ) {
			if ( isset( $assoc[ $key ] ) ) {
				$input[ str_replace( '-', '_', $key ) ] = $assoc[ $key ];
			}
		}
		$input['cover']   = isset( $assoc['cover'] );
		$input['closing'] = isset( $assoc['closing'] );
		if ( isset( $assoc['event-type'] ) ) {
			$input['tax_filters']['event_type'] = explode( ',', $assoc['event-type'] );
		}
		$ability = wp_get_ability( 'extrachill/event-roundup-build' );
		if ( ! $ability ) {
			\WP_CLI::error( 'Roundup build ability is unavailable.' );
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
