<?php
/**
 * Stranded-submission detector — the observability half of born-on-main (#110).
 *
 * Editorial blog posts are meant to be BORN ON MAIN (blog 1) via the compose
 * proxy. #106/#107 hardened *prevention* (per-request markers + a server 409
 * guard). This file adds *detection*: a scan for `post` entries that look like
 * stranded editorial content — a real article that should have landed on main
 * but didn't — so a stranding is caught in hours by a report, not by chance
 * weeks later (as the Steve Hughes submission, post 88, was).
 *
 * Originally the scan covered only the Studio subsite (blog 12). #210 widened
 * it to EVERY non-main live site on the network: team-authored posts created
 * through wp-admin on community/events/wire/etc. strand just as surely as a
 * mis-routed compose write on Studio (a pending community recap sat unseen
 * for ~27h before this existed).
 *
 * Two observability surfaces live in the compose layer:
 *   - This scan (WP-CLI `wp extrachill-studio detect-strandings`, plus the
 *     review-queue page and its hourly recovery cron), which inspects every
 *     non-main live site for candidate strandings.
 *   - The #107 guard's rejection counter (see rest.php), which records when a
 *     compose-marked local write is blocked so a recurring routing miss is
 *     visible. This command also prints that counter.
 *
 * WHAT COUNTS AS A CANDIDATE STRANDING
 * ------------------------------------
 * A `post` (post_type=post, in draft/pending/publish/future/private — never
 * auto-draft or trash) on any NON-MAIN live site that:
 *   - is NOT a social draft (social drafts legitimately live on the Studio
 *     subsite and carry `_studio_social_*` meta — see inc/social-drafts.php);
 *     AND
 *   - looks like real editorial content: substantial body text OR an attached
 *     image / featured image; AND
 *   - is authored by an Extra Chill team member (the people who use compose
 *     and wp-admin).
 *
 * These are heuristics, deliberately tuned to flag rather than to be certain —
 * the output is a candidate list for a human/agent to eyeball, not an
 * auto-remediation. Recovery (migrating a stranded post back to main) is the
 * separate extrachill-multisite#85/#86 primitive.
 *
 * @package    ExtraChillStudio
 * @subpackage Compose
 * @since      0.20.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimum rendered-text length (characters) for a post body to be considered
 * "substantial" editorial content. Short posts with no media are far more
 * likely to be scratch/social scaffolding than a stranded article.
 */
const EC_STUDIO_STRAND_MIN_CONTENT_LEN = 200;

/**
 * Post statuses that can hold a stranded editorial post.
 *
 * Deliberately exhaustive over the editorial lifecycle — including `publish`,
 * because a post published on the wrong site is stranded too. `auto-draft` and
 * `trash` are excluded by construction: an unsaved shell and a deleted post
 * are not strandings and must never alert an editor.
 */
const EC_STUDIO_STRAND_STATUSES = array( 'draft', 'pending', 'publish', 'future', 'private' );

/**
 * Scan the CURRENT site for candidate stranded editorial submissions.
 *
 * Returns a list of posts (on whichever blog is current) that look like
 * editorial content authored by a team member and are NOT social drafts.
 * Runs in the CURRENT blog context — the network scan
 * ({@see ec_studio_detect_network_strandings()}) switches blogs around this,
 * so the caller is responsible for being on the site it wants scanned. This
 * keeps the per-site heuristics reusable outside WP-CLI too.
 *
 * @since 0.20.1
 *
 * @param array $args {
 *     Optional. Scan tuning.
 *
 *     @type int $min_content_len Minimum content length to treat as substantial.
 *                                Default EC_STUDIO_STRAND_MIN_CONTENT_LEN.
 *     @type int $limit           Max posts to inspect PER SITE. Default 500.
 * }
 * @return array<int, array<string, mixed>> Candidate strandings; each entry has
 *                                           id, title, author, author_id, date,
 *                                           status, reason.
 */
