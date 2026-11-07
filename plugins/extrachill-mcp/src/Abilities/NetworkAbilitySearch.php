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
 * known network site. The local registry is matched without a pre-ranking
 * limit, excluding entries the MCP may not invoke (see Exposure).
 * Every other known site is listed through core's
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

		/** @var array<string,array<string,mixed>> $remote_records Local and remote records by ability name, for ranking and schemas. */
		$remote_records = array();

		/** @var array<int,array<string,mixed>> $abilities Declared so the by-ref merge below type-checks. */
		$abilities     = array();
		$sites_queried = array();
		$errors        = array();

		$local_site_key  = NetworkDispatch::local_site_key();
		$sites_queried[] = $local_site_key ?? 'local';
		$local_records   = self::local_records( $category );
		foreach ( $local_records as $record ) {
			$remote_records[ $record['name'] ] = $record;
		}
		$this->merge_results( $abilities, array( 'abilities' => self::match_records( $local_records, $query ) ), $local_site_key );

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
			$this->merge_results( $abilities, array( 'abilities' => self::match_records( $records, $query ) ), $site_key );
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
	 * Per query word (see expand_terms()): a whole hyphen-delimited segment of
	 * the local name (after the namespace) scores 6, a substring of the local
	 * name 4, the namespace 3, the label 2, the summary or category 1.
	 * Synonyms score at a reduced weight. When every query word hits the name
	 * or label the score gets a 1.5x bonus. An exact name match wins outright.
	 * Abilities present on every queried site score 0.75x, so a domain ability
	 * with the same hits ranks above them while network-wide tools such as
	 * web-fetch still surface. Ties keep name order.
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
		$groups = self::expand_terms( array_merge( (array) ( $parsed['terms'] ?? array() ), (array) ( $parsed['required'] ?? array() ) ) );
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

			$segments = preg_split( '/[^a-z0-9]+/', $local, -1, PREG_SPLIT_NO_EMPTY );
			$segments = is_array( $segments ) ? $segments : array();

			$score   = 0.0;
			$covered = 0;
			foreach ( $groups as $group ) {
				$best     = 0.0;
				$in_title = false;
				foreach ( $group['alternates'] as $term => $weight ) {
					$hit = 0.0;
					if ( in_array( $term, $segments, true ) ) {
						$hit = 6;
					} elseif ( str_contains( $local, $term ) ) {
						$hit = 4;
					} elseif ( str_contains( $ns, $term ) ) {
						$hit = 3;
					} elseif ( '' !== $label && str_contains( $label, $term ) ) {
						$hit = 2;
					} elseif ( str_contains( $summary, $term ) || str_contains( $category, $term ) ) {
						$hit = 1;
					}
					$best     = max( $best, $hit * $weight );
					$in_title = $in_title || $hit >= 2;
				}
				$score += $best;
				if ( $in_title && $group['counts'] ) {
					++$covered;
				}
			}

			/* Every query word in the name or label beats one word matched strongly. */
			$counted = count( array_filter( array_column( $groups, 'counts' ) ) );
			if ( $counted > 1 && $covered === $counted ) {
				$score *= 1.5;
			}
			if ( '' !== $exact && $exact === $name ) {
				$score += 1000;
			}

			/*
			 * Abilities present on every queried site (network-active plugins)
			 * are often plumbing, but some are real tools (web fetch, media
			 * upload). Demote them gently so a strong match still surfaces.
			 */
			$sites      = is_array( $entry['sites'] ?? null ) ? $entry['sites'] : array();
			$everywhere = $site_count > 1 && count( $sites ) >= $site_count;
			if ( $everywhere ) {
				$score *= 0.75;
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
	 * `select:` picks exact names. Otherwise at least one plain term and every
	 * +required term must appear in the name, label, description or category.
	 * Queries without plain terms only apply the required filters. Parsing is
	 * delegated to the substrate when it is loaded, so query syntax stays
	 * owned there.
	 *
	 * @param array<int,array<string,mixed>> $records REST ability records.
	 * @return array<int,array<string,mixed>>
	 */
	public static function match_records( array $records, string $query ): array {
		$parsed         = function_exists( 'agents_parse_ability_search_query' )
			? agents_parse_ability_search_query( $query )
			: self::parse_query( $query );
		$selected       = array_map( 'strval', (array) ( $parsed['selected'] ?? array() ) );
		$terms          = array_values( array_filter( array_map( 'strval', (array) ( $parsed['terms'] ?? array() ) ), static fn( string $term ): bool => '' !== $term ) );
		$required_terms = array_map( 'strval', (array) ( $parsed['required'] ?? array() ) );

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
				foreach ( $required_terms as $term ) {
					if ( '' !== $term && ! str_contains( $haystack, strtolower( $term ) ) ) {
						continue 2;
					}
				}

				$matches_term = array() === $terms;
				foreach ( self::expand_terms( $terms ) as $group ) {
					foreach ( array_keys( $group['alternates'] ) as $alternate ) {
						if ( str_contains( $haystack, (string) $alternate ) ) {
							$matches_term = true;
							break 2;
						}
					}
				}
				if ( ! $matches_term ) {
					continue;
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
		}

		return $entries;
	}

	/**
	 * Words that carry no meaning in an ability search.
	 */
	private const STOPWORDS = array( 'a', 'an', 'and', 'the', 'to', 'of', 'for', 'in', 'on', 'with', 'my', 'me', 'i', 'is', 'it', 'from', 'by', 'or' );

	/**
	 * Light vocabulary bridges: a query word also matches these words, at a
	 * reduced weight, so "fetch url" or "read a web page" reach web-fetch.
	 */
	private const SYNONYMS = array(
		'url'     => array( 'web', 'link' ),
		'urls'    => array( 'web', 'link' ),
		'link'    => array( 'url', 'web' ),
		'website' => array( 'web', 'site' ),
		'webpage' => array( 'web', 'page' ),
		'page'    => array( 'web' ),
		'open'    => array( 'fetch', 'read' ),
		'download' => array( 'fetch' ),
		'scrape'  => array( 'fetch' ),
		'browse'  => array( 'fetch', 'web' ),
		'image'   => array( 'media' ),
		'photo'   => array( 'media', 'image' ),
		'picture' => array( 'media', 'image' ),
		'upload'  => array( 'media' ),
	);

	private const SYNONYM_WEIGHT = 0.6;

	/**
	 * Expand raw query terms into scoring groups.
	 *
	 * Each group is one query word with its alternates (the word itself at
	 * weight 1, synonyms at a reduced weight). Words are split on hyphens,
	 * underscores, slashes and dots ("fetch-url" -> fetch, url), and the
	 * joined token is kept as an extra, non-counting group so exact ability
	 * names still score. Stopwords are dropped.
	 *
	 * @param array<int,mixed> $terms Raw terms.
	 * @return array<int,array{alternates: array<string,float>, counts: bool}>
	 */
	public static function expand_terms( array $terms ): array {
		$groups = array();
		$seen   = array();
		foreach ( $terms as $raw ) {
			$raw = strtolower( trim( (string) $raw ) );
			if ( '' === $raw ) {
				continue;
			}
			$parts = preg_split( '/[-_\/.]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
			$parts = is_array( $parts ) ? $parts : array( $raw );
			if ( count( $parts ) > 1 && ! isset( $seen[ $raw ] ) ) {
				$seen[ $raw ] = true;
				$groups[]     = array(
					'alternates' => array( $raw => 1.0 ),
					'counts'     => false,
				);
			}
			foreach ( $parts as $part ) {
				if ( isset( $seen[ $part ] ) || in_array( $part, self::STOPWORDS, true ) ) {
					continue;
				}
				$seen[ $part ] = true;
				$alternates    = array( $part => 1.0 );
				foreach ( self::SYNONYMS[ $part ] ?? array() as $synonym ) {
					if ( ! isset( $alternates[ $synonym ] ) ) {
						$alternates[ $synonym ] = self::SYNONYM_WEIGHT;
					}
				}
				$groups[] = array(
					'alternates' => $alternates,
					'counts'     => true,
				);
			}
		}

		return $groups;
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
	 * Read exposed local abilities in the same record shape as remote REST
	 * results. Matching and ranking must see every candidate on every site.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function local_records( string $category ): array {
		$records = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! Exposure::meta_is_exposed( $ability->get_meta() ) || ( '' !== $category && $category !== $ability->get_category() ) ) {
				continue;
			}

			$records[] = array(
				'name'         => $ability->get_name(),
				'label'        => $ability->get_label(),
				'description'  => $ability->get_description(),
				'category'     => $ability->get_category(),
				'input_schema' => $ability->get_input_schema(),
			);
		}

		return $records;
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
					'description' => __( 'Search query. Plain terms match any word and rank by relevance across name, label, description and category. Use +keyword for required terms and select:foo/bar,baz/qux for exact abilities.', 'extrachill-mcp' ),
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
