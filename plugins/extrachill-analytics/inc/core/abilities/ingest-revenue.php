<?php
/**
 * Ingest Revenue Ability
 *
 * The first-class, idempotent mutation ability that owns Mediavine (and any
 * future per-URL ad-revenue) ingestion into the revenue store.
 *
 * WHY THIS EXISTS (issue #140 / extrachill-cli#92):
 * The CLI `revenue fetch` path used to serialize source rows to a RANDOM temp
 * CSV and call the importer without an explicit batch. The importer derived the
 * batch from that random filename, so re-fetching one period created a SECOND
 * snapshot for the same period_label. Because the revenue ARC and rollups SUM
 * every batch by default, a repeat operational fetch silently doubled that
 * month. This ability replaces that flow with a deterministic snapshot identity
 * keyed on STABLE inputs (source + source-site + blog + period) and explicit
 * replace/additive semantics, so identical inputs always land on the same
 * snapshot and leave totals unchanged.
 *
 * STORAGE OWNERSHIP:
 * Analytics owns persistence, period/snapshot identity, replacement policy,
 * resolution, and mutation validation. It reuses the existing writer
 * (`extrachill_analytics_revenue_upsert()`) and the shared slug resolver
 * (`extrachill_analytics_revenue_resolve_slug()`) — there is still exactly one
 * writer and one resolution path. The ability is agnostic about the Data
 * Machine Business source ability: callers hand it already-normalized revenue
 * rows plus source provenance.
 *
 * IDENTITY CONTRACT:
 * The default snapshot identity is deterministic. Running the same
 * (source, source_site, blog_id, period) twice MUST resolve to the same
 * `import_batch`, so the store's UNIQUE (blog_id, slug, period_label,
 * import_batch) key upserts the same rows in place instead of adding a parallel
 * snapshot. The identity is NEVER derived from a temp filename.
 *
 * REPLACEMENT vs ADDITIVE:
 * - `replace` (default): REQUIRES non-empty rows (an empty/omitted set can never
 *   wipe an existing snapshot). It upserts incoming rows into the deterministic
 *   snapshot and removes stale rows that disappeared from that exact snapshot.
 *   It never discovers, adopts, or deletes another batch. Existing legacy
 *   batches require an explicit one-time operator migration or purge outside
 *   this runtime ability. The whole mutation runs in a transaction (where
 *   supported) under a per-period advisory lock so concurrent refreshes cannot
 *   union one snapshot.
 * - `additive`: NEVER deletes, and REQUIRES an explicit operator-
 *   supplied `snapshot` identity (a distinct parallel batch) that must NOT
 *   collide with the deterministic replace identity. This is the only way to
 *   land a second snapshot for the same period on purpose; it needs network-
 *   level authorization because it is the path that can intentionally create a
 *   double-counted snapshot.
 *
 * SAFETY:
 * - `rows` is required and non-empty; an empty/omitted set is rejected and never
 *   wipes a snapshot.
 * - Authorization is against the TARGET blog (or network-level); current-site
 *   `manage_options` cannot mutate an arbitrary blog_id. Additive requires
 *   network-level capability.
 * - Transaction begin/commit failures FAIL CLOSED (rollback, success=false);
 *   the engine never continues non-atomically and never reports success after a
 *   failed commit.
 *
 * @package ExtraChill\Analytics
 * @since 0.28.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compute a deterministic snapshot identity from stable inputs.
 *
 * Pure (no WordPress I/O): `extrachill_analytics_revenue_resolve_period()` is
 * pure and `sanitize_key()` is pure. The same inputs always produce the same
 * `import_batch`, which is the whole point — re-running identical inputs lands
 * on the same snapshot and the unique key upserts in place.
 *
 * @param array $args {
 *     Identity inputs.
 *
 *     @type int    $blog_id      Blog the rows belong to. 0 = current (resolved later).
 *     @type string $source       Source provenance slug, e.g. "mediavine". Default "mediavine".
 *     @type string $source_site  Source site / host the rows were pulled for. Falls back to hostname.
 *     @type string $hostname     Hostname fallback for source_site. Default "extrachill".
 *     @type string $period       Period token (YYYY-MM, YYYY, or '' for the flat lifetime file).
 *     @type string $period_start Explicit window start (Y-m-d) override.
 *     @type string $period_end   Explicit window end (Y-m-d) override.
 * }
 * @return array{import_batch:string,period_label:string,period_start:string,period_end:string,source:string,source_site:string,blog_id:int}
 */
