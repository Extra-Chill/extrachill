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
 * known network site. The local hop reuses `agents/ability-search` directly
 * via `NetworkDispatch::run_local_ability()` and drops entries the MCP may
 * not invoke (see Exposure). Every other known site is listed through core's
 * `GET /wp-abilities/v1/abilities` collection via
 * `NetworkDispatch::list_abilities()` (`ec_cross_site_rest_request()`
 * underneath, forced onto the HTTP-loopback transport so the target's own
 * registry answers — Extra-Chill/extrachill-mcp#22) and matched locally
 * with the substrate's query syntax. Results are merged and each entry is
 * tagged with the site key that owns it.
 *
 * Core's collection returns exactly the target's `show_in_rest` abilities
 * and needs only the `read` capability, which is the same set a cross-site
 * ability-call can reach through the run route. Remote search therefore
 * works for every MCP user without a host permission filter being active on
 * the target site (Extra-Chill/extrachill-mcp#25).
 *
 * Presence in a result is discovery, not authorization: the abilities
 * listed for a remote site are the ones it exposes over REST. This class does not
 * additionally probe `check_permissions()` per result — venue-scoped booking
 * abilities deny everyone, including administrators, when probed with empty
 * input (no `booking_id` to resolve a venue from), so pre-filtering that way
 * would hide bookings from the exact user the feature exists for. The real
 * gate is `WP_Ability::execute()`'s own `check_permissions()` on
 * `extrachill/ability-call` at call time.
 */
final class NetworkAbilitySearch {

	private Access $access;

	/** Default number of ranked results returned. */
	public const DEFAULT_LIMIT = 10;
	/** Upper bound on ranked results returned. */
	public const MAX_LIMIT = 50;
	/** How many matches each site contributes before network-wide ranking. */
	public const PER_SITE_LIMIT = 100;
	/** Top results that carry a compact input schema. */
	public const SCHEMA_DETAIL_COUNT = 3;
	/** Longest property description kept in a compact schema. */
	public const SCHEMA_DESCRIPTION_MAX = 160;

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
				'description'         => __( 'Search abilities registered anywhere on the Extra Chill network — editorial, artists, events, venues and bookings, community, shop, and newsletter. Results are ranked by relevance and listed once with every site that exposes them (`sites`); the top hits include a compact input_schema. Use select:name for an exact ability and its parameters.', 'extrachill-mcp' ),
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
		$query       = is_string( $input['query'] ?? null ) ? trim( $input['query'] ) : '';
		$category    = is_string( $input['category'] ?? null ) ? trim( $input['category'] ) : '';
		$limit_value = $input['limit'] ?? self::DEFAULT_LIMIT;
		$limit       = max( 1, min( self::MAX_LIMIT, is_numeric( $limit_value ) ? (int) $limit_value : self::DEFAULT_LIMIT ) );

		// Each site is searched wide; the final list is ranked and limited once.
		$search_input = array(
			'query'    => $query,
			'category' => $category,
			'limit'    => self::PER_SITE_LIMIT,
		);

		/** @var array<string,array<string,mixed>> $remote_records Remote REST records by ability name, for ranking and schemas. */
		$remote_records = array();

		/** @var array<int,array<string,mixed>> $abilities Declared so the by-ref merge below type-checks. */
		$abilities     = array();
		$sites_queried = array();
		$errors        = array();

		$local_site_key = NetworkDispatch::local_site_key();
		$local_result    = NetworkDispatch::run_local_ability( 'agents/ability-search', $search_input );

		if ( is_wp_error( $local_result ) ) {
			$errors[ $local_site_key ?? 'local' ] = NetworkDispatch::describe_error( $local_result );
		} else {
			$sites_queried[] = $local_site_key ?? 'local';
			$this->merge_results( $abilities, self::exposed_only( $local_result ), $local_site_key );
		}

		foreach ( NetworkDispatch::remote_sites() as $site_key ) {
			$records = NetworkDispatch::list_abilities( $site_key, $category );

			if ( is_wp_error( $records ) ) {
				$errors[ $site_key ] = NetworkDispatch::describe_error( $records );
				continue;
			}

			$sites_queried[] = $site_key;
			foreach ( $records as $record ) {
				if ( is_string( $record['name'] ?? null ) && ! isset( $remote_records[ $record['name'] ] ) ) {
					$remote_records[ $record['name'] ] = $record;
				}
			}
			$this->merge_results( $abilities, array( 'abilities' => self::match_records( $records, $query, self::PER_SITE_LIMIT ) ), $site_key );
		}

		$abilities = self::rank( self::dedupe( $abilities ), $query, count( $sites_queried ), $remote_records );
		$abilities = array_slice( $abilities, 0, $limit );
		$abilities = self::attach_schemas( $abilities, $query, $remote_records );

		return array(
			'query'         => $query,
			'count'         => count( $abilities ),
			'abilities'     => $abilities,
			'sites_queried' => $sites_queried,
			'errors'        => $errors,
		);
	}

	/**
	 * Collapse entries for the same ability on several sites into one entry.
	 *
	 * The first site that reported the ability stays in `site` (backward
	 * compatible); every site is listed in `sites`.
	 *
	 * @param array<int,array<string,mixed>> $abilities Per-site entries.
	 * @return array<int,array<string,mixed>>
	 */
	public static function dedupe( array $abilities ): array {
		$merged = array();

		foreach ( $abilities as $entry ) {
			$name = is_string( $entry['name'] ?? null ) ? $entry['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$site = is_string( $entry['site'] ?? null ) ? $entry['site'] : null;

			if ( ! isset( $merged[ $name ] ) ) {
				$entry['sites']  = null === $site ? array() : array( $site );
				$merged[ $name ] = $entry;
				continue;
			}

			if ( null !== $site && ! in_array( $site, $merged[ $name ]['sites'], true ) ) {
				$merged[ $name ]['sites'][] = $site;
			}
		}

		return array_values( $merged );
	}

	/**
	 * Order deduplicated entries by relevance to the query.
	 *
	 * Per term: a hit in the ability's local name (after the namespace) scores
	 * 4, in the namespace 3, in the label 2, in the summary or category 1. An
	 * exact name match wins outright. Abilities present on every queried site
	 * (shared plumbing such as chat-run or analytics helpers) score half, so a
	 * domain ability with the same hits ranks above them. Ties keep name order.
	 *
	 * @param array<int,array<string,mixed>>    $abilities      Deduplicated entries.
	 * @param string                            $query          Search query.
	 * @param int                               $site_count     Number of sites queried.
	 * @param array<string,array<string,mixed>> $remote_records Remote records by name.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank( array $abilities, string $query, int $site_count, array $remote_records = array() ): array {
		$parsed = function_exists( 'agents_parse_ability_search_query' )
			? agents_parse_ability_search_query( $query )
			: self::parse_query( $query );
		$terms  = array_values( array_filter( array_map( 'strtolower', array_map( 'strval', array_merge( (array) ( $parsed['terms'] ?? array() ), (array) ( $parsed['required'] ?? array() ) ) ) ) ) );
		$exact  = strtolower( trim( $query ) );

		$scored = array();
		foreach ( $abilities as $entry ) {
			$name     = strtolower( (string) $entry['name'] );
			$slash    = strpos( $name, '/' );
			$ns       = false === $slash ? '' : substr( $name, 0, $slash );
			$local    = false === $slash ? $name : substr( $name, $slash + 1 );
			$label    = strtolower( self::label_for( (string) $entry['name'], $remote_records ) );
			$category = strtolower( (string) ( $remote_records[ $entry['name'] ]['category'] ?? '' ) );
			$summary  = strtolower( (string) ( $entry['summary'] ?? '' ) );

			$score = 0.0;
			foreach ( $terms as $term ) {
				if ( str_contains( $local, $term ) ) {
					$score += 4;
				} elseif ( str_contains( $ns, $term ) ) {
					$score += 3;
				} elseif ( '' !== $label && str_contains( $label, $term ) ) {
					$score += 2;
				} elseif ( str_contains( $summary, $term ) || str_contains( $category, $term ) ) {
					++$score;
				}
			}
			if ( '' !== $exact && $exact === $name ) {
				$score += 1000;
			}

			$sites      = is_array( $entry['sites'] ?? null ) ? $entry['sites'] : array();
			$everywhere = $site_count > 1 && count( $sites ) >= $site_count;
			if ( $everywhere ) {
				$score /= 2;
			}

			$scored[] = array( $score, $everywhere ? 1 : 0, (string) $entry['name'], $entry );
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				return array( $b[0], $a[1], $a[2] ) <=> array( $a[0], $b[1], $b[2] );
			}
		);

		return array_column( $scored, 3 );
	}

	/**
	 * Attach a compact input schema to the top results, or to every result
	 * of a `select:` query, so the caller can pass parameters without guessing.
	 *
	 * @param array<int,array<string,mixed>>    $abilities      Ranked entries.
	 * @param string                            $query          Search query.
	 * @param array<string,array<string,mixed>> $remote_records Remote records by name.
	 * @return array<int,array<string,mixed>>
	 */
	private static function attach_schemas( array $abilities, string $query, array $remote_records ): array {
		$is_select = str_contains( $query, 'select:' );

		foreach ( $abilities as $index => $entry ) {
			if ( ! $is_select && $index >= self::SCHEMA_DETAIL_COUNT ) {
				break;
			}

			$name   = (string) $entry['name'];
			$schema = null;
			if ( isset( $remote_records[ $name ]['input_schema'] ) && is_array( $remote_records[ $name ]['input_schema'] ) ) {
				$schema = $remote_records[ $name ]['input_schema'];
			} elseif ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				$ability = wp_get_ability( $name );
				if ( $ability instanceof \WP_Ability ) {
					$schema = $ability->get_input_schema();
				}
			}

			if ( is_array( $schema ) ) {
				$abilities[ $index ]['input_schema'] = self::compact_schema( $schema );
			}
		}

		return $abilities;
	}

	/**
	 * Reduce an input schema to one level: property type, trimmed
	 * description, enum and default, plus the required list.
	 *
	 * @param array<string,mixed> $schema Full input schema.
	 * @return array<string,mixed>
	 */
	public static function compact_schema( array $schema ): array {
		$compact = array( 'type' => 'object' );

		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		$out        = array();
		foreach ( $properties as $key => $property ) {
			if ( ! is_string( $key ) || ! is_array( $property ) ) {
				continue;
			}
			$item = array();
			if ( isset( $property['type'] ) ) {
				$item['type'] = $property['type'];
			}
			if ( is_string( $property['description'] ?? null ) && '' !== trim( $property['description'] ) ) {
				$description         = (string) preg_replace( '/\s+/', ' ', trim( $property['description'] ) );
				$item['description'] = strlen( $description ) > self::SCHEMA_DESCRIPTION_MAX
					? rtrim( substr( $description, 0, self::SCHEMA_DESCRIPTION_MAX - 1 ) ) . '…'
					: $description;
			}
			if ( is_array( $property['enum'] ?? null ) ) {
				$item['enum'] = array_values( $property['enum'] );
			}
			if ( array_key_exists( 'default', $property ) && is_scalar( $property['default'] ) ) {
				$item['default'] = $property['default'];
			}
			$out[ $key ] = $item;
		}
		if ( array() !== $out ) {
			$compact['properties'] = $out;
		}

		$required = is_array( $schema['required'] ?? null ) ? array_values( array_filter( $schema['required'], 'is_string' ) ) : array();
		if ( array() !== $required ) {
			$compact['required'] = $required;
		}

		return $compact;
	}

	/**
	 * Ability label from the remote record or the local registry.
	 *
	 * @param string                            $name           Ability name.
	 * @param array<string,array<string,mixed>> $remote_records Remote records by name.
	 */
	private static function label_for( string $name, array $remote_records ): string {
		if ( is_string( $remote_records[ $name ]['label'] ?? null ) ) {
			return $remote_records[ $name ]['label'];
		}
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			$ability = wp_get_ability( $name );
			if ( $ability instanceof \WP_Ability ) {
				return (string) $ability->get_label();
			}
		}
		return '';
	}

	/**
	 * Match a remote site's REST ability records against a search query and
	 * shape them like agents/ability-search compact entries.
	 *
	 * Mirrors the substrate's matching (agents_ability_matches_query()):
	 * `select:` picks exact names, otherwise every term and +required term
	 * must appear in the name, label, description or category. Parsing is
	 * delegated to the substrate when it is loaded, so query syntax stays
	 * owned there.
	 *
	 * @param array<int,array<string,mixed>> $records REST ability records.
	 * @return array<int,array<string,mixed>>
	 */
	public static function match_records( array $records, string $query, int $limit ): array {
		$parsed   = function_exists( 'agents_parse_ability_search_query' )
			? agents_parse_ability_search_query( $query )
			: self::parse_query( $query );
		$selected = array_map( 'strval', (array) ( $parsed['selected'] ?? array() ) );
		$terms    = array_map( 'strval', array_merge( (array) ( $parsed['terms'] ?? array() ), (array) ( $parsed['required'] ?? array() ) ) );

		$entries = array();
		foreach ( $records as $record ) {
			$name = is_string( $record['name'] ?? null ) ? $record['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			if ( array() !== $selected ) {
				if ( ! in_array( $name, $selected, true ) ) {
					continue;
				}
			} else {
				$haystack = strtolower( implode( ' ', array( $name, (string) ( $record['label'] ?? '' ), (string) ( $record['description'] ?? '' ), (string) ( $record['category'] ?? '' ) ) ) );
				foreach ( $terms as $term ) {
					if ( '' !== $term && ! str_contains( $haystack, strtolower( $term ) ) ) {
						continue 2;
					}
				}
			}

			$summary = trim( (string) ( $record['description'] ?? '' ) );
			if ( '' === $summary ) {
				$summary = trim( (string) ( $record['label'] ?? '' ) );
			}
			$schema   = is_array( $record['input_schema'] ?? null ) ? $record['input_schema'] : array();
			$required = is_array( $schema['required'] ?? null ) ? array_values( array_filter( $schema['required'], 'is_string' ) ) : array();

			$entries[] = array(
				'name'            => $name,
				'summary'         => (string) preg_replace( '/\s+/', ' ', $summary ),
				'required_fields' => $required,
			);

			if ( count( $entries ) >= $limit ) {
				break;
			}
		}

		return $entries;
	}

	/**
	 * Fallback query parser matching agents_parse_ability_search_query(),
	 * used only when the substrate function is not loaded.
	 *
	 * @return array{terms: array<int,string>, required: array<int,string>, selected: array<int,string>}
	 */
	private static function parse_query( string $query ): array {
		$parsed = array(
			'terms'    => array(),
			'required' => array(),
			'selected' => array(),
		);

		$tokens = preg_split( '/\s+/', trim( $query ) );

		foreach ( false === $tokens ? array() : $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( str_starts_with( $token, 'select:' ) ) {
				$parsed['selected'] = array_merge( $parsed['selected'], array_values( array_filter( array_map( 'trim', explode( ',', substr( $token, 7 ) ) ) ) ) );
			} elseif ( str_starts_with( $token, '+' ) ) {
				$parsed['required'][] = strtolower( substr( $token, 1 ) );
			} else {
				$parsed['terms'][] = strtolower( $token );
			}
		}

		return $parsed;
	}

	/**
	 * Drop local entries the MCP may not invoke (see Exposure), so search
	 * never advertises an ability that ability-call would refuse.
	 *
	 * @param mixed $result Raw local search result.
	 * @return mixed
	 */
	private static function exposed_only( $result ) {
		if ( ! is_array( $result ) || ! isset( $result['abilities'] ) || ! is_array( $result['abilities'] ) ) {
			return $result;
		}

		$result['abilities'] = array_values(
			array_filter(
				$result['abilities'],
				static function ( $entry ): bool {
					return ! is_array( $entry ) || ! is_string( $entry['name'] ?? null ) || Exposure::is_locally_exposed( $entry['name'] );
				}
			)
		);

		return $result;
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
					'description' => __( 'Maximum number of ranked ability entries to return across the whole network.', 'extrachill-mcp' ),
					'default'     => self::DEFAULT_LIMIT,
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
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
								'description' => __( 'Network site key that owns this ability (e.g. "events"), or null when unresolved. The first of `sites`.', 'extrachill-mcp' ),
							),
							'sites'           => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Every network site that exposes this ability.', 'extrachill-mcp' ),
							),
							'input_schema'    => array(
								'type'        => 'object',
								'description' => __( 'Compact input schema (property type, description, enum, default; required). Present on the top results and on select: results.', 'extrachill-mcp' ),
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
