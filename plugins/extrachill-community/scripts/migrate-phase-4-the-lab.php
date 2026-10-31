<?php
/**
 * Phase 4 — The Lab fill + cleanup migration (issue #69).
 *
 * One-time, idempotent, dry-runnable data migration. NOT loaded by the plugin —
 * run once against the community site (blog 2). `eval-file` passes positional
 * args through `$args`, so pass the literal word `dry-run` (no leading dashes)
 * for a no-write preview:
 *
 *   # Preview (no writes):
 *   wp --allow-root --path=/var/www/extrachill.com \
 *      --url=community.extrachill.com \
 *      eval-file scripts/migrate-phase-4-the-lab.php dry-run
 *
 *   # Apply (ONLY after the plan is reviewed on the PR):
 *   wp --allow-root --path=/var/www/extrachill.com \
 *      --url=community.extrachill.com \
 *      eval-file scripts/migrate-phase-4-the-lab.php
 *
 * What it does:
 *   1. Refreshes The Lab (13548) forum description so its differentiator
 *      identity is visible: open web · WordPress · AI × music · build-in-public
 *      · dev log · roadmap / feature requests.
 *   2. MIGRATE gathered Lab-material INTO The Lab (13548):
 *        - 554   "Community Website Ideas"   (from Extra Chill Team 547)
 *        - 11342 "Need a Website? Affordable WordPress Dev for Bands…" (from
 *                 The Back Bar 81)
 *      (5298 "Tool" stays in Music Discussion — it's about the band Tool /
 *       Danny Carey, not a software tool. Verified by body.)
 *   3. Re-home support strays OUT of The Lab into The Back Bar (81):
 *        - 14114 "Can't edit link page"
 *        - 13554 "How to Use The Extra Chill Community"
 *        - 13580 "Question about inserting a Spotify preview…"
 *        - 13639 "Comedy Board?"
 *      (13563/13564/13582/13642/13663 stay — genuine Lab-material.)
 *   4. Retire the dead "Independent Artists" predecessor (5432):
 *        - Repoint the 4 stray reply `_bbp_forum_id` refs (1424, 1425, 1465,
 *          8535) to whatever forum each reply's parent topic actually lives in
 *          (all three parent topics — 1420, 1393, 2225 — are live in Artist
 *          Corner 14120, so the refs become 14120).
 *        - Trash forum 5432 (0 topics; no nav/template/code links).
 *   5. Recalculate bbPress counts on every affected forum.
 *
 * bbPress reassignment contract honored (same as #58/#63):
 *   - Topic: post_parent -> dest forum; _bbp_forum_id meta via
 *     bbp_update_topic_forum_id().
 *   - Replies: _bbp_forum_id meta via bbp_update_reply_forum_id()
 *     (reply post_parent stays = topic_id, which is correct for bbPress).
 *   - Counts recalculated directly (bbp_update_forum() only recounts inside
 *     bbp_deleted_topic/save_post filters, so we call the count helpers).
 *
 * Idempotent: re-running is safe. Topics already in the destination forum are
 * skipped; refs already repointed are left as-is; an already-trashed 5432 is
 * left as-is.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$script_args = (array) ( $args ?? array() );
$dry_run     = in_array( 'dry-run', $script_args, true )
	|| in_array( '--dry-run', $script_args, true );

/**
 * Echo helper.
 *
 * @param string $msg Message.
 */
$log = static function ( $msg ) {
	WP_CLI::log( $msg );
};

if ( $dry_run ) {
	$log( '=== DRY RUN — no writes will be performed ===' );
}

/** ---------------------------------------------------------------------------
 * Configuration.
 * ------------------------------------------------------------------------- */

$the_lab_forum_id    = 13548; // The Lab.
$back_bar_forum_id   = 81;   // The Back Bar (off-topic / catch-all).
$artist_corner_id    = 14120; // Artist Corner.
$independent_artists = 5432; // Dead predecessor to Artist Corner.

// The Lab's refreshed description — the differentiator identity, made visible.
$the_lab_description = 'The differentiator room: the open web, WordPress, AI × music, and build-in-public. '
	. 'Dev logs, roadmap, feature requests, and practical site-building help for artists — how independent '
	. 'music should live online, owning your corner of the web instead of renting it. Music always leads; '
	. 'this is the evidence we walk the walk.';

// Lab-material to GATHER into The Lab (topic_id => human-readable label).
$gather_into_lab = array(
	554   => 'Community Website Ideas (from Extra Chill Team 547)',
	11342 => 'Need a Website? Affordable WordPress Dev for Bands (from The Back Bar 81)',
);

