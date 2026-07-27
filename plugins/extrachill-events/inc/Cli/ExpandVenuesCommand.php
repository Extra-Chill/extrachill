<?php
/** Headless CLI adapter for venue expansion abilities. */

namespace ExtraChillEvents\Cli;

defined( 'ABSPATH' ) || exit;

class ExpandVenuesCommand {
	/** Plan or schedule venue expansion. Dry-run is the default. */
	public function __invoke( array $args, array $assoc_args ): void {
		$city     = trim( (string) ( $assoc_args['city'] ?? '' ) );
		$file     = trim( (string) ( $assoc_args['cities-file'] ?? '' ) );
		$has_city = '' !== $city;
		$has_file = '' !== $file;
		if ( $has_city === $has_file ) {
			\WP_CLI::error( 'Provide exactly one of --city or --cities-file.' );
		}
		$cities = $has_city ? array( $city ) : self::parseCitiesFile( $file );
		if ( empty( $cities ) ) {
			\WP_CLI::error( 'No cities were found.' );
		}
		$ability = wp_get_ability( 'extrachill/expand-venues' );
		if ( ! $ability ) {
			\WP_CLI::error( 'extrachill/expand-venues ability is unavailable.' );
		}
		$max_venues    = (int) ( $assoc_args['max-venues-per-city'] ?? 10 );
		$agent_context = array();
		if ( ! empty( $assoc_args['apply'] ) ) {
			if ( ! class_exists( '\\DataMachine\\Cli\\AgentResolver' ) ) {
				\WP_CLI::error( 'Data Machine agent resolver is unavailable.' );
			}
			$agent_context = \DataMachine\Cli\AgentResolver::resolveEffectiveContext( $assoc_args );
		}
		$input = array(
			'cities'               => $cities,
			'country'              => (string) ( $assoc_args['country'] ?? '' ),
			'max_cities'           => (int) ( $assoc_args['max-cities'] ?? 10 ),
			'max_venues_per_city'  => $max_venues,
			'discovery_budget'     => (int) ( $assoc_args['discovery-budget'] ?? count( $cities ) ),
			'qualification_budget' => (int) ( $assoc_args['qualification-budget'] ?? count( $cities ) * $max_venues ),
			'skip_existing'        => ! isset( $assoc_args['skip-existing'] ) || (bool) $assoc_args['skip-existing'],
			'apply'                => ! empty( $assoc_args['apply'] ),
			'request_delay_ms'     => (int) ( $assoc_args['request-delay-ms'] ?? 1000 ),
			'max_verdict_age_days' => (int) ( $assoc_args['max-verdict-age-days'] ?? 30 ),
			'city_delay_seconds'   => (int) ( $assoc_args['city-delay-seconds'] ?? 60 ),
		);
		if ( ! empty( $agent_context['agent_slug'] ) ) {
			$input['agent_slug'] = (string) $agent_context['agent_slug'];
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( empty( $assoc_args['apply'] ) ) {
			\WP_CLI::warning( 'Plan only. Pass --apply to schedule this bounded batch.' );
		}
	}

	/** Parse comments and blank lines while preserving first-seen order. */
	public static function parseCitiesFile( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$lines  = file( $path, FILE_IGNORE_NEW_LINES );
		$cities = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( ! in_array( $line, $cities, true ) ) {
				$cities[] = $line;
			}
		}
		return $cities;
	}
}
