<?php
/**
 * Giveaway entries source: provided entries (e.g. a Venmo payment list).
 *
 * Entry source seam for extrachill/run-giveaway. The Instagram source reads
 * comments; this source takes rows of {name, amount, note} (or a Venmo
 * statement CSV), turns money into weighted per-lot tickets, and draws one
 * winner per lot with random_int.
 *
 * @package    ExtraChillStudio
 * @subpackage Abilities
 */

defined( 'ABSPATH' ) || exit;

const EC_STUDIO_RAFFLE_DEFAULT_RULES = array(
	'price_per_entry'   => 1.0,
	'minimum'           => 10.0,
	'lots'              => array( 'A', 'B', 'C' ),
	'donation_keywords' => array( 'donation' ),
	'lot_aliases'       => array(),
	'distinct_winners'  => false,
);

/**
 * Words that may surround lot labels in a note without changing its meaning.
 */
const EC_STUDIO_RAFFLE_FILLER_WORDS = array( 'lot', 'lots', 'raffle', 'raffles', 'and', 'plus', 'for', 'entry', 'entries', 'both', 'in', 'into', 'the' );

/**
 * Normalize raffle rules, filling defaults.
 *
 * @param array $rules Requested rules.
 * @return array
 */
function ec_studio_normalize_raffle_rules( array $rules ): array {
	$out = EC_STUDIO_RAFFLE_DEFAULT_RULES;

	if ( isset( $rules['price_per_entry'] ) && is_numeric( $rules['price_per_entry'] ) && (float) $rules['price_per_entry'] > 0 ) {
		$out['price_per_entry'] = (float) $rules['price_per_entry'];
	}
	if ( isset( $rules['minimum'] ) && is_numeric( $rules['minimum'] ) && (float) $rules['minimum'] >= 0 ) {
		$out['minimum'] = (float) $rules['minimum'];
	}
	if ( ! empty( $rules['lots'] ) && is_array( $rules['lots'] ) ) {
		$lots = array_values( array_unique( array_filter( array_map( static fn( $l ) => trim( (string) $l ), $rules['lots'] ), static fn( $s ) => '' !== $s ) ) );
		if ( $lots ) {
			$out['lots'] = $lots;
		}
	}
	if ( isset( $rules['donation_keywords'] ) && is_array( $rules['donation_keywords'] ) ) {
		$out['donation_keywords'] = array_values( array_filter( array_map( static fn( $k ) => strtolower( trim( (string) $k ) ), $rules['donation_keywords'] ), static fn( $s ) => '' !== $s ) );
	}
	if ( isset( $rules['lot_aliases'] ) && is_array( $rules['lot_aliases'] ) ) {
		foreach ( $rules['lot_aliases'] as $lot => $phrases ) {
			if ( ! in_array( (string) $lot, $out['lots'], true ) ) {
				continue;
			}
			$phrases = array_values( array_filter( array_map( static fn( $p ) => strtolower( trim( (string) $p ) ), (array) $phrases ), static fn( $s ) => '' !== $s ) );
			if ( $phrases ) {
				$out['lot_aliases'][ (string) $lot ] = $phrases;
			}
		}
	}
	if ( isset( $rules['distinct_winners'] ) ) {
		$out['distinct_winners'] = (bool) $rules['distinct_winners'];
	}

	return $out;
}

/**
 * Parse a money string like "+ $1,250.00", "$10", "-$5.00", "10".
 *
 * @param mixed $value Raw amount.
 * @return float|null Signed amount, or null when not a number.
 */
function ec_studio_parse_raffle_amount( $value ): ?float {
	if ( is_int( $value ) || is_float( $value ) ) {
		return (float) $value;
	}
	$raw = trim( (string) $value );
	if ( '' === $raw ) {
		return null;
	}
	$negative = str_starts_with( $raw, '-' ) || str_starts_with( $raw, '(' );
	$digits   = preg_replace( '/[^0-9.]/', '', $raw );
	if ( '' === $digits || ! is_numeric( $digits ) ) {
		return null;
	}
	return $negative ? - (float) $digits : (float) $digits;
}

/**
 * Parse a Venmo statement CSV into payment rows.
 *
 * Finds the header row (it contains "From" and "Amount (total)"), keeps
 * incoming payments, and skips outgoing money, transfers, failed payments,
 * and summary rows.
 *
 * @param string $csv Raw CSV text.
 * @return array{ rows: array, skipped: int }
 */
