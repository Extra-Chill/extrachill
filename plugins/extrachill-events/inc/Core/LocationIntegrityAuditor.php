<?php
/**
 * Read-only location taxonomy integrity checks.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

class LocationIntegrityAuditor {

	/**
	 * Find root-level terms that overlap cities and canonical cities sharing a name.
	 *
	 * Matching is deliberately exact. Results are operator-review candidates, not
	 * merge instructions.
	 *
	 * @param array $terms Location term rows containing term_id, name, slug, parent, and count.
	 * @return array<int,array<string,mixed>>
	 */
	public static function audit( array $terms ): array {
		$by_id = array();
		foreach ( $terms as $term ) {
			$term_id = (int) ( $term['term_id'] ?? 0 );
			if ( $term_id > 0 ) {
				$by_id[ $term_id ] = $term;
			}
		}

		$roots  = array();
		$cities = array();
		foreach ( $by_id as $term ) {
			$depth = self::depth( $term, $by_id );
			if ( 0 === $depth ) {
				$roots[] = $term;
			} elseif ( $depth >= 2 ) {
				$cities[] = $term;
			}
		}

		$findings = array();
		foreach ( $roots as $root ) {
			foreach ( $cities as $city ) {
				$reason = self::root_overlap_reason( $root, $city );
				if ( null === $reason ) {
					continue;
				}

				$findings[] = self::finding( 'root_city_overlap', $reason, $root, $city );
			}
		}

		$city_count = count( $cities );
		for ( $left = 0; $left < $city_count; ++$left ) {
			for ( $right = $left + 1; $right < $city_count; ++$right ) {
				if ( self::normalize_name( $cities[ $left ]['name'] ?? '' ) !== self::normalize_name( $cities[ $right ]['name'] ?? '' ) ) {
					continue;
				}

				$findings[] = self::finding( 'canonical_city_overlap', 'exact_name', $cities[ $left ], $cities[ $right ] );
			}
		}

		usort(
			$findings,
			static function ( array $a, array $b ): int {
				return array( $a['issue'], $a['candidate_name'], $a['canonical_name'], $a['candidate_id'] ) <=> array( $b['issue'], $b['candidate_name'], $b['canonical_name'], $b['candidate_id'] );
			}
		);

		return $findings;
	}

	/**
	 * Determine a term's hierarchy depth without taxonomy lookups.
	 */
	private static function depth( array $term, array $by_id ): int {
		$depth   = 0;
		$parent  = (int) ( $term['parent'] ?? 0 );
		$visited = array();

		while ( $parent > 0 && isset( $by_id[ $parent ] ) && ! isset( $visited[ $parent ] ) ) {
			$visited[ $parent ] = true;
			++$depth;
			$parent = (int) ( $by_id[ $parent ]['parent'] ?? 0 );
		}

		return $depth;
	}

	/**
	 * Return the exact overlap rule, or null when no conservative rule matches.
	 */
	private static function root_overlap_reason( array $root, array $city ): ?string {
		$root_name = self::normalize_name( $root['name'] ?? '' );
		$city_name = self::normalize_name( $city['name'] ?? '' );
		$root_slug = self::normalize_slug( $root['slug'] ?? '' );
		$city_slug = self::normalize_slug( $city['slug'] ?? '' );

		if ( '' !== $root_name && $root_name === $city_name ) {
			return 'exact_name';
		}
		if ( '' !== $root_slug && $root_slug === $city_slug ) {
			return 'exact_slug';
		}
		if ( '' !== $city_name && 1 === preg_match( '/^' . preg_quote( $city_name, '/' ) . ',\s*[a-z]{2,3}$/', $root_name ) ) {
			return 'state_qualified_name';
		}
		if ( '' !== $city_slug && 1 === preg_match( '/^' . preg_quote( $city_slug, '/' ) . '-[a-z]{2,3}$/', $root_slug ) ) {
			return 'state_qualified_slug';
		}

		return null;
	}

	private static function finding( string $issue, string $reason, array $candidate, array $canonical ): array {
		return array(
			'issue'           => $issue,
			'reason'          => $reason,
			'candidate_id'    => (int) $candidate['term_id'],
			'candidate_name'  => (string) $candidate['name'],
			'candidate_slug'  => (string) $candidate['slug'],
			'candidate_count' => (int) ( $candidate['count'] ?? 0 ),
			'canonical_id'    => (int) $canonical['term_id'],
			'canonical_name'  => (string) $canonical['name'],
			'canonical_slug'  => (string) $canonical['slug'],
			'canonical_count' => (int) ( $canonical['count'] ?? 0 ),
		);
	}

	private static function normalize_name( $name ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $name ) ) );
	}

	private static function normalize_slug( $slug ): string {
		return strtolower( trim( (string) $slug, "- \t\n\r\0\x0B" ) );
	}
}
