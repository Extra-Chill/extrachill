<?php
/**
 * Phase 4 follow-up cleanup migration (issue #69 follow-up).
 *
 * One-time, idempotent, dry-runnable. NOT loaded by the plugin. Run once
 * against the community site (blog 2). `eval-file` passes positional args via
 * `$args`; pass the literal word `dry-run` (no dashes) for a no-write preview:
 *
 *   # Preview (no writes):
 *   wp --allow-root --path=/var/www/extrachill.com \
 *      --url=community.extrachill.com \
 *      eval-file scripts/migrate-phase-4-followup-cleanup.php dry-run
 *
 *   # Apply (ONLY after the plan is reviewed on the PR):
 *   wp --allow-root --path=/var/www/extrachill.com \
 *      --url=community.extrachill.com \
 *      eval-file scripts/migrate-phase-4-followup-cleanup.php
 *
 * This is the follow-up to the merged Phase 4 migration (#70). The first pass
 * missed three things found in a deeper platform-wide sweep:
 *
 *   1. GATHER 14090 "Hello from Sarai Chinwag" INTO The Lab (13548). It's the
 *      first post via Data Machine bearer token / agentic auth going live —
 *      build-in-public × AI × platform. (The original #70 migration merged
 *      without this entry; this re-adds it.)
 *
 *   2. RETIRE the dead "Shakedown Street" marketplace forum (120, draft) — an
 *      abandoned buy/sell idea that never launched. Its 2 stale 2024 topics
 *      (828 "Tye Dye", 351 "Selling my soul") move to The Back Bar (81), then
 *      the empty draft forum is trashed. (Slipped past #63 because it was
 *      already `draft`, not `publish`.)
 *
 *   2b. MOVE 3 leaked artist self-introductions out of Music Discussion (1983)
 *      into Artist Corner (14120): 5975 (A. O. Wilder), 6767 (Tanager), 11108
 *      (Rainbow Vixen). They escaped the #63 Artist Corner curation. Per the
 *      #63 ruling, `<Artist> – <City> (<Genre>)` self-intros / own-release
 *      drops belong in Artist Corner; album reviews / news / established-act
 *      talk stay in Music Discussion. Verified by body that these are genuine
 *      self-intros. This is consistency enforcement, not a new structure — the
 *      epic (#53) deliberately keeps Music Discussion a mixed artist+fan
 *      melting pot; only self-identity intros graduate to Artist Corner.
 *
 *   3. SALVAGE 2342 "Moving to Austin & the Evolution of Extra Chill" out of
 *      the dead (hidden) Extra Chill Team forum (547) into The Lab (13548). It
 *      is a ~5.8k-char founder/platform-evolution narrative with one clean
 *      reply — public-worthy build-in-public origin content. The rest of the
 *      Team forum (event-planning, media-pass logistics, names, test posts,
 *      and the New Music Radar threads tied to the parked future wire vertical)
 *      stays private and untouched.
 *
 * bbPress reassignment contract honored (same as #58/#63/#70):
 *   - Topic: post_parent -> dest forum; _bbp_forum_id via
 *     bbp_update_topic_forum_id().
 *   - Replies: _bbp_forum_id via bbp_update_reply_forum_id() (reply post_parent
 *     stays = topic_id, correct for bbPress).
 *   - Counts recalculated directly on every affected forum.
 *
 * Idempotent: topics already in the destination are skipped; an already-trashed
 * 120 is left as-is.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$script_args = (array) ( $args ?? array() );
$dry_run     = in_array( 'dry-run', $script_args, true )
	|| in_array( '--dry-run', $script_args, true );

$log = static function ( $msg ) {
	WP_CLI::log( $msg );
};

if ( $dry_run ) {
	$log( '=== DRY RUN — no writes will be performed ===' );
}

/** ---------------------------------------------------------------------------
 * Configuration.
 * ------------------------------------------------------------------------- */
$the_lab_forum_id  = 13548; // The Lab.
$back_bar_forum_id = 81;    // The Back Bar.
$artist_corner_id  = 14120; // Artist Corner.
$music_disc_id     = 1983;  // Music Discussion (source of leaked self-intros).
$team_forum_id     = 547;   // Extra Chill Team (hidden, dead — left in place).
$shakedown_id      = 120;   // Shakedown Street (dead marketplace, draft).

// Gather into The Lab.
$gather_into_lab = array(
	14090 => 'Hello from Sarai Chinwag — agentic auth / build-in-public (from The Back Bar 81)',
	2342  => 'Moving to Austin & the Evolution of Extra Chill — founder narrative (salvaged from Team 547)',
);

