<?php
/**
 * Executable database behavior for the Link Page Analytics migration.
 *
 * The migration's owned SQL is written for MySQL/MariaDB: SHOW COLUMNS
 * preflight with exact column types, SHOW INDEX prefix contracts
 * (link_url(191), link_text(100)), LEFT()-based unique-key collision reads,
 * BINARY/<=>/LIMIT 1 exact-snapshot rollback deletes, and JSON_TYPE integer
 * identity matching. The managed harness database is a SQLite adapter that
 * cannot execute or faithfully report those behaviors (it answers SHOW
 * COLUMNS with no rows, reports Sub_part null for every index, has no
 * LEFT()/BINARY/<=> operators, no DELETE LIMIT, and returns lowercase
 * JSON_TYPE values), so this test verifies the owned semantics through a
 * delegating wpdb double: configured surfaces execute the migration's SQL
 * semantics in PHP, and every unconfigured call — connections,
 * switch_to_blog()/set_prefix(), real per-blog tables, exact row inserts and
 * reads, the LEFT()-free views collision read, and rollbacks outside the
 * shadow store — delegates to the real harness database. The real wpdb is
 * restored in tear_down() by the base test case.
 *
 * @package ExtraChill\Analytics
 */

// PHPUnit requires the *Test.php filename; the delegating wpdb double stays
// beside its only consumer.
// phpcs:disable WordPress.Files.FileName,Universal.Files.SeparateFunctionsFromOO,Generic.Files.OneObjectStructurePerFile,Generic.CodeAnalysis.UnusedFunctionParameter

if ( ! function_exists( 'ec_register_link_page_migration_participant' ) ) {
	/**
	 * Capture the sibling runtime registration contract when the Link Pages
	 * plugin is not active in the test sandbox.
	 *
	 * @param string $name             Participant name.
	 * @param string $contract_version Contract version.
	 * @param array  $callbacks        Operation callbacks.
	 * @param int    $priority         Registration priority.
	 * @return bool
	 */
	function ec_register_link_page_migration_participant( $name, $contract_version, $callbacks, $priority = 10 ) {
		$GLOBALS['extrachill_analytics_test_migration_participant'] = compact( 'name', 'contract_version', 'callbacks', 'priority' );
		return true;
	}
}

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once dirname( __DIR__ ) . '/inc/database/link-page-analytics-db.php';
require_once dirname( __DIR__ ) . '/inc/core/link-page-storage-migration.php';

/**
 * Delegating wpdb double that executes the migration's owned SQL semantics.
 */
final class Link_Page_Migration_Wpdb_Fixture {

	/**
	 * Real wpdb used for every unconfigured call.
	 *
	 * @var wpdb
	 */
	private $real;

	/**
	 * Prepared statements stored behind markers.
	 *
	 * @var array<int,array{query:string,args:array<int,mixed>}>
	 */
	private $prepared_statements = array();

	/**
	 * Configured SQL errors by surface name.
	 *
	 * Surfaces: schema_columns, schema_indexes, source_views, source_clicks,
	 * collision_views, collision_clicks, historical, rollback_delete.
	 *
	 * @var array<string,string>
	 */
	public $errors = array();

	/**
	 * Corrupt the last column's Default per table kind for a schema mismatch.
	 *
	 * @var array<string,bool>
	 */
	public $schema_default_mismatch = array();

	/**
	 * Corrupt the last index row's Sub_part per table kind, producing an
	 * index-content mismatch without changing which indexes exist.
	 *
	 * @var array<string,bool>
	 */
	public $index_content_mismatch = array();

	/**
	 * Drop the `unique_daily_link_click` index rows per table kind,
	 * producing an index-existence mismatch.
	 *
	 * @var array<string,bool>
	 */
	public $index_missing = array();

	/**
	 * Shadow row store for intercepted rollback tables, keyed by table name.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public $shadow = array();

	/**
	 * Shadow historical event rows served for the events-table projection.
	 *
	 * @var array<int,array<string,string>>
	 */
	public $events = array();

	/**
	 * Serve one row with invalid event JSON to trigger the identity guard.
	 *
	 * @var bool
	 */
	public $return_invalid_historical_json = false;

	/**
	 * Log of executed owned operations.
	 *
	 * @var string[]
	 */
	public $operations = array();

	/**
	 * Most recent database error, mirroring the real wpdb between owned calls.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Store the real wpdb.
	 *
	 * @param wpdb $real Real harness database.
	 */
	public function __construct( $real ) {
		$this->real = $real;
	}

	/**
	 * Expose the real wpdb for fixture operations outside owned surfaces.
	 *
	 * @return wpdb
	 */
	public function real_wpdb() {
		return $this->real;
	}

	/**
	 * Delegate unknown property reads to the real wpdb.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->real->{$name};
	}

	/**
	 * Delegate unknown property writes to the real wpdb.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Property value.
	 */
	public function __set( $name, $value ) {
		$this->real->{$name} = $value;
	}

	/**
	 * Delegate unknown method calls to the real wpdb.
	 *
	 * @param string $name Method name.
	 * @param array  $args Method arguments.
	 * @return mixed
	 */
	public function __call( $name, $args ) {
		return call_user_func_array( array( $this->real, $name ), $args );
	}