function ec_studio_parse_venmo_csv( string $csv ): array {
	$lines   = preg_split( '/\r\n|\n|\r/', trim( $csv ) );
	$header  = null;
	$rows    = array();
	$skipped = 0;

	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$cells = array_map( 'trim', str_getcsv( $line, ',', '"', '\\' ) );

		if ( null === $header ) {
			$lower = array_map( 'strtolower', $cells );
			if ( in_array( 'from', $lower, true ) && in_array( 'amount (total)', $lower, true ) ) {
				$header = array_flip( $lower );
			}
			continue;
		}

		$get = static function ( string $col ) use ( $header, $cells ): string {
			return isset( $header[ $col ] ) ? (string) ( $cells[ $header[ $col ] ] ?? '' ) : '';
		};

		$from   = $get( 'from' );
		$amount = ec_studio_parse_raffle_amount( $get( 'amount (total)' ) );
		$type   = strtolower( $get( 'type' ) );
		$status = strtolower( $get( 'status' ) );

		$is_payment  = '' === $type || in_array( $type, array( 'payment', 'charge' ), true );
		$is_complete = '' === $status || in_array( $status, array( 'complete', 'completed', 'issued' ), true );

		if ( '' === $from || null === $amount || $amount <= 0 || ! $is_payment || ! $is_complete ) {
			++$skipped;
			continue;
		}

		$rows[] = array(
			'name'     => $from,
			'amount'   => $amount,
			'note'     => $get( 'note' ),
			'datetime' => $get( 'datetime' ),
		);
	}

	return array(
		'rows'    => $rows,
		'skipped' => $skipped,
	);
}

/**
 * Classify one payment note.
 *
 * @param string $note  Payment note.
 * @param array  $rules Normalized rules.
 * @return array{ kind: string, lots: string[] } kind is lots|donation|unclear.
 */
function ec_studio_classify_raffle_note( string $note, array $rules ): array {
	$lots_by_key = array();
	foreach ( $rules['lots'] as $lot ) {
		$lots_by_key[ strtolower( $lot ) ] = $lot;
	}

	$text   = strtolower( $note );
	$found  = array();
	$tokens = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );

	// Whole-pool phrases such as all lots, all three, every lot.
	if ( preg_match( '/\b(all|every)\s+(the\s+)?(lots?|raffles?|three|3|of\s+them)\b/', $text ) ) {
		$found = array_values( $lots_by_key );
	}

	// Prefixed labels such as lot A, lots A and B, raffle b plus lot c.
	if ( empty( $found ) && preg_match_all( '/\b(?:lots?|raffles?)\s*[#:]?\s*((?:[a-z0-9]+)(?:\s*(?:,|&|\+|\/|and|plus)\s*(?:lots?\s*)?[a-z0-9]+)*)/', $text, $matches ) ) {
		foreach ( $matches[1] as $group ) {
			foreach ( preg_split( '/[^a-z0-9]+/', $group, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
				if ( isset( $lots_by_key[ $token ] ) ) {
					$found[] = $lots_by_key[ $token ];
				}
			}
		}
	}

	// Bare labels: the note is only lot labels and filler words.
	if ( empty( $found ) && $tokens ) {
		$bare = array();
		foreach ( $tokens as $token ) {
			if ( isset( $lots_by_key[ $token ] ) ) {
				$bare[] = $lots_by_key[ $token ];
			} elseif ( ! in_array( $token, EC_STUDIO_RAFFLE_FILLER_WORDS, true ) ) {
				$bare = array();
				break;
			}
		}
		$found = $bare;
	}

	// Prize names, e.g. lot_aliases {"B": ["fade to black"]}.
	foreach ( $rules['lot_aliases'] ?? array() as $lot => $phrases ) {
		foreach ( $phrases as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				$found[] = $lot;
				break;
			}
		}
	}

	$found = array_values( array_unique( $found ) );

	$is_donation = false;
	foreach ( $rules['donation_keywords'] as $keyword ) {
		if ( '' !== $keyword && str_contains( $text, $keyword ) ) {
			$is_donation = true;
			break;
		}
	}

	if ( $found && $is_donation ) {
		return array(
			'kind' => 'unclear',
			'lots' => $found,
		);
	}
	if ( $found ) {
		// Keep rule order for stable output.
		$ordered = array_values( array_filter( $rules['lots'], static fn( $l ) => in_array( $l, $found, true ) ) );
		return array(
			'kind' => 'lots',
			'lots' => $ordered,
		);
	}
	if ( $is_donation || '' === trim( $note ) ) {
		return array(
			'kind' => 'donation',
			'lots' => array(),
		);
	}
	return array(
		'kind' => 'unclear',
		'lots' => array(),
	);
}

