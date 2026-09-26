<?php
/**
 * Studio Submission Review Queue — editor-facing pending-submission list.
 *
 * Compose blog submissions are BORN ON MAIN (blog 1) and land there as
 * `pending` under the writer's authorship (see inc/compose/rest.php and
 * Extra-Chill/extrachill-studio#106/#107). Until now nothing surfaced those
 * pending submissions to an editor: a post that no one noticed was
 * functionally lost — the failure that filed #109 (a real submission sat
 * unnoticed on main because someone had to go looking for it).
 *
 * This page closes the editorial loop. It registers a wp-admin screen on the
 * Studio subsite (blog 12) — where team editors already work — and queries
 * MAIN for pending posts by Extra Chill team members and administrators. Studio
 * provenance (`_ec_studio_submission`) enriches the submitted timestamp when
 * present, but is deliberately not an admission rule: a failed or bypassed
 * marker write must not make pending editorial work invisible. Each row links
 * straight to the review/edit/preview surfaces on main so an editor can pick the
 * submission up and publish it.
 *
 * #210 adds a second surface on the same page: "Stranded on other sites". The
 * main pending queue only sees blog 1, so a team-authored editorial `post`
 * created through wp-admin on community/events/wire/etc. was invisible to
 * every safety net (a pending community recap sat unseen ~27h). The page now
 * also lists candidates from ec_studio_detect_network_strandings() — blog,
 * title, author, status, and an edit link ON that site — and the hourly
 * recovery cron alerts the editor once per stranded post: the producer
 * receipt/idempotency key (`stranded:<blog>:<post>`) guarantees the email
 * itself is sent once per post, while the hourly re-scan keeps retrying
 * safely until the post leaves a strandable status.
 *
 * Why a Studio-subsite admin page (not a page ON main):
 *   - This plugin is active only on Studio (blog 12); it is not present on
 *     main, so it cannot register an admin menu there. The DATA is main-side,
 *     so the page queries main via switch_to_blog + WP_Query. This mirrors the
 *     cross-site pattern the compose proxy already uses.
 *
 * Distinct from #42 (SOCIALS submissions): that queue is for social drafts;
 * this one is strictly the BLOG compose editorial queue, keyed on a different
 * marker. The two do not collide.
 *
 * @package    ExtraChillStudio
 * @subpackage ReviewQueue
 * @since      0.20.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capability required to view the review queue.
 *
 * Gated to editors (and above): reviewing/publishing other people's
 * submissions is an editorial action, so plain contributors — who can submit
 * but not review — are excluded. `edit_others_posts` is the core capability
 * that distinguishes editors from authors/contributors.
 */
const EC_STUDIO_REVIEW_QUEUE_CAP = 'edit_others_posts';

/**
 * Admin page slug for the review queue.
 */
const EC_STUDIO_REVIEW_QUEUE_SLUG = 'ec-studio-review-queue';

/** Hourly recovery hook for pending submissions that missed their live alert. */
const EC_STUDIO_REVIEW_NOTIFICATION_CRON = 'ec_studio_recover_review_notifications';

/**
 * Register the review-queue admin page under the Posts menu.
 *
 * Placed under Posts (edit.php) rather than a dedicated top-level menu: it is
 * a small editorial list that belongs next to the post lists an editor
 * already uses, and it avoids adding menu chrome for a single screen.
 *
 * @since 0.20.1
 *
 * @return void
 */
function ec_studio_review_queue_register_page(): void {
	add_submenu_page(
		'edit.php',
		__( 'Studio Submissions', 'extrachill-studio' ),
		__( 'Studio Submissions', 'extrachill-studio' ),
		EC_STUDIO_REVIEW_QUEUE_CAP,
		EC_STUDIO_REVIEW_QUEUE_SLUG,
		'ec_studio_review_queue_render_page'
	);
}
add_action( 'admin_menu', 'ec_studio_review_queue_register_page' );

/**
 * Resolve main extrachill.com's blog id.
 *
 * @since 0.20.1
 *
 * @return int Main blog id, or 0 when unresolved.
 */
