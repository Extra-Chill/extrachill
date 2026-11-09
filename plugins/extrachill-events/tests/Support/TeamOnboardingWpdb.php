<?php
/**
 * Typed partitions over the existing transactional onboarding fake.
 *
 * @package ExtraChillEvents\Tests
 */

/** Keeps equal numeric team IDs in independent in-memory stores. */
class TeamOnboardingWpdb {
	public $prefix       = 'wp_7_';
	public $terms        = 'wp_7_terms';
	public $users        = 'wp_users';
	public $last_error   = '';
	public $insert_id    = 0;
	public $legacy_rows  = 0;
	public $drops        = array();
	private $partitions  = array();
	private $active_type = 'venue';

	public function __construct() {
		foreach ( array( 'venue', 'promoter' ) as $type ) {
			$this->partitions[ $type ] = new VenueMembershipWpdb();
		}
	}

	public function prepare( $query, ...$args ) {
		return $this->partitions['venue']->prepare( $query, ...$args );
	}

	public function get_var( $query ) {
		if ( preg_match( '/SHOW TABLES LIKE/', $query ) ) {
			return $this->legacy_rows ? 'wp_7_ec_venue_claims' : null;
		}
		if ( false !== strpos( $query, 'SELECT COUNT(*)' ) ) {
			return $this->legacy_rows;
		}
		return $this->dispatch( 'get_var', $query );
	}

	public function get_row( $query, $output = null ) {
		if ( false !== strpos( $query, 'ec_promoter_organizations' ) ) {
			return array(
				'id'                  => 1,
				'promoter_term_id'    => 55,
				'status'              => 'active',
				'version'             => 1,
				'verified_by_user_id' => 1,
				'verified_at'         => '2026-01-01 00:00:00',
				'updated_at'          => '2026-01-01 00:00:00',
			);
		}
		return $this->dispatch( 'get_row', $query, $output );
	}

	public function get_results( $query, $output = null ) {
		return $this->dispatch( 'get_results', $query, $output );
	}

	private function dispatch( $method, $query, $output = null ) {
		preg_match( "/team_type = '(venue|promoter)'/", $query, $match );
		$type              = $match[1] ?? 'venue';
		$this->active_type = $type;
		$query             = str_replace( array( "team_type = 'promoter'", ' AS promoter_term_id', 'promoter_term_id' ), array( "team_type = 'venue'", ' AS venue_term_id', 'venue_term_id' ), $query );
		$result            = $this->partitions[ $type ]->$method( $query, $output );
		$this->last_error  = $this->partitions[ $type ]->last_error;
		if ( 'promoter' === $type && is_array( $result ) ) {
			$alias  = static function ( $row ) {
				$row['promoter_term_id'] = $row['venue_term_id'];
				return $row;
			};
			$result = isset( $result['id'] ) ? $alias( $result ) : array_map( $alias, $result );
		}
		return $result;
	}

	public function insert( $table, $row ) {
		$type = $row['team_type'] ?? 'venue';
		if ( 'promoter' === $type && isset( $row['promoter_term_id'] ) ) {
			$row['team_term_id'] = $row['promoter_term_id'];
			unset( $row['promoter_term_id'] );
		}
		if ( false !== strpos( $table, 'ec_team_members' ) ) {
			$row['team_type'] = 'venue';
		}
		$result           = $this->partitions[ $type ]->insert( $table, $row );
		$this->insert_id  = $this->partitions[ $type ]->insert_id;
		$this->last_error = $this->partitions[ $type ]->last_error;
		return $result;
	}

	public function update( $table, $data, $where ) {
		$type               = $where['team_type'];
		$where['team_type'] = 'venue';
		return $this->partitions[ $type ]->update( $table, $data, $where );
	}

	public function query( $query ) {
		if ( false !== strpos( $query, 'DROP TABLE' ) ) {
			$this->drops[] = $query;
			return 1;
		}
		// The preceding typed lock establishes the partition for opaque-ID updates.
		if ( 0 === strpos( $query, 'UPDATE ' ) ) {
			return $this->partitions[ $this->active_type ]->query( $query );
		}
		$result = 0;
		foreach ( $this->partitions as $partition ) {
			$result = max( $result, (int) $partition->query( $query ) );
		}
		return $result;
	}
}
