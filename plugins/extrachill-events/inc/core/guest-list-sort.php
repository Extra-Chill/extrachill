<?php
/**
 * A to Z order for guest list rows (extrachill#1142).
 *
 * Door staff look names up by eye, so the door list renders everyone, account
 * RSVPs, comps, band-added guests, and the venue's and promoter's standing
 * lists (extrachill#1130), in one case-insensitive A to Z run instead of
 * issue order.
 *
 * A plus-one has no column of its own: a host or band types it as a separate
 * name, "Jake +1". When a row named "<primary> +N" sits on the list next to a
 * row named "<primary>", it is kept immediately after that primary, whatever
 * its own spelling would sort to ("Jake+1" would otherwise land after
 * "Jake Adams"). A "+N" row with no matching primary sorts by its base name.
 *
 * Pure: no WordPress calls beyond the optional remove_accents() (intl's
 * Normalizer otherwise) for locale-tolerant comparison.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The name a guest row sorts by: guest_name, else the account display name.
 *
 * @param array $row Guest or attendee row.
 * @return string
 */
function extrachill_events_guest_row_name( array $row ): string {
	$name = trim( (string) ( $row['guest_name'] ?? '' ) );
	if ( '' === $name ) {
		$name = trim( (string) ( $row['display_name'] ?? '' ) );
	}

	return $name;
}

/**
 * Comparison form of a name: accents folded, lowercased, whitespace collapsed.
 *
 * @param string $name Raw name.
 * @return string
 */
function extrachill_events_normalize_guest_name( string $name ): string {
	if ( function_exists( 'remove_accents' ) ) {
		$name = remove_accents( $name );
	} elseif ( class_exists( 'Normalizer' ) ) {
		$name = (string) preg_replace( '/\p{Mn}+/u', '', (string) Normalizer::normalize( $name, Normalizer::FORM_D ) );
	}
	$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );

	return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
}

/**
 * Split a "Name +N" plus-one name into its primary name and N.
 *
 * @param string $name Normalized name.
 * @return array{0: string, 1: int}|null [ primary name, N ], or null when not a plus-one.
 */
function extrachill_events_split_plus_one_name( string $name ): ?array {
	if ( ! preg_match( '/^(.*?)\s*\+\s*(\d{1,3})$/u', $name, $matches ) || '' === trim( $matches[1] ) ) {
		return null;
	}

	return array( trim( $matches[1] ), (int) $matches[2] );
}

/**
 * Sort guest list rows A to Z by name, each "+N" row right after its primary.
 *
 * Natural, case- and accent-insensitive comparison; ties break by pass_id,
 * then by input order, so duplicate names keep a stable order. Each returned
 * row gains `door_group`: the index of its primary in the result (its own
 * index for primaries), so the door list's filter can keep a party together.
 *
 * @param array[] $rows Rows with guest_name and/or display_name, optional pass_id.
 * @return array[] Reindexed, sorted rows.
 */
function extrachill_events_sort_guest_rows( array $rows ): array {
	$items = array();
	foreach ( array_values( $rows ) as $index => $row ) {
		$row     = (array) $row;
		$name    = extrachill_events_normalize_guest_name( extrachill_events_guest_row_name( $row ) );
		$plus    = extrachill_events_split_plus_one_name( $name );
		$items[] = array(
			'row'     => $row,
			'index'   => $index,
			'name'    => $name,
			'plus'    => $plus,
			'pass_id' => (int) ( $row['pass_id'] ?? 0 ),
		);
	}

	$compare = static function ( array $a, array $b, string $key_a, string $key_b ): int {
		$result = strnatcasecmp( $key_a, $key_b );
		if ( 0 === $result ) {
			$result = $a['pass_id'] <=> $b['pass_id'];
		}

		return 0 === $result ? $a['index'] <=> $b['index'] : $result;
	};

	// Primaries: every row that is not a "+N" of a name on the list.
	$names = array();
	foreach ( $items as $item ) {
		if ( null === $item['plus'] ) {
			$names[ $item['name'] ] = true;
		}
	}

	$primaries = array();
	$followers = array();
	foreach ( $items as $item ) {
		if ( null !== $item['plus'] && isset( $names[ $item['plus'][0] ] ) ) {
			$followers[ $item['plus'][0] ][] = $item;
		} else {
			$primaries[] = $item;
		}
	}

	usort(
		$primaries,
		static function ( $a, $b ) use ( $compare ) {
			$key_a = null === $a['plus'] ? $a['name'] : $a['plus'][0];
			$key_b = null === $b['plus'] ? $b['name'] : $b['plus'][0];
			return $compare( $a, $b, $key_a, $key_b );
		}
	);

	$sorted = array();
	foreach ( $primaries as $primary ) {
		$group    = count( $sorted );
		$sorted[] = array_merge( $primary['row'], array( 'door_group' => $group ) );

		// Plus-ones follow the first primary with their name, lowest N first.
		if ( null !== $primary['plus'] || empty( $followers[ $primary['name'] ] ) ) {
			continue;
		}
		$own = $followers[ $primary['name'] ];
		unset( $followers[ $primary['name'] ] );
		usort(
			$own,
			static function ( $a, $b ) use ( $compare ) {
				$result = $a['plus'][1] <=> $b['plus'][1];
				return 0 === $result ? $compare( $a, $b, $a['name'], $b['name'] ) : $result;
			}
		);
		foreach ( $own as $follower ) {
			$sorted[] = array_merge( $follower['row'], array( 'door_group' => $group ) );
		}
	}

	return $sorted;
}