function ec_studio_review_queue_main_blog_id(): int {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return 0;
	}

	return (int) ec_get_blog_id( 'main' );
}

/**
 * Check the current user's editorial capability in the main-site context.
 *
 * Multisite roles are site-specific, so Studio access does not imply main-site
 * review access.
 *
 * @param int $main_blog_id Main site blog ID.
 * @return bool Whether the current user can review others' posts on main.
 */
function ec_studio_review_queue_user_can_review_main( int $main_blog_id ): bool {
	if ( $main_blog_id <= 0 ) {
		return false;
	}

	switch_to_blog( $main_blog_id );
	try {
		return current_user_can( EC_STUDIO_REVIEW_QUEUE_CAP );
	} finally {
		restore_current_blog();
	}
}

/**
 * Fetch pending Studio submissions from main extrachill.com.
 *
 * Runs a WP_Query inside `switch_to_blog( main )` for pending posts authored by
 * Extra Chill team members or administrators. WordPress's pending status is the
 * authoritative editorial handoff; Studio provenance is optional metadata and
 * must never gate visibility.
 *
 * Each returned row is a plain array of the fields the table renders, resolved
 * WHILE STILL in main's context (author display name, edit/preview URLs) so the
 * caller never has to switch blogs again. This keeps all cross-site work in one
 * switch/restore pair.
 *
 * @since 0.20.1
 *
 * @param int $main_blog_id Resolved main blog id.
 * @return array<int, array<string, mixed>> Rows for the review table.
 */
function ec_studio_review_queue_fetch_submissions( int $main_blog_id ): array {
	if ( $main_blog_id <= 0 ) {
		return array();
	}

	$rows = array();

	switch_to_blog( $main_blog_id );
	try {
		$team_author_ids = get_users(
			array(
				'role__in' => array( 'extra_chill_team', 'administrator' ),
				'fields'   => 'ID',
			)
		);
		$team_author_ids = array_map( 'intval', $team_author_ids );
		if ( empty( $team_author_ids ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'pending',
				'author__in'     => $team_author_ids,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				$post = get_post( $post );
			}
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$submission = get_post_meta( $post->ID, '_ec_studio_submission', true );

			$submitted_at = is_array( $submission ) && ! empty( $submission['submitted_at'] )
				? (string) $submission['submitted_at']
				: '';

			$author_id   = (int) $post->post_author;
			$author_name = $author_id > 0 ? (string) get_the_author_meta( 'display_name', $author_id ) : '';

			$title = (string) get_the_title( $post );

			$rows[] = array(
				'id'           => (int) $post->ID,
				'author_id'    => $author_id,
				'title'        => '' !== $title ? $title : __( '(no title)', 'extrachill-studio' ),
				'author'       => '' !== $author_name ? $author_name : __( 'Unknown', 'extrachill-studio' ),
				'submitted_at' => $submitted_at,
				'modified_gmt' => (string) $post->post_modified_gmt,
				'edit_url'     => (string) get_edit_post_link( $post->ID, 'raw' ),
				'preview_url'  => (string) get_preview_post_link( $post ),
			);
		}
	} finally {
		restore_current_blog();
	}

	return $rows;
}

/** Ensure missed or bypassed submission alerts are retried hourly. */
function ec_studio_review_queue_schedule_notification_recovery(): void {
	if ( ! wp_next_scheduled( EC_STUDIO_REVIEW_NOTIFICATION_CRON ) ) {
		wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'hourly', EC_STUDIO_REVIEW_NOTIFICATION_CRON );
	}
}
add_action( 'init', 'ec_studio_review_queue_schedule_notification_recovery' );

/**
 * Retry notifications for every pending post; queued actions and receipts deduplicate.
 *
 * #210: also walks the network strand scan and enqueues one unique alert per
 * candidate. Two layers deduplicate — Action Scheduler's unique-args guard
 * collapses back-to-back hourly scans while an alert is still pending, and the
 * `stranded:<blog>:<post>` receipt key makes the email itself one-per-post
 * forever.
 */
