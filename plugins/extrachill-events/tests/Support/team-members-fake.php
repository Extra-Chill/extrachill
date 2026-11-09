<?php
/**
 * Adapter for in-memory wpdb fakes that model team membership per team type.
 *
 * Production stores every relationship in `ec_team_members`, discriminated by
 * `team_type`. The regex-driven fakes in the membership, promoter and booking
 * suites keep one in-memory partition per team type (keyed `ec_venue_members`
 * and `ec_promoter_members`) and match typed `{type}_term_id` predicates. These
 * helpers translate the real team-table SQL and rows into that partition shape
 * so the fakes keep exercising the production queries unchanged.
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! function_exists( 'ec_team_fake_partition' ) ) {
	/** In-memory partition key for one team type. */
	function ec_team_fake_partition( string $team_type, string $prefix = 'wp_7_' ): string {
		return $prefix . 'ec_' . $team_type . '_members';
	}

	/** Rewrite one team-table statement into its typed partition form. */
	function ec_team_fake_sql( $query ) {
		if ( is_string( $query ) && preg_match( '/ec_team_(claims|invitations)/', $query ) ) {
			$query = preg_replace( "/team_type = '(venue|promoter)' AND /", '', $query );
			return str_replace( array( 'ec_team_claims', 'ec_team_invitations', 'team_term_id', 'team_type ASC, ' ), array( 'ec_venue_claims', 'ec_venue_invitations', 'venue_term_id', '' ), $query );
		}
		if ( ! is_string( $query ) || false === strpos( $query, 'ec_team_members' ) ) {
			return $query;
		}
		if ( ! preg_match( "/team_type = '(venue|promoter)'/", $query, $type ) ) {
			return str_replace( array( 'ec_team_members', 'team_type ASC, ', 'team_term_id' ), array( 'ec_venue_members', '', 'venue_term_id' ), $query );
		}
		$type  = $type[1];
		$query = preg_replace( '/((?:\w+\.)?)id, \1team_term_id AS ' . $type . '_term_id, .*?\1revoked_at(?:, \1revoked_by_user_id)?(?= FROM)/', '$1*', $query );
		$query = preg_replace( "/(?:\\w+\\.)?team_type = (?:'(?:venue|promoter)'|\\w+\\.team_type) AND /", '', $query );
		$query = str_replace( array( 'ec_team_members', 'team_term_id' ), array( 'ec_' . $type . '_members', $type . '_term_id' ), $query );
		return $query;
	}

	/**
	 * Rewrite a team-table insert/update target, row and where clause.
	 *
	 * @return array{0:string,1:array,2:array}
	 */
	function ec_team_fake_write( string $table, array $row, array $where = array() ): array {
		if ( preg_match( '/ec_team_(claims|invitations)/', $table ) ) {
			$row['venue_term_id'] = $row['team_term_id'];
			return array( str_replace( 'ec_team_', 'ec_venue_', $table ), $row, $where );
		}
		if ( false === strpos( $table, 'ec_team_members' ) ) {
			return array( $table, $row, $where );
		}
		$type = (string) ( $row['team_type'] ?? $where['team_type'] ?? '' );
		foreach ( array( 'row', 'where' ) as $part ) {
			if ( array_key_exists( 'team_term_id', $$part ) ) {
				${$part}[ $type . '_term_id' ] = ${$part}['team_term_id'];
			}
			unset( ${$part}['team_type'], ${$part}['team_term_id'] );
		}
		return array( str_replace( 'ec_team_members', 'ec_' . $type . '_members', $table ), $row, $where );
	}
}