// Shakedown Street topics to preserve in The Back Bar before trashing 120.
$shakedown_topics = array(
	828 => 'Tye Dye (marketplace offer)',
	351 => 'Selling my soul ($3.50 joke)',
);

// Artist self-introductions that leaked past the #63 Artist Corner curation
// and are still sitting in Music Discussion. Per the #63 ruling — `<Artist> –
// <City> (<Genre>)` self-intros and own-release drops belong in Artist Corner;
// album reviews / news / established-act talk stay in Music Discussion — these
// are genuine self-intros (verified by body) and should be in Artist Corner.
$leaked_self_intros = array(
	5975  => 'A. O. Wilder – New York, NY (Country Rock) — self-intro',
	6767  => 'Tanager – Ypsilanti, MI (Indie Rock) — self-intro',
	11108 => 'Rainbow Vixen Ladson SC (Rap/Hip-Hop/R&B) — self-intro',
);

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
		return false;
	}

	$title = get_the_title( $topic_id );

	if ( $dry_run ) {
		$log( "  [dry-run] would move topic {$topic_id} ({$title}) from {$current_parent} -> {$dest_forum_id}" );
		return true;
	}

	wp_update_post(
		array(
			'ID'          => $topic_id,
			'post_parent' => $dest_forum_id,
		)
	);
	bbp_update_topic_forum_id( $topic_id, $dest_forum_id );

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
 * STEP 1 — Gather Lab-material into The Lab (14090 + salvaged 2342).
 * ======================================================================== */
$log( "\n== Step 1: gather Lab-material into The Lab ==" );
foreach ( $gather_into_lab as $topic_id => $label ) {
	$log( "  {$topic_id}: {$label}" );
	$move_topic( $topic_id, $the_lab_forum_id );
}

/** ===========================================================================
 * STEP 2 — Preserve Shakedown Street topics in The Back Bar.
 * ======================================================================== */
$log( "\n== Step 2: preserve Shakedown Street topics in The Back Bar ==" );
foreach ( $shakedown_topics as $topic_id => $label ) {
	$log( "  {$topic_id}: {$label}" );
	$move_topic( $topic_id, $back_bar_forum_id );
}

/** ===========================================================================
 * STEP 2b — Move leaked artist self-intros into Artist Corner (enforce #63).
 * ======================================================================== */
$log( "\n== Step 2b: move leaked self-intros into Artist Corner ==" );
foreach ( $leaked_self_intros as $topic_id => $label ) {
	$log( "  {$topic_id}: {$label}" );
	$move_topic( $topic_id, $artist_corner_id );
}

/** ===========================================================================
 * STEP 3 — Trash the dead Shakedown Street marketplace forum (120).
 * ======================================================================== */
$log( "\n== Step 3: retire Shakedown Street marketplace forum (120) ==" );
$shakedown = get_post( $shakedown_id );
if ( ! $shakedown || 'forum' !== $shakedown->post_type ) {
	$log( "  forum {$shakedown_id} not found — nothing to trash" );
} elseif ( 'trash' === $shakedown->post_status ) {
	$log( "  forum {$shakedown_id} already trashed — skip" );
} else {
	$remaining_topics = get_posts(
		array(
			'post_type'      => bbp_get_topic_post_type(),
			'post_parent'    => $shakedown_id,
			'post_status'    => array( 'publish', 'closed', 'private', 'hidden', 'pending' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	$subforums = get_posts(
		array(
			'post_type'      => 'forum',
			'post_parent'    => $shakedown_id,
			'post_status'    => array( 'publish', 'private', 'hidden', 'draft' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $remaining_topics ) ) {
		WP_CLI::warning( "  forum {$shakedown_id} still has topics — NOT trashing." );
	} elseif ( ! empty( $subforums ) ) {
		WP_CLI::warning( "  forum {$shakedown_id} still has subforums — NOT trashing." );
	} elseif ( $dry_run ) {
		$log( "  [dry-run] would trash empty marketplace forum {$shakedown_id}" );
	} else {
		wp_trash_post( $shakedown_id );
		$log( "  trashed empty marketplace forum {$shakedown_id}" );
	}
}

/** ===========================================================================
 * STEP 4 — Recalculate counts on every affected forum.
 * ======================================================================== */
$log( "\n== Step 4: recalculate bbPress counts ==" );
$affected = array_unique(
	array(
		$the_lab_forum_id,
		$back_bar_forum_id,
		$artist_corner_id,
		$music_disc_id,
		$team_forum_id,
		$shakedown_id,
	)
);
foreach ( $affected as $forum_id ) {
	if ( $forum_id > 0 ) {
		$recalc_forum( $forum_id );
	}
}

WP_CLI::success( $dry_run ? 'Dry run complete.' : 'Phase 4 follow-up cleanup complete.' );