function ec_studio_review_queue_recover_notifications(): void {
	$main_blog_id = ec_studio_review_queue_main_blog_id();
	if ( $main_blog_id <= 0 ) {
		return;
	}

	if ( function_exists( 'ec_studio_schedule_editor_notification' ) ) {
		foreach ( ec_studio_review_queue_fetch_submissions( $main_blog_id ) as $submission ) {
			$post_id   = (int) $submission['id'];
			$author_id = (int) $submission['author_id'];
			ec_studio_schedule_editor_notification( $post_id, $author_id );
		}
	}

	if ( function_exists( 'ec_studio_schedule_stranded_post_notification' ) && function_exists( 'ec_studio_detect_network_strandings' ) ) {
		foreach ( ec_studio_detect_network_strandings() as $candidate ) {
			ec_studio_schedule_stranded_post_notification(
				(int) $candidate['blog_id'],
				(int) $candidate['id'],
				(int) $candidate['author_id']
			);
		}
	}
}
add_action( EC_STUDIO_REVIEW_NOTIFICATION_CRON, 'ec_studio_review_queue_recover_notifications' );

/** Remove the recovery event when Studio is deactivated. */
function ec_studio_review_queue_unschedule_notification_recovery(): void {
	wp_clear_scheduled_hook( EC_STUDIO_REVIEW_NOTIFICATION_CRON );
}
register_deactivation_hook( EXTRACHILL_STUDIO_PLUGIN_FILE, 'ec_studio_review_queue_unschedule_notification_recovery' );

/**
 * Producer namespace for stranded-post editor alerts (#210).
 *
 * Paired with the `stranded:<blog>:<post>` idempotency key in the receipt
 * service, so one stranded post can only ever produce one editor alert.
 */
const EC_STUDIO_STRANDED_PRODUCER = 'extrachill-studio/stranded-post';

/**
 * Enqueue one unique background delivery for a stranded-post editor alert.
 *
 * Mirrors ec_studio_schedule_editor_notification() (compose/rest.php): the
 * hourly cron runs in a cron context while the mail ability needs a trusted
 * issuer, so the actual send happens in an Action Scheduler worker. `unique`
 * collapses repeated hourly enqueues for the same post while one is pending;
 * the receipt key provides the permanent once-per-post guarantee.
 *
 * @since 0.28.0
 *
 * @param int $blog_id  Blog the post is stranded on.
 * @param int $post_id  Stranded post ID on that blog.
 * @param int $author_id Stranded post author ID.
 * @return bool True when delivery was queued.
 */
function ec_studio_schedule_stranded_post_notification( int $blog_id, int $post_id, int $author_id ): bool {
	if ( $blog_id <= 0 || $post_id <= 0 || $author_id <= 0 || ! function_exists( 'as_enqueue_async_action' ) ) {
		return false;
	}

	try {
		$action_id = as_enqueue_async_action(
			'ec_studio_deliver_stranded_post_notification',
			array( $blog_id, $post_id, $author_id ),
			'extrachill-studio-email',
			true
		);
	} catch ( \Throwable $exception ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
			sprintf( '[extrachill-studio] Failed to schedule the stranded-post notification for blog %1$d post %2$d: %3$s', $blog_id, $post_id, $exception->getMessage() )
		);
		return false;
	}

	return (int) $action_id > 0;
}

/**
 * Resolve the editor who receives stranded-post alerts.
 *
 * Same resolution order as ec_studio_notify_editor_for_post() (compose/rest.php):
 * main's admin_email user when they hold edit_others_posts there, else the
 * lowest-ID main user who does. Resolved under switch_to_blog( main ) so
 * multisite role data is read on the site the capability means.
 *
 * @since 0.28.0
 *
 * @param int $main_blog_id Resolved main blog id.
 * @return \WP_User|null The alert recipient, or null when none qualifies.
 */
