<?php
/**
 * JSON CLI adapter for event source ramp evidence.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\EventSourceRampEvaluator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adapts local JSON evidence to the pure ramp evaluator. */
class EventSourceRampCommand {

	/**
	 * Evaluate machine-readable preflight or postflight evidence.
	 *
	 * ## OPTIONS
	 *
	 * --source-class=<class>
	 * : ticketmaster, dice, or universal_scraper.
	 *
	 * --current-max-items=<number>
	 * : Current declared ramp stage.
	 *
	 * --phase=<phase>
	 * : preflight or postflight.
	 *
	 * --evidence=<path>
	 * : JSON evidence file. Use - to read standard input.
	 *
	 * --scope=<scope>
	 * : Reversible target scope: pipeline or flow.
	 *
	 * --scope-id=<id>
	 * : Pipeline or flow ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events ramp --source-class=ticketmaster --current-max-items=1 --phase=preflight --evidence=preflight.json --scope=pipeline --scope-id=10
	 *
	 *     wp extrachill events ramp --source-class=dice --current-max-items=3 --phase=postflight --evidence=- --scope=flow --scope-id=42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		$required = array( 'source-class', 'current-max-items', 'phase', 'evidence', 'scope', 'scope-id' );
		foreach ( $required as $name ) {
			if ( ! isset( $assoc_args[ $name ] ) || '' === (string) $assoc_args[ $name ] ) {
				\WP_CLI::error( sprintf( '--%s is required.', $name ) );
			}
		}

		$path = (string) $assoc_args['evidence'];
		$json = '-' === $path ? stream_get_contents( STDIN ) : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Operator-supplied local CLI evidence file.
		if ( false === $json ) {
			\WP_CLI::error( 'Unable to read evidence JSON.' );
		}

		$evidence = json_decode( $json, true );
		if ( ! is_array( $evidence ) ) {
			\WP_CLI::error( 'Evidence must be a JSON object.' );
		}

		$result = ( new EventSourceRampEvaluator() )->evaluate(
			array(
				'source_class'      => (string) $assoc_args['source-class'],
				'current_max_items' => (int) $assoc_args['current-max-items'],
				'phase'             => (string) $assoc_args['phase'],
				'scope'             => (string) $assoc_args['scope'],
				'scope_id'          => (int) $assoc_args['scope-id'],
				'evidence'          => $evidence,
			)
		);

		\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
