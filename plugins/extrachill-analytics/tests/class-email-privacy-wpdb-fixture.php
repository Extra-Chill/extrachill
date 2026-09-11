<?php
/**
 * WPDB double for the email advisory-lock cleanup tests.
 *
 * GET_LOCK()/RELEASE_LOCK() are MySQL-only advisory mutexes the SQLite harness
 * database cannot execute, and per-query failure injection needs a
 * deterministic surface no SQLite query can produce on demand. The double
 * serves configured per-query results (lock acquisition, batch writes, batch
 * reads, injected errors) and delegates every unconfigured call to the real
 * wpdb, so options, cron, and cache keep their real behavior. The real wpdb is
 * restored in tear_down().
 *
 * @package ExtraChill\Analytics
 */

/**
 * Configured-result wpdb double delegating unconfigured calls to the real wpdb.
 */
final class Email_Privacy_Wpdb_Fixture {
	/**
	 * Real wpdb used for every unconfigured call.
	 *
	 * @var wpdb
	 */
	private $real;

	/**
	 * Configured mutation results.
	 *
	 * @var array<int, int|false>
	 */
	public $query_results = array();

	/**
	 * Per-query database errors, including empty success values.
	 *
	 * @var string[]
	 */
	public $query_errors = array();

	/**
	 * Configured result pages.
	 *
	 * @var array<int, array<object>>
	 */
	public $rows = array();

	/**
	 * Configured scalar results for advisory-lock queries.
	 *
	 * @var array<int, int|string|null>
	 */
	public $var_results = array();

	/**
	 * Per-scalar-query database errors.
	 *
	 * @var string[]
	 */
	public $var_errors = array();

	/**
	 * Captured prepared queries.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Configured database error.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Capture the real wpdb at construction.
	 */
	public function __construct() {
		$this->real = $GLOBALS['wpdb'];
	}

	/**
	 * Proxy wpdb properties (table names, prefixes, insert_id) to the real wpdb.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->real->{$name};
	}

	/**
	 * Proxy wpdb method calls not overridden here to the real wpdb.
	 *
	 * @param string $name      Method name.
	 * @param array  $arguments Arguments.
	 * @return mixed
	 */
	public function __call( $name, array $arguments ) {
		return $this->real->{$name}( ...$arguments );
	}

	/**
	 * No-op sink matching the real wpdb error-silencing surface.
	 *
	 * @param bool $errors Whether to suppress errors.
	 * @return bool Prior value.
	 */
	public function suppress_errors( $errors ) {
		unset( $errors );
		return true;
	}

	/**
	 * Substitute basic wpdb placeholders for query assertions.
	 *
	 * @param string $query SQL with placeholders.
	 * @param mixed  ...$args Placeholder values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$index = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $placeholder ) use ( $args, &$index ) {
				$value = $args[ $index++ ] ?? '';
				return '%s' === $placeholder[0] ? "'" . addslashes( (string) $value ) . "'" : (string) (int) $value;
			},
			$query
		);
	}

	/**
	 * Serve a configured mutation result, then delegate.
	 *
	 * @param string $query Prepared SQL.
	 * @return int|false
	 */
	public function query( $query ) {
		$this->queries[]  = $query;
		$this->last_error = (string) ( empty( $this->query_errors ) ? '' : array_shift( $this->query_errors ) );
		if ( empty( $this->query_results ) ) {
			return $this->real->query( $query );
		}
		return array_shift( $this->query_results );
	}

	/**
	 * Serve a configured scalar result, then delegate.
	 *
	 * @param string $query Prepared SQL.
	 * @return mixed
	 */
	public function get_var( $query ) {
		$this->queries[]  = $query;
		$this->last_error = (string) ( empty( $this->var_errors ) ? '' : array_shift( $this->var_errors ) );
		if ( empty( $this->var_results ) ) {
			return $this->real->get_var( $query );
		}
		return array_shift( $this->var_results );
	}

	/**
	 * Serve a configured result page, then delegate.
	 *
	 * @param string $query Prepared SQL.
	 * @return array<object>|false
	 */
	public function get_results( $query ) {
		$this->queries[] = $query;
		if ( empty( $this->rows ) ) {
			return $this->real->get_results( $query );
		}
		return array_shift( $this->rows );
	}

	/**
	 * Delegate row reads to the real wpdb.
	 *
	 * @param string $query Prepared SQL.
	 * @return object|null
	 */
	public function get_row( $query ) {
		$this->queries[] = $query;
		return $this->real->get_row( $query );
	}

	/**
	 * Delegate inserts to the real wpdb.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Column values.
	 * @param array  $format Formats.
	 * @return int|false
	 */
	public function insert( $table, array $data, $format = null ) {
		unset( $format );
		return $this->real->insert( $table, $data );
	}

	/**
	 * Delegate updates to the real wpdb.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Column values.
	 * @param array  $where  Where conditions.
	 * @return int|false
	 */
	public function update( $table, array $data, array $where ) {
		return $this->real->update( $table, $data, $where );
	}
}
