<?php
/**
 * `wp extrachill events flows audit-pipelines` — find and (optionally) fix
 * venue scraper flows that live under the wrong city pipeline.
 *
 * Background
 * ----------
 * Every venue scraper flow has a `universal_web_scraper` step whose handler
 * config stores the venue address (city / state / zip). The flow itself sits
 * under a city pipeline — e.g. flow 731 "Northside Tavern" should live under
 * the Cincinnati Events pipeline, but currently lives under Tulsa Events
 * (pipeline 74). When the location-pollution repair in issue #98 ships, those
 * misassigned flows would start tagging events with their pipeline's market
 * (Tulsa) instead of the venue's actual market (Cincinnati). This command
 * catches that before #98's `--commit` step runs.
 *
 * Detection rule (deliberately conservative)
 * ------------------------------------------
 * A flow is flagged as misassigned only when ALL of the following hold:
 *
 *   1. Both the venue and the pipeline resolve to a market slug (via the
 *      city → market map in inc/core/location-normalizer.php, with a
 *      sluggified fallback so well-known canonical cities like "Cincinnati"
 *      that aren't in the rollup map still resolve).
 *   2. The two slugs differ.
 *   3. There is an existing pipeline whose label resolves to the venue's
 *      market slug. (We refuse to "move" a flow to a pipeline that doesn't
 *      exist — operator must run `wp extrachill-events add-city` first.)
 *   4. The venue's state matches the **state parent term** of the target
 *      pipeline's location term. This is the safety filter that rejects
 *      name-collision false positives like a California "Albany" venue
 *      matching the New York "Albany Events" pipeline.
 *
 * Anything that fails rule 4 is reported as a `name_collision` candidate
 * rather than a clean mismatch — visible in the dry-run output for operator
 * review, but never auto-moved.
 *
 * The audit deliberately does NOT flag legitimate suburb rollups like
 * Albany / Berkeley → Oakland or Black Mountain → Asheville. Those rollups
 * are encoded in `extrachill_events_get_city_market_map()` and the resolver
 * collapses both venue and pipeline through the same map before comparison.
 *
 * Repair (`--commit`)
 * -------------------
 * For each clean mismatch:
 *
 *   1. Verify the target pipeline exists. (Detection already filters for
 *      this, but re-check in case state changed mid-run.)
 *   2. Build a step-id map from the target pipeline's `pipeline_config`,
 *      keyed by `step_type` + `execution_order`, so we can rewrite each
 *      flow step's `pipeline_step_id` to a real step in the target
 *      pipeline. (Each pipeline owns its own UUID step IDs.)
 *   3. Rewrite the flow_config JSON: every step's top-level `pipeline_id`,
 *      `pipeline_step_id`, and `flow_step_id` (plus the outer object keys
 *      that mirror `flow_step_id`) all get rewritten to point at the new
 *      pipeline. Persist via `Flows::update_flow($flow_id, ['flow_config'
 *      => $array])` — the repository auto-JSON-encodes (see MEMORY.md).
 *   4. Update `c8c_7_datamachine_flows.pipeline_id` directly. `Flows::
 *      update_flow()` deliberately doesn't expose pipeline_id as a writable
 *      column, so this is the only path.
 *
 * Historical events imported by a moved flow keep their existing `location`
 * taxonomy. `wp extrachill events fix-locations` (existing) already reads
 * venue city as ground truth and will repair them on the next run; the
 * `--commit` output mentions this as a follow-up.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use DataMachine\Core\Database\Flows\Flows;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditPipelinesCommand {

	/**
	 * Audit (and optionally repair) venue scraper flows that live under the
	 * wrong city pipeline.
	 *
	 * The dry-run prints a table of mismatched flows along with their
	 * current and expected pipeline IDs. Use `--commit` to move flows to
	 * the correct pipeline.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Move flagged flows to the correct pipeline. Without this flag the
	 * command only reports.
	 *
	 * [--flow-id=<id>]
	 * : Restrict to a single flow ID. Useful for spot-checking the result
	 * of `--commit` on one flow at a time.
	 *
	 * [--include-collisions]
	 * : Also include candidates whose target pipeline state doesn't match
	 * the venue state (likely name-collision false positives). These are
	 * NEVER auto-moved even with `--commit`; the flag just surfaces them
	 * in the report for operator review.
	 *
	 * [--format=<format>]
	 * : Output format.
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
	 *     # Audit only.
	 *     wp extrachill events flows audit-pipelines --url=events.extrachill.com
	 *
	 *     # See ambiguous candidates too.
	 *     wp extrachill events flows audit-pipelines --include-collisions --url=events.extrachill.com
	 *
	 *     # Move one flow to its correct pipeline.
	 *     wp extrachill events flows audit-pipelines --flow-id=731 --commit --url=events.extrachill.com
	 *
	 *     # Apply all clean mismatches.
	 *     wp extrachill events flows audit-pipelines --commit --url=events.extrachill.com
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		if ( ! class_exists( 'DataMachine\\Core\\Database\\Flows\\Flows' ) ) {
			\WP_CLI::error( 'Data Machine Flows repository is not available.' );
			return;
		}

		$commit             = ! empty( $assoc_args['commit'] );
		$include_collisions = ! empty( $assoc_args['include-collisions'] );
		$only_flow_id       = isset( $assoc_args['flow-id'] ) ? (int) $assoc_args['flow-id'] : 0;
		$format             = $assoc_args['format'] ?? 'table';

		$pipeline_index = $this->build_pipeline_index();
		if ( empty( $pipeline_index['by_slug'] ) ) {
			\WP_CLI::error( 'No pipelines found.' );
			return;
		}

		$flows = $this->load_scraper_flows( $only_flow_id );
		if ( empty( $flows ) ) {
			\WP_CLI::log( 'No universal_web_scraper flows found.' );
			return;
		}

		$candidates = array();
		foreach ( $flows as $flow_row ) {
			$candidate = $this->evaluate_flow( $flow_row, $pipeline_index );
			if ( null === $candidate ) {
				continue;
			}
			$candidates[] = $candidate;
		}

		$mismatches = array_values(
			array_filter( $candidates, fn( $c ) => 'mismatch' === $c['status'] )
		);
		$collisions = array_values(
			array_filter( $candidates, fn( $c ) => 'name_collision' === $c['status'] )
		);

		$rows = $include_collisions
			? array_merge( $mismatches, $collisions )
			: $mismatches;

		if ( empty( $rows ) ) {
			\WP_CLI::success( 'No misassigned venue flows found.' );
			if ( ! $include_collisions && ! empty( $collisions ) ) {
				\WP_CLI::log(
					sprintf(
						'(%d name-collision candidate(s) suppressed. Re-run with --include-collisions to see them.)',
						count( $collisions )
					)
				);
			}
			return;
		}

		if ( ! $commit ) {
			\WP_CLI::log(
				sprintf(
					'Found %d misassigned flow(s)%s.',
					count( $mismatches ),
					$include_collisions && ! empty( $collisions )
						? sprintf( ' and %d name-collision candidate(s)', count( $collisions ) )
						: ''
				)
			);
			$this->render( $rows, $format );
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Re-run with --commit to move flows to the correct pipeline.' );
			return;
		}

		// --commit path: only act on clean mismatches.
		if ( empty( $mismatches ) ) {
			\WP_CLI::warning( 'No clean mismatches to commit. Name-collision candidates are not auto-moved.' );
			return;
		}

		$db_flows = new Flows();
		$applied  = array();
		$failed   = array();

		foreach ( $mismatches as $row ) {
			$result = $this->move_flow( $row, $db_flows );
			if ( $result['ok'] ) {
				$applied[] = array_merge( $row, array( 'note' => $result['note'] ) );
			} else {
				$failed[] = array_merge( $row, array( 'note' => $result['note'] ) );
			}
		}

		if ( ! empty( $applied ) ) {
			\WP_CLI::success( sprintf( 'Moved %d flow(s).', count( $applied ) ) );
			$this->render( $applied, $format );
		}

		if ( ! empty( $failed ) ) {
			\WP_CLI::warning( sprintf( '%d flow(s) failed to move.', count( $failed ) ) );
			$this->render( $failed, $format );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log(
			'Follow-up: run `wp extrachill events fix-locations` to retag historical events under the moved flows so their location terms match the venue city.'
		);
	}

	/**
	 * Build an index of pipelines by market slug + by ID, including the
	 * parsed pipeline_config for step-id rewrites.
	 *
	 * @return array{by_slug: array<string,int>, by_id: array<int,array{name:string,slug:string,config:array,steps_by_key:array<string,array>}>}
	 */
	private function build_pipeline_index(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_pipelines';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->prefix; query has no user-supplied values.
			"SELECT pipeline_id, pipeline_name, pipeline_config FROM {$table}",
			ARRAY_A
		);

		$by_slug = array();
		$by_id   = array();

		foreach ( (array) $rows as $row ) {
			$id    = (int) $row['pipeline_id'];
			$label = preg_replace( '/ Events$/', '', (string) $row['pipeline_name'] );
			$slug  = self::resolve_market_slug( $label );
			if ( '' === $slug ) {
				continue;
			}

			$config = json_decode( (string) ( $row['pipeline_config'] ?? '' ), true );
			$config = is_array( $config ) ? $config : array();

			$steps_by_key = array();
			foreach ( $config as $pipeline_step_id => $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$step_type       = (string) ( $step['step_type'] ?? '' );
				$execution_order = (int) ( $step['execution_order'] ?? 0 );
				if ( '' === $step_type ) {
					continue;
				}
				$key                  = $step_type . ':' . $execution_order;
				$steps_by_key[ $key ] = array(
					'pipeline_step_id' => (string) $pipeline_step_id,
					'step_type'        => $step_type,
					'execution_order'  => $execution_order,
				);
			}

			$by_id[ $id ] = array(
				'name'         => (string) $row['pipeline_name'],
				'slug'         => $slug,
				'config'       => $config,
				'steps_by_key' => $steps_by_key,
			);

			// First pipeline wins per slug — pipelines are operator-curated
			// 1:1 with markets, so duplicates would be a data bug.
			if ( ! isset( $by_slug[ $slug ] ) ) {
				$by_slug[ $slug ] = $id;
			}
		}

		return array(
			'by_slug' => $by_slug,
			'by_id'   => $by_id,
		);
	}

	/**
	 * Load all flows whose flow_config references universal_web_scraper.
	 *
	 * @param int $only_flow_id Restrict to a single flow when > 0.
	 * @return array<int,array<string,mixed>>
	 */
	private function load_scraper_flows( int $only_flow_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		if ( $only_flow_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->prefix.
					"SELECT flow_id, flow_name, pipeline_id, flow_config FROM {$table} WHERE flow_id = %d",
					$only_flow_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->prefix; LIKE pattern is a fixed literal with no user input.
				"SELECT flow_id, flow_name, pipeline_id, flow_config FROM {$table} WHERE flow_config LIKE '%universal_web_scraper%'",
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Evaluate one flow row and classify it as mismatch / name_collision /
	 * match. Returns null for flows that don't actually have a venue
	 * config (e.g. roundup flows that mention universal_web_scraper in an
	 * unrelated field).
	 *
	 * @param array<string,mixed>                                                                                                               $flow_row Flow DB row.
	 * @param array{by_slug: array<string,int>, by_id: array<int,array{name:string,slug:string,config:array,steps_by_key:array<string,array>}>} $pipeline_index Pipeline index.
	 * @return array<string,mixed>|null
	 */
	private function evaluate_flow( array $flow_row, array $pipeline_index ): ?array {
		$flow_config = json_decode( (string) ( $flow_row['flow_config'] ?? '' ), true );
		if ( ! is_array( $flow_config ) ) {
			return null;
		}

		$venue = $this->extract_venue_address( $flow_config );
		if ( null === $venue ) {
			return null;
		}

		$current_pipeline_id = (int) $flow_row['pipeline_id'];
		$current_pipeline    = $pipeline_index['by_id'][ $current_pipeline_id ] ?? null;
		if ( null === $current_pipeline ) {
			return null;
		}

		$pipeline_slug = $current_pipeline['slug'];
		$venue_slug    = self::resolve_market_slug( $venue['city'], $venue['state'], $venue['zip'] );
		if ( '' === $venue_slug || $venue_slug === $pipeline_slug ) {
			return null;
		}

		$target_pipeline_id = $pipeline_index['by_slug'][ $venue_slug ] ?? 0;
		if ( $target_pipeline_id <= 0 ) {
			// Venue resolves to a market that has no pipeline. Not a
			// "wrong pipeline" bug — operator needs to add the city first.
			return null;
		}

		$target_pipeline = $pipeline_index['by_id'][ $target_pipeline_id ] ?? null;
		if ( null === $target_pipeline ) {
			return null;
		}

		$state_match = $this->venue_state_matches_pipeline( $venue['state'], $target_pipeline['slug'] );

		return array(
			'status'               => $state_match ? 'mismatch' : 'name_collision',
			'flow_id'              => (int) $flow_row['flow_id'],
			'flow_name'            => (string) $flow_row['flow_name'],
			'venue_city'           => $venue['city'],
			'venue_state'          => $venue['state'],
			'venue_zip'            => $venue['zip'],
			'current_pipeline_id'  => $current_pipeline_id,
			'current_pipeline'     => $current_pipeline['name'],
			'expected_pipeline_id' => $target_pipeline_id,
			'expected_pipeline'    => $target_pipeline['name'],
			'_flow_config'         => $flow_config,
			'_target_pipeline'     => $target_pipeline,
		);
	}

	/**
	 * Extract venue city/state/zip from the universal_web_scraper step in a
	 * flow_config. Supports both handler-shape variants (handler_slug +
	 * handler_config; handler_slugs[] + handler_configs{slug}).
	 *
	 * @param array<string,mixed> $flow_config Decoded flow_config.
	 * @return array{city:string,state:string,zip:string}|null
	 */
	private function extract_venue_address( array $flow_config ): ?array {
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$handler = null;

			if ( isset( $step['handler_configs']['universal_web_scraper'] )
				&& is_array( $step['handler_configs']['universal_web_scraper'] ) ) {
				$handler = $step['handler_configs']['universal_web_scraper'];
			} elseif ( ( $step['handler_slug'] ?? '' ) === 'universal_web_scraper'
				&& isset( $step['handler_config'] )
				&& is_array( $step['handler_config'] ) ) {
				$handler = $step['handler_config'];
			}

			if ( null === $handler ) {
				continue;
			}

			$city  = trim( (string) ( $handler['venue_city'] ?? '' ) );
			$state = trim( (string) ( $handler['venue_state'] ?? '' ) );
			$zip   = trim( (string) ( $handler['venue_zip'] ?? '' ) );

			if ( '' === $city ) {
				return null;
			}

			return array(
				'city'  => $city,
				'state' => $state,
				'zip'   => $zip,
			);
		}

		return null;
	}

	/**
	 * Resolve a city label (or full city/state/zip) to a market slug.
	 *
	 * Resolution order:
	 *   1. The city → market map (handles rollups: Black Mountain → Asheville etc.)
	 *   2. Sluggified city — covers canonical cities not in the rollup map
	 *      (e.g. Cincinnati, Tulsa).
	 *
	 * @param string $city  City label or pipeline label (with " Events" suffix already stripped).
	 * @param string $state Optional state.
	 * @param string $zip   Optional zip.
	 * @return string Market slug, or empty string when nothing resolves.
	 */
	private static function resolve_market_slug( string $city, string $state = '', string $zip = '' ): string {
		if ( '' === trim( $city ) ) {
			return '';
		}

		if ( function_exists( 'extrachill_events_get_market_slug_for_venue' ) ) {
			$mapped = extrachill_events_get_market_slug_for_venue( $city, $state, $zip );
			if ( is_string( $mapped ) && '' !== $mapped ) {
				return $mapped;
			}
		}

		return (string) sanitize_title( $city );
	}

	/**
	 * Does the venue's state match the state parent term of the target
	 * pipeline's location term? Returns true when we can confidently say
	 * they match, false when they don't, and true (allow-through) when we
	 * can't tell either way — the caller treats the indeterminate case as
	 * if it matched, since refusing to act on indeterminate state would
	 * suppress real bugs.
	 *
	 * The Northside Tavern fix relies on this: venue state OH must match
	 * the Cincinnati term's parent (Ohio) before we move the flow.
	 *
	 * @param string $venue_state         Venue state (abbrev or full name).
	 * @param string $target_pipeline_slug Slug we'd move the flow toward.
	 * @return bool
	 */
	private function venue_state_matches_pipeline( string $venue_state, string $target_pipeline_slug ): bool {
		$venue_state = trim( $venue_state );
		if ( '' === $venue_state ) {
			return true; // No venue state — can't disprove, allow through.
		}

		// Find the location term whose slug matches the target. Prefer an
		// exact slug match.
		$target_term = get_term_by( 'slug', $target_pipeline_slug, 'location' );
		if ( ! $target_term || is_wp_error( $target_term ) || $target_term->parent <= 0 ) {
			return true; // Indeterminate.
		}

		$parent = get_term( $target_term->parent, 'location' );
		if ( ! $parent || is_wp_error( $parent ) ) {
			return true;
		}

		$parent_name = strtolower( $parent->name );
		$venue_lower = strtolower( $venue_state );

		if ( $parent_name === $venue_lower ) {
			return true;
		}

		$abbrev_map = self::state_abbreviation_map();
		$expanded   = $abbrev_map[ strtoupper( $venue_state ) ] ?? null;
		if ( $expanded && strtolower( $expanded ) === $parent_name ) {
			return true;
		}

		return false;
	}

	/**
	 * US state abbreviation → full name map.
	 *
	 * Duplicated narrowly here (also exists in EventLocationAlignmentAbilities)
	 * to avoid coupling a thin CLI command to a heavier abilities class.
	 *
	 * @return array<string,string>
	 */
	private static function state_abbreviation_map(): array {
		return array(
			'AL' => 'Alabama',
			'AK' => 'Alaska',
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'DC' => 'District of Columbia',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
		);
	}

	/**
	 * Move one flow to its expected pipeline. Rewrites every step's
	 * pipeline_id / pipeline_step_id / flow_step_id (and outer object keys)
	 * to reference the target pipeline's step UUIDs, then persists.
	 *
	 * @param array<string,mixed> $row      Candidate row from evaluate_flow().
	 * @param Flows               $db_flows Data Machine flows repository.
	 * @return array{ok:bool, note:string}
	 */
	private function move_flow( array $row, Flows $db_flows ): array {
		$flow_id            = (int) $row['flow_id'];
		$old_pipeline_id    = (int) $row['current_pipeline_id'];
		$new_pipeline_id    = (int) $row['expected_pipeline_id'];
		$flow_config        = $row['_flow_config'];
		$target_pipeline    = $row['_target_pipeline'];
		$target_steps_index = $target_pipeline['steps_by_key'] ?? array();

		// Pre-flight: every step in the source flow_config must have a
		// matching step in the target pipeline by step_type + execution_order.
		$rewrite_plan = array();
		foreach ( $flow_config as $old_flow_step_key => $step ) {
			if ( ! is_array( $step ) ) {
				return array(
					'ok'   => false,
					'note' => 'malformed flow_config (non-array step)',
				);
			}
			$step_type       = (string) ( $step['step_type'] ?? '' );
			$execution_order = (int) ( $step['execution_order'] ?? 0 );
			$key             = $step_type . ':' . $execution_order;
			if ( ! isset( $target_steps_index[ $key ] ) ) {
				return array(
					'ok'   => false,
					'note' => sprintf(
						'target pipeline %d is missing a %s step at execution_order %d',
						$new_pipeline_id,
						$step_type,
						$execution_order
					),
				);
			}
			$new_pipeline_step_id               = $target_steps_index[ $key ]['pipeline_step_id'];
			$new_flow_step_id                   = $new_pipeline_step_id . '_' . $flow_id;
			$rewrite_plan[ $old_flow_step_key ] = array(
				'new_flow_step_id'     => $new_flow_step_id,
				'new_pipeline_step_id' => $new_pipeline_step_id,
			);
		}

		// Apply the rewrite into a fresh array so we preserve outer ordering.
		$new_flow_config = array();
		foreach ( $flow_config as $old_flow_step_key => $step ) {
			$plan                     = $rewrite_plan[ $old_flow_step_key ];
			$step['flow_step_id']     = $plan['new_flow_step_id'];
			$step['pipeline_step_id'] = $plan['new_pipeline_step_id'];
			$step['pipeline_id']      = $new_pipeline_id;
			$step['flow_id']          = $flow_id;
			$new_flow_config[ $plan['new_flow_step_id'] ] = $step;
		}

		// Persist flow_config via the repository (auto-JSON-encodes).
		$ok = $db_flows->update_flow( $flow_id, array( 'flow_config' => $new_flow_config ) );
		if ( ! $ok ) {
			return array(
				'ok'   => false,
				'note' => 'Flows::update_flow returned false',
			);
		}

		// Update pipeline_id directly — Flows::update_flow doesn't expose it.
		global $wpdb;
		$flows_table = $wpdb->prefix . 'datamachine_flows';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			$flows_table,
			array( 'pipeline_id' => $new_pipeline_id ),
			array( 'flow_id' => $flow_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return array(
				'ok'   => false,
				'note' => 'pipeline_id update failed: ' . $wpdb->last_error,
			);
		}

		return array(
			'ok'   => true,
			'note' => sprintf( 'moved pipeline_id %d → %d', $old_pipeline_id, $new_pipeline_id ),
		);
	}

	/**
	 * Render the result set using wp-cli formatter.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows to render.
	 * @param string                         $format table|json|csv.
	 */
	private function render( array $rows, string $format ): void {
		// Strip internal fields (prefixed with `_`).
		$visible = array_map(
			static function ( $row ) {
				$out = array();
				foreach ( $row as $k => $v ) {
					if ( is_string( $k ) && 0 === strpos( $k, '_' ) ) {
						continue;
					}
					$out[ $k ] = $v;
				}
				return $out;
			},
			$rows
		);

		if ( empty( $visible ) ) {
			return;
		}

		$fields = array_keys( $visible[0] );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $visible, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $visible, $fields );
	}
}
