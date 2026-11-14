<?php
/**
 * Owned column and index contract for the per-site Link Page analytics tables,
 * plus the versioned schema-reconciliation pass that keeps existing tables on
 * that contract (#287).
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read the mutable error state from the most recent database operation.
 *
 * @phpstan-impure Database queries mutate this global value between calls.
 */
function extrachill_analytics_link_page_schema_database_error() {
	global $wpdb;
	return (string) $wpdb->last_error;
}

/**
 * Find the first key whose value differs (added, removed, or changed)
 * between an expected and actual map, so a mismatch message can name it.
 *
 * @param array $expected Expected map.
 * @param array $actual   Actual map.
 * @return string|null
 */
function extrachill_analytics_link_page_schema_first_difference( $expected, $actual ) {
	foreach ( $expected as $key => $value ) {
		if ( ! array_key_exists( $key, $actual ) || $actual[ $key ] !== $value ) {
			return (string) $key;
		}
	}
	foreach ( $actual as $key => $value ) {
		if ( ! array_key_exists( $key, $expected ) ) {
			return (string) $key;
		}
	}
	return null;
}

/**
 * Read exact current columns for one owned table, keyed by field name in
 * physical column order.
 *
 * @param string $table Table name.
 * @return array<string,array<string,mixed>>|WP_Error
 */
function extrachill_analytics_link_page_schema_read_actual_columns( $table ) {
	global $wpdb;
	$column_rows    = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned schema preflight.
	$database_error = extrachill_analytics_link_page_schema_database_error();
	if ( '' !== $database_error ) {
		return new WP_Error( 'analytics_link_page_schema_read_failed', $database_error, array( 'table' => $table ) ); }
	$actual_columns = array();
	foreach ( $column_rows ? $column_rows : array() as $row ) {
		$actual_columns[ $row['Field'] ] = array(
			'type'            => strtolower( $row['Type'] ),
			'null'            => $row['Null'],
			'default_is_null' => null === $row['Default'],
			'default'         => $row['Default'],
			'extra'           => strtolower( $row['Extra'] ),
		);
	}
	return $actual_columns;
}

/**
 * Read exact current indexes for one owned table.
 *
 * @param string $table Table name.
 * @return array<string,array<string,mixed>>|WP_Error
 */
function extrachill_analytics_link_page_schema_read_actual_indexes( $table ) {
	global $wpdb;
	$index_rows     = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact schema preflight.
	$database_error = extrachill_analytics_link_page_schema_database_error();
	if ( '' !== $database_error ) {
		return new WP_Error( 'analytics_link_page_schema_read_failed', $database_error, array( 'table' => $table ) ); }
	$actual = array();
	foreach ( $index_rows ? $index_rows : array() as $row ) {
		$actual[ $row['Key_name'] ]['unique']                                = '0' === (string) $row['Non_unique'];
		$actual[ $row['Key_name'] ]['columns'][ (int) $row['Seq_in_index'] ] = array( $row['Column_name'], null === $row['Sub_part'] ? null : (int) $row['Sub_part'] );
	}
	foreach ( $actual as &$index ) {
		ksort( $index['columns'], SORT_NUMERIC );
		$index['columns'] = array_values( $index['columns'] );
	}
	unset( $index );
	return $actual;
}

/**
 * Verify one owned table has the required columns and indexes.
 *
 * Column order is part of the contract: PHP's `!==` on an associative array
 * compares key order as well as key/value pairs, so a table whose columns
 * are otherwise identical but physically reordered (the #287 drift) fails
 * this check.
 *
 * @param string $table   Table name.
 * @param array  $columns Required columns.
 * @param array  $indexes Required indexes.
 * @return true|WP_Error
 */
