<?php
/**
 * ImportOrchestrator — drives a single import run end-to-end.
 *
 * Lifecycle:
 *   1. start()   — Insert a row in ec_concert_import_runs (status=pending),
 *                  enqueue the first async action.
 *   2. process() — Action Scheduler worker. Fetch ONE page, match each event,
 *                  call ec_users_mark_event() for matches, update counters.
 *                  Decide next move:
 *                    - More pages + quota remaining: enqueue next page now.
 *                    - More pages + rate limit hit:  enqueue with backoff delay.
 *                    - More pages + daily quota hit: enqueue at tomorrow's UTC.
 *                    - Done: mark status=complete.
 *
 * The run row is the single source of truth for resume state. The Action
 * Scheduler hook payload only carries the run id; everything else is read
 * from the row. This is what makes multi-day resume safe: even if Action
 * Scheduler loses or duplicates the action, the row's state determines what
 * happens next.
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.13.0
 */

namespace ExtraChill\Users\Concert_Import;

defined( 'ABSPATH' ) || exit;

final class ImportOrchestrator {

	public const HOOK            = 'extrachill_concert_import_process';
	public const GROUP           = 'extrachill-concert-import';
	public const STATUS_PENDING  = 'pending';
	public const STATUS_RUNNING  = 'running';
	public const STATUS_COMPLETE = 'complete';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_PAUSED   = 'paused';

	/**
	 * Register the Action Scheduler hook.
	 *
	 * Called from the plugin bootstrap so the worker is wired even if no
	 * import is currently running.
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 1 );
	}

	/**
	 * Start a new import run.
	 *
	 * @param int    $user_id  WP user ID initiating the import.
	 * @param string $slug     Source slug (e.g. 'setlist-fm').
	 * @param string $username External-platform username.
	 *
	 * @return array{ run_id: int }|\WP_Error
	 */
	public static function start( int $user_id, string $slug, string $username ) {
		$source = self::get_source( $slug );
		if ( ! $source ) {
			return new \WP_Error( 'unknown_source', 'Unknown import source.', array( 'status' => 400 ) );
		}
		if ( ! $source->is_configured() ) {
			return new \WP_Error(
				'source_not_configured',
				sprintf( 'The %s import is not configured on this platform yet.', $source->label() ),
				array( 'status' => 503 )
			);
		}
		if ( '' === trim( $username ) ) {
			return new \WP_Error( 'missing_username', 'Username is required.', array( 'status' => 400 ) );
		}

		// Block concurrent runs for (user, source) — only one active run at a time.
		$active = self::get_active_run( $user_id, $slug );
		if ( $active ) {
			return new \WP_Error(
				'import_in_progress',
				'An import for this source is already in progress.',
				array(
					'status' => 409,
					'run_id' => (int) $active['id'],
				)
			);
		}

		// Persist the per-user username for future re-runs.
		update_user_meta( $user_id, self::username_meta_key( $slug ), sanitize_text_field( $username ) );

		global $wpdb;
		$table = extrachill_users_concert_import_runs_table_name();
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'user_id'           => $user_id,
				'source_slug'       => $slug,
				'status'            => self::STATUS_PENDING,
				'external_username' => $username,
				'next_page'         => 1,
				'started_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$run_id = (int) $wpdb->insert_id;
		if ( ! $run_id ) {
			return new \WP_Error( 'insert_failed', 'Could not create import run row.', array( 'status' => 500 ) );
		}

		self::enqueue( $run_id, 0 );

		return array( 'run_id' => $run_id );
	}

	/**
	 * Action Scheduler entry point.
	 *
	 * @param int $run_id Import run row ID.
	 */
	public static function process( int $run_id ): void {
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return;
		}
		if ( in_array( $run['status'], array( self::STATUS_COMPLETE, self::STATUS_FAILED ), true ) ) {
			return;
		}

