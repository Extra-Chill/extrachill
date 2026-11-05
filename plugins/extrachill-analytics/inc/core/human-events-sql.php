<?php
/**
 * Shared read-side human-event filter.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build a bot-exclusion predicate, keeping unclassified legacy rows as human.
 *
 * MariaDB JSON booleans require IS NOT TRUE: COALESCE(..., false) = false
 * silently kept bot rows on production (issues #85/#87). Missing keys yield
 * SQL NULL and remain eligible, matching the search-gaps contract.
 *
 * @param string $table_alias Optional code-defined SQL table alias.
 * @return string SQL WHERE fragment with no value placeholders.
 * @throws InvalidArgumentException When the alias is not a SQL identifier.
 */
function extrachill_analytics_human_events_sql( $table_alias = '' ) {
	if ( '' !== $table_alias && ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table_alias ) ) {
		throw new InvalidArgumentException( 'Invalid analytics table alias.' );
	}

	$column = '' === $table_alias ? 'event_data' : $table_alias . '.event_data';

	return "JSON_EXTRACT({$column}, '$.is_bot') IS NOT TRUE";
}
