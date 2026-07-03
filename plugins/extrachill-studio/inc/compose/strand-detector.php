<?php
/**
 * Stranded-submission detector — the observability half of born-on-main (#110).
 *
 * Studio lives on the Studio subsite (blog 12); editorial blog posts are meant
 * to be BORN ON MAIN (blog 1) via the compose proxy. #106/#107 hardened
 * *prevention* (per-request markers + a server 409 guard). This file adds
 * *detection*: a scan of the Studio subsite for `post` entries that look like
 * stranded editorial content — a real article that should have landed on main
 * but didn't — so a stranding is caught in hours by a report, not by chance
 * weeks later (as the Steve Hughes submission, post 88, was).
 *
 * Two observability surfaces live in the compose layer:
 *   - This scan (WP-CLI `wp extrachill-studio detect-strandings`), which
 *     inspects blog 12 for candidate strandings.
 *   - The #107 guard's rejection counter (see rest.php), which records when a
 *     compose-marked local write is blocked so a recurring routing miss is
 *     visible. This command also prints that counter.
 *
 * WHAT COUNTS AS A CANDIDATE STRANDING
 * ------------------------------------
 * A blog-12 `post` (post_type=post) that:
 *   - is NOT a social draft (social drafts legitimately live on blog 12 and
 *     carry `_studio_social_*` meta — see inc/social-drafts.php); AND
 *   - looks like real editorial content: substantial body text OR an attached
 *     image / featured image; AND
 *   - is authored by an Extra Chill team member (the people who use compose).
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
 * "substantial" editorial content. Short blog-12 posts with no media are far
 * more likely to be scratch/social scaffolding than a stranded article.
 */
const EC_STUDIO_STRAND_MIN_CONTENT_LEN = 200;

/**
 * Scan the Studio subsite for candidate stranded editorial submissions.
 *
 * Returns a list of blog-12 posts that look like editorial content authored by
 * a team member and are NOT social drafts. Runs in the CURRENT blog context
 * (the caller is responsible for being on / switching to the Studio subsite),
 * so it is reusable outside WP-CLI too.
 *
 * @since 0.20.1
 *
 * @param array $args {
 *     Optional. Scan tuning.
 *
 *     @type int $min_content_len Minimum content length to treat as substantial.
 *                                Default EC_STUDIO_STRAND_MIN_CONTENT_LEN.
 *     @type int $limit           Max posts to inspect. Default 500.
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
			// Any lifecycle state — a stranding can be draft, pending, or even
			// published-on-the-wrong-site.
			'post_status'            => array( 'draft', 'pending', 'publish', 'future', 'private' ),
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

	// A scheduled/cross-posted job id is a social-draft signal.
	if ( metadata_exists( 'post', $post_id, \ExtraChillStudio\META_JOB_ID ) ) {
		$job_id = (int) get_post_meta( $post_id, \ExtraChillStudio\META_JOB_ID, true );
		if ( $job_id > 0 ) {
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
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/strand-detector-cli.php';
}
