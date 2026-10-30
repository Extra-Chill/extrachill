<?php
/**
 * Phase 1B forum consolidation migration (issue #58).
 *
 * One-time, idempotent data migration. NOT loaded by the plugin — run once
 * against the community site (blog 2). `eval-file` passes positional args
 * through `$args`, so pass the literal word `dry-run` (no leading dashes) for
 * a no-write preview:
 *
 *   # Preview (no writes):
 *   wp --allow-root --path=/var/www/extrachill.com \
 *      --url=community.extrachill.com \
 *      eval-file scripts/migrate-phase-1b-forum-consolidation.php dry-run
 *
 *   # Apply:
 *   wp --allow-root --path=/var/www/extrachill.com \
 *      --url=community.extrachill.com \
 *      eval-file scripts/migrate-phase-1b-forum-consolidation.php
 *
 * What it does:
 *   1. Ensures the "Live Shows & Scenes" and "Artist Corner" forums exist.
 *   2. Renames "Tech Support" -> "The Lab" (title + slug the-lab).
 *   3. Reassigns geographic topics (Charleston/Austin/Philadelphia/Local
 *      Scenes) into "Live Shows & Scenes", carrying each origin forum's
 *      location term onto the moved topics.
 *   4. Reassigns the curated Artist Corner topic set out of Music Discussion.
 *   5. Trashes the now-empty geographic forums.
 *   6. Recalculates bbPress counts on every affected forum.
 *
 * bbPress reassignment contract honored:
 *   - Topic: post_parent -> dest forum; _bbp_forum_id meta via
 *     bbp_update_topic_forum_id().
 *   - Replies: _bbp_forum_id meta via bbp_update_reply_forum_id()
 *     (reply post_parent stays = topic_id, which is correct for bbPress).
 *   - Counts recalculated directly (bbp_update_forum() only recounts inside
 *     bbp_deleted_topic/save_post filters, so we call the count helpers).
 *
 * Idempotent: re-running is safe. Topics already in the destination forum are
 * skipped; forums already created/renamed/trashed are left as-is.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// `eval-file` exposes positional args via $args. Accept `dry-run` or
// `--dry-run` defensively.
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
 * Configuration: source forums, destination, location terms, Artist Corner set.
 * ------------------------------------------------------------------------- */

// Source geographic forums => location term slug to backfill onto moved topics.
// Local Scenes (8889) has no location term, so its topics carry none.
$geo_sources = array(
	114  => 'charleston',   // Charleston
	8891 => 'austin',       // Austin
	8984 => 'philadelphia', // Philadelphia
	8889 => null,           // Local Scenes (parent category, no location)
);

$tech_support_forum_id = 13548;

// Curated Artist Corner topic IDs (reviewed self-promo / artist self-intro /
// release-drop topics currently living in Music Discussion, forum 1983).
$artist_corner_topic_ids = array(
	14001,
	13807,
	13775,
	13763,
	13733,
	13731,
	13724,
	13703,
	13698,
	13671,
	13558,
	13403,
	11403,
	11100,
	10987,
	10952,
	9391,
	9190,
	8961,
	8862,
	8859,
	8780,
	8660,
	8523,
	7057,
	6966,
	6954,
	6923,
	6874,
	6754,
	6753,
	6724,
	6673,
	6650,
	6364,
	6130,
	5729,
	5647,
	5386,
	5337,
	5282,
	5277,
	5232,
	5209,
	5058,
	5043,
	4941,
	4940,
	4857,
	2286,
	2270,
	2225,
	2206,
	1923,
	1786,
	1777,
	1676,
	1491,
	1448,
	1420,
	1393,
	1385,
	1364,
	1341,
	1314,
	1280,
	1271,
	1254,
	1107,
	908,
	895,
	892,
	844,
	604,
);

