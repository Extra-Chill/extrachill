<?php
/**
 * Image Voting Block initialization
 * REST endpoint handled by extrachill-api plugin: /wp-json/extrachill/v1/image-voting/vote-count/{post_id}/{instance_id}
 * AJAX action: extrachill_blocks_image_vote
 * Newsletter integration: extrachill_multisite_subscribe() bridge function
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_extrachill_blocks_image_vote', 'extrachill_blocks_handle_image_vote');
add_action('wp_ajax_nopriv_extrachill_blocks_image_vote', 'extrachill_blocks_handle_image_vote');

function extrachill_blocks_handle_image_vote() {
    if (!check_ajax_referer('extrachill_blocks_vote_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    $post_id = absint($_POST['post_id']);
    $instance_id = sanitize_text_field($_POST['instance_id']);
    $email_address = sanitize_email($_POST['email_address']);

    if (!$post_id || empty($instance_id) || empty($email_address)) {
        wp_send_json_error(array('message' => 'Invalid data.'));
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(array('message' => 'Post not found.'));
    }

    $blocks = parse_blocks($post->post_content);
    $updated_blocks = array();
    $vote_counted = false;
    $new_vote_count = 0;

    foreach ($blocks as $block) {
        if ($block['blockName'] === 'extrachill-blocks/image-voting') {
            $block_instance_id = $block['attrs']['uniqueBlockId'];

            if ($block_instance_id === $instance_id) {
                if (!isset($block['attrs']['voteCount'])) {
                    $block['attrs']['voteCount'] = 0;
                }
                if (!isset($block['attrs']['voters'])) {
                    $block['attrs']['voters'] = array();
                }

                $has_voted = in_array($email_address, $block['attrs']['voters']);

                if ($has_voted) {
                    wp_send_json_error(array(
                        'message' => 'You have already voted for this item.',
                        'code' => 'already_voted'
                    ));
                }

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
        wp_send_json_error(array('message' => 'Block not found or vote not processed.'));
    }

    $updated_content = serialize_blocks($updated_blocks);

    $result = wp_update_post(array(
        'ID' => $post_id,
        'post_content' => $updated_content
    ), true);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => 'Failed to save vote.'));
    }

    wp_send_json_success(array(
        'message' => 'Vote counted successfully.',
        'vote_count' => $new_vote_count
    ));
}

add_action('wp_enqueue_scripts', 'extrachill_blocks_image_voting_localize_script', 20);

/**
 * Localize AJAX data for frontend JavaScript
 * Priority 20 ensures this runs after WordPress auto-enqueues viewScript from block.json
 */
function extrachill_blocks_image_voting_localize_script() {
    if (has_block('extrachill-blocks/image-voting')) {
        wp_localize_script(
            'extrachill-blocks-image-voting-view-script',
            'extraChillBlocksImageVoting',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('extrachill_blocks_vote_nonce')
            )
        );
    }
}