<?php
/**
 * Network-wide ability call meta-ability.
 *
 * @package ExtraChillMcp\Abilities
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Abilities;

use ExtraChillMcp\Access;
use ExtraChillMcp\Network\NetworkDispatch;
use ExtraChillMcp\Usage\InvocationContext;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `extrachill/ability-call`.
 *
 * Resolves the target ability's owning site via
 * `NetworkDispatch::resolve_owner()` (`ec_get_ability_site_affinity()`
 * underneath), then dispatches: locally through the Agents API substrate's
 * own `agents/ability-call` (reusing its redaction and not-found handling)
 * when the owner is local/unknown/ambiguous, or on the owning site's own
 * `/wp-abilities/v1/abilities/{name}/run` route via
 * `NetworkDispatch::run_ability()` (`ec_cross_site_rest_request()`
 * underneath, which preserves the acting user on both its in-process and
 * HTTP-loopback transports).
 *
 * Authorization is unchanged by the network hop: `WP_Ability::execute()`
 * runs the TARGET ability's own `permission_callback` on the target site
 * with the acting user — the ownership index is built as a super admin (see
 * extrachill-network#196) purely to enumerate what exists, so presence in
 * `agents/ability-search` results is not itself authorization. This class
 * never substitutes its own judgment for that check.
 */
final class NetworkAbilityCall {

	private Access $access;

	public function __construct( Access $access ) {
		$this->access = $access;
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	public function register_ability(): void {
		if ( wp_has_ability( 'extrachill/ability-call' ) ) {
			return;
		}

		wp_register_ability(
			'extrachill/ability-call',
			array(
				'label'               => __( 'Call Network Ability', 'extrachill-mcp' ),
				'description'         => __( 'Invoke a registered ability by name, on whichever Extra Chill network site owns it, with JSON parameters.', 'extrachill-mcp' ),
				'category'            => 'extrachill-mcp',
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this->access, 'permission_callback' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'idempotent' => false,
					),
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$name       = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';
		$parameters = is_array( $input['parameters'] ?? null ) ? $input['parameters'] : array();

		if ( '' === $name ) {
			return new \WP_Error( 'extrachill_mcp_ability_call_missing_name', __( 'Ability name is required.', 'extrachill-mcp' ) );
		}

		if ( 'extrachill/ability-call' === $name ) {
			return new \WP_Error( 'extrachill_mcp_ability_call_recursion', __( 'extrachill/ability-call cannot call itself.', 'extrachill-mcp' ) );
		}

		$site_key = NetworkDispatch::resolve_owner( $name );

		// The MCP server advertises two tools, so the transport's own
		// observability sees every call in the network as
		// `extrachill/ability-call`. Record the name the caller actually
		// asked for so `mcp_tool_invoked` can carry it; see
		// ExtraChillMcp\Usage\InvocationContext. Best-effort and never
		// consulted by dispatch.
		InvocationContext::record( $name, $site_key ?? NetworkDispatch::local_site_key() );

		if ( null === $site_key ) {
			return $this->call_local( $name, $parameters );
		}

		return $this->call_remote( $site_key, $name, $parameters );
	}

	/**
	 * @param array<string,mixed> $parameters
	 * @return array<string,mixed>|\WP_Error
	 */
	private function call_local( string $name, array $parameters ) {
		$result = NetworkDispatch::run_local_ability(
			'agents/ability-call',
			array(
				'name'       => $name,
				'parameters' => $parameters,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'extrachill_mcp_ability_call_invalid_response', __( 'Local ability dispatch returned an unexpected response.', 'extrachill-mcp' ) );
		}

		$result['site'] = NetworkDispatch::local_site_key();

		return $result;
	}

	/**
	 * @param array<string,mixed> $parameters
	 * @return array<string,mixed>|\WP_Error
	 */
	private function call_remote( string $site_key, string $name, array $parameters ) {
		$result = NetworkDispatch::run_ability( $site_key, $name, $parameters );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Unlike the local hop (which reuses agents/ability-call's own
		// redaction, computed from the target ability's schema/meta), this
		// plugin has no local copy of a remote-only ability's schema to
		// redact sensitive input against. Echoing raw parameters back here
		// could leak them; omitting the field entirely is the safe default.
		return array(
			'name'   => $name,
			'site'   => $site_key,
			'result' => $result,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'name'       => array(
					'type'        => 'string',
					'description' => __( 'Registered ability name to invoke, e.g. extrachill/add-venue.', 'extrachill-mcp' ),
				),
				'parameters' => array(
					'type'        => 'object',
					'description' => __( 'JSON parameters passed to the target ability.', 'extrachill-mcp' ),
					'default'     => array(),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function output_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'name', 'site', 'result' ),
			'properties' => array(
				'name'                => array( 'type' => 'string' ),
				'site'                => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Network site key that owns the invoked ability, or null when it ran locally with an unresolved site key.', 'extrachill-mcp' ),
				),
				'parameters'          => array(
					'type'        => 'object',
					'description' => __( 'Target ability parameters with sensitive values redacted. Present only for a locally-dispatched ability.', 'extrachill-mcp' ),
				),
				'parameters_redacted' => array( 'type' => 'boolean' ),
				'result'              => array( 'type' => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ) ),
			),
		);
	}
}
