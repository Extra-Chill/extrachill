<?php
/**
 * Review-notify: alert the editor when a main-site post is submitted for review.
 *
 * The sibling of publish-notify.php. It observes WordPress core's own
 * `transition_post_status` edge into `pending`, so it fires for every way a
 * post can be submitted: core's block editor "Submit for Review" button, the
 * REST API (which is what Studio Compose writes through), or WP-CLI. Nothing
 * outside core's status lifecycle is needed to detect a submission.
 *
 * Editorial review only exists on the main site, so only main-site `post`
 * submissions are observed.
 *
 * WHY THE SEND IS DEFERRED
 * ------------------------
 * The transition fires in the submitter's request, and submitters are usually
 * contributors. A queued email records its issuer, and the mail worker refuses
 * issuers without Data Machine tool capabilities, so the send cannot be queued
 * from here. The hook only enqueues one unique Action Scheduler action; the
 * worker (no current user) queues the email through Data Machine's
 * pre-authenticated seam as a system issuer.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/** Producer id for review-notify receipts. */
const EC_USERS_REVIEW_NOTIFY_PRODUCER = 'extrachill-users/review-notify';

/** Action Scheduler hook that delivers one review notification. */
const EC_USERS_REVIEW_NOTIFY_ACTION = 'ec_users_review_notify_deliver';

/** Retries after a failed delivery, spaced EC_USERS_REVIEW_NOTIFY_RETRY_DELAY apart. */
const EC_USERS_REVIEW_NOTIFY_MAX_RETRIES = 3;
const EC_USERS_REVIEW_NOTIFY_RETRY_DELAY = 600;

/**
 * Observe main-site posts entering review.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Old post status.
 * @param \WP_Post $post       Post object.
 * @return void
 */
