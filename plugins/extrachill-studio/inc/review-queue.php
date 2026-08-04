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

/** Retry notifications for every pending post; queued actions and receipts deduplicate. */
function ec_studio_review_queue_recover_notifications(): void {
	$main_blog_id = ec_studio_review_queue_main_blog_id();
	if ( $main_blog_id <= 0 || ! function_exists( 'ec_studio_schedule_editor_notification' ) ) {
		return;
	}

	foreach ( ec_studio_review_queue_fetch_submissions( $main_blog_id ) as $submission ) {
		$post_id   = (int) $submission['id'];
		$author_id = (int) $submission['author_id'];
		ec_studio_schedule_editor_notification( $post_id, $author_id );
	}
}
add_action( EC_STUDIO_REVIEW_NOTIFICATION_CRON, 'ec_studio_review_queue_recover_notifications' );

/** Remove the recovery event when Studio is deactivated. */
function ec_studio_review_queue_unschedule_notification_recovery(): void {
	wp_clear_scheduled_hook( EC_STUDIO_REVIEW_NOTIFICATION_CRON );
}
register_deactivation_hook( EXTRACHILL_STUDIO_PLUGIN_FILE, 'ec_studio_review_queue_unschedule_notification_recovery' );

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

	switch_to_blog( $main_blog_id );
	try {
		$can_review_main = current_user_can( EC_STUDIO_REVIEW_QUEUE_CAP );
	} finally {
		restore_current_blog();
	}

	if ( ! $can_review_main ) {
		wp_die( esc_html__( 'You do not have permission to review submissions on the main site.', 'extrachill-studio' ) );
	}

	$rows = ec_studio_review_queue_fetch_submissions( $main_blog_id );

	if ( empty( $rows ) ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No Studio submissions are waiting for review right now.', 'extrachill-studio' ) . '</p></div>';
		echo '</div>';
		return;
	}

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
	echo '</div>';
}