	/**
	 * Store a prepared statement and its typed arguments behind a marker.
	 *
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Placeholder arguments.
	 * @return string Marker string the owned dispatch recognizes.
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$id                               = count( $this->prepared_statements );
		$this->prepared_statements[ $id ] = array(
			'query' => $query,
			'args'  => array_values( $args ),
		);
		return "/*ec-prepared:{$id}*/{$query}";
	}

	/**
	 * Execute an owned or delegated row read.
	 *
	 * @param string|null $sql    SQL string.
	 * @param string|null $output Output type.
	 * @return array|false
	 */
	public function get_results( $sql = null, $output = ARRAY_A ) {
		$result = $this->dispatch( (string) $sql, 'get_results' );
		if ( $result instanceof Link_Page_Migration_Delegation ) {
			return $this->delegate_results( $result->sql, $output );
		}
		return $result;
	}

	/**
	 * Execute an owned or delegated scalar read.
	 *
	 * @param string|null $sql SQL string.
	 * @return string|null|false
	 */
	public function get_var( $sql = null ) {
		$result = $this->dispatch( (string) $sql, 'get_var' );
		if ( $result instanceof Link_Page_Migration_Delegation ) {
			return $this->delegate_var( $result->sql );
		}
		return $result;
	}

	/**
	 * Delegate a single-row read, resolving prepared markers first.
	 *
	 * @param string|null $sql    SQL string.
	 * @param string|null $output Output type.
	 * @param int         $y      Row offset.
	 * @return mixed
	 */
	public function get_row( $sql = null, $output = OBJECT, $y = 0 ) {
		$result           = $this->real->get_row( $this->resolve_marker( (string) $sql ), $output, $y );
		$this->last_error = (string) $this->real->last_error;
		return $result;
	}

	/**
	 * Delegate a column read, resolving prepared markers first.
	 *
	 * @param string|null $sql SQL string.
	 * @return array
	 */
	public function get_col( $sql = null ) {
		$result           = $this->real->get_col( $this->resolve_marker( (string) $sql ) );
		$this->last_error = (string) $this->real->last_error;
		return $result;
	}

	/**
	 * Execute an owned or delegated statement.
	 *
	 * @param string|null $sql SQL string.
	 * @return int|false
	 */
	public function query( $sql = null ) {
		$result = $this->dispatch( (string) $sql, 'query' );
		if ( $result instanceof Link_Page_Migration_Delegation ) {
			return $this->delegate_query( $result->sql );
		}
		return $result;
	}

	/**
	 * Insert one exact row through the real database.
	 *
	 * @param string                         $table  Table name.
	 * @param array<string,mixed>            $data   Column values.
	 * @param array<int,string>|string|false $format Placeholder formats.
	 * @return int|false
	 */
	public function insert( $table, $data, $format = false ) {
		$result           = false !== $format
			? $this->real->insert( $table, $data, $format )
			: $this->real->insert( $table, $data );
		$this->last_error = (string) $this->real->last_error;
		return $result;
	}

	/**
	 * Dispatch an owned surface or produce a delegation instruction.
	 *
	 * @param string $sql  SQL string, possibly behind a prepared marker.
	 * @param string $kind Calling wpdb method, which owned shapes may match.
	 * @return mixed|Link_Page_Migration_Delegation Owned result, or a
	 *                                              delegation carrying SQL.
	 */
	private function dispatch( $sql, $kind ) {
		$this->last_error   = '';
		list( $raw, $args ) = $this->statement( $sql );

		if ( 'get_results' === $kind ) {
			if ( preg_match( '/^SHOW COLUMNS FROM `([^`]+)`/', $raw, $match ) && $this->is_owned_table( $match[1] ) ) {
				return $this->schema_columns( $match[1] );
			}
			if ( preg_match( '/^SHOW INDEX FROM `([^`]+)`/', $raw, $match ) && $this->is_owned_table( $match[1] ) ) {
				return $this->schema_indexes( $match[1] );
			}
			if ( preg_match( '/^SELECT view_id, link_page_id, stat_date, view_count FROM `([^`]+)` WHERE link_page_id IN/', $raw ) ) {
				return $this->source_rows( 'source_views', $raw );
			}
			if ( preg_match( '/^SELECT click_id, link_page_id, stat_date, link_url, link_text, click_count FROM `([^`]+)` WHERE link_page_id IN/', $raw ) ) {
				return $this->source_rows( 'source_clicks', $raw );
			}
			if ( false !== strpos( $raw, 'FROM `' ) && false !== strpos( $raw, 'extrachill_analytics_events' ) && false !== strpos( $raw, 'JSON_VALID(event_data)' ) ) {
				return $this->historical_rows( $raw, $args );
			}
		}
		if ( 'get_var' === $kind ) {
			if ( false !== strpos( $raw, 'WHERE view_id = %d OR' ) ) {
				return $this->collision_views( $raw, $args );
			}
			if ( false !== strpos( $raw, 'WHERE click_id = %d OR' ) ) {
				return $this->collision_clicks( $args );
			}
			if ( preg_match( '/^SELECT (view_id|click_id) FROM `([^`]+)` WHERE \1 = %d LIMIT 1$/', $raw, $match ) && isset( $this->shadow[ $match[2] ] ) ) {
				return $this->rollback_read( $match[1], $match[2], $args );
			}
		}
		if ( 'query' === $kind && preg_match( '/^DELETE FROM `([^`]+)`/', $raw, $match ) && isset( $this->shadow[ $match[1] ] ) ) {
			return $this->rollback_delete( $match[1], $args );
		}

		return new Link_Page_Migration_Delegation( $this->resolve_marker( $sql ) );
	}

	/**
	 * Expand a prepared marker through the real wpdb, or pass SQL through.
	 *
	 * @param string $sql Possibly marked SQL.
	 * @return string
	 */
	private function resolve_marker( $sql ) {
		$marker_id = $this->marker_id( $sql );
		if ( null === $marker_id || ! isset( $this->prepared_statements[ $marker_id ] ) ) {
			return $sql;
		}
		$stored = $this->prepared_statements[ $marker_id ];
		return call_user_func_array( array( $this->real, 'prepare' ), array_merge( array( $stored['query'] ), $stored['args'] ) );
	}

	/**
	 * Resolve a marker string to its stored statement.
	 *
	 * @param string $sql Possibly marked SQL.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function statement( $sql ) {
		$marker_id = $this->marker_id( $sql );
		if ( null !== $marker_id && isset( $this->prepared_statements[ $marker_id ] ) ) {
			$stored = $this->prepared_statements[ $marker_id ];
			return array( $stored['query'], $stored['args'] );
		}
		return array( $sql, array() );
	}

	/**
	 * Marker id of a prepared string, or null.
	 *
	 * @param string $sql Possibly marked SQL.
	 * @return int|null
	 */
	private function marker_id( $sql ) {
		if ( preg_match( '#^/\*ec-prepared:(\d+)\*/#', $sql, $match ) ) {
			return (int) $match[1];
		}
		return null;
	}

	/**
	 * Whether a table name is one of the migration's owned tables.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function is_owned_table( $table ) {
		return false !== strpos( $table, 'extrch_link_page_daily' );
	}

	/**
	 * Whether a table name is the daily-views table.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function is_views_table( $table ) {
		return false !== strpos( $table, 'daily_views' );
	}

	/**
	 * Serve exact contract column rows for one owned table.
	 *
	 * @param string $table Table name.
	 * @return array<int,array<string,mixed>>|false
	 */
	private function schema_columns( $table ) {
		if ( isset( $this->errors['schema_columns'] ) ) {
			$this->last_error = $this->errors['schema_columns'];
			return false;
		}
		$contracts = $this->is_views_table( $table ) ? extrachill_analytics_link_page_migration_view_columns() : extrachill_analytics_link_page_migration_click_columns();
		$rows      = array();
		foreach ( $contracts as $field => $contract ) {
			$rows[] = array(
				'Field'   => $field,
				'Type'    => $contract['type'],
				'Null'    => $contract['null'],
				'Default' => $contract['default'],
				'Extra'   => $contract['extra'],
			);
		}
		$kind = $this->is_views_table( $table ) ? 'views' : 'clicks';
		if ( ! empty( $this->schema_default_mismatch[ $kind ] ) ) {
			$rows[ count( $rows ) - 1 ]['Default'] = null;
		}
		return $rows;
	}

	/**
	 * Serve exact contract index rows for one owned table.
	 *
	 * @param string $table Table name.
	 * @return array<int,array<string,mixed>>|false
	 */
	private function schema_indexes( $table ) {
		if ( isset( $this->errors['schema_indexes'] ) ) {
			$this->last_error = $this->errors['schema_indexes'];
			return false;
		}
		$contracts = $this->is_views_table( $table ) ? extrachill_analytics_link_page_migration_view_indexes() : extrachill_analytics_link_page_migration_click_indexes();
		$rows      = array();
		foreach ( $contracts as $name => $contract ) {
			foreach ( $contract['columns'] as $position => $column ) {
				$rows[] = array(
					'Key_name'     => $name,
					'Non_unique'   => $contract['unique'] ? '0' : '1',
					'Seq_in_index' => (string) ( $position + 1 ),
					'Column_name'  => $column[0],
					'Sub_part'     => $column[1],
				);
			}
		}
		$kind = $this->is_views_table( $table ) ? 'views' : 'clicks';
		if ( ! empty( $this->index_missing[ $kind ] ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'unique_daily_link_click' !== $row['Key_name'] && 'unique_daily_view' !== $row['Key_name'];
					}
				)
			);
		}
		if ( ! empty( $this->index_content_mismatch[ $kind ] ) ) {
			$rows[ count( $rows ) - 1 ]['Sub_part'] = 999;
		}
		return $rows;
	}

	/**
	 * Read exact source rows for one owned table through the real database,
	 * or fail closed when the surface is configured to error.
	 *
	 * @param string $surface Error-injection surface name.
	 * @param string $raw     Raw, already-interpolated SELECT statement.
	 * @return array<int,array<string,mixed>>|false
	 */
	private function source_rows( $surface, $raw ) {
		if ( isset( $this->errors[ $surface ] ) ) {
			$this->last_error = $this->errors[ $surface ];
			return false;
		}
		$this->operations[] = $surface;
		return $this->delegate_results( $raw, ARRAY_A );
	}

	/**
	 * Evaluate the views collision read through the real database.
	 *
	 * @param string           $raw  Raw SQL.
	 * @param array<int,mixed> $args Prepared arguments.
	 * @return string|null|false
	 */
	private function collision_views( $raw, $args ) {
		if ( isset( $this->errors['collision_views'] ) ) {
			$this->last_error = $this->errors['collision_views'];
			return null;
		}
		$this->operations[] = 'collision_views';
		return $this->delegate_var( $this->expand( $raw, $args ) );
	}

	/**
	 * Evaluate the click collision read with owned prefix collation semantics.
	 *
	 * @param array<int,mixed> $args Prepared arguments.
	 * @return string|null|false
	 */
	private function collision_clicks( $args ) {
		if ( isset( $this->errors['collision_clicks'] ) ) {
			$this->last_error = $this->errors['collision_clicks'];
			return null;
		}
		$this->operations[] = 'collision_clicks';
		$rows               = $this->real->get_results(
			$this->real->prepare(
				"SELECT click_id, link_url, link_text FROM `{$this->real->prefix}extrch_link_page_daily_link_clicks` WHERE link_page_id = %d AND stat_date = %s",
				$args[1],
				$args[2]
			),
			ARRAY_A
		);
		$this->last_error   = (string) $this->real->last_error;
		if ( '' !== $this->last_error ) {
			return null;
		}
		foreach ( (array) $rows as $row ) {
			$primary = (int) $row['click_id'] === (int) $args[0];
			$unique  = $this->prefix_equal( $row['link_url'], $args[3], 191 ) && $this->prefix_equal( $row['link_text'], $args[4], 100 );
			if ( $primary || $unique ) {
				return (string) $row['click_id'];
			}
		}
		return null;
	}

	/**
	 * Execute the owned exact-snapshot conditional delete.
	 *
	 * @param string           $table Table name.
	 * @param array<int,mixed> $args  Snapshot values in owned column order.
	 * @return int|false
	 */
	private function rollback_delete( $table, $args ) {
		if ( isset( $this->errors['rollback_delete'] ) ) {
			$this->last_error = $this->errors['rollback_delete'];
			return false;
		}
		$this->operations[] = 'delete:' . $table;
		$formats            = $this->is_views_table( $table )
			? array( '%d', '%d', '%s', '%d' )
			: array( '%d', '%d', '%s', '%s', '%s', '%d' );
		foreach ( $this->shadow[ $table ] as $index => $row ) {
			$columns = array_values( array_keys( $row ) );
			$matches = true;
			foreach ( $formats as $position => $format ) {
				$value   = $row[ $columns[ $position ] ];
				$matches = $matches && ( '%d' === $format
					? (int) $value === (int) $args[ $position ]
					: (string) $value === (string) $args[ $position ] );
			}
			if ( $matches ) {
				array_splice( $this->shadow[ $table ], $index, 1 );
				return 1;
			}
		}
		return 0;
	}

	/**
	 * Read the owned key from the shadow store after a no-op delete.
	 *
	 * @param string           $key   Key column name.
	 * @param string           $table Table name.
	 * @param array<int,mixed> $args  Prepared arguments.
	 * @return string|null
	 */
	private function rollback_read( $key, $table, $args ) {
		foreach ( $this->shadow[ $table ] as $row ) {
			if ( (int) $row[ $key ] === (int) $args[0] ) {
				return (string) $row[ $key ];
			}
		}
		return null;
	}

	/**
	 * Execute strict JSON integer matching, including JSON_VALID behavior.
	 *
	 * @param string           $raw  Raw SQL.
	 * @param array<int,mixed> $args Prepared arguments.
	 * @return array<int,array<string,string>>|false
	 */
	private function historical_rows( $raw, $args ) {
		if ( isset( $this->errors['historical'] ) ) {
			$this->last_error = $this->errors['historical'];
			return false;
		}
		preg_match( '/ IN \(([^)]+)\)/', $raw, $match );
		$ids                = array_map( 'intval', explode( ',', $match[1] ?? '' ) );
		$strict             = false !== strpos( $raw, "JSON_TYPE(JSON_EXTRACT(event_data, '$.post_id')) = 'INTEGER'" );
		$blog               = (int) $args[0];
		$this->operations[] = 'historical:' . $blog;
		if ( $this->return_invalid_historical_json ) {
			return array(
				array(
					'blog_id'    => (string) $blog,
					'source_url' => 'https://artist.extrachill.com/bad',
					'event_data' => '{bad',
				),
			);
		}
		$result = array();
		foreach ( $this->events as $event ) {
			$decoded = json_decode( $event['event_data'], true );
			if ( JSON_ERROR_NONE !== json_last_error() || (int) $event['blog_id'] !== $blog || ! is_array( $decoded ) || ! array_key_exists( 'post_id', $decoded ) ) {
				continue;
			}
			$post_id = $decoded['post_id'];
			$matches = $strict ? is_int( $post_id ) && in_array( $post_id, $ids, true ) : in_array( (int) $post_id, $ids, true );
			if ( $matches ) {
				$result[] = array_intersect_key( $event, array_flip( array( 'blog_id', 'source_url', 'event_data' ) ) );
			}
		}
		return $result;
	}

	/**
	 * Case-insensitive indexed-prefix comparison.
	 *
	 * @param string $left   Stored value.
	 * @param string $right  Candidate value.
	 * @param int    $length Prefix length.
	 * @return bool
	 */
	private function prefix_equal( $left, $right, $length ) {
		return strcasecmp( substr( (string) $left, 0, $length ), substr( (string) $right, 0, $length ) ) === 0;
	}

	/**
	 * Expand a stored statement through the real wpdb.
	 *
	 * @param string           $raw  Raw SQL.
	 * @param array<int,mixed> $args Prepared arguments.
	 * @return string
	 */
	private function expand( $raw, $args ) {
		return call_user_func_array( array( $this->real, 'prepare' ), array_merge( array( $raw ), $args ) );
	}

	/**
	 * Delegate a scalar read to the real database.
	 *
	 * @param string $sql Expanded SQL.
	 * @return string|null|false
	 */
	private function delegate_var( $sql ) {
		$result           = $this->real->get_var( $sql );
		$this->last_error = (string) $this->real->last_error;
		return $result;
	}

	/**
	 * Delegate a row read to the real database.
	 *
	 * @param string      $sql    Expanded SQL.
	 * @param string|null $output Output type.
	 * @return array|false
	 */
	private function delegate_results( $sql, $output ) {
		$result           = $this->real->get_results( $sql, $output );
		$this->last_error = (string) $this->real->last_error;
		return $result;
	}

	/**
	 * Delegate a statement to the real database.
	 *
	 * @param string $sql Expanded SQL.
	 * @return int|false
	 */
	private function delegate_query( $sql ) {
		$result           = $this->real->query( $sql );
		$this->last_error = (string) $this->real->last_error;
		return $result;
	}
}

