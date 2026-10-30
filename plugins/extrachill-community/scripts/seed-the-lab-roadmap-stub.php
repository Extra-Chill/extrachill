<?php
/**
 * Phase 4 — The Lab "Roadmap / What we're building" pinned stub (issue #69).
 *
 * One-time, idempotent, dry-runnable. NOT loaded by the plugin. Creates a
 * SINGLE structural pinned topic in The Lab (13548) that frames the room and
 * points at the existing feature-request flow. This is STRUCTURE, not
 * fabricated dev-log history — no fake activity is authored.
 *
 *   # Preview (no writes):
 *   wp --allow-root --path=/var/www/extrachill.com \
 *      --url=community.extrachill.com \
 *      eval-file scripts/seed-the-lab-roadmap-stub.php dry-run
 *
 *   # Apply (ONLY after the plan is reviewed on the PR):
 *   wp --allow-root --path=/var/www/extrachill.com \
 *      --url=community.extrachill.com \
 *      eval-file scripts/seed-the-lab-roadmap-stub.php
 *
 * Idempotent: keyed on slug `the-lab-roadmap`. Re-running will not duplicate.
 * The topic author defaults to the first administrator on the community site;
 * override with `EC_LAB_ROADMAP_AUTHOR=<user_id>` if needed.
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

$the_lab_forum_id = 13548;
$stub_slug        = 'the-lab-roadmap';
$stub_title       = 'Roadmap / What we\'re building';

// Structural framing only — no fabricated history, no fake dev-log entries.
$stub_body = <<<'HTML'
<p>Welcome to <strong>The Lab</strong> — the open-web, build-in-public corner of Extra Chill. This is where we talk about how independent music should live online: WordPress, the open web, AI × music, dev logs, and the tools artists actually use to own their corner of the internet instead of renting it.</p>

<h2>What lives here</h2>
<ul>
<li><strong>Dev log &amp; build-in-public</strong> — what we shipped, what we're tinkering with, what the platform is becoming.</li>
<li><strong>Roadmap &amp; feature requests</strong> — what's next, and your input on it.</li>
<li><strong>Site-building for artists</strong> — practical help with link pages, websites, and the music × web-ownership intersection.</li>
</ul>

<h2>Got a feature idea or found a bug?</h2>
<p>Post it in <a href="https://community.extrachill.com/t/bug-reports-feature-requests">Bug Reports / Feature Requests</a>. Requests there feed our build pipeline directly — this is the real entry point, not a suggestion box that goes nowhere.</p>

<p>Music always leads at Extra Chill. The Lab is the evidence we walk the walk: an independent music platform built in public, on the open web.</p>
HTML;

/** ---------------------------------------------------------------------------
 * Idempotency: bail if a topic with this slug already exists in The Lab.
 * ------------------------------------------------------------------------- */
global $wpdb;
$existing = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status NOT IN ( 'trash', 'auto-draft' ) LIMIT 1",
		bbp_get_topic_post_type(),
		$stub_slug
	)
);
if ( $existing ) {
	$log( "  roadmap stub already exists (topic {$existing}) — nothing to do" );
	WP_CLI::success( 'Roadmap stub already present.' );
	return;
}

// Resolve forum.
$lab = get_post( $the_lab_forum_id );
if ( ! $lab || 'forum' !== $lab->post_type ) {
	WP_CLI::error( "The Lab forum {$the_lab_forum_id} not found." );
}

// Resolve an author (env override, else first admin).
$author_id = (int) getenv( 'EC_LAB_ROADMAP_AUTHOR' );
if ( ! $author_id ) {
	$admins    = get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		)
	);
	$author_id = ! empty( $admins ) ? (int) $admins[0] : 0;
}
if ( ! $author_id ) {
	WP_CLI::error( 'No administrator found to author the roadmap stub. Pass EC_LAB_ROADMAP_AUTHOR=<user_id>.' );
}

if ( $dry_run ) {
	$log( "  [dry-run] would create pinned topic '{$stub_title}' (slug {$stub_slug}) in forum {$the_lab_forum_id} by user {$author_id}" );
	WP_CLI::success( 'Dry run complete.' );
	return;
}

/** ---------------------------------------------------------------------------
 * Create via bbp_insert_topic so all bbPress topic meta is set correctly.
 * ------------------------------------------------------------------------- */
$topic_id = bbp_insert_topic(
	array(
		'post_parent'  => $the_lab_forum_id,
		'post_author'  => $author_id,
		'post_title'   => $stub_title,
		'post_name'    => $stub_slug,
		'post_content' => $stub_body,
		'post_status'  => bbp_get_public_status_id(),
	),
	array(
		'forum_id' => $the_lab_forum_id,
	)
);

if ( is_wp_error( $topic_id ) || ! $topic_id ) {
	WP_CLI::error( 'Failed to create the roadmap stub topic.' );
}

// Pin it (super-sticky to the forum).
bbp_stick_topic( $topic_id, true );

// Recalc forum counts.
bbp_update_forum_topic_count( $the_lab_forum_id );
bbp_update_forum_last_topic_id( $the_lab_forum_id );
bbp_update_forum_last_active_id( $the_lab_forum_id );
bbp_update_forum_last_active_time( $the_lab_forum_id );

$log( "  created + pinned roadmap stub topic {$topic_id} in The Lab ({$the_lab_forum_id})" );
WP_CLI::success( 'Roadmap stub created.' );