function extrachill_analytics_revenue_snapshot_identity( array $args ) {
	$raw_source = isset( $args['source'] ) ? strtolower( trim( (string) $args['source'] ) ) : '';
	if ( '' === $raw_source ) {
		$raw_source = 'mediavine';
	}
	$source = sanitize_key( $raw_source );
	if ( '' === $source ) {
		$source = 'source';
	}

	$raw_source_site = isset( $args['source_site'] ) ? strtolower( trim( (string) $args['source_site'] ) ) : '';
	if ( '' === $raw_source_site ) {
		$raw_source_site = isset( $args['hostname'] ) && '' !== trim( (string) $args['hostname'] )
			? strtolower( trim( (string) $args['hostname'] ) )
			: 'extrachill';
	}
	$source_site = sanitize_key( $raw_source_site );
	if ( '' === $source_site ) {
		$source_site = 'site';
	}

	$blog_id = isset( $args['blog_id'] ) ? max( 0, (int) $args['blog_id'] ) : 0;

	$resolved = extrachill_analytics_revenue_resolve_period(
		isset( $args['period'] ) ? (string) $args['period'] : '',
		isset( $args['period_start'] ) ? (string) $args['period_start'] : '',
		isset( $args['period_end'] ) ? (string) $args['period_end'] : ''
	);

	// Deterministic, filename-free identity. Every part comes from stable
	// operator/source inputs, never a temp path, so identical runs collide on
	// purpose and the store's unique key dedupes instead of duplicating.
	$parts = array( $source, $source_site );
	if ( $blog_id > 0 ) {
		$parts[] = 'b' . $blog_id;
	}
	$parts[] = sanitize_key( $resolved['label'] );

	// The readable prefix is useful operationally, while the hash prevents
	// sanitize/truncation collisions (for example, "a.b" and "ab") and includes
	// explicit date overrides. Keep the final value inside varchar(64).
	$fingerprint  = substr(
		hash( 'sha256', implode( "\0", array( $raw_source, $raw_source_site, (string) $blog_id, $resolved['label'], $resolved['start'], $resolved['end'] ) ) ),
		0,
		12
	);
	$prefix       = substr( trim( implode( '-', $parts ), '-' ), 0, 51 );
	$import_batch = trim( $prefix, '-' ) . '-' . $fingerprint;

	return array(
		'import_batch' => $import_batch,
		'period_label' => $resolved['label'],
		'period_start' => $resolved['start'],
		'period_end'   => $resolved['end'],
		'source'       => $source,
		'source_site'  => $source_site,
		'blog_id'      => $blog_id,
	);
}

/**
 * Build a deterministic ingestion plan from incoming records and the snapshot's
 * existing rows.
 *
 * Pure (no I/O). This is the decision core that the engine unit-tests directly:
 * it classifies each incoming slug as an insert (new to the snapshot) or a
 * replace (already present), and — for replace mode — identifies the stale rows
 * that disappeared from the refreshed source and must be removed. The engine
 * then applies this plan through the store; the plan itself owns the within-
 * batch counts the ability reports, so the load-bearing logic is fully testable
 * without a database.
 *
 * @param array              $records Normalized incoming records, each with at least a `slug`.
 * @param array<int, object> $existing Existing rows of the target snapshot (objects with `id`, `slug`).
 * @param string             $mode     'replace' or 'additive'.
 * @return array{inserts:array,replaces:array,deletes:array} Plan.
 */
function extrachill_analytics_revenue_build_ingestion_plan( array $records, array $existing, $mode ) {
	$existing_by_slug = array();
	foreach ( $existing as $row ) {
		$slug                      = is_object( $row ) && isset( $row->slug ) ? (string) $row->slug : (string) ( isset( $row['slug'] ) ? $row['slug'] : '' );
		$id                        = is_object( $row ) && isset( $row->id ) ? (int) $row->id : (int) ( isset( $row['id'] ) ? $row['id'] : 0 );
		$existing_by_slug[ $slug ] = $id;
	}

	$inserts  = array();
	$replaces = array();
	$seen_new = array();

	foreach ( $records as $rec ) {
		$slug = isset( $rec['slug'] ) ? (string) $rec['slug'] : '';
		if ( isset( $existing_by_slug[ $slug ] ) ) {
			$replaces[] = $rec;
		} else {
			$inserts[] = $rec;
		}
		$seen_new[ $slug ] = true;
	}

	$deletes = array();
	if ( 'replace' === $mode ) {
		foreach ( $existing_by_slug as $slug => $id ) {
			if ( ! isset( $seen_new[ $slug ] ) ) {
				$deletes[] = $id;
			}
		}
	}

	return array(
		'inserts'  => $inserts,
		'replaces' => $replaces,
		'deletes'  => $deletes,
	);
}

/**
 * Validate a Y-m-d date string is real (not just formatted).
 *
 * @param string $date Candidate date.
 * @return bool
 */