function extrachill_analytics_link_page_schema_table_ready( $table, $columns, $indexes ) {
	$actual_columns = extrachill_analytics_link_page_schema_read_actual_columns( $table );
	if ( is_wp_error( $actual_columns ) ) {
		return $actual_columns;
	}
	if ( $actual_columns !== $columns ) {
		$column = extrachill_analytics_link_page_schema_first_difference( $columns, $actual_columns );
		return new WP_Error(
			'analytics_link_page_schema_mismatch',
			sprintf( 'An owned Analytics table does not match its expected column contract (column: `%s`).', (string) $column ),
			array(
				'table'  => $table,
				'column' => $column,
			)
		);
	}
	$actual = extrachill_analytics_link_page_schema_read_actual_indexes( $table );
	if ( is_wp_error( $actual ) ) {
		return $actual;
	}
	$actual_names   = array_keys( $actual );
	$expected_names = array_keys( $indexes );
	sort( $actual_names, SORT_STRING );
	sort( $expected_names, SORT_STRING );
	if ( $actual_names !== $expected_names ) {
		$index_name = extrachill_analytics_link_page_schema_first_difference(
			array_fill_keys( $expected_names, true ),
			array_fill_keys( $actual_names, true )
		);
		return new WP_Error(
			'analytics_link_page_schema_index_mismatch',
			sprintf( 'An owned Analytics table has an unexpected index contract (index: `%s`).', (string) $index_name ),
			array(
				'table' => $table,
				'index' => $index_name,
			)
		);
	}
	foreach ( $indexes as $name => $index_contract ) {
		if ( ! isset( $actual[ $name ] ) || $actual[ $name ] !== $index_contract ) {
			return new WP_Error(
				'analytics_link_page_schema_index_mismatch',
				sprintf( 'An owned Analytics table does not match its expected index contract (index: `%s`).', $name ),
				array(
					'table' => $table,
					'index' => $name,
				)
			);
		}
	}
	return true;
}

/**
 * Build the SQL definition fragment for one contract column.
 *
 * `default_is_null` is true both for a genuinely nullable-and-NULL-defaulted
 * column and for a NOT NULL column that never had a DEFAULT clause at all
 * (every owned column today is the latter) — either way SHOW COLUMNS reports
 * Default as NULL, and either way no DEFAULT clause belongs in the rebuilt
 * definition. Emitting `DEFAULT NULL` for a NOT NULL column with no declared
 * default would itself be invalid SQL.
 *
 * @param string $name Column name.
 * @param array  $spec Contract column definition.
 * @return string
 */
