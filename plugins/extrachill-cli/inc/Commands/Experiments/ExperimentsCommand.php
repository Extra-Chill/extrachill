<?php
/**
 * Platform Experiments CLI Command
 *
 * Thin adapters over the Network lifecycle and Analytics report abilities.
 *
 * @package ExtraChill\CLI\Commands\Experiments
 */

namespace ExtraChill\CLI\Commands\Experiments;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExperimentsCommand {

	/**
	 * List registered platform experiments.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. JSON and CSV preserve the complete owner envelope.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill experiments list
	 *     wp extrachill experiments list --format=json
	 *     wp --allow-root --path=/var/www/extrachill.com extrachill experiments list
	 *
	 * ## NOTES
	 *
	 * Network permits trusted local WP-CLI execution of its private experiment
	 * admin Abilities. Use the standard local command above without `--user`;
	 * web and REST callers still require Network's administrative capability.
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ) {
		$result = $this->execute( 'extrachill/list-experiments', null, 'Extra Chill Network' );
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}
		if ( 'csv' === $format ) {
			$this->display_csv( $result );
			return;
		}

		if ( empty( $result ) ) {
			WP_CLI::log( 'No registered experiments.' );
			return;
		}
		foreach ( $result as $definition ) {
			$this->display_definition( (array) $definition );
		}
	}

	/**
	 * Get one registered platform experiment.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Stable experiment key.
	 *
	 * [--format=<format>]
	 * : Output format. JSON and CSV preserve the complete owner envelope.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill experiments get geo-bridge-holdout
	 *     wp extrachill experiments get geo-bridge-holdout --format=json
	 *
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$definition = $this->definition( $args[0] ?? '' );
		$format     = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::print_value( $definition, array( 'format' => 'json' ) );
			return;
		}
		if ( 'csv' === $format ) {
			$this->display_csv( array( $definition ) );
			return;
		}

		$this->display_definition( $definition );
	}

	/**
	 * Transition one experiment's current definition version.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Stable experiment key.
	 *
	 * <state>
	 * : Requested lifecycle state.
	 * ---
	 * options:
	 *   - inactive
	 *   - active
	 *   - paused
	 *   - completed
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation when activating or completing an experiment.
	 *
	 * [--format=<format>]
	 * : Output format. JSON and CSV preserve the complete before, transition, and after owner envelopes.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill experiments state geo-bridge-holdout active
	 *     wp extrachill experiments state geo-bridge-holdout completed --yes --format=json
	 *
	 * @when after_wp_load
	 */
	public function state( $args, $assoc_args ) {
		$key    = (string) ( $args[0] ?? '' );
		$state  = (string) ( $args[1] ?? '' );
		$before = $this->definition( $key );
		$this->require_registered( $before, 'transition state' );

		if ( in_array( $state, array( 'active', 'completed' ), true ) ) {
			WP_CLI::confirm( sprintf( 'Transition experiment %s to %s?', $key, $state ), $assoc_args );
		}

		$transition = $this->execute(
			'extrachill/transition-experiment-state',
			array(
				'experiment_key'     => $key,
				'definition_version' => (int) $before['definition_version'],
				'state'              => $state,
			),
			'Extra Chill Network'
		);
		$after      = $this->definition( $key );
		$result     = compact( 'before', 'transition', 'after' );
		$format     = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}
		if ( 'csv' === $format ) {
			$this->display_csv( array( $result ) );
			return;
		}

