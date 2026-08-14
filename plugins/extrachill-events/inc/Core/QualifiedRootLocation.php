<?php
/**
 * Exact hierarchy matching for state/province-qualified root locations.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Matches qualified root terms against canonical location hierarchy identity. */
final class QualifiedRootLocation {

	/**
	 * Match a root "City, subdivision" term to one canonical hierarchy term.
	 *
	 * @param object        $root             Root candidate.
	 * @param array<object> $terms            All location terms.
	 * @param callable|null $hierarchy_filter Optional test seam matching the shared resolver callback.
	 * @return array{status:string,canonical:?object,reason:string}
	 */
	public static function match( object $root, array $terms, ?callable $hierarchy_filter = null ): array {
		if ( 0 !== (int) ( $root->parent ?? 0 ) ) {
			return self::result( 'not_candidate', null, 'term_is_not_root' );
		}

		if ( ! preg_match( '/^(.+),\s*([^,]+)$/', trim( (string) ( $root->name ?? '' ) ), $parts ) ) {
			return self::result( 'not_candidate', null, 'name_has_no_subdivision_qualifier' );
		}

		foreach ( $terms as $term ) {
			if ( (int) ( $term->parent ?? 0 ) === (int) ( $root->term_id ?? 0 ) ) {
				return self::result( 'ambiguous', null, 'candidate_has_children' );
			}
		}

		$city        = self::key( $parts[1] );
		$subdivision = trim( $parts[2] );
		$matches     = array_values(
			array_filter(
				$terms,
				static fn( object $term ): bool => self::key( $term->name ?? '' ) === $city && (int) ( $term->parent ?? 0 ) > 0
			)
		);

		if ( null === $hierarchy_filter ) {
			if ( ! function_exists( 'extrachill_events_filter_locations_by_hierarchy' ) ) {
				return self::result( 'unresolved', null, 'canonical_hierarchy_resolver_unavailable' );
			}
			$hierarchy_filter = 'extrachill_events_filter_locations_by_hierarchy';
		}

		$matches = array_values( $hierarchy_filter( $matches, $subdivision, '' ) );
		if ( 1 === count( $matches ) ) {
			return self::result( 'safe_match', $matches[0], 'exact_city_and_subdivision_hierarchy' );
		}

		return self::result(
			count( $matches ) > 1 ? 'ambiguous' : 'unresolved',
			null,
			count( $matches ) > 1 ? 'multiple_canonical_hierarchy_matches' : 'no_canonical_hierarchy_match'
		);
	}

	/**
	 * Normalize a location identity for exact comparison.
	 *
	 * @param string $value Location identity.
	 */
	private static function key( string $value ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ) );
	}

	/**
	 * Build one classification result.
	 *
	 * @param string      $status    Classification status.
	 * @param object|null $canonical Canonical term, when uniquely resolved.
	 * @param string      $reason    Machine-readable reason.
	 */
	private static function result( string $status, ?object $canonical, string $reason ): array {
		return array(
			'status'    => $status,
			'canonical' => $canonical,
			'reason'    => $reason,
		);
	}
}