function extrachill_analytics_link_page_schema_column_definition_sql( $name, $spec ) {
	global $wpdb;
	$sql  = "`{$name}` {$spec['type']}";
	$sql .= ( 'NO' === $spec['null'] ) ? ' NOT NULL' : ' NULL';
	if ( ! $spec['default_is_null'] ) {
		$sql .= $wpdb->prepare( ' DEFAULT %s', $spec['default'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Builds a literal DDL fragment from the owned contract, not a full query.
	}
	if ( '' !== $spec['extra'] ) {
		$sql .= ' ' . $spec['extra'];
	}
	return $sql;
}

/**
 * Build the SQL definition fragment for one contract index.
 *
 * @param string $name Index name.
 * @param array  $spec Contract index definition.
 * @return string
 */
function extrachill_analytics_link_page_schema_index_definition_sql( $name, $spec ) {
	$columns = array();
	foreach ( $spec['columns'] as $column ) {
		$columns[] = $column[1] ? "`{$column[0]}`({$column[1]})" : "`{$column[0]}`";
	}
	$type = $spec['unique'] ? 'UNIQUE KEY' : 'KEY';
	return "{$type} `{$name}` (" . implode( ', ', $columns ) . ')';
}

/**
 * Bring one owned table's columns and indexes to the exact contract.
 *
 * `dbDelta()` appends a new column at the end of the table and never
 * rebuilds an existing index, so a table created before a contract change —
 * `link_text` added after `extrch_link_page_daily_link_clicks` originally
 * shipped — keeps its stale column order and stale `unique_daily_link_click`
 * definition forever without an explicit reconciliation pass (#287).
 *
 * Non-destructive by construction: every column operation here is an ADD or
 * a same-or-widening-type MODIFY addressed by name, so no column is ever
 * dropped or narrowed and no value is lost; reordering a column via
 * `MODIFY COLUMN ... AFTER` moves it and its values together. Every index
 * operation replaces a stale index definition with the current contract —
 * DROP INDEX / ADD (UNIQUE) KEY changes which combinations are permitted
 * going forward but does not touch row data, and widening a unique key can
 * only relax an existing constraint, never turn a previously distinct row
 * combination into a duplicate. PRIMARY KEY changes are out of scope: no
 * owned contract in this codebase changes a PRIMARY KEY, and a residual
 * mismatch there is reported back as an error instead of acted on.
 *
 * Idempotent: `table_ready()` short-circuits immediately once the table
 * already matches, so a second call against an already-reconciled table
 * issues no DDL.
 *
 * @param string $table   Table name.
 * @param array  $columns Contract columns, in target order.
 * @param array  $indexes Contract indexes.
 * @return true|WP_Error
 */
function extrachill_analytics_link_page_schema_reconcile_table( $table, $columns, $indexes ) {
	global $wpdb;

	$ready = extrachill_analytics_link_page_schema_table_ready( $table, $columns, $indexes );
	if ( true === $ready ) {
		return true;
	}
	if ( 'analytics_link_page_schema_read_failed' === $ready->get_error_code() ) {
		return $ready;
	}

	$actual_columns = extrachill_analytics_link_page_schema_read_actual_columns( $table );
	if ( is_wp_error( $actual_columns ) ) {
		return $actual_columns;
	}
	if ( array() === $actual_columns ) {
		// The table does not exist yet; create_table() owns creation, not this routine.
		return true;
	}

	$previous = null;
	foreach ( $columns as $name => $spec ) {
		$definition = extrachill_analytics_link_page_schema_column_definition_sql( $name, $spec );
		$position   = null === $previous ? 'FIRST' : "AFTER `{$previous}`";
		$verb       = isset( $actual_columns[ $name ] ) ? 'MODIFY COLUMN' : 'ADD COLUMN';
		$wpdb->query( "ALTER TABLE `{$table}` {$verb} {$definition} {$position}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owned reconciliation DDL built entirely from the owned column contract.
		$database_error = extrachill_analytics_link_page_schema_database_error();
		if ( '' !== $database_error ) {
			return new WP_Error(
				'analytics_link_page_schema_reconcile_column_failed',
				$database_error,
				array(
					'table'  => $table,
					'column' => $name,
				)
			);
		}
		$previous = $name;
	}

	$actual_indexes = extrachill_analytics_link_page_schema_read_actual_indexes( $table );
	if ( is_wp_error( $actual_indexes ) ) {
		return $actual_indexes;
	}
	foreach ( $actual_indexes as $name => $definition ) {
		if ( 'PRIMARY' === $name ) {
			continue;
		}
		if ( isset( $indexes[ $name ] ) && $indexes[ $name ] === $definition ) {
			continue;
		}
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owned reconciliation DDL: replaces a stale index with the current contract.
		$database_error = extrachill_analytics_link_page_schema_database_error();
		if ( '' !== $database_error ) {
			return new WP_Error(
				'analytics_link_page_schema_reconcile_index_failed',
				$database_error,
				array(
					'table' => $table,
					'index' => $name,
				)
			);
		}
		unset( $actual_indexes[ $name ] );
	}
	foreach ( $indexes as $name => $spec ) {
		if ( 'PRIMARY' === $name || isset( $actual_indexes[ $name ] ) ) {
			continue;
		}
		$definition = extrachill_analytics_link_page_schema_index_definition_sql( $name, $spec );
		$wpdb->query( "ALTER TABLE `{$table}` ADD {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owned reconciliation DDL built entirely from the owned index contract.
		$database_error = extrachill_analytics_link_page_schema_database_error();
		if ( '' !== $database_error ) {
			return new WP_Error(
				'analytics_link_page_schema_reconcile_index_failed',
				$database_error,
				array(
					'table' => $table,
					'index' => $name,
				)
			);
		}
	}

	return extrachill_analytics_link_page_schema_table_ready( $table, $columns, $indexes );
}

/** Return the exact daily-view column contract. */
function extrachill_analytics_link_page_schema_view_columns() {
	return array(
		'view_id'      => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => 'auto_increment',
		),
		'link_page_id' => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'stat_date'    => array(
			'type'            => 'date',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'view_count'   => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => false,
			'default'         => '0',
			'extra'           => '',
		),
	); }

/** Return the exact click column contract. */
function extrachill_analytics_link_page_schema_click_columns() {
	return array(
		'click_id'     => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => 'auto_increment',
		),
		'link_page_id' => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'stat_date'    => array(
			'type'            => 'date',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'link_url'     => array(
			'type'            => 'varchar(2083)',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'link_text'    => array(
			'type'            => 'varchar(255)',
			'null'            => 'NO',
			'default_is_null' => false,
			'default'         => '',
			'extra'           => '',
		),
		'click_count'  => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => false,
			'default'         => '0',
			'extra'           => '',
		),
	); }

/** Return the exact daily-view index contract. */
function extrachill_analytics_link_page_schema_view_indexes() {
	return array(
		'PRIMARY'           => array(
			'unique'  => true,
			'columns' => array( array( 'view_id', null ) ),
		),
		'unique_daily_view' => array(
			'unique'  => true,
			'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ) ),
		),
	); }

/** Return the exact click index contract. */
function extrachill_analytics_link_page_schema_click_indexes() {
	return array(
		'PRIMARY'                 => array(
			'unique'  => true,
			'columns' => array( array( 'click_id', null ) ),
		),
		'unique_daily_link_click' => array(
			'unique'  => true,
			'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ), array( 'link_url', 191 ), array( 'link_text', 100 ) ),
		),
		'link_page_date'          => array(
			'unique'  => false,
			'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ) ),
		),
	); }