/**
 * Internal instruction to skip owned dispatch and delegate the carried SQL.
 */
final class Link_Page_Migration_Delegation {

	/**
	 * SQL to delegate.
	 *
	 * @var string
	 */
	public $sql;

	/**
	 * Store the delegation SQL.
	 *
	 * @param string $sql SQL string.
	 */
	public function __construct( $sql ) {
		$this->sql = $sql;
	}
}

/**
 * Verify the Analytics migration participant against executable database behavior.
 */
final class LinkPageStorageMigrationContractTest extends Extrachill_Analytics_TestCase {

	/**
	 * Delegating database double under test.
	 *
	 * @var Link_Page_Migration_Wpdb_Fixture
	 */
	private $db;

	/**
	 * Resolved per-test blog IDs.
	 *
	 * @var array<string,int>
	 */
	private $blogs = array();

	/**
	 * Install the double over the real wpdb for the duration of each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->db = new Link_Page_Migration_Wpdb_Fixture( $GLOBALS['wpdb'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored by the base tear_down(); required to route owned queries through the double.
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['extrachill_analytics_test_migration_participant'] = null;
	}

	/**
	 * Schema SQL errors and default-value distinctions fail closed on either site.
	 */
	public function test_source_and_destination_schema_failures_are_blockers(): void {
		$this->blogs();
		$this->db->errors['schema_columns'] = 'source schema failed';
		$result                             = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_schema_read_failed', $result->get_error_code() );

		$this->db->errors                            = array();
		$this->db->schema_default_mismatch['clicks'] = true;
		$result                                      = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_schema_mismatch', $result->get_error_code() );
		// Regression for #286: readiness runs the source-side schema check
		// first, so a mismatch found there must not be misreported as the
		// destination's fault.
		$this->assertStringContainsString( 'source', $result->get_error_message() );
		$this->assertStringNotContainsString( 'destination', $result->get_error_message() );

		$this->db->schema_default_mismatch = array();
		$this->db->errors                  = array( 'schema_indexes' => 'destination schema failed' );
		$result                            = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_schema_read_failed', $result->get_error_code() );
	}

