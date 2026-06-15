<?php
/**
 * Image Voting Block initialization
 * REST endpoint: /wp-json/extrachill/v1/blog/image-voting/vote
 * Newsletter integration: extrachill_multisite_subscribe() bridge function
 *
 * Votes are stored as native anonymous WordPress comments with a custom
 * comment_type of 'image_vote'. Each vote is one comment row, identified by
 * the voter's email and the block instance_id (stored in comment meta). This
 * is concurrency-safe (atomic wp_insert_comment, no post read-modify-write),
 * keeps voter emails out of post_content / the rendered DOM, and never creates
 * a post revision per vote. See Extra-Chill/extrachill-content-blocks#13.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom comment type used to store image votes.
 *
 * Kept out of the post's visible comment thread and out of comment_count
 * (WordPress only counts the empty/'comment' type).
 */
if ( ! defined( 'EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE' ) ) {
	define( 'EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE', 'image_vote' );
}

/**
 * Count votes for a single image-voting block instance.
 *
 * Counts image_vote comments on the post whose instance_id meta matches.
 *
 * @param int    $post_id     Post ID containing the block.
 * @param string $instance_id Unique block instance ID (uniqueBlockId attribute).
 * @return int Vote count.
 */
function extrachill_content_blocks_image_vote_count( $post_id, $instance_id ) {
	$count = get_comments(
		array(
			'post_id'    => (int) $post_id,
			'type'       => EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE,
			'meta_key'   => 'instance_id',
			'meta_value' => (string) $instance_id,
			'status'     => 'approve',
			'count'      => true,
		)
	);

	return (int) $count;
}

/**
 * Determine whether an email has already voted for a block instance.
 *
 * Dedup key is (post_id × instance_id × email): a voter may vote on every
 * contestant on the page, but only once per contestant.
 *
 * @param int    $post_id     Post ID containing the block.
 * @param string $instance_id Unique block instance ID.
 * @param string $email       Voter email address.
 * @return bool True when a matching vote already exists.
 */
function extrachill_content_blocks_image_vote_has_voted( $post_id, $instance_id, $email ) {
	if ( '' === (string) $email ) {
		return false;
	}

	$existing = get_comments(
		array(
			'post_id'      => (int) $post_id,
			'author_email' => (string) $email,
			'type'         => EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE,
			'meta_key'     => 'instance_id',
			'meta_value'   => (string) $instance_id,
			'status'       => 'approve',
			'count'        => true,
		)
	);

	return (int) $existing > 0;
}

/**
 * Process image voting (business logic)
 *
 * Inserts a single anonymous image_vote comment for the (post, instance, email)
 * tuple, deduped per contestant, and syncs the email to Sendy. No longer reads
 * or rewrites post_content.
 *
 * @param int    $post_id Post ID containing the block
 * @param string $instance_id Unique block instance ID
 * @param string $email_address Voter's email address
 * @return array Response array with success status, message, and vote_count
 */
function extrachill_content_blocks_process_image_vote( $post_id, $instance_id, $email_address ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array(
			'success' => false,
			'message' => 'Post not found.',
		);
	}

	// Dedup: one vote per (post × instance × email).
	if ( extrachill_content_blocks_image_vote_has_voted( $post_id, $instance_id, $email_address ) ) {
		return array(
			'success' => false,
			'message' => 'You have already voted for this item.',
			'code'    => 'already_voted',
		);
	}

	// Atomic insert — no post read-modify-write, concurrency-safe.
	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => (int) $post_id,
			'comment_author_email' => (string) $email_address,
			'user_id'              => 0,
			'comment_type'         => EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE,
			'comment_approved'     => 1,
			'comment_content'      => '',
		)
	);

	if ( ! $comment_id ) {
		return array(
			'success' => false,
			'message' => 'Failed to save vote.',
		);
	}

	add_comment_meta( $comment_id, 'instance_id', (string) $instance_id );

	// Newsletter integration (non-blocking). Behavior unchanged — still
	// delegates to the extrachill/subscribe ability via the multisite bridge.
	if ( function_exists( 'extrachill_multisite_subscribe' ) ) {
		$subscription_result = extrachill_multisite_subscribe( $email_address, 'image_voting' );
		if ( ! $subscription_result['success'] ) {
			error_log(
				sprintf(
					'Image voting newsletter subscription failed for %s: %s',
					$email_address,
					$subscription_result['message']
				)
			);
		}
	}

	return array(
		'success'    => true,
		'message'    => 'Vote counted successfully.',
		'vote_count' => extrachill_content_blocks_image_vote_count( $post_id, $instance_id ),
	);
}