function ec_users_review_notify_on_transition( string $new_status, string $old_status, $post ): void {
	if ( 'pending' !== $new_status || 'pending' === $old_status ) {
		return;
	}

	if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
		return;
	}

	$blog_id = (int) get_current_blog_id();
	if ( ! function_exists( 'ec_get_blog_id' ) || (int) ec_get_blog_id( 'main' ) !== $blog_id ) {
		return;
	}

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		return;
	}

	try {
		as_enqueue_async_action(
			EC_USERS_REVIEW_NOTIFY_ACTION,
			array( $blog_id, (int) $post->ID ),
			'extrachill-users-email',
			true
		);
	} catch ( \Throwable $exception ) {
		error_log( sprintf( 'ec_users_review_notify: could not schedule the review alert for post %1$d: %2$s', (int) $post->ID, $exception->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
	}
}
add_action( 'transition_post_status', 'ec_users_review_notify_on_transition', 10, 3 );

/**
 * Resolve the editor who receives review alerts: the main site's admin-email
 * user when they can edit others' posts, otherwise the first main-site editor.
 *
 * Call in the main-site blog context.
 *
 * @return \WP_User|null
 */
function ec_users_review_notify_recipient(): ?\WP_User {
	$admin = get_user_by( 'email', (string) get_option( 'admin_email' ) );
	if ( $admin instanceof \WP_User && user_can( $admin, 'edit_others_posts' ) ) {
		return $admin;
	}

	$editors = get_users(
		array(
			'capability' => 'edit_others_posts',
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'number'     => 1,
		)
	);

	return isset( $editors[0] ) && $editors[0] instanceof \WP_User ? $editors[0] : null;
}

/**
 * Deliver the review notification (bell + email) for one pending post.
 *
 * Runs in the Action Scheduler worker. Idempotent through the network receipt
 * service; a failed email enqueue releases the receipt so a retry can deliver.
 *
 * @param int $blog_id Blog that owns the post (main).
 * @param int $post_id Post that entered review.
 * @param int $attempt Retry attempt number (0 for the first delivery).
 * @return bool True when the notification exists or was delivered.
 */
function ec_users_review_notify_deliver( int $blog_id, int $post_id, int $attempt = 0 ): bool {
	if ( $blog_id <= 0 || $post_id <= 0 || ! function_exists( 'ec_users_notify_with_receipts' ) ) {
		return false;
	}

	$recipient   = null;
	$author_id   = 0;
	$author_name = '';
	$post_title  = '';
	$link        = '';

	switch_to_blog( $blog_id );
	try {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && 'pending' === $post->post_status ) {
			$recipient   = ec_users_review_notify_recipient();
			$author_id   = (int) $post->post_author;
			$author_name = (string) get_the_author_meta( 'display_name', $author_id );
			$post_title  = (string) get_the_title( $post );
			$link        = (string) admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}
	} finally {
		restore_current_blog();
	}

	// Post left review before the worker ran, or nobody can review it.
	if ( ! $recipient instanceof \WP_User || $author_id <= 0 || '' === $link ) {
		return false;
	}

	// An editor submitting their own post does not need to be told about it.
	if ( (int) $recipient->ID === $author_id ) {
		return true;
	}

	$recipient_id = (int) $recipient->ID;
	$key          = sprintf( 'blog:%d:post:%d', $blog_id, $post_id );
	$owns_email   = is_email( (string) $recipient->user_email ) && function_exists( 'ec_send_email_queued' ) && function_exists( 'ec_users_release_notification_receipt' );

	$delivery = ec_users_notify_with_receipts(
		array( $recipient_id ),
		array(
			'actor_id'            => $author_id,
			'type'                => 'editorial_submission',
			'link'                => $link,
			/* translators: 1: author name, 2: post title. */
			'title'               => sprintf( __( 'New post submitted for review by %1$s: %2$s', 'extrachill-users' ), $author_name, $post_title ),
			'item_id'             => $post_id,
			'producer'            => EC_USERS_REVIEW_NOTIFY_PRODUCER,
			'idempotency_key'     => $key,
			'producer_owns_email' => $owns_email,
		)
	);

	$receipt         = is_array( $delivery['recipients'][ $recipient_id ] ?? null ) ? $delivery['recipients'][ $recipient_id ] : array();
	$status          = (string) ( $receipt['status'] ?? 'failed' );
	$notification_id = (int) ( $receipt['notification_id'] ?? 0 );
	if ( 'existing' === $status ) {
		return true;
	}
	if ( 'inserted' !== $status || $notification_id <= 0 ) {
		error_log( sprintf( 'ec_users_review_notify: could not claim the review notification for post %d.', $post_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
		ec_users_review_notify_schedule_retry( $blog_id, $post_id, $attempt );
		return false;
	}
	if ( ! $owns_email ) {
		return true;
	}

	/* translators: %s: post title. */
	$subject    = sprintf( __( 'New post submitted for review: %s', 'extrachill-users' ), $post_title );
	$queue_args = array(
		'to'       => $recipient->user_email,
		'subject'  => $subject,
		'template' => 'extrachill/branded',
		'context'  => array(
			'subject_html'   => esc_html( $subject ),
			'recipient_name' => $recipient->display_name,
			'body_html'      => '<p>' . sprintf(
				/* translators: 1: author name, 2: post title. */
				esc_html__( '%1$s submitted “%2$s” for editorial review.', 'extrachill-users' ),
				esc_html( $author_name ),
				esc_html( $post_title )
			) . '</p><p>' . esc_html__( 'The draft is ready to review on Extra Chill.', 'extrachill-users' ) . '</p>',
			'cta_url'        => $link,
			'cta_label'      => __( 'Review submission', 'extrachill-users' ),
			'preheader'      => __( 'A new post is ready for editorial review.', 'extrachill-users' ),
		),
	);

	$queue  = static function () use ( $queue_args ) {
		return ec_send_email_queued( $queue_args );
	};
	$helper = '\\DataMachine\\Abilities\\PermissionHelper';
	try {
		$result = class_exists( $helper ) ? $helper::run_as_authenticated( $queue ) : $queue();
	} catch ( \Throwable $exception ) {
		$result = new \WP_Error( 'queue_exception', $exception->getMessage() );
	}

	if ( is_array( $result ) && ! empty( $result['success'] ) ) {
		return true;
	}

	$detail   = is_wp_error( $result )
		? $result->get_error_code() . ': ' . $result->get_error_message()
		: ( is_array( $result ) && isset( $result['error'] ) && is_scalar( $result['error'] ) ? (string) $result['error'] : 'success=false' );
	$released = ec_users_release_notification_receipt( $notification_id, $recipient_id, EC_USERS_REVIEW_NOTIFY_PRODUCER, $key );
	error_log( sprintf( 'ec_users_review_notify: email enqueue failed for post %1$d (%2$s); receipt released: %3$s.', $post_id, $detail, $released ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
	if ( $released ) {
		ec_users_review_notify_schedule_retry( $blog_id, $post_id, $attempt );
	}
	return false;
}
add_action( EC_USERS_REVIEW_NOTIFY_ACTION, 'ec_users_review_notify_deliver', 10, 3 );

/**
 * Schedule a bounded retry of a failed delivery.
 *
 * @param int $blog_id Blog that owns the post.
 * @param int $post_id Post that entered review.
 * @param int $attempt Attempt that just failed.
 * @return void
 */
function ec_users_review_notify_schedule_retry( int $blog_id, int $post_id, int $attempt ): void {
	if ( $attempt >= EC_USERS_REVIEW_NOTIFY_MAX_RETRIES || ! function_exists( 'as_schedule_single_action' ) ) {
		return;
	}

	as_schedule_single_action(
		time() + EC_USERS_REVIEW_NOTIFY_RETRY_DELAY,
		EC_USERS_REVIEW_NOTIFY_ACTION,
		array( $blog_id, $post_id, $attempt + 1 ),
		'extrachill-users-email'
	);
}