	/**
	 * Column and index mismatches carry distinguishable error codes, and
	 * each message names the specific column or index that failed rather
	 * than repeating a single generic contract phrase for both.
	 */
	public function test_column_and_index_mismatches_are_distinguishable_and_named(): void {
		$this->blogs();

		$this->db->schema_default_mismatch['clicks'] = true;
		$result                                      = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_schema_mismatch', $result->get_error_code() );
		$this->assertStringContainsString( 'column', $result->get_error_message() );
		$this->assertNotEmpty( $result->get_error_data()['column'] ?? null );

		$this->db->schema_default_mismatch          = array();
		$this->db->index_content_mismatch['clicks'] = true;
		$result                                      = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_index_mismatch', $result->get_error_code() );
		$this->assertNotSame( 'analytics_link_page_migration_schema_mismatch', $result->get_error_code() );
		$this->assertSame( 'link_page_date', $result->get_error_data()['index'] ?? null );
		$this->assertStringContainsString( 'link_page_date', $result->get_error_message() );
		$this->assertStringContainsString( 'index', $result->get_error_message() );

		$this->db->index_content_mismatch = array();
		$this->db->index_missing['clicks'] = true;
		$result                             = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_index_mismatch', $result->get_error_code() );
		$this->assertSame( 'unique_daily_link_click', $result->get_error_data()['index'] ?? null );
		$this->assertStringContainsString( 'unique_daily_link_click', $result->get_error_message() );
	}