function extrachill_analytics_revenue_is_valid_date( $date ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) ) {
		return false;
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/**
 * Validate the period token and explicit date overrides.
 *
 * @param string $period Period token.
 * @param string $start  Explicit window start.
 * @param string $end    Explicit window end.
 * @return string|null Error message, or null when valid.
 */
function extrachill_analytics_revenue_validate_period( $period, $start, $end ) {
	$period = trim( (string) $period );
	$start  = trim( (string) $start );
	$end    = trim( (string) $end );

	if ( '' !== $period && ! preg_match( '/^\d{4}(?:-\d{2})?$/', $period ) ) {
		return 'period must be YYYY-MM, YYYY, or empty.';
	}

	if ( preg_match( '/^(\d{4})-(\d{2})$/', $period, $m ) ) {
		$month = (int) $m[2];
		if ( $month < 1 || $month > 12 ) {
			return 'period month must be 01-12.';
		}
	}

	if ( '' !== $start && ! extrachill_analytics_revenue_is_valid_date( $start ) ) {
		return 'period_start must be a valid Y-m-d date.';
	}
	if ( '' !== $end && ! extrachill_analytics_revenue_is_valid_date( $end ) ) {
		return 'period_end must be a valid Y-m-d date.';
	}
	if ( ( '' === $start ) !== ( '' === $end ) ) {
		return 'period_start and period_end must be provided together.';
	}
	if ( '' !== $start && $start > $end ) {
		return 'period_start must not be after period_end.';
	}

	return null;
}

/**
 * Authorize a revenue ingestion against the TARGET blog.
 *
 * Current-site `manage_options` must not mutate an arbitrary blog_id: the check
 * is scoped to the requested blog (target-blog capability), or requires a
 * network-level capability. Additive mode — the path that can intentionally
 * create a double-counted parallel snapshot — requires network-level authority
 * regardless of blog. WP-CLI is trusted (server-side operator).
 *
 * @param int    $blog_id Target blog.
 * @param string $mode    'replace' or 'additive'.
 * @return bool
 */
function extrachill_analytics_revenue_ingest_authorize( $blog_id, $mode ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		return false;
	}

	// Additive creates a parallel snapshot — elevated, network-level capability.
	if ( 'additive' === $mode ) {
		return current_user_can( 'manage_network_options' );
	}

	$current = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

	// Replace on the current blog: site-level manage_options is sufficient.
	if ( (int) $blog_id === $current ) {
		return current_user_can( 'manage_options' );
	}

	// Replace on a different blog: target-blog capability OR network-level.
	if ( function_exists( 'current_user_can_for_site' ) && current_user_can_for_site( $blog_id, 'manage_options' ) ) {
		return true;
	}
	return current_user_can( 'manage_network_options' );
}

/**
 * Ingest a set of normalized revenue rows into the store with deterministic
 * snapshot identity and explicit replace/additive semantics.
 *
 * Safety contract:
 * - `rows` is required and non-empty; an empty/omitted set is rejected and
 *   NEVER wipes an existing snapshot.
 * - Replace establishes ONE canonical snapshot per (blog, period): it upserts
 *   incoming rows and removes stale rows only within that exact batch. Legacy
 *   batch cleanup is an explicit one-time operator action outside this ability.
 * - The whole mutation runs in a transaction under a per-period advisory lock,
 *   begun BEFORE the snapshot read, so concurrent refreshes cannot union
 *   snapshots. Transaction begin/commit failures FAIL CLOSED.
 *
 * @param array                                   $input_rows Normalized revenue rows. Each row may carry a pre-resolved
 *                                                   `post_id`; otherwise the slug is resolved. Metric keys mirror
 *                                                   the store: views, revenue, rpm, cpm, viewability, fill_rate,
 *                                                   impressions_per_pageview. `slug`/`url` carry the path.
 * @param array                                   $args {
 *     Ingestion options.
 *
 *     @type int                 $blog_id      Target blog. 0 = current blog.
 *     @type string              $hostname     Hostname for slug->post resolution. Default extrachill.com.
 *     @type string              $source       Source provenance slug. Default mediavine.
 *     @type string              $source_site  Source site identifier. Default derived from hostname.
 *     @type string              $period       Period token (YYYY-MM / YYYY / '').
 *     @type string              $period_start Explicit window start override.
 *     @type string              $period_end   Explicit window end override.
 *     @type string              $mode         'replace' (default) or 'additive'.
 *     @type string              $snapshot     Explicit snapshot identity for additive mode (required when mode=additive).
 *     @type bool                $dry_run      Parse + plan but write nothing.
 * }
 * @param Extrachill_Analytics_Revenue_Store|null $store Storage handle. Null builds the production store.
 * @return array Ingestion result (see output_schema) or { success:false, error }.
 */