function ec_studio_review_queue_resolve_editor_recipient( int $main_blog_id ): ?\WP_User {
	switch_to_blog( $main_blog_id );
	try {
		$admin = get_user_by( 'email', (string) get_option( 'admin_email' ) );
		if ( $admin instanceof \WP_User && user_can( $admin, 'edit_others_posts' ) ) {
			return $admin;
		}

		$editors = get_users(
			array(
				'capability' => 'edit_others_posts',
				'orderby'    => 'ID',
				'order'      => 'ASC',
			)
		);
		foreach ( $editors as $editor ) {
			if ( $editor instanceof \WP_User && user_can( $editor, 'edit_others_posts' ) ) {
				return $editor;
			}
		}

		return null;
	} finally {
		restore_current_blog();
	}
}

/**
 * Create an idempotent stranded-post alert for one candidate (#210).
 *
 * Runs in the Action Scheduler worker (`ec_studio_deliver_stranded_post_notification`)
 * enqueued by the hourly recovery cron. Re-verifies the post inside its own
 * blog before alerting: the scan and the worker are separated in time, so the
 * post may have been moved/trashed/deleted meanwhile. Only the strandable
 * statuses alert — never `auto-draft` (unsaved shell) or `trash` (deleted).
 *
 * The notification goes through the receipt service with producer
 * `extrachill-studio/stranded-post` and key `stranded:<blog>:<post>`, so the
 * alert exists exactly once per stranded post no matter how many hourly scans
 * enqueue it. The email rides the same producer-owned seam as
 * ec_studio_notify_editor_for_post(): on queue failure the receipt is released
 * so the next hourly scan can retry cleanly, and the send itself is wrapped in
 * PermissionHelper::run_as_authenticated() — a bare
 * ec_send_email_queued() call fails in the worker with
 * `email_queue_issuer_required` because the worker has no user session.
 *
 * @since 0.28.0
 *
 * @param int $blog_id  Blog the post is stranded on.
 * @param int $post_id  Stranded post ID on that blog.
 * @param int $author_id Stranded post author ID.
 * @return bool True when the alert exists or was inserted.
 */