/** ---------------------------------------------------------------------------
 * Helper: find a forum by exact slug (direct DB read).
 *
 * Uses $wpdb rather than get_posts() so the lookup is immune to bbPress's
 * forum-query meta filters (which hide forums lacking _bbp_forum_type) and to
 * any object-cache staleness mid-run. This is what makes creation idempotent.
 * ------------------------------------------------------------------------- */
$find_forum_by_slug = static function ( $slug ) {
	global $wpdb;
	$id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_name = %s AND post_status NOT IN ( 'trash', 'auto-draft' ) ORDER BY ID ASC LIMIT 1",
			$slug
		)
	);
	return $id ? (int) $id : 0;
};

/** ---------------------------------------------------------------------------
 * Helper: create a forum via bbp_insert_forum (sets the bbPress identity meta
 * — _bbp_forum_type/_bbp_status/_bbp_forum_id — that wp_insert_post omits and
 * that forum listings/dropdowns filter on). Idempotent on slug.
 * ------------------------------------------------------------------------- */
$ensure_forum = static function ( $slug, $title, $content, $menu_order ) use ( $find_forum_by_slug, $log, $dry_run ) {
	$existing = $find_forum_by_slug( $slug );
	if ( $existing ) {
		$log( "  forum '{$slug}' already exists (ID {$existing})" );
		return $existing;
	}
	if ( $dry_run ) {
		$log( "  [dry-run] would create forum '{$title}' (slug {$slug})" );
		return 0;
	}
	$forum_id = bbp_insert_forum(
		array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'menu_order'   => $menu_order,
		)
	);
	if ( is_wp_error( $forum_id ) || ! $forum_id ) {
		WP_CLI::error( "Failed to create forum '{$slug}'." );
	}
	$log( "  created forum '{$title}' (ID {$forum_id})" );
	return (int) $forum_id;
};

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
	$current_parent = (int) get_post_field( 'post_parent', $topic_id );
	if ( $current_parent === (int) $dest_forum_id ) {
		return false; // Already moved — idempotent skip.
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
 * STEP 1 — Ensure destination forums exist.
 * ======================================================================== */
$log( "\n== Step 1: ensure destination forums ==" );

$scenes_forum_id = $ensure_forum(
	'live-shows-scenes',
	'Live Shows & Scenes',
	'Live music, local scenes, and shows from every city. Where you are is a tag, not a wall — filter by location to find your scene.',
	2
);

$artist_corner_forum_id = $ensure_forum(
	'artist-corner',
	'Artist Corner',
	'For artists: introduce yourself, share new releases, and talk shop — DIY tools, link pages, promotion, and the business of being independent.',
	4
);

/** ===========================================================================
 * STEP 2 — Rename Tech Support -> The Lab (title + slug).
 * ======================================================================== */
$log( "\n== Step 2: rename Tech Support -> The Lab ==" );
$lab = get_post( $tech_support_forum_id );
if ( $lab && 'forum' === $lab->post_type ) {
	if ( 'the-lab' === $lab->post_name && 'The Lab' === $lab->post_title ) {
		$log( '  Tech Support already renamed to The Lab' );
	} elseif ( $dry_run ) {
		$log( "  [dry-run] would rename forum {$tech_support_forum_id} to 'The Lab' (slug the-lab)" );
	} else {
		wp_update_post(
			array(
				'ID'           => $tech_support_forum_id,
				'post_title'   => 'The Lab',
				'post_name'    => 'the-lab',
				'post_content' => 'The open web, WordPress, AI × music, and build-in-public. Site-building help for artists, dev logs, and tinkering with how independent music lives online.',
			)
		);
		$log( "  renamed forum {$tech_support_forum_id} -> The Lab (the-lab)" );
	}
} else {
	WP_CLI::warning( "Tech Support forum {$tech_support_forum_id} not found — skipping rename." );
}

/** ===========================================================================
 * STEP 3 — Move geographic topics into Live Shows & Scenes + backfill location.
 * ======================================================================== */
$log( "\n== Step 3: migrate geographic topics -> Live Shows & Scenes ==" );
$moved_geo = 0;
foreach ( $geo_sources as $src_forum_id => $location_slug ) {
	$topic_ids = get_posts(
		array(
			'post_type'      => bbp_get_topic_post_type(),
			'post_parent'    => $src_forum_id,
			'post_status'    => array( 'publish', 'closed', 'private', 'hidden', 'pending' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	$log( '  source forum ' . $src_forum_id . ': ' . count( $topic_ids ) . ' topics' );

	foreach ( $topic_ids as $topic_id ) {
		if ( $move_topic( $topic_id, $scenes_forum_id ) ) {
			++$moved_geo;
		}

		// Backfill the origin forum's location term onto the moved topic.
		if ( $location_slug && ! $dry_run ) {
			$location_term = get_term_by( 'slug', $location_slug, 'location' );
			if ( $location_term && ! is_wp_error( $location_term ) ) {
				wp_set_object_terms( $topic_id, array( (int) $location_term->term_id ), 'location' );
			}
		} elseif ( $location_slug && $dry_run ) {
			$log( "    [dry-run] would tag topic {$topic_id} with location:{$location_slug}" );
		}
	}
}
$log( "  geographic topics moved this run: {$moved_geo}" );

/** ===========================================================================
 * STEP 4 — Move curated Artist Corner topics out of Music Discussion.
 * ======================================================================== */
$log( "\n== Step 4: migrate curated Artist Corner topics ==" );
$moved_artist = 0;
foreach ( $artist_corner_topic_ids as $topic_id ) {
	$topic_post = get_post( $topic_id );
	if ( ! $topic_post || bbp_get_topic_post_type() !== $topic_post->post_type ) {
		WP_CLI::warning( "  topic {$topic_id} not found or not a topic — skipping." );
		continue;
	}
	if ( $move_topic( $topic_id, $artist_corner_forum_id ) ) {
		++$moved_artist;
	}
}
$log( "  Artist Corner topics moved this run: {$moved_artist}" );

/** ===========================================================================
 * STEP 5 — Trash now-empty geographic forums.
 * ======================================================================== */
$log( "\n== Step 5: trash emptied geographic forums ==" );
foreach ( array_keys( $geo_sources ) as $src_forum_id ) {
	$remaining = get_posts(
		array(
			'post_type'      => bbp_get_topic_post_type(),
			'post_parent'    => $src_forum_id,
			'post_status'    => array( 'publish', 'closed', 'private', 'hidden', 'pending' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	$subforums = get_posts(
		array(
			'post_type'      => 'forum',
			'post_parent'    => $src_forum_id,
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $remaining ) ) {
		WP_CLI::warning( "  forum {$src_forum_id} still has topics — NOT trashing." );
		continue;
	}
	if ( ! empty( $subforums ) ) {
		WP_CLI::warning( "  forum {$src_forum_id} still has subforums — NOT trashing." );
		continue;
	}

	$forum_status = get_post_status( $src_forum_id );
	if ( 'trash' === $forum_status ) {
		$log( "  forum {$src_forum_id} already trashed" );
		continue;
	}
	if ( $dry_run ) {
		$log( "  [dry-run] would trash empty forum {$src_forum_id}" );
		continue;
	}
	wp_trash_post( $src_forum_id );
	$log( "  trashed empty forum {$src_forum_id}" );
}

/** ===========================================================================
 * STEP 6 — Recalculate counts on every affected forum.
 * ======================================================================== */
$log( "\n== Step 6: recalculate bbPress counts ==" );
$affected = array_merge(
	array_keys( $geo_sources ),
	array( $scenes_forum_id, $artist_corner_forum_id, 1983 ) // + Music Discussion source.
);
foreach ( array_unique( $affected ) as $forum_id ) {
	if ( $forum_id > 0 ) {
		$recalc_forum( $forum_id );
	}
}

WP_CLI::success( $dry_run ? 'Dry run complete.' : 'Phase 1B forum consolidation migration complete.' );