	/**
	 * `extrachill_analytics_link_page_migration_table_ready()` names the
	 * caller-supplied role in its message instead of always asserting
	 * "destination", and stays neutral when no role is supplied.
	 */
	public function test_table_ready_names_the_supplied_role(): void {
		list( $source ) = $this->blogs();
		switch_to_blog( $source );
		try {
			$table   = extrachill_analytics_link_page_clicks_table();
			$columns = extrachill_analytics_link_page_migration_click_columns();
			// Force a column mismatch deterministically by requiring a
			// column that cannot exist, independent of fixture wiring.
			$columns['click_id']['type'] = 'not-a-real-type';

			$as_source = extrachill_analytics_link_page_migration_table_ready(
				$table,
				$columns,
				extrachill_analytics_link_page_migration_click_indexes(),
				'source'
			);
			$this->assertStringContainsString( 'source', $as_source->get_error_message() );
			$this->assertStringNotContainsString( 'destination', $as_source->get_error_message() );

			$as_destination = extrachill_analytics_link_page_migration_table_ready(
				$table,
				$columns,
				extrachill_analytics_link_page_migration_click_indexes(),
				'destination'
			);
			$this->assertStringContainsString( 'destination', $as_destination->get_error_message() );
			$this->assertStringNotContainsString( 'source', $as_destination->get_error_message() );

			$unspecified = extrachill_analytics_link_page_migration_table_ready(
				$table,
				$columns,
				extrachill_analytics_link_page_migration_click_indexes()
			);
			$this->assertStringNotContainsString( 'source', $unspecified->get_error_message() );
			$this->assertStringNotContainsString( 'destination', $unspecified->get_error_message() );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Source and collision reads fail closed on database errors.
	 */
	public function test_source_and_collision_query_errors_fail_closed(): void {
		list( $source, $destination )     = $this->blogs();
		$this->db->errors['source_views'] = 'source read failed';
		$result                           = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_source_read_failed', $result->get_error_code() );

		$this->db->errors                    = array();
		$this->db->errors['collision_views'] = 'collision read failed';
		$this->insert_view( $source, 7, 42, 12 );
		$result = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_collision_read_failed', $result->get_error_code() );

		$this->db->errors                     = array();
		$this->db->errors['collision_clicks'] = 'click collision read failed';
		$this->wipe_tables( $source );
		$this->wipe_tables( $destination );
		$this->insert_click( $source, 9, 42, 'https://example.com', 'Example', 3 );
		$result = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_collision_read_failed', $result->get_error_code() );
	}

	/**
	 * Primary and indexed-prefix collisions block planning through real tables.
	 */
	public function test_primary_and_prefix_unique_collisions_block_planning(): void {
		list( $source, $destination ) = $this->blogs();
		$this->insert_view( $source, 7, 42, 12 );
		$this->insert_view( $destination, 7, 99, 1 );
		$result = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_collision', $result->get_error_code() );
		$this->assertContains( 'collision_views', $this->db->operations );

		$this->wipe_tables( $source );
		$this->wipe_tables( $destination );
		$url = str_repeat( 'A', 191 );
		$this->insert_click( $source, 9, 42, $url . '/source', 'BUY TICKETS', 1 );
		$this->insert_click( $destination, 88, 42, strtolower( $url ) . '/other', 'buy tickets', 1 );
		$result = $this->plan_readiness();
		$this->assertSame( 'analytics_link_page_migration_collision', $result->get_error_code() );
		$this->assertContains( 'collision_clicks', $this->db->operations );
	}

	/**
	 * Historical identity accepts only exact JSON integers and returns a deduped projection.
	 */
	public function test_historical_json_identity_is_strict_and_deduped(): void {
		foreach ( array( 42, 42, '42', true, 42.0, '42suffix', 420 ) as $index => $post_id ) {
			$this->db->events[] = array(
				'blog_id'    => '4',
				'source_url' => 'https://artist.extrachill.com/link',
				'event_data' => wp_json_encode( array( 'post_id' => $post_id ) ),
			);
		}
		$this->db->events[] = array(
			'blog_id'    => '4',
			'source_url' => 'bad',
			'event_data' => '{bad',
		);
		$result             = extrachill_analytics_link_page_migration_plan_result(
			array(
				'views'  => array(),
				'clicks' => array(),
			),
			4,
			$this->context( 'source_inventory' )
		);
		$this->assertSame(
			array(
				array(
					'matched_post_id' => 42,
					'blog_id'         => 4,
					'source_url'      => 'https://artist.extrachill.com/link',
				),
			),
			$result['historical_network_events']
		);

		$this->db->errors['historical'] = 'historical query failed';
		$result                         = extrachill_analytics_link_page_migration_plan_result(
			array(
				'views'  => array(),
				'clicks' => array(),
			),
			4,
			$this->context( 'source_inventory' )
		);
		if ( ! is_wp_error( $result ) ) {
			self::fail(
				'phase2 non-error: ' . wp_json_encode(
					array(
						'ops'  => $this->db->operations,
						'err'  => $this->db->last_error,
						'keys' => array_keys( (array) $result ),
					)
				)
			);
		}
		$this->assertSame( 'analytics_link_page_migration_historical_read_failed', $result->get_error_code() );

		$this->db->errors                         = array();
		$this->db->return_invalid_historical_json = true;
		$result                                   = extrachill_analytics_link_page_migration_plan_result(
			array(
				'views'  => array(),
				'clicks' => array(),
			),
			4,
			$this->context( 'source_inventory' )
		);
		$this->assertSame( 'analytics_link_page_migration_historical_json_failed', $result->get_error_code() );
	}

	/**
	 * Interrupted intents, exact snapshots, absence, and mismatches have safe rollback semantics.
	 */
	public function test_conditional_rollback_handles_interruption_absence_and_mismatch(): void {
		list( , $destination ) = $this->blogs();
		switch_to_blog( $destination );
		$table = extrachill_analytics_link_page_clicks_table();
		restore_current_blog();

		$row                        = array(
			'click_id'     => 9,
			'link_page_id' => 42,
			'stat_date'    => '2026-08-25',
			'link_url'     => 'https://example.com/Tickets?ref=A&B=1',
			'link_text'    => 'Tickets 100%',
			'click_count'  => 3,
		);
		$this->db->shadow[ $table ] = array( $row );
		$entry                      = $this->journal_entry( $row );

		$this->assertTrue( extrachill_analytics_link_page_migration_rollback( $this->rollback_context( array( $entry ) ) ) );
		$this->assertSame( array(), $this->db->shadow[ $table ] );
		$this->assertSame( array( 'delete:' . $table ), $this->db->operations );

		$this->db->operations = array();
		$this->assertTrue( extrachill_analytics_link_page_migration_rollback( $this->rollback_context( array( $entry ) ) ) );
		$this->assertSame( array( 'delete:' . $table ), $this->db->operations );

		$changed                    = $row;
		$changed['link_text']       = 'Case Changed';
		$this->db->shadow[ $table ] = array( $changed );
		$result                     = extrachill_analytics_link_page_migration_rollback( $this->rollback_context( array( $entry ) ) );
		$this->assertSame( 'analytics_link_page_migration_rollback_mismatch', $result->get_error_code() );
		$this->assertSame( array( $changed ), $this->db->shadow[ $table ] );
	}

	/**
	 * Apply leaves source immutable, validation succeeds, and context stays stable.
	 */
	public function test_apply_validate_inventory_and_context_are_stable(): void {
		list( $source, $destination, $detached ) = $this->blogs();
		$this->insert_view( $source, 7, 42, 12 );
		$this->insert_click( $source, 9, 42, 'https://example.com/tickets', 'Tickets', 3 );
		$source_views_before  = $this->read_table( $source, 'views' );
		$source_clicks_before = $this->read_table( $source, 'clicks' );

		$plan                           = $this->plan_inventory();
		$context                        = $this->context( 'apply' );
		$context['source_blog_id']      = $source;
		$context['destination_blog_id'] = $destination;
		$context['participant_plans']   = array( 'analytics' => $plan );
		$entries                        = array();
		$context['journal_record']      = static function ( $entry, $callback ) use ( &$entries ) {
			$result           = call_user_func( $callback );
			$entry['applied'] = true;
			$entries[]        = $entry;
			return $result;
		};

		switch_to_blog( $detached );
		$this->assertTrue( extrachill_analytics_link_page_migration_apply( $context ) );
		$this->assertSame( $detached, get_current_blog_id() );
		$this->assertTrue( extrachill_analytics_link_page_migration_validate( $context ) );
		$this->assertSame( $detached, get_current_blog_id() );
		restore_current_blog();

		$this->assertSame( $source_views_before, $this->read_table( $source, 'views' ) );
		$this->assertSame( $source_clicks_before, $this->read_table( $source, 'clicks' ) );
		$this->assertCount( 2, $entries );
		$this->assertSame( $plan, $this->plan_inventory() );
	}

	/**
	 * Views and clicks migrate independently by link_page_id: a clicks-only
	 * page, a views-only page with a non-contiguous gap between rows (the
	 * 2026-07-18 to 2026-09-19 view-recording outage), and a requested link
	 * page ID with no rows in either table (its Link Page no longer exists,
	 * or its history already fell out of the 90-day retention window) all
	 * plan without error. Only the rows that actually exist are copied, and
	 * no symmetry or contiguity between the two tables is required.
	 */
	public function test_plan_copies_asymmetric_gapped_and_absent_link_page_rows(): void {
		list( $source ) = $this->blogs();

		// Link page 42: a click was recorded but its view was lost to the outage.
		$this->insert_click( $source, 1, 42, 'https://example.com/tickets', 'Tickets', 3 );

		// Link page 55: two view rows survive with a two-month retention/outage
		// gap between them, and it has no clicks at all.
		$this->insert_view( $source, 2, 55, 4, '2026-07-17' );
		$this->insert_view( $source, 3, 55, 9, '2026-09-19' );

		switch_to_blog( $source );
		try {
			// 77 is requested alongside the others but has never had a view or
			// click row recorded for it.
			$result = extrachill_analytics_link_page_migration_plan(
				array(
					'mode'          => 'source_inventory',
					'link_page_ids' => array( 42, 55, 77 ),
				)
			);
		} finally {
			restore_current_blog();
		}

		$this->assertIsArray( $result );
		$this->assertSame( array( '2026-07-17', '2026-09-19' ), array_column( $result['rows']['views'], 'stat_date' ) );
		foreach ( $result['rows']['views'] as $row ) {
			$this->assertSame( 55, (int) $row['link_page_id'] );
		}
		$this->assertCount( 1, $result['rows']['clicks'] );
		$this->assertSame( 42, (int) $result['rows']['clicks'][0]['link_page_id'] );
		$this->assertSame( array(), $result['historical_network_events'] );
	}

	/**
	 * Registration exposes every latest sibling callback at contract version 1.
	 */
	public function test_participant_registration_version_is_enforced(): void {
		extrachill_analytics_register_link_page_migration_participant();
		$participant = $GLOBALS['extrachill_analytics_test_migration_participant'];
		$this->assertSame( 'analytics', $participant['name'] );
		$this->assertSame( '1', $participant['contract_version'] );
		$this->assertSame( 20, $participant['priority'] );
		$this->assertSame( array( 'claim_owner', 'plan', 'apply', 'validate', 'rollback' ), array_keys( $participant['callbacks'] ) );
		foreach ( $participant['callbacks'] as $callback ) {
			$this->assertTrue( is_callable( $callback ) );
		}
		$this->assertFalse( extrachill_analytics_link_page_migration_claim_owner( array() ) );
	}

	/**
	 * Swap the real wpdb in for fixture work.
	 *
	 * @return Link_Page_Migration_Wpdb_Fixture Double to restore afterwards.
	 */
	private function real_wpdb_swap(): Link_Page_Migration_Wpdb_Fixture {
		$double = $GLOBALS['wpdb'];
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fixture operations run on the real engine; restored by each helper.
		$GLOBALS['wpdb'] = $this->db->real_wpdb();
		return $double;
	}

	/**
	 * Restore the double after fixture work.
	 *
	 * @param Link_Page_Migration_Wpdb_Fixture $double Double to restore.
	 */
	private function restore_double( $double ): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reinstall the double for production-call surfaces.
		$GLOBALS['wpdb'] = $double;
	}

	/**
	 * Create the source, destination, and detached sites with owned tables.
	 *
	 * @return array<int,int> Source, destination, and detached blog IDs.
	 */
	private function blogs(): array {
		$double = $this->real_wpdb_swap();
		// A unique domain per call is required: create_blog() resolves the
		// created site by domain, and a repeated literal domain across test
		// methods would resolve back to the first test's site and its already
		// populated owned tables instead of a fresh, empty one.
		$suffix                     = uniqid();
		$this->blogs['source']      = $this->create_blog( "linkpage-source-{$suffix}.example.org" );
		$this->blogs['destination'] = $this->create_blog( "linkpage-dest-{$suffix}.example.org" );
		$this->blogs['detached']    = $this->create_blog( "linkpage-detached-{$suffix}.example.org" );
		foreach ( array( 'source', 'destination' ) as $kind ) {
			switch_to_blog( $this->blogs[ $kind ] );
			extrachill_analytics_link_page_create_table();
			restore_current_blog();
		}
		$this->restore_double( $double );
		return array( $this->blogs['source'], $this->blogs['destination'], $this->blogs['detached'] );
	}

	/**
	 * Run the readiness plan from the source blog context.
	 *
	 * @return WP_Error|array
	 */
	private function plan_readiness() {
		switch_to_blog( $this->blogs['source'] );
		try {
			return extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Run the inventory plan from the source blog context.
	 *
	 * @return WP_Error|array
	 */
	private function plan_inventory() {
		switch_to_blog( $this->blogs['source'] );
		try {
			return extrachill_analytics_link_page_migration_plan( $this->context( 'source_inventory' ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Insert one exact daily-view row.
	 *
	 * @param int    $blog_id      Blog ID.
	 * @param int    $view_id      Primary key.
	 * @param int    $link_page_id Link Page ID.
	 * @param int    $view_count   Daily count.
	 * @param string $date         Stat date.
	 */
	private function insert_view( $blog_id, $view_id, $link_page_id, $view_count, $date = '2026-08-25' ): void {
		$double = $this->real_wpdb_swap();
		switch_to_blog( $blog_id );
		$GLOBALS['wpdb']->insert(
			extrachill_analytics_link_page_views_table(),
			array(
				'view_id'      => $view_id,
				'link_page_id' => $link_page_id,
				'stat_date'    => $date,
				'view_count'   => $view_count,
			),
			array( '%d', '%d', '%s', '%d' )
		);
		restore_current_blog();
		$this->restore_double( $double );
	}

	/**
	 * Insert one exact daily-click row.
	 *
	 * @param int    $blog_id      Blog ID.
	 * @param int    $click_id     Primary key.
	 * @param int    $link_page_id Link Page ID.
	 * @param string $link_url     Destination URL.
	 * @param string $link_text    Link text.
	 * @param int    $click_count  Daily count.
	 */
	private function insert_click( $blog_id, $click_id, $link_page_id, $link_url, $link_text, $click_count ): void {
		$double = $this->real_wpdb_swap();
		switch_to_blog( $blog_id );
		$GLOBALS['wpdb']->insert(
			extrachill_analytics_link_page_clicks_table(),
			array(
				'click_id'     => $click_id,
				'link_page_id' => $link_page_id,
				'stat_date'    => '2026-08-25',
				'link_url'     => $link_url,
				'link_text'    => $link_text,
				'click_count'  => $click_count,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d' )
		);
		restore_current_blog();
		$this->restore_double( $double );
	}

	/**
	 * Delete every owned row on one blog's tables.
	 *
	 * @param int $blog_id Blog ID.
	 */
	private function wipe_tables( $blog_id ): void {
		$double = $this->real_wpdb_swap();
		switch_to_blog( $blog_id );
		$GLOBALS['wpdb']->query( 'DELETE FROM `' . extrachill_analytics_link_page_views_table() . '`' );
		$GLOBALS['wpdb']->query( 'DELETE FROM `' . extrachill_analytics_link_page_clicks_table() . '`' );
		restore_current_blog();
		$this->restore_double( $double );
	}

	/**
	 * Read one owned table's exact rows on a blog.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $kind    views or clicks.
	 * @return array<int,array<string,mixed>>
	 */
	private function read_table( $blog_id, $kind ): array {
		$double = $this->real_wpdb_swap();
		switch_to_blog( $blog_id );
		$table = 'views' === $kind ? extrachill_analytics_link_page_views_table() : extrachill_analytics_link_page_clicks_table();
		$order = 'views' === $kind ? 'view_id' : 'click_id';
		$rows  = (array) $GLOBALS['wpdb']->get_results( "SELECT * FROM `{$table}` ORDER BY {$order}", ARRAY_A );
		restore_current_blog();
		$this->restore_double( $double );
		return array_values( $rows );
	}

	/**
	 * Build a participant context.
	 *
	 * @param string $mode Participant mode.
	 * @return array<string,mixed>
	 */
	private function context( $mode ): array {
		return array(
			'mode'                => $mode,
			'source_blog_id'      => $this->blogs['source'] ?? 0,
			'destination_blog_id' => $this->blogs['destination'] ?? 0,
			'link_page_ids'       => array( 42 ),
		);
	}

	/**
	 * Build a rollback context.
	 *
	 * @param array<int,array<string,mixed>> $entries Journal entries.
	 * @return array<string,mixed>
	 */
	private function rollback_context( $entries ): array {
		return array(
			'destination_blog_id' => $this->blogs['destination'] ?? 0,
			'journal_entries'     => $entries,
		);
	}

	/**
	 * Return one participant insert journal entry.
	 *
	 * @param array<string,mixed> $row Exact row snapshot.
	 * @return array<string,mixed>
	 */
	private function journal_entry( $row ): array {
		return array(
			'type'        => 'participant',
			'participant' => 'analytics',
			'operation'   => 'insert',
			'table_kind'  => 'clicks',
			'row_id'      => (int) $row['click_id'],
			'row'         => $row,
			'applied'     => false,
		);
	}
}