/**
 * Tally payment rows into weighted per-lot tickets.
 *
 * Every price_per_entry is one ticket. Money is split evenly across the lots
 * named in the note; whole tickets per lot are kept and any leftover money is
 * counted as a donation. Donation notes, empty notes, and payments under the
 * minimum are donations with zero tickets. Notes that can't be read are
 * returned in `unclear` and never silently dropped.
 *
 * @param array $rows  Rows of {name, amount, note}.
 * @param array $rules Raffle rules.
 * @return array
 */
function ec_studio_tally_raffle_entries( array $rows, array $rules ): array {
	$rules     = ec_studio_normalize_raffle_rules( $rules );
	$price     = $rules['price_per_entry'];
	$by_lot    = array_fill_keys( $rules['lots'], array() );
	$donations = array();
	$unclear   = array();
	$breakdown = array();
	$don_total = 0.0;

	foreach ( array_values( $rows ) as $index => $row ) {
		$name   = trim( (string) ( $row['name'] ?? '' ) );
		$note   = (string) ( $row['note'] ?? '' );
		$amount = ec_studio_parse_raffle_amount( $row['amount'] ?? null );
		$base   = array(
			'row'    => $index + 1,
			'name'   => $name,
			'amount' => $amount,
			'note'   => $note,
		);

		if ( '' === $name || null === $amount || $amount <= 0 ) {
			$unclear[] = $base + array( 'reason' => 'missing_name_or_amount' );
			continue;
		}

		$class = ec_studio_classify_raffle_note( $note, $rules );

		if ( 'unclear' === $class['kind'] ) {
			$unclear[] = $base + array(
				'reason' => $class['lots'] ? 'lot_and_donation_in_note' : 'unreadable_note',
				'lots'   => $class['lots'],
			);
			continue;
		}

		if ( 'donation' === $class['kind'] || $amount + 1e-9 < $rules['minimum'] ) {
			$reason      = 'donation' === $class['kind'] ? ( '' === trim( $note ) ? 'no_lot' : 'donation_note' ) : 'under_minimum';
			$donations[] = $base + array( 'reason' => $reason );
			$don_total  += $amount;
			$breakdown[] = $base + array(
				'kind'    => 'donation',
				'reason'  => $reason,
				'tickets' => array(),
			);
			continue;
		}

		$lot_count = count( $class['lots'] );
		$per_lot   = (int) floor( ( $amount / $lot_count ) / $price + 1e-9 );
		$leftover  = round( $amount - ( $per_lot * $price * $lot_count ), 2 );
		$tickets   = array();

		foreach ( $class['lots'] as $lot ) {
			if ( $per_lot > 0 ) {
				$key = strtolower( $name );
				if ( ! isset( $by_lot[ $lot ][ $key ] ) ) {
					$by_lot[ $lot ][ $key ] = array(
						'name'    => $name,
						'tickets' => 0,
						'amount'  => 0.0,
					);
				}
				$by_lot[ $lot ][ $key ]['tickets'] += $per_lot;
				$by_lot[ $lot ][ $key ]['amount']  += $per_lot * $price;
			}
			$tickets[ $lot ] = $per_lot;
		}

		if ( $leftover > 0 ) {
			$donations[] = $base + array(
				'reason'   => 'split_leftover',
				'leftover' => $leftover,
			);
			$don_total  += $leftover;
		}

		$breakdown[] = $base + array(
			'kind'     => 'entries',
			'tickets'  => $tickets,
			'leftover' => $leftover,
		);
	}

	$lots = array();
	foreach ( $by_lot as $lot => $people ) {
		$next     = 1;
		$entrants = array();
		$amount   = 0.0;
		foreach ( $people as $person ) {
			$entrants[] = array(
				'name'    => $person['name'],
				'tickets' => $person['tickets'],
				'from'    => $next,
				'to'      => $next + $person['tickets'] - 1,
			);
			$next      += $person['tickets'];
			$amount    += $person['amount'];
		}
		$lots[ $lot ] = array(
			'total_tickets' => $next - 1,
			'total_amount'  => round( $amount, 2 ),
			'entrants'      => $entrants,
		);
	}

	return array(
		'rules'     => $rules,
		'lots'      => $lots,
		'donations' => array(
			'total' => round( $don_total, 2 ),
			'rows'  => $donations,
		),
		'unclear'   => $unclear,
		'rows'      => $breakdown,
	);
}

