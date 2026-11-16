<?php
/**
 * Image Voting Block initialization
 * REST endpoint: /wp-json/extrachill/v1/blog/image-voting/vote
 * Newsletter integration: extrachill_network_subscribe() bridge function
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
 * Comment meta key marking an image_vote comment created by the legacy
 * backfill (wp extrachill-content-blocks backfill-image-votes).
 *
 * Backfilled comments represent votes that are still recorded in the legacy
 * voteCount/voters block attributes, so the read path must not count both.
 */
if ( ! defined( 'EXTRACHILL_IMAGE_VOTE_BACKFILL_META' ) ) {
	define( 'EXTRACHILL_IMAGE_VOTE_BACKFILL_META', 'legacy_backfill' );
}

/**
 * Legacy vote count recorded in a block's attributes.
 *
 * Before votes moved to image_vote comments (issue #13) every vote rewrote
 * the block's voteCount attribute (and appended the email to voters[]). Older
 * blocks kept voteCount but no emails, so the larger of the two is the
 * recorded total.
 *
 * @param array<string,mixed> $attrs Block attributes.
 * @return int Legacy vote count (0 when the block has no legacy votes).
 */
function extrachill_content_blocks_image_vote_legacy_count_from_attrs( array $attrs ): int {
	$vote_count = isset( $attrs['voteCount'] ) && is_numeric( $attrs['voteCount'] ) ? (int) $attrs['voteCount'] : 0;
	$voters     = extrachill_content_blocks_image_vote_legacy_voters_from_attrs( $attrs );

	return max( 0, $vote_count, count( $voters ) );
}

/**
 * Legacy voter emails recorded in a block's attributes (lowercased, unique).
 *
 * @param array<string,mixed> $attrs Block attributes.
 * @return string[] Lowercased voter emails.
 */
function extrachill_content_blocks_image_vote_legacy_voters_from_attrs( array $attrs ): array {
	$voters = isset( $attrs['voters'] ) && is_array( $attrs['voters'] ) ? $attrs['voters'] : array();
	$emails = array();

	foreach ( $voters as $voter ) {
		if ( ! is_scalar( $voter ) ) {
			continue;
		}
		$email = strtolower( trim( (string) $voter ) );
		if ( '' !== $email ) {
			$emails[ $email ] = $email;
		}
	}

	return array_values( $emails );
}

/**
 * Collect legacy vote data for every image-voting block in a block tree.
 *
 * @param array<int|string,mixed>                                  $blocks Parsed blocks.
 * @param array<string,array{count:int,voters:array<int,string>}> $map    Accumulator keyed by uniqueBlockId.
 * @return array<string,array{count:int,voters:array<int,string>}>
 */
function extrachill_content_blocks_image_vote_collect_legacy( array $blocks, array $map = array() ): array {
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		if ( 'extrachill/image-voting' === ( $block['blockName'] ?? '' ) ) {
			$attrs       = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$instance_id = isset( $attrs['uniqueBlockId'] ) && is_scalar( $attrs['uniqueBlockId'] ) ? (string) $attrs['uniqueBlockId'] : '';

			if ( '' !== $instance_id && ! isset( $map[ $instance_id ] ) ) {
				$map[ $instance_id ] = array(
					'count'  => extrachill_content_blocks_image_vote_legacy_count_from_attrs( $attrs ),
					'voters' => extrachill_content_blocks_image_vote_legacy_voters_from_attrs( $attrs ),
				);
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$map = extrachill_content_blocks_image_vote_collect_legacy( $block['innerBlocks'], $map );
		}
	}

	return $map;
}

/**
 * Legacy (pre-comment storage) vote data for one block instance in a post.
 *
 * Used where only (post, instance) is known — the list ability and the vote
 * handler. render.php passes the block's own attributes instead.
 *
 * @param int    $post_id     Post ID containing the block.
 * @param string $instance_id Unique block instance ID.
 * @return array{count:int,voters:array<int,string>} Legacy count and lowercased voter emails.
 */
