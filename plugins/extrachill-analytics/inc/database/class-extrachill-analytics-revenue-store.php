<?php
/**
 * Revenue snapshot store.
 *
 * A thin, cohesive wrapper over the existing revenue persistence helpers
 * (`extrachill_analytics_revenue_get_rows()`, `extrachill_analytics_revenue_upsert()`,
 * `extrachill_analytics_revenue_delete_ids()`, and the transaction helpers). It
 * exists for one reason: the ingestion ability's replacement/transaction logic
 * is load-bearing and must be unit-testable, and this repository has no
 * WordPress-DB test scaffold. The store exposes the small set of operations the
 * engine needs behind a duck-typed interface, so the pure decision logic can be
 * exercised against an in-memory fake in tests while production binds to this
 * real `$wpdb`-backed implementation.
 *
 * This is NOT a parallel writer: `upsert()` delegates straight to the single
 * existing writer (`extrachill_analytics_revenue_upsert()`), and `get_snapshot()`
 * delegates to the existing reader. There is exactly one persistence
 * implementation; this class only groups the calls the engine makes.
 *
 * @package ExtraChill\Analytics
 * @since 0.28.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin $wpdb-backed revenue store implementing the operations the ingestion
 * engine depends on.
 */
final class Extrachill_Analytics_Revenue_Store {

	/**
	 * Return the existing rows of exactly one snapshot.
	 *
	 * Each row is an object including at least `id` and `slug`. Scoped to one
	 * (blog_id, period_label, import_batch) so the caller can compute a
	 * deterministic replace plan that never escapes the snapshot.
	 *
	 * @param int    $blog_id      Blog ID.
	 * @param string $period_label Canonical period label.
	 * @param string $import_batch Snapshot identity / batch label.
	 * @return array<int, object> Existing snapshot rows.
	 */
	public function get_snapshot( $blog_id, $period_label, $import_batch ) {
		return extrachill_analytics_revenue_get_rows(
			array(
				'blog_id'      => $blog_id,
				'period_label' => $period_label,
				'import_batch' => $import_batch,
			)
		);
	}

	/**
	 * Insert or replace one snapshot row.
	 *
	 * Delegates to the single existing writer so there is one persistence path.
	 *
	 * @param array $row Record shaped per extrachill_analytics_revenue_upsert().
	 * @return int|false Inserted/replaced row ID, or false on failure.
	 */
	public function upsert( array $row ) {
		return extrachill_analytics_revenue_upsert( $row );
	}

	/**
	 * Delete snapshot rows by primary-key ID.
	 *
	 * @param array<int> $ids Row IDs to delete.
	 * @return int|false Rows deleted, or false on error.
	 */
	public function delete_ids( array $ids ) {
		return extrachill_analytics_revenue_delete_ids( $ids );
	}

	/**
	 * Acquire a named advisory lock serializing ingestion for one period.
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds to wait.
	 * @return bool
	 */
	public function lock( $name, $timeout = 10 ) {
		return extrachill_analytics_revenue_lock_acquire( $name, $timeout );
	}

	/**
	 * Release a previously acquired advisory lock.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public function unlock( $name ) {
		return extrachill_analytics_revenue_lock_release( $name );
	}

	/**
	 * Begin a transaction (no-op on engines without transactions).
	 *
	 * @return bool
	 */
	public function begin() {
		return extrachill_analytics_revenue_transaction_begin();
	}

	/**
	 * Commit the active transaction.
	 *
	 * @return bool
	 */
	public function commit() {
		return extrachill_analytics_revenue_transaction_commit();
	}

	/**
	 * Roll back the active transaction.
	 *
	 * @return bool
	 */
	public function rollback() {
		return extrachill_analytics_revenue_transaction_rollback();
	}
}