function extrachill_analytics_revenue_ingest_rows( array $input_rows, array $args, $store = null ) {
	$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'replace';
	if ( ! in_array( $mode, array( 'replace', 'additive' ), true ) ) {
		return array(
			'success' => false,
			'error'   => 'mode must be "replace" or "additive".',
		);
	}

	// SAFETY: rows is required and non-empty. An empty/omitted set can never
	// wipe an existing snapshot — clearing a snapshot needs a separate,
	// separately-authorized delete operation (out of scope here).
	if ( empty( $input_rows ) ) {
		return array(
			'success' => false,
			'error'   => 'rows is required and must be non-empty.',
		);
	}

	$blog_id           = isset( $args['blog_id'] ) && (int) $args['blog_id'] > 0 ? (int) $args['blog_id'] : get_current_blog_id();
	$hostname          = ! empty( $args['hostname'] ) ? (string) $args['hostname'] : 'extrachill.com';
	$dry_run           = ! empty( $args['dry_run'] );
	$period            = isset( $args['period'] ) ? (string) $args['period'] : '';
	$period_start_arg  = isset( $args['period_start'] ) ? (string) $args['period_start'] : '';
	$period_end_arg    = isset( $args['period_end'] ) ? (string) $args['period_end'] : '';
	$snapshot_override = isset( $args['snapshot'] ) ? trim( (string) $args['snapshot'] ) : '';

	// Validate period token + explicit date overrides.
	$date_error = extrachill_analytics_revenue_validate_period( $period, $period_start_arg, $period_end_arg );
	if ( null !== $date_error ) {
		return array(
			'success' => false,
			'error'   => $date_error,
		);
	}

	$identity            = extrachill_analytics_revenue_snapshot_identity(
		array(
			'blog_id'      => $blog_id,
			'source'       => isset( $args['source'] ) ? (string) $args['source'] : 'mediavine',
			'source_site'  => isset( $args['source_site'] ) ? (string) $args['source_site'] : $hostname,
			'hostname'     => $hostname,
			'period'       => $period,
			'period_start' => $period_start_arg,
			'period_end'   => $period_end_arg,
		)
	);
	$identity['blog_id'] = $blog_id;

	$batch = $identity['import_batch'];

	// Additive: explicit snapshot identity required, must not collide with the
	// deterministic replace identity (else it would silently overwrite the
	// canonical snapshot), and must fit the column.
	if ( 'additive' === $mode ) {
		if ( '' === $snapshot_override ) {
			return array(
				'success' => false,
				'error'   => 'additive mode requires an explicit snapshot identity (a distinct batch label). Use replace for the default deterministic snapshot.',
			);
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $snapshot_override ) ) {
			return array(
				'success' => false,
				'error'   => 'additive snapshot must be 1-64 lowercase letters, numbers, hyphens, or underscores.',
			);
		}
		if ( hash_equals( $batch, $snapshot_override ) ) {
			return array(
				'success' => false,
				'error'   => 'additive snapshot must differ from the deterministic replace identity for this period.',
			);
		}
		$batch                    = $snapshot_override;
		$identity['import_batch'] = $batch;
	}

	// Validate the final batch label fits the store's varchar(64) column.
	if ( '' === $batch || strlen( $batch ) > 64 ) {
		return array(
			'success' => false,
			'error'   => 'snapshot identity must be non-empty and no longer than 64 characters.',
		);
	}

	$period_label = $identity['period_label'];
	$period_start = $identity['period_start'];
	$period_end   = $identity['period_end'];

	// Resolve + normalize each incoming row against the target blog, deduping
	// by normalized slug (last occurrence wins). Resolution (url_to_postid) is
	// current-blog-scoped, so switch for the duration to keep the stamped
	// blog_id and resolved post_id in agreement on a multisite.
	$switched = false;
	if ( $blog_id > 0 && function_exists( 'switch_to_blog' ) && function_exists( 'get_current_blog_id' ) && get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$by_slug    = array();
	$duplicates = 0;
	foreach ( $input_rows as $input ) {
		$raw_slug = isset( $input['slug'] ) ? (string) $input['slug'] : ( isset( $input['url'] ) ? (string) $input['url'] : '' );
		if ( '' === trim( $raw_slug ) ) {
			continue;
		}
		$is_home = '/' === extrachill_analytics_revenue_frontend_path( $raw_slug );

		if ( $is_home ) {
			$post_id = 0;
			$slug    = '';
		} elseif ( isset( $input['post_id'] ) && (int) $input['post_id'] > 0 ) {
			$post_id = (int) $input['post_id'];
			if ( function_exists( 'get_post' ) ) {
				$post = get_post( $post_id );
				$slug = ( is_object( $post ) && isset( $post->post_name ) ) ? $post->post_name : trim( (string) strtok( $raw_slug, '?#' ), '/' );
			} else {
				$slug = trim( (string) strtok( $raw_slug, '?#' ), '/' );
			}
		} else {
			$resolved_row = extrachill_analytics_revenue_resolve_slug( $raw_slug, $hostname );
			$post_id      = $resolved_row['post_id'];
			$slug         = $resolved_row['slug'];
		}

		if ( '' === $slug && ! $is_home ) {
			continue;
		}
		$canonical_url = $post_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';

		if ( isset( $by_slug[ $slug ] ) ) {
			++$duplicates;
		}
		$by_slug[ $slug ] = array(
			'blog_id'                  => $blog_id,
			'slug'                     => $slug,
			'url'                      => $raw_slug,
			'post_id'                  => $post_id,
			'content_blog_id'          => $post_id > 0 ? $blog_id : null,
			'canonical_url'            => $canonical_url,
			'views'                    => isset( $input['views'] ) ? (int) $input['views'] : 0,
			'revenue'                  => isset( $input['revenue'] ) ? (float) $input['revenue'] : 0.0,
			'rpm'                      => isset( $input['rpm'] ) ? (float) $input['rpm'] : 0.0,
			'cpm'                      => isset( $input['cpm'] ) ? (float) $input['cpm'] : 0.0,
			'viewability'              => isset( $input['viewability'] ) ? (float) $input['viewability'] : 0.0,
			'fill_rate'                => isset( $input['fill_rate'] ) ? (float) $input['fill_rate'] : 0.0,
			'impressions_per_pageview' => isset( $input['impressions_per_pageview'] ) ? (float) $input['impressions_per_pageview'] : 0.0,
			'period_label'             => $period_label,
			'period_start'             => $period_start,
			'period_end'               => $period_end,
			'import_batch'             => $batch,
		);
	}

	if ( $switched && function_exists( 'restore_current_blog' ) ) {
		restore_current_blog();
	}

	// Local resolution is authoritative for the snapshot site. Resolve only the
	// remaining host-relative paths through Network, once per unique path. This
	// happens before the store is opened, so an incomplete scan cannot delete or
	// replace any part of the existing snapshot.
	$network_paths = array();
	foreach ( $by_slug as $record ) {
		if ( empty( $record['post_id'] ) && '' !== $record['slug'] ) {
			$path = extrachill_analytics_revenue_frontend_path( $record['url'] );
			if ( null !== $path ) {
				$network_paths[ $path ] = true;
			}
		}
	}
	$network = extrachill_analytics_revenue_resolve_network_paths( array_keys( $network_paths ) );
	if ( ! $network['success'] ) {
		return array(
			'success' => false,
			'written' => false,
			'error'   => $network['error'],
		);
	}
	foreach ( $by_slug as &$record ) {
		if ( ! empty( $record['post_id'] ) || '' === $record['slug'] ) {
			continue;
		}
		$path   = extrachill_analytics_revenue_frontend_path( $record['url'] );
		$result = null !== $path && isset( $network['results'][ $path ] ) ? $network['results'][ $path ] : null;
		if ( is_array( $result ) && 'resolved' === ( $result['status'] ?? '' ) && isset( $result['candidate'] ) && is_array( $result['candidate'] ) ) {
			$record['post_id']         = (int) $result['candidate']['post_id'];
			$record['content_blog_id'] = (int) $result['candidate']['blog_id'];
			$record['canonical_url']   = (string) $result['candidate']['canonical_url'];
		}
	}
	unset( $record );

	$records = array_values( $by_slug );

	// Count resolved/unresolved over the DEDUPED set so duplicates don't inflate
	// the counts.
	$resolved   = 0;
	$unresolved = 0;
	foreach ( $records as $rec ) {
		if ( $rec['post_id'] > 0 ) {
			++$resolved;
		} else {
			++$unresolved;
		}
	}

	$rows_count = count( $records );

	$base = array(
		'mode'                   => $mode,
		'dry_run'                => $dry_run,
		'rows'                   => $rows_count,
		'input_rows'             => count( $input_rows ),
		'duplicate_rows_deduped' => $duplicates,
		'resolved'               => $resolved,
		'unresolved'             => $unresolved,
		'identity'               => array(
			'import_batch' => $batch,
			'period_label' => $period_label,
			'period_start' => $period_start,
			'period_end'   => $period_end,
			'source'       => $identity['source'],
			'source_site'  => $identity['source_site'],
			'blog_id'      => $blog_id,
		),
	);

	// A normalized input that reduced to zero usable slugs cannot replace
	// anything — refuse rather than risk an empty write.
	if ( 0 === $rows_count ) {
		return array_merge(
			array(
				'success' => false,
				'written' => false,
				'error'   => 'rows contained no usable slug after normalization.',
			),
			$base
		);
	}

	// Acquire the production store when the caller (production callback) did not
	// inject one. Tests inject an in-memory fake to exercise the full path.
	if ( null === $store ) {
		if ( ! class_exists( 'Extrachill_Analytics_Revenue_Store' ) ) {
			return array_merge(
				array(
					'success' => false,
					'written' => false,
					'error'   => 'Revenue store is not available.',
				),
				$base
			);
		}
		$store = new Extrachill_Analytics_Revenue_Store();
	}

	// Dry run: read exactly this snapshot, plan, and report — mutate nothing.
	// No lock is needed because nothing is written.
	if ( $dry_run ) {
		$existing = $store->get_snapshot( $blog_id, $period_label, $batch );
		$plan     = extrachill_analytics_revenue_build_ingestion_plan( $records, $existing, $mode );

		return array_merge(
			array(
				'success'      => true,
				'written'      => false,
				'inserted'     => count( $plan['inserts'] ),
				'replaced'     => count( $plan['replaces'] ),
				'deleted'      => 0,
				'would_delete' => count( $plan['deletes'] ),
			),
			$base
		);
	}

	// WRITE PATH — critical section. Begin the transaction BEFORE the snapshot
	// read and hold a per-period advisory lock so two concurrent refreshes
	// cannot both read the prior snapshot and union their writes. Begin/commit
	// failures fail CLOSED (rollback, success=false): the engine never continues
	// non-atomically and never reports success after a failed commit.
	if ( false === $store->begin() ) {
		return array_merge(
			array(
				'success' => false,
				'written' => false,
				'error'   => 'Could not begin a transaction; ingestion aborted (fail closed).',
			),
			$base
		);
	}

	$lock_name = 'ecrev_ing_' . md5( $blog_id . ':' . $period_label );
	if ( false === $store->lock( $lock_name, 10 ) ) {
		$rollback_ok = $store->rollback();
		return array_merge(
			array(
				'success' => false,
				'written' => false,
				'error'   => 'Could not acquire the ingestion lock for this period; another refresh may be in progress.' . ( false === $rollback_ok ? ' Rollback also failed.' : '' ),
			),
			$base
		);
	}

	$op_error       = null;
	$deletes_count  = 0;
	$inserts_count  = 0;
	$replaces_count = 0;

	try {
		$existing = $store->get_snapshot( $blog_id, $period_label, $batch );
		$plan     = extrachill_analytics_revenue_build_ingestion_plan( $records, $existing, $mode );

		$inserts_count  = count( $plan['inserts'] );
		$replaces_count = count( $plan['replaces'] );
		$deletes_count  = count( $plan['deletes'] );

		foreach ( $plan['inserts'] as $rec ) {
			if ( false === $store->upsert( $rec ) ) {
				$op_error = 'Failed to insert a revenue row.';
				break;
			}
		}
		if ( null === $op_error ) {
			foreach ( $plan['replaces'] as $rec ) {
				if ( false === $store->upsert( $rec ) ) {
					$op_error = 'Failed to replace a revenue row.';
					break;
				}
			}
		}
		if ( null === $op_error && ! empty( $plan['deletes'] ) ) {
			if ( false === $store->delete_ids( $plan['deletes'] ) ) {
				$op_error = 'Failed to remove stale revenue rows.';
			}
		}
		if ( null === $op_error && false === $store->commit() ) {
			$op_error = 'Failed to commit the revenue snapshot.';
		}
	} catch ( Throwable $throwable ) {
		$op_error = 'Revenue ingestion failed: ' . $throwable->getMessage();
	} finally {
		if ( null !== $op_error ) {
			$rollback_ok = $store->rollback();
			if ( false === $rollback_ok ) {
				$op_error .= ' Rollback also failed.';
			}
		}
		// Keep rollback inside the lock; then always release the advisory lock.
		$store->unlock( $lock_name );
	}

	if ( null !== $op_error ) {
		return array_merge(
			array(
				'success'  => false,
				'written'  => false,
				'error'    => $op_error,
				'inserted' => $inserts_count,
				'replaced' => $replaces_count,
			),
			$base
		);
	}

	return array_merge(
		array(
			'success'  => true,
			'written'  => true,
			'inserted' => $inserts_count,
			'replaced' => $replaces_count,
			'deleted'  => $deletes_count,
		),
		$base
	);
}