function extrachill_content_blocks_image_vote_legacy_votes( $post_id, $instance_id ): array {
	$empty = array(
		'count'  => 0,
		'voters' => array(),
	);

	$post = get_post( (int) $post_id );
	if ( ! $post || '' === (string) $instance_id ) {
		return $empty;
	}

	$map = extrachill_content_blocks_image_vote_collect_legacy( parse_blocks( (string) $post->post_content ) );

	return $map[ (string) $instance_id ] ?? $empty;
}

/**
 * Count image_vote comments for an instance that came from the legacy backfill.
 *
 * @param int    $post_id     Post ID containing the block.
 * @param string $instance_id Unique block instance ID.
 * @return int Backfilled vote count.
 */
function extrachill_content_blocks_image_vote_backfilled_count( $post_id, $instance_id ): int {
	$count = get_comments(
		array(
			'post_id'    => (int) $post_id,
			'type'       => EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE,
			'status'     => 'approve',
			'count'      => true,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Two-key lookup on a single post's votes.
				array(
					'key'   => 'instance_id',
					'value' => (string) $instance_id,
				),
				array(
					'key'   => EXTRACHILL_IMAGE_VOTE_BACKFILL_META,
					'value' => '1',
				),
			),
		)
	);

	return (int) $count;
}

/**
 * Total displayed vote count for an image-voting block instance.
 *
 * Combines votes cast since the move to comment storage with the historical
 * count still recorded in the block's legacy voteCount/voters attributes.
 * Comments created by the legacy backfill stand in for those same historical
 * votes, so they are reconciled with max() rather than added:
 *
 *   total = live comments + max( legacy attribute count, backfilled comments )
 *
 * This renders correctly before the backfill (legacy attrs only), after it
 * (no double count), and after the legacy attrs are stripped from
 * post_content (backfilled comments only).
 *
 * @param int      $post_id      Post ID containing the block.
 * @param string   $instance_id  Unique block instance ID.
 * @param int|null $legacy_count Legacy count when the caller already has the
 *                               block attributes; null to read post_content.
 * @return int Vote count.
 */
function extrachill_content_blocks_image_vote_total( $post_id, $instance_id, $legacy_count = null ): int {
	$all = extrachill_content_blocks_image_vote_count( $post_id, $instance_id );

	if ( null === $legacy_count ) {
		$legacy       = extrachill_content_blocks_image_vote_legacy_votes( $post_id, $instance_id );
		$legacy_count = $legacy['count'];
	}

	$legacy_count = max( 0, (int) $legacy_count );
	if ( 0 === $legacy_count ) {
		return $all;
	}

	$backfilled = min( $all, extrachill_content_blocks_image_vote_backfilled_count( $post_id, $instance_id ) );

	return ( $all - $backfilled ) + max( $legacy_count, $backfilled );
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
 * @param string $newsletter_context Newsletter integration context for the subscribe bridge.
 * @return array Response array with success status, message, and vote_count
 */
function extrachill_content_blocks_process_image_vote( $post_id, $instance_id, $email_address, $newsletter_context = 'image_voting' ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array(
			'success' => false,
			'message' => 'Post not found.',
		);
	}

	// Dedup: one vote per (post × instance × email), including votes still
	// recorded only in the legacy voters[] block attribute.
	$legacy_votes = extrachill_content_blocks_image_vote_legacy_votes( $post_id, (string) $instance_id );
	if ( in_array( strtolower( trim( (string) $email_address ) ), $legacy_votes['voters'], true )
		|| extrachill_content_blocks_image_vote_has_voted( $post_id, $instance_id, $email_address ) ) {
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
	// delegates to the extrachill/subscribe ability via the network bridge.
	if ( function_exists( 'extrachill_network_subscribe' ) ) {
		$subscription_result = extrachill_network_subscribe( $email_address, (string) $newsletter_context );
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
		'vote_count' => extrachill_content_blocks_image_vote_total( $post_id, (string) $instance_id, $legacy_votes['count'] ),
	);
}