// Support / off-topic strays to re-home OUT of The Lab into The Back Bar.
$rehome_to_back_bar = array(
	14114 => "Can't edit link page (support)",
	13554 => 'How to Use The Extra Chill Community (help)',
	13580 => 'Question about inserting a Spotify preview (support)',
	13639 => 'Comedy Board? (off-topic)',
);

// Stray reply refs pointing at the dead predecessor forum 5432. Each is
// repointed to its parent topic's actual forum.
$predecessor_reply_refs = array( 1424, 1425, 1465, 8535 );

/** ---------------------------------------------------------------------------
 * Helper: recalc all bbPress counts for a forum.
 * ------------------------------------------------------------------------- */
$recalc_forum = static function ( $forum_id ) use ( $log, $dry_run ) {
	if ( ! $forum_id ) {
		return;
	}
	if ( $dry_run ) {
		$log( "  [dry-run] would recalc counts for forum {$forum_id}" );
		return;
	}
	bbp_update_forum_topic_count( $forum_id );
	bbp_update_forum_topic_count_hidden( $forum_id );
	bbp_update_forum_reply_count( $forum_id );
	bbp_update_forum_reply_count_hidden( $forum_id );
	bbp_update_forum_last_topic_id( $forum_id );
	bbp_update_forum_last_reply_id( $forum_id );
	bbp_update_forum_last_active_id( $forum_id );
	bbp_update_forum_last_active_time( $forum_id );
	$log( "  recalculated counts for forum {$forum_id} (topics=" . bbp_get_forum_topic_count( $forum_id, true, true ) . ')' );
};

/** ---------------------------------------------------------------------------
 * Helper: reassign a single topic (and its replies) to a destination forum.
 * Returns true if a move actually happened.
 * ------------------------------------------------------------------------- */
