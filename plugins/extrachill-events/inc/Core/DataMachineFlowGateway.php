<?php
/**
 * Data Machine flow gateway for events-domain flow and pipeline operations.
 *
 * Every read and write FlowOps, `extrachill/add-venue` and the event-source
 * approval abilities make against Data Machine flows and pipelines goes
 * through this class, and this class only talks to Data Machine through its
 * public surface: the flow/pipeline abilities plus
 * `Flows::update_flow_scheduling_metadata()` for non-authoritative
 * scheduling keys. Nothing here writes Data Machine tables directly.
 *
 * Abilities run as the system: callers are already-authorized entry points
 * (WP-CLI commands, the recheck Action Scheduler callback, or an events
 * ability whose own permission callback already passed — the events-domain
 * ability is the authorization boundary), so elevation is scoped to the
 * single ability call. Events staff without Data Machine manage caps can
 * therefore complete add-venue / source approval end to end.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data Machine-backed flow access used by FlowOps.
 *
 * @internal
 */
class DataMachineFlowGateway {

	/**
	 * Read one flow with decoded configs.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array{flow_id:int,flow_name:string,flow_config:array,scheduling_config:array}|null
	 */
	public function get_flow( int $flow_id ): ?array {
		$result = self::execute(
			'datamachine/get-flows',
			array(
				'flow_id'     => $flow_id,
				'output_mode' => 'list',
			)
		);
		$flow   = is_array( $result ) ? ( $result['flows'][0] ?? null ) : null;
		if ( ! is_array( $flow ) ) {
			return null;
		}

		return array(
			'flow_id'           => (int) ( $flow['flow_id'] ?? $flow_id ),
			'flow_name'         => (string) ( $flow['flow_name'] ?? '' ),
			'flow_config'       => is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array(),
			'scheduling_config' => is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array(),
		);
	}

	/**
	 * Replace a flow's desired scheduling config and re-sync its schedule.
	 *
	 * Data Machine registers or unregisters the flow's routine to match
	 * the interval, so a `manual` interval also stops scheduled runs.
	 *
	 * @param int   $flow_id           Flow ID.
	 * @param array $scheduling_config Full desired scheduling config.
	 * @return bool True on success.
	 */
	public function update_scheduling( int $flow_id, array $scheduling_config ): bool {
		$result = self::execute(
			'datamachine/update-flow',
			array(
				'flow_id'           => $flow_id,
				'scheduling_config' => $scheduling_config,
			)
		);

		return is_array( $result ) && ! empty( $result['success'] );
	}

	/**
	 * Set and remove non-authoritative scheduling metadata keys.
	 *
	 * @param int   $flow_id Flow ID.
	 * @param array $set     Keys to set.
	 * @param array $remove  Keys to remove.
	 * @return bool True on success.
	 */
	public function patch_scheduling_metadata( int $flow_id, array $set, array $remove = array() ): bool {
		if ( ! class_exists( '\\DataMachine\\Core\\Database\\Flows\\Flows' ) ) {
			return false;
		}

		return ( new \DataMachine\Core\Database\Flows\Flows() )->update_flow_scheduling_metadata( $flow_id, $set, $remove );
	}

	/**
	 * Point one flow step's universal_web_scraper at a new source URL.
	 *
	 * @param string $flow_step_id Flow step ID.
	 * @param string $source_url   New source URL.
	 * @return bool True on success.
	 */
	public function update_step_source_url( string $flow_step_id, string $source_url ): bool {
		$result = self::execute(
			'datamachine/update-flow-step',
			array(
				'flow_step_id'   => $flow_step_id,
				'handler_slug'   => 'universal_web_scraper',
				'handler_config' => array( 'source_url' => $source_url ),
			)
		);

		return is_array( $result ) && ! empty( $result['success'] );
	}

	/**
	 * Start a flow run now.
	 *
	 * @param int $flow_id Flow ID.
	 * @return bool True when Data Machine accepted the run.
	 */
	public function run_flow( int $flow_id ): bool {
		$result = $this->run_flow_result( $flow_id );

		return is_array( $result ) && ! empty( $result['success'] );
	}

	/**
	 * Start a flow run now and return Data Machine's raw result.
	 *
	 * @param int $flow_id Flow ID.
	 * @return mixed Ability result, or a WP_Error when unavailable.
	 */
	public function run_flow_result( int $flow_id ) {
		return self::execute( 'datamachine/run-flow', array( 'flow_id' => $flow_id ) );
	}

	/**
	 * Whether a Data Machine ability is registered.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	public function is_available( string $name ): bool {
		return function_exists( 'wp_get_ability' ) && (bool) wp_get_ability( $name );
	}

	/**
	 * Create a flow on a pipeline.
	 *
	 * @param array $input `datamachine/create-flow` input.
	 * @return mixed Ability result, or a WP_Error when unavailable.
	 */
	public function create_flow( array $input ) {
		return self::execute( 'datamachine/create-flow', $input );
	}

	/**
	 * Update one flow step's handler, handler config or user message.
	 *
	 * @param array $input `datamachine/update-flow-step` input.
	 * @return mixed Ability result, or a WP_Error when unavailable.
	 */
	public function update_flow_step( array $input ) {
		return self::execute( 'datamachine/update-flow-step', $input );
	}

	/**
	 * Create a pipeline.
	 *
	 * @param array $input `datamachine/create-pipeline` input.
	 * @return mixed Ability result, or a WP_Error when unavailable.
	 */
	public function create_pipeline( array $input ) {
		return self::execute( 'datamachine/create-pipeline', $input );
	}

	/**
	 * Read normalized pipeline configuration.
	 *
	 * @param array $selector `datamachine/get-pipeline-configuration` input.
	 * @return mixed Ability result, or a WP_Error when unavailable.
	 */
	public function describe_pipeline( array $selector ) {
		return self::execute( 'datamachine/get-pipeline-configuration', $selector );
	}

	/**
	 * Update one pipeline or flow step through the owner contract.
	 *
	 * @param array $input `datamachine/update-step-configuration` input.
	 * @return mixed Ability result, or a WP_Error when unavailable.
	 */
	public function update_step_configuration( array $input ) {
		return self::execute( 'datamachine/update-step-configuration', $input );
	}

	/**
	 * Execute a Data Machine ability as the system.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return mixed Ability result, or a WP_Error when unavailable.
	 */
	private static function execute( string $name, array $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			return new \WP_Error( 'ability_unavailable', sprintf( 'Ability %s is not registered.', $name ) );
		}

		$run = static fn() => $ability->execute( $input );

		return class_exists( '\\DataMachine\\Abilities\\PermissionHelper' )
			? \DataMachine\Abilities\PermissionHelper::run_as_system( $run )
			: $run();
	}
}