function ec_studio_detect_strandings( array $args = array() ): array {
	$min_len = isset( $args['min_content_len'] ) ? max( 0, (int) $args['min_content_len'] ) : EC_STUDIO_STRAND_MIN_CONTENT_LEN;
	$limit   = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 500;

	$query = new \WP_Query(
		array(
			'post_type'              => 'post',
			// Any editorial lifecycle state — a stranding can be draft, pending,
			// or even published-on-the-wrong-site. Never auto-draft or trash.
			'post_status'            => EC_STUDIO_STRAND_STATUSES,
			'posts_per_page'         => $limit,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			// Exclude social drafts at the query layer: a post carrying the
			// platforms meta is a legitimate blog-12 social draft, never a
			// stranded editorial article.
			'meta_query'             => array(
				array(
					'key'     => \ExtraChillStudio\META_PLATFORMS,
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	$candidates = array();

	foreach ( $query->posts as $post_ref ) {
		$post = get_post( $post_ref );
		if ( ! $post instanceof \WP_Post ) {
			continue;
		}

		// Belt-and-suspenders: skip anything still carrying ANY social-draft
		// marker, even if the platforms meta was cleared but others remain.
		if ( ec_studio_post_looks_like_social_draft( (int) $post->ID ) ) {
			continue;
		}

		$author_id = (int) $post->post_author;
		if ( ! ec_studio_author_is_team_member( $author_id ) ) {
			continue;
		}

		$reason = ec_studio_strand_content_reason( $post, $min_len );
		if ( '' === $reason ) {
			continue;
		}

		$author = get_userdata( $author_id );
		$title  = get_the_title( $post );

		$candidates[] = array(
			'id'        => (int) $post->ID,
			'title'     => '' !== $title ? $title : '(no title)',
			'author'    => $author ? $author->user_login : (string) $author_id,
			'author_id' => $author_id,
			'date'      => (string) $post->post_date_gmt,
			'status'    => (string) $post->post_status,
			'reason'    => $reason,
		);
	}

	return $candidates;
}

/**
 * Scan EVERY non-main live site on the network for candidate strandings.
 *
 * #210: the per-site scan only sees one blog, so a team-authored editorial
 * `post` stranded on community/events/wire/artist/etc. (created through
 * wp-admin's + New, not the compose proxy) was invisible to every editorial
 * safety net — a pending community recap sat unseen for ~27h. This wrapper
 * iterates the network, switches per site, and tags each candidate with its
 * `blog_id` + `site_name` so every surface (CLI, review queue, hourly alert)
 * can name where the post is stuck.
 *
 * Scope:
 *   - Sites: `get_sites()` excluding archived/deleted (and spam), mirroring
 *     the canonical `ec_get_all_site_ids()` maintenance pattern. MAIN is
 *     always skipped — posts on main are where editorial content belongs.
 *     Studio IS scanned: its social-draft exclusion is what keeps legitimate
 *     social drafts out.
 *   - Bounded: the per-site `limit` (default 500) bounds each site's query;
 *     the scan never runs unbounded queries.
 *   - Each site is visited under `switch_to_blog()` with
 *     `restore_current_blog()` in a `finally`, so a throwing site can never
 *     leave the request stuck on the wrong blog.
 *
 * @since 0.28.0
 *
 * @param array $args {
 *     Optional. Scan tuning, forwarded to
 *     {@see ec_studio_detect_strandings()} per site.
 *
 *     @type int $min_content_len Minimum content length to treat as substantial.
 *                                Default EC_STUDIO_STRAND_MIN_CONTENT_LEN.
 *     @type int $limit           Max posts to inspect PER SITE. Default 500.
 * }
 * @return array<int, array<string, mixed>> Candidate strandings; each entry has
 *                                           id, title, author, author_id, date,
 *                                           status, reason, blog_id, site_name.
 */
function ec_studio_detect_network_strandings( array $args = array() ): array {
	if ( ! function_exists( 'get_sites' ) ) {
		return array();
	}

	$main_blog_id = ec_studio_strand_main_blog_id();

	$sites = get_sites(
		array(
			'fields'   => 'all',
			'number'   => 0,
			'archived' => 0,
			'deleted'  => 0,
			'spam'     => 0,
		)
	);

	$candidates = array();

	foreach ( $sites as $site ) {
		// fields=all guarantees WP_Site objects.
		$blog_id = (int) $site->blog_id;

		// Main is the correct home for editorial posts — never a stranding.
		if ( $blog_id <= 0 || $blog_id === $main_blog_id ) {
			continue;
		}

		switch_to_blog( $blog_id );
		try {
			$site_name = (string) get_option( 'blogname' );

			foreach ( ec_studio_detect_strandings( $args ) as $candidate ) {
				$candidate['blog_id']   = $blog_id;
				$candidate['site_name'] = $site_name;
				$candidates[]           = $candidate;
			}
		} finally {
			restore_current_blog();
		}
	}

	return $candidates;
}

/**
 * Resolve the main site's blog id for the strand scan.
 *
 * Prefers the canonical `ec_get_blog_id( 'main' )` (extrachill-network), then
 * the network's primary site id, and finally treats the current blog as main
 * (single-site/dev installs: the one site IS main, so nothing is excluded
 * wrongly and the scan finds no non-main sites to walk).
 *
 * @since 0.28.0
 *
 * @return int Main blog id, or the current blog id when main is unresolvable.
 */
function ec_studio_strand_main_blog_id(): int {
	if ( function_exists( 'ec_get_blog_id' ) ) {
		$main = (int) ec_get_blog_id( 'main' );
		if ( $main > 0 ) {
			return $main;
		}
	}

	if ( function_exists( 'get_network' ) ) {
		$network = get_network();
		if ( $network && (int) $network->site_id > 0 ) {
			return (int) $network->site_id;
		}
	}

	return (int) get_current_blog_id();
}

/**
 * Whether a Studio-subsite post is a legitimate social draft.
 *
 * Social drafts live on blog 12 by design (see inc/social-drafts.php) and are
 * identified by their `_studio_social_*` meta. Distinguishing them from a
 * stranded editorial `post` is the crux of the whole detector.
 *
 * CRITICAL: this must test the meta *row's existence in the database*, NOT the
 * value `get_post_meta()` returns. social-drafts.php registers these keys with
 * `register_post_meta` DEFAULTS (`_studio_social_media_kind` => 'image',
 * `_studio_social_aspect_ratio` => '4:5'), so `get_post_meta()` returns those
 * non-empty defaults for EVERY `post` — including editorial ones that have no
 * social meta row at all. A naive `! empty( get_post_meta(...) )` check would
 * therefore treat every stranded article as a social draft and hide it (this
 * is exactly what would have re-buried post 88). So we:
 *   1. gate on `metadata_exists()` (real DB row), and
 *   2. require a MEANINGFUL social signal — an actually-selected platform, a
 *      non-empty caption, or attached social images — not just a defaulted key.
 *
 * @since 0.20.1
 *
 * @param int $post_id Post ID on the current (Studio) blog.
 * @return bool True when the post is a genuine social draft.
 */
function ec_studio_post_looks_like_social_draft( int $post_id ): bool {
	// A selected platform is the definitive social-draft marker.
	if ( metadata_exists( 'post', $post_id, \ExtraChillStudio\META_PLATFORMS ) ) {
		$platforms = get_post_meta( $post_id, \ExtraChillStudio\META_PLATFORMS, true );
		if ( is_array( $platforms ) && ! empty( $platforms ) ) {
			return true;
		}
	}

	// A non-empty caption stored on disk is a social-draft signal.
	if ( metadata_exists( 'post', $post_id, \ExtraChillStudio\META_CAPTION ) ) {
		$caption = get_post_meta( $post_id, \ExtraChillStudio\META_CAPTION, true );
		if ( '' !== trim( (string) $caption ) ) {
			return true;
		}
	}

	// Attached social images stored on disk are a social-draft signal.
	if ( metadata_exists( 'post', $post_id, \ExtraChillStudio\META_IMAGES ) ) {
		$images = get_post_meta( $post_id, \ExtraChillStudio\META_IMAGES, true );
		if ( is_array( $images ) && ! empty( $images ) ) {
			return true;
		}
	}

	// A queued delivery reference is a social-draft signal.
	if ( metadata_exists( 'post', $post_id, \ExtraChillStudio\META_DELIVERY_REF ) ) {
		$delivery_ref = (string) get_post_meta( $post_id, \ExtraChillStudio\META_DELIVERY_REF, true );
		if ( '' !== $delivery_ref ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether a user id holds the Extra Chill team role.
 *
 * Team members are the only people who use the compose pane, so a stranded
 * editorial post is authored by one of them. Delegates to the canonical
 * `ec_is_team_member( $user_id )` (extrachill-users) when available.
 *
 * @since 0.20.1
 *
 * @param int $user_id Author user id.
 * @return bool True when the author is a team member.
 */
function ec_studio_author_is_team_member( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}

	if ( function_exists( 'ec_is_team_member' ) ) {
		return (bool) ec_is_team_member( $user_id );
	}

	// Fallback when extrachill-users isn't loaded: a user with edit_posts on a
	// blog-12 editorial post is close enough to flag for review.
	return user_can( $user_id, 'edit_posts' );
}

/**
 * Explain why a post looks like substantial editorial content, or '' if not.
 *
 * A stranded article is one that carries real editorial weight — a body of
 * text and/or images — as opposed to an empty scratch post. Returns a short
 * human-readable reason for the report, or an empty string when the post is
 * too thin to flag.
 *
 * @since 0.20.1
 *
 * @param \WP_Post $post    Post on the current (Studio) blog.
 * @param int      $min_len Minimum rendered-text length to treat as substantial.
 * @return string Reason string, or '' when the post is not a candidate.
 */
function ec_studio_strand_content_reason( \WP_Post $post, int $min_len ): string {
	$reasons = array();

	// Substantial body text (strip blocks/shortcodes/tags before measuring).
	$plain = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
	$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain );
	if ( $len >= $min_len ) {
		$reasons[] = sprintf( '%d chars of body text', $len );
	}

	// A featured image is a strong editorial signal.
	if ( has_post_thumbnail( $post ) ) {
		$reasons[] = 'has featured image';
	}

	// Attached images (inserted media owned by the post) are another signal.
	$attachments = get_children(
		array(
			'post_parent'    => $post->ID,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'numberposts'    => 1,
			'fields'         => 'ids',
		)
	);
	if ( ! empty( $attachments ) ) {
		$reasons[] = 'has attached image(s)';
	}

	return implode( '; ', $reasons );
}

/**
 * Read the persisted guard-rejection observability record.
 *
 * Surfaced alongside the scan so an operator sees both signals in one place:
 * candidate strandings already on disk AND whether the #107 guard has been
 * actively blocking compose-marked local writes (a recurring count means the
 * client-side rewrite is systematically missing).
 *
 * @since 0.20.1
 *
 * @return array<string, mixed> The rejection record, or an empty array when
 *                              nothing has ever been blocked.
 */
function ec_studio_get_guard_rejection_record(): array {
	if ( ! defined( 'EC_STUDIO_COMPOSE_GUARD_REJECTIONS_OPTION' ) ) {
		return array();
	}

	$record = get_option( EC_STUDIO_COMPOSE_GUARD_REJECTIONS_OPTION, array() );

	return is_array( $record ) ? $record : array();
}

/*
 * ---------------------------------------------------------------------------
 * WP-CLI surface.
 * ---------------------------------------------------------------------------
 */
if ( defined( 'WP_CLI' ) ) {
	require_once __DIR__ . '/strand-detector-cli.php';
}