		$source = self::get_source( $run['source_slug'] );
		if ( ! $source ) {
			self::mark_failed( $run_id, 'Source adapter is no longer available.' );
			return;
		}
		if ( ! $source->is_configured() ) {
			self::mark_failed( $run_id, 'Source is no longer configured.' );
			return;
		}

		self::update_run(
			$run_id,
			array(
				'status'          => self::STATUS_RUNNING,
				'next_attempt_at' => null,
			)
		);

		// Reset per-day request counter when the calendar date rolls over (UTC).
		$today = gmdate( 'Y-m-d' );
		if ( $run['requests_today_date'] !== $today ) {
			self::update_run(
				$run_id,
				array(
					'requests_today'      => 0,
					'requests_today_date' => $today,
				)
			);
			$run['requests_today']      = 0;
			$run['requests_today_date'] = $today;
		}

		$rate    = $source->rate_limit();
		$max_day = isset( $rate['requests_per_day'] ) ? (int) $rate['requests_per_day'] : 0;
		if ( $max_day && $run['requests_today'] >= $max_day ) {
			self::reschedule_tomorrow( $run_id );
			return;
		}

		$page   = ! empty( $run['next_page'] ) ? (int) $run['next_page'] : 1;
		$result = $source->fetch_page( (string) $run['external_username'], $page );

