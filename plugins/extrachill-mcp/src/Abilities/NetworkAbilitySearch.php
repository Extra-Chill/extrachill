<?php
/**
 * Network-wide ability search meta-ability.
 *
 * @package ExtraChillMcp\Abilities
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Abilities;

use ExtraChillMcp\Access;
use ExtraChillMcp\Network\NetworkDispatch;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `extrachill/ability-search`.
 *
 * Wraps the Agents API substrate's `agents/ability-search` — which is
 * local-only by design, the substrate must not learn about Extra Chill's
 * multisite (Extra-Chill/extrachill-mcp#7) — with a fan-out across every
 * known network site. Each site is queried with the same search input; the
 * local hop reuses `agents/ability-search` directly via
 * `NetworkDispatch::run_local_ability()`, every other known site is queried
 * through `NetworkDispatch::run_ability()` (which is `ec_cross_site_rest_request()`
 * underneath — no second dispatcher). Results are merged and each entry is
 * tagged with the site key that owns it.
 *
 * Presence in a result is discovery, not authorization: the abilities
 * returned by a remote site's `agents/ability-search` reflect whatever that
 * site's own `agents_ability_search_permission` filter allows for the
 * forwarded caller, exactly as the local hop does. This class does not
 * additionally probe `check_permissions()` per result — venue-scoped booking
 * abilities deny everyone, including administrators, when probed with empty
 * input (no `booking_id` to resolve a venue from), so pre-filtering that way
 * would hide bookings from the exact user the feature exists for. The real
 * gate is `WP_Ability::execute()`'s own `check_permissions()` on
 * `extrachill/ability-call` at call time.
 */
final class NetworkAbilitySearch {

	private Access $access;

	public function __construct( Access $access ) {
		$this->access = $access;
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	public function register_ability(): void {
		if ( wp_has_ability( 'extrachill/ability-search' ) ) {
			return;
		}

		wp_register_ability(
			'extrachill/ability-search',
			array(
				'label'               => __( 'Search Network Abilities', 'extrachill-mcp' ),
				'description'         => __( 'Search abilities registered anywhere on the Extra Chill network — editorial, artists, events, venues and bookings, community, shop, and newsletter. Each result is tagged with the site that owns it.', 'extrachill-mcp' ),
				'category'            => 'extrachill-mcp',
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this->access, 'permission_callback' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $input Search input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input ): array {
		$query    = is_string( $input['query'] ?? null ) ? trim( $input['query'] ) : '';
		$category = is_string( $input['category'] ?? null ) ? trim( $input['category'] ) : '';
		$limit_value = $input['limit'] ?? 20;
		$limit       = max( 1, min( 100, is_numeric( $limit_value ) ? (int) $limit_value : 20 ) );

		$search_input = array(
			'query'    => $query,
			'category' => $category,
			'limit'    => $limit,
		);

		/** @var array<int,array<string,mixed>> $abilities Declared so the by-ref merge below type-checks. */
		$abilities     = array();
		$sites_queried = array();
		$errors        = array();

		$local_site_key = NetworkDispatch::local_site_key();
		$local_result    = NetworkDispatch::run_local_ability( 'agents/ability-search', $search_input );

		if ( is_wp_error( $local_result ) ) {
			$errors[ $local_site_key ?? 'local' ] = $local_result->get_error_message();
		} else {
			$sites_queried[] = $local_site_key ?? 'local';
			$this->merge_results( $abilities, $local_result, $local_site_key );
		}

		foreach ( NetworkDispatch::remote_sites() as $site_key ) {
			$response = NetworkDispatch::run_ability( $site_key, 'agents/ability-search', $search_input );

			if ( is_wp_error( $response ) ) {
				$errors[ $site_key ] = $response->get_error_message();
				continue;
			}

			$sites_queried[] = $site_key;
			$this->merge_results( $abilities, $response, $site_key );
		}

		return array(
			'query'         => $query,
			'count'         => count( $abilities ),
			'abilities'     => $abilities,
			'sites_queried' => $sites_queried,
			'errors'        => $errors,
		);
	}

	/**
	 * Append one site's `agents/ability-search` result onto the merged list,
	 * tagging each entry with the site that produced it.
	 *
	 * @param array<int,array<string,mixed>> $abilities Merged list, by reference.
	 * @param mixed                          $result    Raw per-site search result.
	 * @param string|null                    $site_key  Site key to tag entries with.
	 */
	private function merge_results( array &$abilities, $result, ?string $site_key ): void {
		if ( ! is_array( $result ) || ! isset( $result['abilities'] ) || ! is_array( $result['abilities'] ) ) {
			return;
		}

		foreach ( $result['abilities'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			// is_array() alone narrows to array<mixed>, which widens the
			// by-ref parameter's declared element type. Entries are the
			// string-keyed ability records agents/ability-search returns, so
			// state that rather than loosening the declared contract to match
			// the weaker inference.
			/** @var array<string,mixed> $record */
			$record         = $entry;
			$record['site'] = $site_key;
			$abilities[]    = $record;
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'    => array(
					'type'        => 'string',
					'description' => __( 'Search query. Supports name/category substrings, +keyword requirements, and select:foo/bar,baz/qux.', 'extrachill-mcp' ),
					'default'     => '',
				),
				'category' => array(
					'type'        => 'string',
					'description' => __( 'Optional exact ability category filter.', 'extrachill-mcp' ),
					'default'     => '',
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of compact ability entries to return per site.', 'extrachill-mcp' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
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
			'required'   => array( 'query', 'count', 'abilities' ),
			'properties' => array(
				'query'         => array( 'type' => 'string' ),
				'count'         => array( 'type' => 'integer' ),
				'abilities'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'name', 'summary', 'required_fields', 'site' ),
						'properties' => array(
							'name'            => array( 'type' => 'string' ),
							'summary'         => array( 'type' => 'string' ),
							'required_fields' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'site'            => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Network site key that owns this ability (e.g. "events"), or null when unresolved.', 'extrachill-mcp' ),
							),
						),
					),
				),
				'sites_queried' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'errors'        => array(
					'type'        => 'object',
					'description' => __( 'Site key => error message for any site that could not be queried.', 'extrachill-mcp' ),
				),
			),
		);
	}
}