function ec_studio_notify_editor_of_stranded_post( int $blog_id, int $post_id, int $author_id ): bool {
	if ( $blog_id <= 0 || $post_id <= 0 || $author_id <= 0 || ! function_exists( 'ec_users_notify_with_receipts' ) || ! function_exists( 'ec_get_blog_id' ) ) {
		return false;
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return false;
	}

	$recipient = ec_studio_review_queue_resolve_editor_recipient( $main_blog_id );
	if ( ! $recipient instanceof \WP_User ) {
		return false;
	}

	$recipient_id = (int) $recipient->ID;
	$site_name    = '';
	$author_name  = '';
	$post_title   = '';
	$link         = '';
	$status       = '';

	switch_to_blog( $blog_id );
	try {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && in_array( $post->post_status, EC_STUDIO_STRAND_STATUSES, true ) ) {
			$status      = (string) $post->post_status;
			$site_name   = (string) get_option( 'blogname' );
			$author_name = (string) get_the_author_meta( 'display_name', $author_id );
			$post_title  = (string) get_the_title( $post );
			$link        = (string) admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}
	} finally {
		restore_current_blog();
	}

	if ( '' === $status || '' === $link ) {
		return true;
	}

	$title = sprintf(
		/* translators: 1: site name, 2: post title. */
		__( 'Post possibly stranded on %1$s: %2$s', 'extrachill-studio' ),
		'' !== $site_name ? $site_name : (string) $blog_id,
		$post_title
	);

	$idempotency_key = 'stranded:' . $blog_id . ':' . $post_id;
	$owns_email      = is_email( (string) $recipient->user_email ) && function_exists( 'ec_users_release_notification_receipt' ) && function_exists( 'ec_send_email_queued' );
	$receipt         = ec_users_notify_with_receipts(
		array( $recipient_id ),
		array(
			'actor_id'            => $author_id,
			'type'                => 'stranded_post',
			'link'                => $link,
			'title'               => $title,
			'item_id'             => $post_id,
			'producer'            => EC_STUDIO_STRANDED_PRODUCER,
			'idempotency_key'     => $idempotency_key,
			'producer_owns_email' => $owns_email,
		)
	);

	$recipient_receipt = is_array( $receipt['recipients'][ $recipient_id ] ?? null ) ? $receipt['recipients'][ $recipient_id ] : array();
	$receipt_status    = (string) ( $recipient_receipt['status'] ?? 'failed' );
	$notification_id   = (int) ( $recipient_receipt['notification_id'] ?? 0 );
	if ( 'existing' === $receipt_status ) {
		return true;
	}
	if ( 'inserted' !== $receipt_status || $notification_id <= 0 ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated observability for a failed editor handoff.
				sprintf( '[extrachill-studio] Failed to claim the stranded-post notification for blog %1$d post %2$d.', $blog_id, $post_id )
			);
		}
		return false;
	}
	if ( ! $owns_email ) {
		return true;
	}

	$subject = sprintf(
		/* translators: 1: site name, 2: post title. */
		__( 'Post stranded on %1$s: %2$s', 'extrachill-studio' ),
		'' !== $site_name ? $site_name : (string) $blog_id,
		$post_title
	);
	$body_html = '<p>' . sprintf(
		/* translators: 1: post title, 2: author name, 3: site name, 4: post status. */
		esc_html__( '“%1$s” by %2$s looks like editorial content stranded on %3$s (status: %4$s). It was not created through the Studio compose flow and may never reach the main site unless someone moves it.', 'extrachill-studio' ),
		esc_html( $post_title ),
		esc_html( $author_name ),
		esc_html( '' !== $site_name ? $site_name : (string) $blog_id ),
		esc_html( $status )
	) . '</p><p>' . esc_html__( 'Open the post to review, move, or delete it.', 'extrachill-studio' ) . '</p>';
	try {
		$queue = ec_studio_stranded_send_mail(
			array(
				'to'       => $recipient->user_email,
				'subject'  => $subject,
				'template' => 'extrachill/branded',
				'context'  => array(
					'subject_html'   => esc_html( $subject ),
					'recipient_name' => $recipient->display_name,
					'body_html'      => $body_html,
					'cta_url'        => $link,
					'cta_label'      => __( 'Open stranded post', 'extrachill-studio' ),
					'preheader'      => __( 'A team-authored post may be stranded away from the main site.', 'extrachill-studio' ),
				),
			)
		);
	} catch ( \Throwable $exception ) {
		$queue = array(
			'success' => false,
			'error'   => $exception->getMessage(),
		);
	}
	// The mail ability returns WP_Error on permission/validation failure.
	// Never index into it: that fatals and strands the receipt (see #209).
	$queued = is_array( $queue ) && ! empty( $queue['success'] );
	if ( ! $queued ) {
		$detail = is_wp_error( $queue )
			? $queue->get_error_code() . ': ' . $queue->get_error_message()
			: ( is_array( $queue ) && isset( $queue['error'] ) && is_scalar( $queue['error'] ) ? (string) $queue['error'] : 'success=false' );
		// Unreachable when the release helper is missing: $owns_email already
		// gated on it before the receipt was claimed.
		$released = ec_users_release_notification_receipt( $notification_id, $recipient_id, EC_STUDIO_STRANDED_PRODUCER, $idempotency_key );
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
			sprintf(
				'[extrachill-studio] Failed to queue the stranded-post email for blog %1$d post %2$d (%3$s); receipt released: %4$s.',
				$blog_id,
				$post_id,
				$detail,
				$released ? 'yes' : 'no'
			)
		);
		return false;
	}

	return true;
}
add_action( 'ec_studio_deliver_stranded_post_notification', 'ec_studio_notify_editor_of_stranded_post', 10, 3 );

/**
 * Send the stranded-post alert email through the pre-authenticated ability seam.
 *
 * The Action Scheduler worker has no user session, so `get_current_user_id()`
 * is 0 and the `datamachine/send-email-queued` ability's permission callback
 * short-circuits with `email_queue_issuer_required` before any SMTP activity.
 * The authorization decision was already made HERE — the receipt claim above
 * is the once-per-post gate — so the send runs through
 * `PermissionHelper::run_as_authenticated()`, the canonical seam for callers
 * that authorized an operation at their own layer (same pattern as
 * ec_studio_transcription_send_mail() and extrachill-users#110). No acting
 * user id is passed: this is a fixed system notification whose recipient,
 * subject, and body are all bound by the caller.
 *
 * Falls back to a direct call when Data Machine is unavailable, so behaviour
 * degrades gracefully rather than fataling.
 *
 * @since 0.28.0
 *
 * @param array $args Arguments forwarded to {@see ec_send_email_queued()}.
 * @return mixed Result envelope from ec_send_email_queued(), or a WP_Error/array error.
 */
