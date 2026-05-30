<?php
/**
 * Flow location guard.
 *
 * Centralizes the rule: `taxonomy_location_selection` on an `upsert_event`
 * flow MUST be a concrete location term ID (as a string) or term-name
 * fallback. Values of `ai_decides`, empty string, or `skip` are not allowed
 * because `location` is a derived taxonomy — the market is determined by
 * the venue's city/state/zip, never by the AI.
 *
 * See Extra-Chill/extrachill-events#98 for full context. This guard is
 * defensive code in extrachill-events because data-machine does not (yet)
 * expose a `datamachine_flow_config_pre_save` filter. A follow-up issue
 * tracks adding that hook upstream; until then, every EC writer that
 * touches flow_config on an `upsert_event` flow must route through this
 * guard.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowLocationGuard {

	/**
	 * Values that are NOT acceptable for `taxonomy_location_selection` on
	 * an `upsert_event` flow. `ai_decides` is the main offender — see
	 * issue #98. Empty string and `skip` would also defeat the purpose
	 * (no location at all), so we coerce those too.
	 */
	private const REJECTED_VALUES = array( 'ai_decides', '', 'skip' );

	/**
	 * Walk a flow_config array and coerce any forbidden
	 * `taxonomy_location_selection` value on an `upsert_event` step to the
	 * caller-supplied pipeline location term.
	 *
	 * Returns a new config (does not mutate $config) plus a list of step
	 * IDs that were coerced. Caller decides whether to write the result
	 * back and how to surface the warning.
	 *
	 * If $pipeline_location_term is empty (i.e. the pipeline doesn't
	 * resolve to a market term), no coercion happens — the caller gets
	 * the original config and an empty coerced list. That avoids
	 * stamping a bogus value when there's nothing better to substitute.
	 *
	 * @param array  $config                  Flow config (associative,
	 *                                        step_id => step_data).
	 * @param string $pipeline_location_term  Term ID string (preferred)
	 *                                        or term name, sourced from
	 *                                        the pipeline this flow
	 *                                        belongs to.
	 * @return array{config:array,coerced:array<int,array{step_id:string,old:string,new:string}>}
	 */
	public static function coerceUpsertEventLocation( array $config, string $pipeline_location_term ): array {
		$coerced = array();

		if ( '' === $pipeline_location_term ) {
			return array(
				'config'  => $config,
				'coerced' => $coerced,
			);
		}

		foreach ( $config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$handler_configs = $step['handler_configs'] ?? array();
			if ( ! is_array( $handler_configs ) || ! isset( $handler_configs['upsert_event'] ) ) {
				continue;
			}

			$upsert = $handler_configs['upsert_event'];
			if ( ! is_array( $upsert ) ) {
				continue;
			}

			$current = $upsert['taxonomy_location_selection'] ?? '';
			if ( ! is_string( $current ) ) {
				continue;
			}

			if ( ! self::isRejectedValue( $current ) ) {
				continue;
			}

			$step['handler_configs']['upsert_event']['taxonomy_location_selection'] = $pipeline_location_term;

			$coerced[] = array(
				'step_id' => (string) $step_id,
				'old'     => $current,
				'new'     => $pipeline_location_term,
			);
		}
		unset( $step );

		return array(
			'config'  => $config,
			'coerced' => $coerced,
		);
	}

	/**
	 * True if $value would make `location` AI-decided / unset on an
	 * upsert_event flow and therefore must be rejected.
	 */
	public static function isRejectedValue( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), self::REJECTED_VALUES, true );
	}

	/**
	 * Resolve a pipeline's canonical location term, returned as a
	 * stringified term ID suitable for stamping into
	 * `taxonomy_location_selection`.
	 *
	 * Strategy:
	 * 1. Strip a trailing " Events" from the pipeline name. This matches
	 *    the convention every EC city pipeline uses ("Charleston Events",
	 *    "Pittsburgh Events", …).
	 * 2. Use a state-aware lookup (parent matches state abbreviation in
	 *    the name like "Wilmington NC Events") to handle disambiguated
	 *    pipeline names — see CityAbilities::ensureLocationTerm which
	 *    creates those.
	 * 3. Plain name lookup fallback.
	 *
	 * Returns '' if no term can be resolved — caller treats that as
	 * "skip this flow, the operator must fix it manually".
	 */
	public static function resolvePipelineLocationTermId( string $pipeline_name ): string {
		if ( ! taxonomy_exists( 'location' ) ) {
			return '';
		}

		$pipeline_name = trim( $pipeline_name );
		if ( '' === $pipeline_name ) {
			return '';
		}

		// Strip trailing " Events" suffix (case-insensitive).
		$base = preg_replace( '/\s+Events\s*$/i', '', $pipeline_name );
		$base = is_string( $base ) ? trim( $base ) : '';
		if ( '' === $base ) {
			return '';
		}

		// Detect a disambiguated pipeline name like "Wilmington NC" —
		// the last token is a US state abbreviation. We use that to
		// pick the right Wilmington (NC vs DE).
		$state_abbr = '';
		$city       = $base;
		if ( preg_match( '/^(.+)\s+([A-Z]{2})$/', $base, $m ) ) {
			$city       = trim( $m[1] );
			$state_abbr = strtoupper( $m[2] );
		}

		// Build a list of candidate location terms with this name.
		$candidates = get_terms(
			array(
				'taxonomy'   => 'location',
				'name'       => $city,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $candidates ) || empty( $candidates ) ) {
			return '';
		}

		// If only one match, use it.
		if ( 1 === count( $candidates ) ) {
			return (string) $candidates[0]->term_id;
		}

		// Multiple candidates — try to disambiguate by state.
		if ( '' !== $state_abbr ) {
			$state_full = self::stateAbbreviationToName( $state_abbr );
			foreach ( $candidates as $candidate ) {
				if ( (int) $candidate->parent <= 0 ) {
					continue;
				}
				$parent = get_term( (int) $candidate->parent, 'location' );
				if ( ! $parent || is_wp_error( $parent ) ) {
					continue;
				}
				if ( '' !== $state_full && strcasecmp( $parent->name, $state_full ) === 0 ) {
					return (string) $candidate->term_id;
				}
				if ( strcasecmp( $parent->name, $state_abbr ) === 0 ) {
					return (string) $candidate->term_id;
				}
			}
		}

		// Multiple matches and no state to disambiguate — refuse rather
		// than guess wrong.
		return '';
	}

	/**
	 * Minimal US state abbreviation → full name map, intentionally
	 * duplicated from EventLocationAlignmentAbilities so this guard
	 * doesn't depend on that ability's load order.
	 */
	private static function stateAbbreviationToName( string $abbr ): string {
		static $map = array(
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

		return $map[ strtoupper( $abbr ) ] ?? '';
	}
}