$move_topic = static function ( $topic_id, $dest_forum_id ) use ( $log, $dry_run ) {
	$topic_post = get_post( $topic_id );
	if ( ! $topic_post || bbp_get_topic_post_type() !== $topic_post->post_type ) {
		WP_CLI::warning( "  topic {$topic_id} not found or not a topic — skipping." );
		return false;
	}

	$current_parent = (int) $topic_post->post_parent;
	if ( $current_parent === (int) $dest_forum_id ) {
		$log( "  topic {$topic_id} already in forum {$dest_forum_id} — skip" );
		return false; // Idempotent skip.
	}

	$title = get_the_title( $topic_id );

	if ( $dry_run ) {
		$log( "  [dry-run] would move topic {$topic_id} ({$title}) from {$current_parent} -> {$dest_forum_id}" );
		return true;
	}

	// Move the topic post_parent.
	wp_update_post(
		array(
			'ID'          => $topic_id,
			'post_parent' => $dest_forum_id,
		)
	);

	// Update topic _bbp_forum_id meta (canonical bbPress helper).
	bbp_update_topic_forum_id( $topic_id, $dest_forum_id );

	// Update every reply's _bbp_forum_id meta. Replies keep post_parent =
	// topic_id (correct for bbPress); only the forum-id meta changes.
	$reply_ids = get_posts(
		array(
			'post_type'      => bbp_get_reply_post_type(),
			'post_status'    => array( 'publish', 'closed', 'private', 'hidden', 'pending', 'spam', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_bbp_topic_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $topic_id,       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	foreach ( $reply_ids as $reply_id ) {
		bbp_update_reply_forum_id( $reply_id, $dest_forum_id );
	}

	$log( "  moved topic {$topic_id} ({$title}) -> forum {$dest_forum_id} (" . count( $reply_ids ) . ' replies)' );
	return true;
};

/** ===========================================================================
 * STEP 1 — Refresh The Lab forum description.
 * ======================================================================== */
$log( "\n== Step 1: refresh The Lab description ==" );
$lab = get_post( $the_lab_forum_id );
if ( $lab && 'forum' === $lab->post_type ) {
	if ( trim( $lab->post_content ) === trim( $the_lab_description ) ) {
		$log( '  The Lab description already current — skip' );
	} elseif ( $dry_run ) {
		$log( "  [dry-run] would update forum {$the_lab_forum_id} description" );
	} else {
		wp_update_post(
			array(
				'ID'           => $the_lab_forum_id,
				'post_content' => $the_lab_description,
			)
		);
		$log( "  updated The Lab ({$the_lab_forum_id}) description" );
	}
} else {
	WP_CLI::warning( "The Lab forum {$the_lab_forum_id} not found — skipping description refresh." );
}

/** ===========================================================================
 * STEP 2 — Gather Lab-material INTO The Lab.
 * ======================================================================== */
$log( "\n== Step 2: gather Lab-material into The Lab ==" );
foreach ( $gather_into_lab as $topic_id => $label ) {
	$log( "  {$topic_id}: {$label}" );
	$move_topic( $topic_id, $the_lab_forum_id );
}

/** ===========================================================================
 * STEP 3 — Re-home support strays out of The Lab into The Back Bar.
 * ======================================================================== */
$log( "\n== Step 3: re-home support strays into The Back Bar ==" );
foreach ( $rehome_to_back_bar as $topic_id => $label ) {
	$log( "  {$topic_id}: {$label}" );
	$move_topic( $topic_id, $back_bar_forum_id );
}

/** ===========================================================================
 * STEP 4 — Retire the Independent Artists predecessor (5432).
 *
 * Repoint each stray reply's _bbp_forum_id to its parent topic's actual forum,
 * then trash 5432 (only if it has no topics/subforums).
 * ======================================================================== */
$log( "\n== Step 4: retire Independent Artists predecessor (5432) ==" );

foreach ( $predecessor_reply_refs as $reply_id ) {
	$reply = get_post( $reply_id );
	if ( ! $reply || bbp_get_reply_post_type() !== $reply->post_type ) {
		WP_CLI::warning( "  ref {$reply_id} not found or not a reply — skipping." );
		continue;
	}

	$current_ref = (int) get_post_meta( $reply_id, '_bbp_forum_id', true );
	if ( $current_ref !== $independent_artists ) {
		$log( "  reply {$reply_id} _bbp_forum_id already {$current_ref} (not 5432) — skip" );
		continue;
	}

	// Resolve the reply's parent topic and that topic's real forum.
	$topic_id = (int) get_post_meta( $reply_id, '_bbp_topic_id', true );
	if ( ! $topic_id ) {
		$topic_id = (int) $reply->post_parent; // bbPress reply post_parent = topic_id.
	}
	$topic = $topic_id ? get_post( $topic_id ) : null;

	if ( $topic && bbp_get_topic_post_type() === $topic->post_type ) {
		$dest_forum = (int) $topic->post_parent; // The topic's live forum.
	} else {
		// Parent topic missing — fall back to Artist Corner (the successor).
		$dest_forum = $artist_corner_id;
		WP_CLI::warning( "  reply {$reply_id} parent topic {$topic_id} missing — repointing to Artist Corner {$artist_corner_id}." );
	}

	if ( $dry_run ) {
		$log( "  [dry-run] would repoint reply {$reply_id} _bbp_forum_id 5432 -> {$dest_forum} (topic {$topic_id})" );
		continue;
	}
	bbp_update_reply_forum_id( $reply_id, $dest_forum );
	$log( "  repointed reply {$reply_id} _bbp_forum_id -> {$dest_forum} (topic {$topic_id})" );
}

// Trash 5432 if empty.
$ia = get_post( $independent_artists );
if ( ! $ia || 'forum' !== $ia->post_type ) {
	$log( "  forum {$independent_artists} not found — nothing to trash" );
} elseif ( 'trash' === $ia->post_status ) {
	$log( "  forum {$independent_artists} already trashed — skip" );
} else {
	$remaining_topics = get_posts(
		array(
			'post_type'      => bbp_get_topic_post_type(),
			'post_parent'    => $independent_artists,
			'post_status'    => array( 'publish', 'closed', 'private', 'hidden', 'pending' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	$subforums        = get_posts(
		array(
			'post_type'      => 'forum',
			'post_parent'    => $independent_artists,
			'post_status'    => array( 'publish', 'private', 'hidden' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $remaining_topics ) ) {
		WP_CLI::warning( "  forum {$independent_artists} still has topics — NOT trashing." );
	} elseif ( ! empty( $subforums ) ) {
		WP_CLI::warning( "  forum {$independent_artists} still has subforums — NOT trashing." );
	} elseif ( $dry_run ) {
		$log( "  [dry-run] would trash empty predecessor forum {$independent_artists}" );
	} else {
		wp_trash_post( $independent_artists );
		$log( "  trashed empty predecessor forum {$independent_artists}" );
	}
}

/** ===========================================================================
 * STEP 5 — Recalculate counts on every affected forum.
 * ======================================================================== */
$log( "\n== Step 5: recalculate bbPress counts ==" );
$affected = array_unique(
	array(
		$the_lab_forum_id,
		$back_bar_forum_id,
		$artist_corner_id,
		547,  // Extra Chill Team (source of 554).
		1983, // Music Discussion (unchanged but harmless to recalc).
		$independent_artists,
	)
);
foreach ( $affected as $forum_id ) {
	if ( $forum_id > 0 ) {
		$recalc_forum( $forum_id );
	}
}

WP_CLI::success( $dry_run ? 'Dry run complete.' : 'Phase 4 The Lab migration complete.' );