function ec_studio_stranded_send_mail( array $args ) {
	if ( ! function_exists( 'ec_send_email_queued' ) ) {
		return array(
			'success' => false,
			'error'   => 'ec_send_email_queued() is unavailable — extrachill-network mail layer not loaded.',
		);
	}

	$helper = '\DataMachine\Abilities\PermissionHelper';
	if ( class_exists( $helper ) ) {
		return $helper::run_as_authenticated(
			static function () use ( $args ) {
				return ec_send_email_queued( $args );
			}
		);
	}

	return ec_send_email_queued( $args );
}

/**
 * Format an ISO-8601 / MySQL datetime for display in the site's timezone.
 *
 * @since 0.20.1
 *
 * Returns a raw (unescaped) string; callers must escape at output.
 *
 * @param string $value Datetime string (ISO-8601 UTC or MySQL GMT).
 * @return string Human-readable local datetime, or an em dash when empty.
 */
function ec_studio_review_queue_format_datetime( string $value ): string {
	$value = trim( $value );
	if ( '' === $value ) {
		return '—';
	}

	$timestamp = strtotime( $value );
	if ( false === $timestamp ) {
		return $value;
	}

	$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

	return (string) wp_date( $format, $timestamp );
}

/**
 * Fetch stranded-post rows for the review-queue table (#210).
 *
 * Wraps {@see ec_studio_detect_network_strandings()} and resolves each
 * candidate's edit URL WHILE switched to its own blog, so the caller never
 * touches another blog's context. The edit link is an empty string when the
 * current user cannot edit on that site — the row still renders, honestly
 * unclickable, because a stranding you can't personally edit is still worth
 * surfacing to whoever can.
 *
 * @since 0.28.0
 *
 * @return array<int, array<string, mixed>> Rows for the stranded table.
 */
function ec_studio_review_queue_fetch_stranded_posts(): array {
	$rows = array();

	foreach ( ec_studio_detect_network_strandings() as $candidate ) {
		$edit_url = '';

		switch_to_blog( (int) $candidate['blog_id'] );
		try {
			$edit_url = (string) get_edit_post_link( (int) $candidate['id'], 'raw' );
		} finally {
			restore_current_blog();
		}

		$rows[] = array(
			'blog_id'   => (int) $candidate['blog_id'],
			'site_name' => (string) $candidate['site_name'],
			'id'        => (int) $candidate['id'],
			'title'     => (string) $candidate['title'],
			'author'    => (string) $candidate['author'],
			'status'    => (string) $candidate['status'],
			'date'      => (string) $candidate['date'],
			'reason'    => (string) $candidate['reason'],
			'edit_url'  => $edit_url,
		);
	}

	return $rows;
}

/**
 * Render the review-queue admin page.
 *
 * @since 0.20.1
 *
 * @return void
 */