/**
 * Register the ingest-revenue ability.
 */
function extrachill_analytics_register_ingest_revenue_ability() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/ingest-revenue',
		array(
			'label'               => __( 'Ingest Revenue Snapshot', 'extrachill-analytics' ),
			'description'         => __( 'Idempotently ingest a set of per-URL ad-revenue rows (e.g. a Mediavine period export) into the revenue store with a deterministic snapshot identity. Default mode is deterministic REPLACE: the same source/site/blog/period always lands on the SAME snapshot, updating rows in place and removing only rows that disappeared from that exact snapshot. It never discovers, adopts, or deletes another batch; existing legacy batches require an explicit one-time operator migration or purge outside this runtime ability. REQUIRES non-empty rows — an empty/omitted set never wipes a snapshot. ADDITIVE mode never deletes and requires an explicit, non-colliding snapshot identity plus network-level authorization. Replace authorizes against the TARGET blog (or network). The whole mutation runs in a transaction under a per-period advisory lock; begin/commit failures fail closed. Accepts normalized revenue rows plus source provenance; agnostic about the upstream source ability. Supports dry-run. Owned by analytics: validation, period/snapshot identity, replacement, resolution, and persistence all live here.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'rows'         => array(
						'type'        => 'array',
						'description' => __( 'REQUIRED, non-empty. Normalized per-URL revenue rows. Each row carries a slug/url plus metrics (views, revenue, rpm, cpm, viewability, fill_rate, impressions_per_pageview) and an optional pre-resolved post_id. Duplicate slugs are deduped (last wins).', 'extrachill-analytics' ),
						'minItems'    => 1,
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'slug'                     => array( 'type' => 'string' ),
								'url'                      => array( 'type' => 'string' ),
								'post_id'                  => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'views'                    => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'revenue'                  => array( 'type' => 'number' ),
								'rpm'                      => array( 'type' => 'number' ),
								'cpm'                      => array( 'type' => 'number' ),
								'viewability'              => array( 'type' => 'number' ),
								'fill_rate'                => array( 'type' => 'number' ),
								'impressions_per_pageview' => array( 'type' => 'number' ),
							),
							'anyOf'      => array(
								array( 'required' => array( 'slug' ) ),
								array( 'required' => array( 'url' ) ),
							),
						),
					),
					'blog_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Blog the rows belong to. 0 = current blog. Replace authorizes against this target blog (target-blog capability) or requires network-level capability.', 'extrachill-analytics' ),
						'default'     => 0,
						'minimum'     => 0,
					),
					'hostname'     => array(
						'type'        => 'string',
						'description' => __( 'Hostname for slug->post resolution (default extrachill.com).', 'extrachill-analytics' ),
						'default'     => 'extrachill.com',
					),
					'source'       => array(
						'type'        => 'string',
						'description' => __( 'Source provenance slug, e.g. "mediavine". Part of the deterministic snapshot identity. Default mediavine.', 'extrachill-analytics' ),
						'default'     => 'mediavine',
					),
					'source_site'  => array(
						'type'        => 'string',
						'description' => __( 'Source site / host the rows were pulled for. Part of the snapshot identity so the same period for different sites never collides. Defaults to the hostname.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period'       => array(
						'type'        => 'string',
						'description' => __( 'Period token: YYYY-MM (monthly), YYYY (yearly), or "" for the flat lifetime file. Part of the snapshot identity and the canonical period_label the ARC groups by.', 'extrachill-analytics' ),
						'default'     => '',
						'pattern'     => '^(?:\\d{4}(?:-(?:0[1-9]|1[0-2]))?)?$',
					),
					'period_start' => array(
						'type'        => 'string',
						'description' => __( 'Explicit window start (Y-m-d) override. Must be a valid date when non-empty. Defaults to the start derived from period.', 'extrachill-analytics' ),
						'default'     => '',
						'pattern'     => '^(?:\\d{4}-\\d{2}-\\d{2})?$',
					),
					'period_end'   => array(
						'type'        => 'string',
						'description' => __( 'Explicit window end (Y-m-d) override. Must be a valid date when non-empty. Defaults to the end derived from period.', 'extrachill-analytics' ),
						'default'     => '',
						'pattern'     => '^(?:\\d{4}-\\d{2}-\\d{2})?$',
					),
					'mode'         => array(
						'type'        => 'string',
						'description' => __( '"replace" (default) updates only the deterministic snapshot in place and removes stale rows only from that snapshot; "additive" never deletes and requires an explicit snapshot identity plus network-level authorization. Existing legacy batches require an explicit operator migration or purge outside this ability.', 'extrachill-analytics' ),
						'default'     => 'replace',
						'enum'        => array( 'replace', 'additive' ),
					),
					'snapshot'     => array(
						'type'        => 'string',
						'description' => __( 'Explicit snapshot identity (batch label). REQUIRED for additive mode (must differ from the deterministic replace identity). Ignored for replace, which always uses the deterministic identity.', 'extrachill-analytics' ),
						'default'     => '',
						'maxLength'   => 64,
						'pattern'     => '^(?:[a-z0-9][a-z0-9_-]{0,63})?$',
					),
					'dry_run'      => array(
						'type'        => 'boolean',
						'description' => __( 'If true, resolve + plan but write nothing. Reported counts reflect what would happen within the exact target snapshot.', 'extrachill-analytics' ),
						'default'     => false,
					),
				),
				'required'   => array( 'rows' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Result with success, written, mode, dry_run, counts (rows, input_rows, duplicate_rows_deduped, resolved, unresolved, inserted, replaced, deleted, and would_delete on dry run), and the stable identity (import_batch, period_label, period_start/end, source, source_site, blog_id). On failure: success=false, written=false, error.', 'extrachill-analytics' ),
				'properties'  => array(
					'success'                => array( 'type' => 'boolean' ),
					'written'                => array( 'type' => 'boolean' ),
					'mode'                   => array(
						'type' => 'string',
						'enum' => array( 'replace', 'additive' ),
					),
					'dry_run'                => array( 'type' => 'boolean' ),
					'rows'                   => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'input_rows'             => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'duplicate_rows_deduped' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'resolved'               => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'unresolved'             => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'inserted'               => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'replaced'               => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'deleted'                => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'would_delete'           => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'error'                  => array( 'type' => 'string' ),
					'identity'               => array(
						'type'       => 'object',
						'properties' => array(
							'import_batch' => array( 'type' => 'string' ),
							'period_label' => array( 'type' => 'string' ),
							'period_start' => array( 'type' => 'string' ),
							'period_end'   => array( 'type' => 'string' ),
							'source'       => array( 'type' => 'string' ),
							'source_site'  => array( 'type' => 'string' ),
							'blog_id'      => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'required'   => array( 'import_batch', 'period_label', 'period_start', 'period_end', 'source', 'source_site', 'blog_id' ),
					),
				),
				'required'    => array( 'success' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_ingest_revenue',
			'permission_callback' => function () {
				// First-pass REST gate (current site). Target-blog / network
				// authorization is enforced inside the execute callback, which
				// sees the requested blog_id and mode.
				return current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);
}

/**
 * Execute callback for the ingest-revenue ability.
 *
 * Performs target-blog / network authorization against the requested blog and
 * mode, then delegates to the engine.
 *
 * @param array $input Input parameters.
 * @return array|\WP_Error Ingestion result, or WP_Error when unauthorized.
 */
function extrachill_analytics_ability_ingest_revenue( $input ) {
	$rows    = isset( $input['rows'] ) && is_array( $input['rows'] ) ? $input['rows'] : array();
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$mode    = isset( $input['mode'] ) ? (string) $input['mode'] : 'replace';
	$target  = $blog_id > 0 ? $blog_id : ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );

	if ( ! extrachill_analytics_revenue_ingest_authorize( $target, $mode ) ) {
		return new WP_Error(
			'extrachill_ingest_revenue_unauthorized',
			'additive' === $mode
				? __( 'You are not authorized to create an additive revenue snapshot (requires network-level capability).', 'extrachill-analytics' )
				/* translators: %d: blog ID */
				: sprintf( __( 'You are not authorized to ingest revenue for blog %d.', 'extrachill-analytics' ), $target ),
			array( 'status' => 403 )
		);
	}

	return extrachill_analytics_revenue_ingest_rows(
		$rows,
		array(
			'blog_id'      => $blog_id,
			'hostname'     => isset( $input['hostname'] ) ? (string) $input['hostname'] : 'extrachill.com',
			'source'       => isset( $input['source'] ) ? (string) $input['source'] : 'mediavine',
			'source_site'  => isset( $input['source_site'] ) ? (string) $input['source_site'] : '',
			'period'       => isset( $input['period'] ) ? (string) $input['period'] : '',
			'period_start' => isset( $input['period_start'] ) ? (string) $input['period_start'] : '',
			'period_end'   => isset( $input['period_end'] ) ? (string) $input['period_end'] : '',
			'mode'         => $mode,
			'snapshot'     => isset( $input['snapshot'] ) ? (string) $input['snapshot'] : '',
			'dry_run'      => ! empty( $input['dry_run'] ),
		)
	);
}