		// Always count the request attempt against today's quota.
		self::increment_requests_today( $run_id );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'rate_limit' === $code ) {
				// Source-specific backoff (seconds) from error_data, default 60s.
				$data  = $result->get_error_data();
				$retry = is_array( $data ) && isset( $data['retry_after'] ) ? max( 1, (int) $data['retry_after'] ) : 60;
				self::reschedule( $run_id, $retry );
				return;
			}
			if ( 'daily_quota' === $code ) {
				self::reschedule_tomorrow( $run_id );
				return;
			}
			self::mark_failed( $run_id, $result->get_error_message() );
			return;
		}

		$events      = isset( $result['events'] ) && is_array( $result['events'] ) ? $result['events'] : array();
		$total_pages = isset( $result['total_pages'] ) ? max( 1, (int) $result['total_pages'] ) : $page;
		$matcher     = new EventMatcher();
		$events_blog = $matcher->blog_id();
		$source_slug = (string) $run['source_slug'];

		$seen             = 0;
		$matched          = 0;
		$created          = 0;
		$unmatched        = 0;
		$skipped          = 0;
		$attendance_error = null;
		$rejected_event   = null;

		// Suppress per-event IndexNow pings for the duration of this import
		// batch. Each created event would otherwise trigger a synchronous
		// outbound IndexNow POST (twice — Data Machine + extrachill-seo both
		// honor this canonical filter), asking search engines to crawl
		// potentially thousands of historical pages mid-import. The sitemap
		// still advertises them on the normal crawl cadence.
		$suppress_indexnow = static function (): bool {
			return true;
		};
		add_filter( 'datamachine_indexnow_skip_auto_submit', $suppress_indexnow );

		// All event resolution + creation happens in the events-blog context.
		// Switching once per page keeps the work atomic and avoids paying the
		// switch_to_blog cost per-event. The EventMatcher will detect that we
		// are already in the events blog and skip its own switch.
		$switched     = false;
		$current_blog = get_current_blog_id();
		if ( $current_blog !== $events_blog ) {
			switch_to_blog( $events_blog );
			$switched = true;
		}

		try {
			foreach ( $events as $external ) {
				if ( ! $external instanceof ExternalEvent ) {
					++$skipped;
					continue;
				}
				++$seen;

				if ( ! $external->is_matchable() ) {
					++$skipped;
					continue;
				}

				// 1. external_id idempotency check. The source's stable
				// identifier is more authoritative than name similarity, so
				// we look here BEFORE running the similarity matcher.
				// Re-imports for previously-created events become a no-op.
				$resolved_id = EventCreator::find_by_external_id( $source_slug, $external->source_id );

				// 2. Similarity match against existing events on the same date.
				if ( null === $resolved_id ) {
					$resolved_id = $matcher->match( $external );
				}

				$was_created = false;

				// 3. Fall through to create. Skipping is data loss — every
				// other event-import path on the platform creates events,
				// and we already have the user's confirmation to bring this
				// history in.
				if ( null === $resolved_id ) {
					$resolved_id = EventCreator::create( $external, $source_slug );
					if ( null === $resolved_id ) {
						// Truly couldn't create (malformed payload, insert
						// failure). Count as unmatched so it shows up in the
						// run summary instead of being lost in skipped.
						++$unmatched;
						continue;
					}
					$was_created = true;
				}

				$mark_result = ec_users_mark_event( (int) $run['user_id'], $resolved_id, $events_blog );
				if ( is_wp_error( $mark_result ) ) {
					++$unmatched;
					$attendance_error = $mark_result;
					$rejected_event   = $external;
					break;
				}

				if ( $was_created ) {
					++$created;
				} else {
					++$matched;
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
			remove_filter( 'datamachine_indexnow_skip_auto_submit', $suppress_indexnow );
		}

		self::increment_counters( $run_id, $seen, $matched, $created, $unmatched, $skipped );
		self::update_run(
			$run_id,
			array(
				'total_pages' => $total_pages,
			)
		);
		if ( $attendance_error ) {
			self::mark_failed(
				$run_id,
				sprintf(
					'Attendance import rejected %s (%s): %s',
					$rejected_event instanceof ExternalEvent ? $rejected_event->label() : 'event',
					$attendance_error->get_error_code(),
					$attendance_error->get_error_message()
				)
			);
			return;
		}

		if ( $page >= $total_pages ) {
			self::mark_complete( $run_id );
			return;
		}

		self::update_run( $run_id, array( 'next_page' => $page + 1 ) );

		// Re-read the row so we use the freshly-incremented requests_today.
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return;
		}

		$max_day = isset( $rate['requests_per_day'] ) ? (int) $rate['requests_per_day'] : 0;
		if ( $max_day && (int) $run['requests_today'] >= $max_day ) {
			self::reschedule_tomorrow( $run_id );
			return;
		}

		$per_sec = isset( $rate['requests_per_second'] ) ? (float) $rate['requests_per_second'] : 0.0;
		$delay   = $per_sec > 0 ? (int) max( 1, ceil( 1.0 / $per_sec ) ) : 1;
		self::reschedule( $run_id, $delay );
	}

	// ─── Source registry ──────────────────────────────────────────────

	/**
	 * Return all registered ImportSource instances keyed by slug.
	 *
	 * @return array<string, ImportSource>
	 */
	public static function sources(): array {
		$sources = apply_filters( 'extrachill_concert_import_sources', array() );
		$out     = array();
		foreach ( (array) $sources as $source ) {
			if ( $source instanceof ImportSource ) {
				$out[ $source->slug() ] = $source;
			}
		}
		return $out;
	}

	public static function get_source( string $slug ): ?ImportSource {
		$sources = self::sources();
		return $sources[ $slug ] ?? null;
	}

	// ─── DB helpers ───────────────────────────────────────────────────

	/**
	 * Build the user-meta key holding the per-user username for a source.
	 */
	public static function username_meta_key( string $slug ): string {
		// Normalize slug to a meta-safe key.
		$key = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $slug ) );
		return 'ec_concert_import_' . $key . '_username';
	}

	/**
	 * Fetch a single run row as an assoc array.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_run( int $run_id ): ?array {
		global $wpdb;
		$table = extrachill_users_concert_import_runs_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$run_id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Get the active (pending/running/paused) run for a (user, source) pair.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_active_run( int $user_id, string $slug ): ?array {
		global $wpdb;
		$table = extrachill_users_concert_import_runs_table_name();
		// Table name is an internal constant from extrachill_users_concert_import_runs_table_name(); not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table}
				WHERE user_id = %d AND source_slug = %s
				AND status IN ( 'pending', 'running', 'paused' )
				ORDER BY id DESC LIMIT 1";
		$row = $wpdb->get_row(
			$wpdb->prepare( $sql, $user_id, $slug ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Get a user's recent import runs (any status) across all sources.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_user_runs( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		$table = extrachill_users_concert_import_runs_table_name();
		// Table name is an internal constant from extrachill_users_concert_import_runs_table_name(); not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM {$table}
				WHERE user_id = %d
				ORDER BY id DESC
				LIMIT %d";
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $user_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	private static function update_run( int $run_id, array $changes ): void {
		global $wpdb;
		$table                 = extrachill_users_concert_import_runs_table_name();
		$changes['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( $table, $changes, array( 'id' => $run_id ) );
	}

	private static function increment_counters( int $run_id, int $seen, int $matched, int $created, int $unmatched, int $skipped ): void {
		global $wpdb;
		$table = extrachill_users_concert_import_runs_table_name();
		$now   = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET total_events_seen      = total_events_seen      + %d,
				    total_events_matched   = total_events_matched   + %d,
				    total_events_created   = total_events_created   + %d,
				    total_events_unmatched = total_events_unmatched + %d,
				    total_events_skipped   = total_events_skipped   + %d,
				    updated_at             = %s
				WHERE id = %d",
				$seen,
				$matched,
				$created,
				$unmatched,
				$skipped,
				$now,
				$run_id
			)
		);
		// phpcs:enable
	}

	private static function increment_requests_today( int $run_id ): void {
		global $wpdb;
		$table = extrachill_users_concert_import_runs_table_name();
		$today = gmdate( 'Y-m-d' );
		$now   = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET requests_today      = CASE WHEN requests_today_date = %s THEN requests_today + 1 ELSE 1 END,
				    requests_today_date = %s,
				    updated_at          = %s
				WHERE id = %d",
				$today,
				$today,
				$now,
				$run_id
			)
		);
		// phpcs:enable
	}

	private static function mark_complete( int $run_id ): void {
		self::update_run(
			$run_id,
			array(
				'status'          => self::STATUS_COMPLETE,
				'next_page'       => null,
				'next_attempt_at' => null,
				'completed_at'    => current_time( 'mysql', true ),
			)
		);
	}

	private static function mark_failed( int $run_id, string $message ): void {
		self::update_run(
			$run_id,
			array(
				'status'          => self::STATUS_FAILED,
				'next_attempt_at' => null,
				'error_message'   => $message,
				'completed_at'    => current_time( 'mysql', true ),
			)
		);
	}

	// ─── Scheduling ───────────────────────────────────────────────────

	/**
	 * Enqueue an immediate async action.
	 */
	private static function enqueue( int $run_id, int $delay_seconds ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( $delay_seconds <= 0 ) {
			as_enqueue_async_action( self::HOOK, array( $run_id ), self::GROUP );
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay_seconds, self::HOOK, array( $run_id ), self::GROUP );
		}
	}

	private static function reschedule( int $run_id, int $delay_seconds ): void {
		$ts = time() + max( 1, $delay_seconds );
		self::update_run(
			$run_id,
			array(
				'status'          => self::STATUS_PAUSED,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $ts ),
			)
		);
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $ts, self::HOOK, array( $run_id ), self::GROUP );
		}
	}

	private static function reschedule_tomorrow( int $run_id ): void {
		// Resume at 00:05 UTC tomorrow — small buffer so the source's daily
		// counter has definitely rolled over.
		$ts = strtotime( 'tomorrow 00:05:00 UTC' );
		if ( ! $ts ) {
			$ts = time() + DAY_IN_SECONDS;
		}
		self::update_run(
			$run_id,
			array(
				'status'          => self::STATUS_PAUSED,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $ts ),
			)
		);
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $ts, self::HOOK, array( $run_id ), self::GROUP );
		}
	}
}
