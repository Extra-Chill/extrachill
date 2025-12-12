<?php
/**
 * Image Voting Block initialization
 * REST endpoint: /wp-json/extrachill/v1/blocks/image-voting/vote
 * Newsletter integration: extrachill_multisite_subscribe() bridge function
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Process image voting (business logic)
 *
 * @param int $post_id Post ID containing the block
 * @param string $instance_id Unique block instance ID
 * @param string $email_address Voter's email address
 * @return array Response array with success status, message, and vote_count
 */
function extrachill_blocks_process_image_vote($post_id, $instance_id, $email_address) {
	$post = get_post($post_id);
	if (!$post) {
		return array(
			'success' => false,
			'message' => 'Post not found.'
		);
	}

	$blocks = parse_blocks($post->post_content);
	$updated_blocks = array();
	$vote_counted = false;
	$new_vote_count = 0;

	foreach ($blocks as $block) {
		if ($block['blockName'] === 'extrachill/image-voting') {
			$block_instance_id = $block['attrs']['uniqueBlockId'] ?? '';

			if ($block_instance_id === $instance_id) {
				if (!isset($block['attrs']['voteCount'])) {
					$block['attrs']['voteCount'] = 0;
				}
				if (!isset($block['attrs']['voters'])) {
					$block['attrs']['voters'] = array();
				}

				$has_voted = in_array($email_address, $block['attrs']['voters']);

				if ($has_voted) {
					return array(
						'success' => false,
						'message' => 'You have already voted for this item.',
						'code' => 'already_voted'
					);
				}

				// Newsletter integration (non-blocking)
				if (function_exists('extrachill_multisite_subscribe')) {
					$subscription_result = extrachill_multisite_subscribe($email_address, 'image_voting');
					if (!$subscription_result['success']) {
						error_log(sprintf(
							'Image voting newsletter subscription failed for %s: %s',
							$email_address,
							$subscription_result['message']
						));
					}
				}

				$block['attrs']['voteCount']++;
				$block['attrs']['voters'][] = $email_address;
				$vote_counted = true;
				$new_vote_count = $block['attrs']['voteCount'];
			}
		}
		$updated_blocks[] = $block;
	}

	if (!$vote_counted) {
		return array(
			'success' => false,
			'message' => 'Block not found or vote not processed.'
		);
	}

	$updated_content = serialize_blocks($updated_blocks);

	$result = wp_update_post(array(
		'ID' => $post_id,
		'post_content' => $updated_content
	), true);

	if (is_wp_error($result)) {
		return array(
			'success' => false,
			'message' => 'Failed to save vote.'
		);
	}

	return array(
		'success' => true,
		'message' => 'Vote counted successfully.',
		'vote_count' => $new_vote_count
	);
}
