<?php
// phpcs:disable Universal.Files.SeparateFunctionsFromOO,Universal.Namespaces.DisallowCurlyBraceSyntax,Universal.Namespaces.DisallowDeclarationWithoutName,Universal.Namespaces.OneDeclarationPerFile -- Test doubles mirror WordPress and dependency signatures.
/**
 * In-memory Data Machine flow gateway for FlowOps tests.
 *
 * FlowOps reaches Data Machine only through DataMachineFlowGateway, so the
 * tests swap in this double instead of faking $wpdb. It applies each call
 * to seeded flows (so tests can assert final state) and records the calls
 * (so tests can assert which Data Machine surface was used).
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core {

	use ExtraChillEvents\Core\DataMachineFlowGateway;

	require_once dirname( __DIR__, 4 ) . '/inc/Core/DataMachineFlowGateway.php';

	if ( ! class_exists( __NAMESPACE__ . '\\FakeFlowGateway' ) ) {
		class FakeFlowGateway extends DataMachineFlowGateway {
			/** @var array<int,array> Flows keyed by flow_id. */
			public array $flows = array();

			/** @var array<int,array{method:string,args:array}> */
			public array $calls = array();

			public function seed( int $flow_id, array $flow_config, array $scheduling_config, string $flow_name = 'Test flow' ): void {
				$this->flows[ $flow_id ] = array(
					'flow_id'           => $flow_id,
					'flow_name'         => $flow_name,
					'flow_config'       => $flow_config,
					'scheduling_config' => $scheduling_config,
				);
			}

			public function calls_to( string $method ): array {
				return array_values( array_filter( $this->calls, static fn( $call ) => $method === $call['method'] ) );
			}

			public function writes(): array {
				return array_values( array_filter( $this->calls, static fn( $call ) => 'get_flow' !== $call['method'] ) );
			}

			public function get_flow( int $flow_id ): ?array {
				$this->calls[] = array(
					'method' => 'get_flow',
					'args'   => array( $flow_id ),
				);
				return $this->flows[ $flow_id ] ?? null;
			}

			public function update_scheduling( int $flow_id, array $scheduling_config ): bool {
				$this->calls[] = array(
					'method' => 'update_scheduling',
					'args'   => array( $flow_id, $scheduling_config ),
				);
				if ( ! isset( $this->flows[ $flow_id ] ) ) {
					return false;
				}
				$this->flows[ $flow_id ]['scheduling_config'] = $scheduling_config;
				return true;
			}

			public function patch_scheduling_metadata( int $flow_id, array $set, array $remove = array() ): bool {
				$this->calls[] = array(
					'method' => 'patch_scheduling_metadata',
					'args'   => array( $flow_id, $set, $remove ),
				);
				if ( ! isset( $this->flows[ $flow_id ] ) ) {
					return false;
				}
				$config = array_merge( $this->flows[ $flow_id ]['scheduling_config'], $set );
				foreach ( $remove as $key ) {
					unset( $config[ $key ] );
				}
				$this->flows[ $flow_id ]['scheduling_config'] = $config;
				return true;
			}

			public function update_step_source_url( string $flow_step_id, string $source_url ): bool {
				$this->calls[] = array(
					'method' => 'update_step_source_url',
					'args'   => array( $flow_step_id, $source_url ),
				);
				foreach ( $this->flows as $flow_id => $flow ) {
					foreach ( $flow['flow_config'] as $key => $step ) {
						if ( (string) ( $step['flow_step_id'] ?? $key ) !== $flow_step_id ) {
							continue;
						}
						if ( isset( $step['handler_configs']['universal_web_scraper'] ) ) {
							$this->flows[ $flow_id ]['flow_config'][ $key ]['handler_configs']['universal_web_scraper']['source_url'] = $source_url;
						} else {
							$this->flows[ $flow_id ]['flow_config'][ $key ]['handler_config']['source_url'] = $source_url;
						}
						return true;
					}
				}
				return false;
			}

			public function run_flow( int $flow_id ): bool {
				$this->calls[] = array(
					'method' => 'run_flow',
					'args'   => array( $flow_id ),
				);
				return isset( $this->flows[ $flow_id ] );
			}
		}
	}
}

namespace {
	if ( ! function_exists( 'get_site_option' ) ) {
		function get_site_option( $option, $default_value = false ) {
			unset( $option );
			return $default_value;
		}
	}
}