/**
 * Draw one winner per lot, weighted by tickets, using random_int.
 *
 * Lots are drawn independently by default, so one person may win several.
 * With distinct_winners, earlier winners' tickets leave later lots' pools;
 * the reported ticket number is always the original ticket number.
 *
 * @param array $lots             `lots` from ec_studio_tally_raffle_entries().
 * @param bool  $distinct_winners Exclude earlier winners from later lots.
 * @return array[] One row per lot: lot, name, ticket, ... (name null when nobody is eligible).
 */
function ec_studio_draw_raffle_winners( array $lots, bool $distinct_winners = false ): array {
	$winners = array();
	$taken   = array();

	foreach ( $lots as $lot => $data ) {
		$eligible = array_values(
			array_filter(
				$data['entrants'] ?? array(),
				static fn( $e ) => $e['tickets'] > 0 && ! ( $distinct_winners && isset( $taken[ strtolower( $e['name'] ) ] ) )
			)
		);
		$pool     = array_sum( array_column( $eligible, 'tickets' ) );

		if ( $pool < 1 ) {
			$winners[] = array(
				'lot'           => (string) $lot,
				'name'          => null,
				'ticket'        => null,
				'tickets_held'  => 0,
				'total_tickets' => (int) ( $data['total_tickets'] ?? 0 ),
				'eligible_pool' => 0,
			);
			continue;
		}

		$pick = random_int( 1, $pool );
		foreach ( $eligible as $entrant ) {
			if ( $pick <= $entrant['tickets'] ) {
				$winners[]                               = array(
					'lot'           => (string) $lot,
					'name'          => $entrant['name'],
					'ticket'        => $entrant['from'] + $pick - 1,
					'tickets_held'  => $entrant['tickets'],
					'total_tickets' => (int) $data['total_tickets'],
					'eligible_pool' => $pool,
				);
				$taken[ strtolower( $entrant['name'] ) ] = true;
				break;
			}
			$pick -= $entrant['tickets'];
		}
	}

	return $winners;
}

/**
 * Run a giveaway from provided entries.
 *
 * @param array $input Ability input (source=entries).
 * @return array|\WP_Error
 */
function ec_studio_run_giveaway_from_entries( array $input ) {
	$rows    = array();
	$skipped = 0;

	if ( ! empty( $input['venmo_csv'] ) && is_string( $input['venmo_csv'] ) ) {
		$parsed  = ec_studio_parse_venmo_csv( $input['venmo_csv'] );
		$rows    = $parsed['rows'];
		$skipped = $parsed['skipped'];
	}
	if ( ! empty( $input['entries'] ) && is_array( $input['entries'] ) ) {
		$rows = array_merge( $rows, array_values( array_filter( $input['entries'], 'is_array' ) ) );
	}

	if ( empty( $rows ) ) {
		return new \WP_Error( 'no_entries', 'source "entries" needs entries rows ({name, amount, note}) or a venmo_csv with incoming payments.', array( 'status' => 400 ) );
	}

	$rules = is_array( $input['rules'] ?? null ) ? $input['rules'] : array();
	if ( isset( $input['distinct_winners'] ) ) {
		$rules['distinct_winners'] = (bool) $input['distinct_winners'];
	}

	$tally  = ec_studio_tally_raffle_entries( $rows, $rules );
	$mode   = ( $input['mode'] ?? 'preview' ) === 'draw' ? 'draw' : 'preview';
	$result = array(
		'source'      => 'entries',
		'mode'        => $mode,
		'rules'       => $tally['rules'],
		'lots'        => $tally['lots'],
		'donations'   => $tally['donations'],
		'unclear'     => $tally['unclear'],
		'rows'        => $tally['rows'],
		'csv_skipped' => $skipped,
		'winners'     => array(),
	);

	if ( 'draw' === $mode ) {
		$result['winners']  = ec_studio_draw_raffle_winners( $tally['lots'], $tally['rules']['distinct_winners'] );
		$result['drawn_at'] = gmdate( 'c' );
	}

	return $result;
}