		WP_CLI::log( 'Before:' );
		$this->display_definition( $before );
		WP_CLI::log( 'Transition:' );
		Utils\format_items( 'table', array( $transition ), array_keys( $transition ) );
		WP_CLI::log( 'After:' );
		$this->display_definition( $after );
	}

	/**
	 * Report assignment, exposure, and outcomes for one experiment.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Stable experiment key.
	 *
	 * [--definition-version=<version>]
	 * : Restrict stored events to one definition version. Omit to retain owner-provided mixed-version diagnostics.
	 *
	 * [--control-variant=<variant>]
	 * : Control variant. Defaults to the current registered definition.
	 *
	 * [--variants=<variants>]
	 * : Comma-separated variant IDs. Defaults to every current registered variant.
	 *
	 * [--outcome-event-types=<types>]
	 * : Comma-separated canonical Analytics outcome event types.
	 *
	 * [--days=<days>]
	 * : UTC lookback days. Accepted range: 1-90. Omit to use the Analytics owner default.
	 *
	 * [--attribution-window-days=<days>]
	 * : Outcome attribution window. Accepted range: 1-90. Omit to use the Analytics owner default.
	 *
	 * [--session-gap-mins=<mins>]
	 * : Inactivity gap separating sessions. Accepted range: 1-120. Omit to use the Analytics owner default.
	 *
	 * [--max-events=<count>]
	 * : Maximum retained rows. Accepted range: 100-100000. Omit to use the Analytics owner default.
	 *
	 * [--format=<format>]
	 * : Output format. JSON and CSV preserve the complete Analytics owner envelope.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill experiments report geo-bridge-holdout
	 *     wp extrachill experiments report geo-bridge-holdout --definition-version=1 --outcome-event-types=bridge_click --format=json
	 *
	 * @when after_wp_load
	 */
	public function report( $args, $assoc_args ) {
		$definition = $this->definition( $args[0] ?? '' );
		$this->require_registered( $definition, 'report' );
		$input      = array(
			'experiment_key'  => (string) $definition['key'],
			'control_variant' => (string) ( $assoc_args['control-variant'] ?? $definition['control_variant'] ),
			'variants'        => isset( $assoc_args['variants'] ) ? $this->csv_values( $assoc_args['variants'] ) : array_keys( (array) $definition['variants'] ),
		);
		$optional = array(
			'definition-version'      => 'definition_version',
			'outcome-event-types'     => 'outcome_event_types',
			'days'                    => 'days',
			'attribution-window-days' => 'attribution_window_days',
			'session-gap-mins'        => 'session_gap_mins',
			'max-events'              => 'max_events',
		);
		foreach ( $optional as $cli_key => $ability_key ) {
			if ( ! isset( $assoc_args[ $cli_key ] ) ) {
				continue;
			}
			$input[ $ability_key ] = 'outcome-event-types' === $cli_key
				? $this->csv_values( $assoc_args[ $cli_key ] )
				: (int) $assoc_args[ $cli_key ];
		}

		$result = $this->execute( 'extrachill/get-experiment-summary', $input, 'Extra Chill Analytics' );
		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}
		if ( 'csv' === $format ) {
			$this->display_csv( array( $result ) );
			return;
		}

		$this->display_report( $definition, $result );
	}

	/** Execute one owner ability and preserve its error message. */
	private function execute( $name, $input, $owner ) {
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			WP_CLI::error( sprintf( '%s ability not found. Is %s active and up to date?', $name, $owner ) );
		}
		$result = null === $input ? $ability->execute() : $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		return $result;
	}

	/** Find one complete definition envelope through Network's list ability. */
	private function definition( $key ) {
		foreach ( $this->execute( 'extrachill/list-experiments', null, 'Extra Chill Network' ) as $definition ) {
			if ( (string) ( $definition['key'] ?? '' ) === (string) $key ) {
				return $definition;
			}
		}
		WP_CLI::error( sprintf( 'Experiment %s is not registered.', (string) $key ) );
	}

	/** Reject owner-reported orphan rows before mutation or analytics calls. */
	private function require_registered( array $definition, $operation ) {
		if ( ! empty( $definition['registered'] ) && empty( $definition['orphaned'] ) ) {
			return;
		}
		WP_CLI::error(
			sprintf(
				'Experiment %s is an orphaned lifecycle record without a registered code definition; cannot %s.',
				(string) ( $definition['key'] ?? '' ),
				$operation
			)
		);
	}

	/** Render one Network definition without changing its values. */
	private function display_definition( array $definition ) {
		$state      = $definition['state'] ?? null;
		$registered = ! empty( $definition['registered'] ) && empty( $definition['orphaned'] );
		WP_CLI::log( sprintf( 'Experiment: %s', $this->human_value( $definition['key'] ?? null ) ) );
		WP_CLI::log( sprintf( 'Registry status: %s', $registered ? 'registered code definition' : 'orphaned lifecycle record (not registered; inspection only)' ) );
		WP_CLI::log( sprintf( 'Definition version: %s', $this->human_value( $definition['definition_version'] ?? null ) ) );
		WP_CLI::log( sprintf( 'Assignment policy: %s', $this->human_value( $definition['assignment_policy'] ?? null ) ) );
		WP_CLI::log( sprintf( 'Code default state: %s', $this->human_value( $definition['default_state'] ?? null ) ) );
		WP_CLI::log( sprintf( 'Effective/live state: %s', $this->human_value( $state ) ) );
		WP_CLI::log( sprintf( 'Runtime status: %s', $registered && 'active' === $state ? 'assignment eligible when the consumer permits it' : 'no-op; normal consumer behavior remains unchanged' ) );
		WP_CLI::log( sprintf( 'Default/control variant: %s / %s', $this->human_value( $definition['default_variant'] ?? null ), $this->human_value( $definition['control_variant'] ?? null ) ) );
		WP_CLI::log( 'Variant weights: ' . $this->pairs( (array) ( $definition['variants'] ?? array() ) ) );
		WP_CLI::log( 'Surfaces: ' . $this->values( (array) ( $definition['surfaces'] ?? array() ) ) );
		WP_CLI::log( '' );
	}

	/** Render owner-produced report sections without calculating metrics. */
	private function display_report( array $definition, array $result ) {
		WP_CLI::log( sprintf( 'Experiment report: %s', $this->human_value( $result['experiment_key'] ?? $definition['key'] ?? null ) ) );
		WP_CLI::log( sprintf( 'Definition version filter: %s', $this->human_value( $result['definition_version'] ?? null ) ) );
		WP_CLI::log( sprintf( 'Control variant: %s; report state: %s', $this->human_value( $result['control_variant'] ?? null ), $this->human_value( $result['state'] ?? null ) ) );
		WP_CLI::log( 'Assignment policy/weights: ' . $this->human_value( $definition['assignment_policy'] ?? null ) . '; ' . $this->pairs( (array) ( $definition['variants'] ?? array() ) ) );
		WP_CLI::log( 'Registered surfaces: ' . $this->values( (array) ( $definition['surfaces'] ?? array() ) ) );

		foreach ( (array) ( $result['variants'] ?? array() ) as $variant ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Variant: %s', $this->human_value( $variant['variant'] ?? null ) ) );
			$this->display_metric_group( 'Assignment (intent-to-treat)', (array) ( $variant['assignment'] ?? array() ) );
			$this->display_metric_group( 'Exposure (descriptive, exposure-conditioned)', (array) ( $variant['exposure'] ?? array() ) );
			foreach ( (array) ( $variant['outcomes'] ?? array() ) as $outcome => $metrics ) {
				WP_CLI::log( sprintf( 'Outcome: %s; coverage: %s', $outcome, $this->human_value( $metrics['coverage_status'] ?? null ) ) );
				$this->display_metric_group( '  After assignment (intent-to-treat; lift/confidence vs control)', $metrics['after_assignment'] ?? null );
				$this->display_metric_group( '  After exposure (descriptive, exposure-conditioned)', $metrics['after_exposure'] ?? null );
			}
		}

		WP_CLI::log( '' );
		$this->display_metric_group( 'Version/surface/policy diagnostics', (array) ( $result['version_diagnostics'] ?? array() ) );
		$this->display_metric_group( 'Coverage and insufficient-data diagnostics', (array) ( $result['coverage'] ?? array() ) );
		$this->display_metric_group( 'Analytics owner contract and interpretation', (array) ( $result['contract'] ?? array() ) );
		$this->display_metric_group( 'Analytics owner window', (array) ( $result['window'] ?? array() ) );
		$this->display_metric_group( 'Analytics owner query and bounds', (array) ( $result['query'] ?? array() ) );
		WP_CLI::log( 'No winner is declared; null and unknown values remain unavailable rather than zero.' );
	}

	/** Render an owner metric group as key/value rows. */
	private function display_metric_group( $label, $metrics ) {
		WP_CLI::log( $label . ':' );
		if ( null === $metrics ) {
			WP_CLI::log( '  unavailable / insufficient data' );
			return;
		}
		$rows = array();
		foreach ( (array) $metrics as $key => $value ) {
			$rows[] = array( 'metric' => $key, 'value' => $this->human_value( $value ) );
		}
		if ( empty( $rows ) ) {
			WP_CLI::log( '  unavailable / insufficient data' );
			return;
		}
		Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}

	/** Emit complete rows as CSV, JSON-encoding every nested value. */
	private function display_csv( array $rows ) {
		$fields = array();
		foreach ( $rows as $row ) {
			$fields = array_values( array_unique( array_merge( $fields, array_keys( (array) $row ) ) ) );
		}
		$flattened = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			foreach ( $row as $key => $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					$row[ $key ] = wp_json_encode( $value );
				}
			}
			$flattened[] = $row;
		}
		Utils\format_items( 'csv', $flattened, $fields );
	}

	/** Parse a comma-separated owner argument without changing its values. */
	private function csv_values( $value ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' ) );
	}

	/** Format arbitrary owner values for human output while preserving null. */
	private function human_value( $value ) {
		if ( null === $value ) {
			return 'unavailable';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}

	/** Format a keyed definition map for humans. */
	private function pairs( array $values ) {
		$pairs = array();
		foreach ( $values as $key => $value ) {
			$pairs[] = $key . '=' . $this->human_value( $value );
		}
		return empty( $pairs ) ? 'unavailable' : implode( ', ', $pairs );
	}

	/** Format a value list for humans. */
	private function values( array $values ) {
		return empty( $values ) ? 'unavailable' : implode( ', ', array_map( array( $this, 'human_value' ), $values ) );
	}
}