function ec_studio_review_queue_render_page(): void {
	if ( ! current_user_can( EC_STUDIO_REVIEW_QUEUE_CAP ) ) {
		wp_die( esc_html__( 'You do not have permission to view Studio submissions.', 'extrachill-studio' ) );
	}

	$main_blog_id = ec_studio_review_queue_main_blog_id();

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Studio Submissions', 'extrachill-studio' ) . '</h1>';
	echo '<p class="description">' . esc_html__( 'Blog posts submitted for review through the Studio Compose tool. These live on the main site as pending drafts — open one to review, edit, and publish it.', 'extrachill-studio' ) . '</p>';

	if ( $main_blog_id <= 0 ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not resolve the main site. The Extra Chill multisite helpers may be unavailable.', 'extrachill-studio' ) . '</p></div>';
		echo '</div>';
		return;
	}

	if ( ! ec_studio_review_queue_user_can_review_main( $main_blog_id ) ) {
		wp_die( esc_html__( 'You do not have permission to review submissions on the main site.', 'extrachill-studio' ) );
	}

	$rows = ec_studio_review_queue_fetch_submissions( $main_blog_id );

	if ( empty( $rows ) ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No Studio submissions are waiting for review right now.', 'extrachill-studio' ) . '</p></div>';
	} else {
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Title', 'extrachill-studio' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Author', 'extrachill-studio' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Submitted', 'extrachill-studio' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'extrachill-studio' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			$edit_url    = (string) $row['edit_url'];
			$preview_url = (string) $row['preview_url'];
			$submitted   = '' !== (string) $row['submitted_at'] ? (string) $row['submitted_at'] : (string) $row['modified_gmt'];

			echo '<tr>';

			echo '<td><strong>';
			if ( '' !== $edit_url ) {
				echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $row['title'] ) . '</a>';
			} else {
				echo esc_html( (string) $row['title'] );
			}
			echo '</strong></td>';

			echo '<td>' . esc_html( (string) $row['author'] ) . '</td>';

			echo '<td>' . esc_html( ec_studio_review_queue_format_datetime( $submitted ) ) . '</td>';

			echo '<td>';
			$actions = array();
			if ( '' !== $edit_url ) {
				$actions[] = '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Review &amp; edit', 'extrachill-studio' ) . '</a>';
			}
			if ( '' !== $preview_url ) {
				$actions[] = '<a href="' . esc_url( $preview_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Preview', 'extrachill-studio' ) . '</a>';
			}
			echo wp_kses_post( implode( ' | ', $actions ) );
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	ec_studio_review_queue_render_stranded_section();

	echo '</div>';
}

/**
 * Render the "Stranded on other sites" section of the review queue (#210).
 *
 * Lists team-authored posts the network strand scan flagged on non-main sites.
 * Assumes the caller already gated the viewer (editorial cap on Studio AND
 * main); it only renders.
 *
 * @since 0.28.0
 *
 * @return void
 */
function ec_studio_review_queue_render_stranded_section(): void {
	echo '<h2>' . esc_html__( 'Stranded on other sites', 'extrachill-studio' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'Team-authored posts on other network sites that look like editorial content which never reached the main site. The hourly check emails the editor once per stranded post.', 'extrachill-studio' ) . '</p>';

	if ( ! function_exists( 'ec_studio_detect_network_strandings' ) ) {
		return;
	}

	$rows = ec_studio_review_queue_fetch_stranded_posts();

	if ( empty( $rows ) ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No stranded posts detected on other sites.', 'extrachill-studio' ) . '</p></div>';
		return;
	}

	echo '<table class="wp-list-table widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Site', 'extrachill-studio' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Title', 'extrachill-studio' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Author', 'extrachill-studio' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Status', 'extrachill-studio' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Last updated', 'extrachill-studio' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Actions', 'extrachill-studio' ) . '</th>';
	echo '</tr></thead>';
	echo '<tbody>';

	foreach ( $rows as $row ) {
		$edit_url = (string) $row['edit_url'];
		$site     = '' !== (string) $row['site_name']
			? (string) $row['site_name']
			/* translators: %d: numeric blog ID. */
			: sprintf( __( 'Site %d', 'extrachill-studio' ), (int) $row['blog_id'] );

		echo '<tr>';

		echo '<td>' . esc_html( $site ) . '</td>';

		echo '<td><strong>';
		if ( '' !== $edit_url ) {
			echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $row['title'] ) . '</a>';
		} else {
			echo esc_html( (string) $row['title'] );
		}
		echo '</strong></td>';

		echo '<td>' . esc_html( (string) $row['author'] ) . '</td>';

		echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';

		echo '<td>' . esc_html( ec_studio_review_queue_format_datetime( (string) $row['date'] ) ) . '</td>';

		echo '<td>';
		if ( '' !== $edit_url ) {
			echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit on site', 'extrachill-studio' ) . '</a>';
		}
		echo '</td>';

		echo '</tr>';
	}

	echo '</tbody></table>';
}
