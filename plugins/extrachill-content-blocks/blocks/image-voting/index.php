<?php
/**
 * Image Voting Block initialization
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Register REST API endpoint for vote count
add_action('rest_api_init', 'extrachill_blocks_image_voting_register_rest_routes');

/**
 * Register REST API routes
 */
function extrachill_blocks_image_voting_register_rest_routes() {
    register_rest_route('extrachill-blocks/v1', '/vote-count/(?P<post_id>\d+)/(?P<instance_id>[a-zA-Z0-9\-]+)', array(
        'methods' => 'GET',
        'callback' => 'extrachill_blocks_get_vote_count',
        'permission_callback' => '__return_true',
        'args' => array(
            'post_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
            'instance_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_string($param);
                }
            ),
        ),
    ));
}

/**
 * Get vote count via REST API
 */
function extrachill_blocks_get_vote_count($data) {
    $post_id = absint($data['post_id']);
    $instance_id = sanitize_text_field($data['instance_id']);

    if (!$post_id) {
        return new WP_Error('invalid_post', 'Invalid post ID', array('status' => 400));
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
    }

    $blocks = parse_blocks($post->post_content);
    $vote_count = 0;

    // Find the matching block instance
    foreach ($blocks as $block) {
        if ($block['blockName'] === 'extrachill-blocks/image-voting') {
            $block_instance_id = 'block-' . $post_id . '-' . substr(md5(serialize($block['attrs'])), 0, 8);
            if ($block_instance_id === $instance_id) {
                $vote_count = isset($block['attrs']['voteCount']) ? (int) $block['attrs']['voteCount'] : 0;
                break;
            }
        }
    }

    return rest_ensure_response(array('vote_count' => $vote_count));
}

// Add AJAX handlers for voting
add_action('wp_ajax_extrachill_blocks_image_vote', 'extrachill_blocks_handle_image_vote');
add_action('wp_ajax_nopriv_extrachill_blocks_image_vote', 'extrachill_blocks_handle_image_vote');

/**
 * Handle image vote AJAX request
 */
function extrachill_blocks_handle_image_vote() {
    // Verify nonce for security
    if (!check_ajax_referer('extrachill_blocks_vote_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    // Sanitize and retrieve data from the request
    $post_id = absint($_POST['post_id']);
    $instance_id = sanitize_text_field($_POST['instance_id']);
    $email_address = sanitize_email($_POST['email_address']);

    if (!$post_id || empty($instance_id) || empty($email_address)) {
        wp_send_json_error(array('message' => 'Invalid data.'));
    }

    // Get the post
    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(array('message' => 'Post not found.'));
    }

    // Parse blocks and find the matching block instance
    $blocks = parse_blocks($post->post_content);
    $updated_blocks = array();
    $vote_counted = false;
    $new_vote_count = 0;

    foreach ($blocks as $block) {
        if ($block['blockName'] === 'extrachill-blocks/image-voting') {
            $block_instance_id = 'block-' . $post_id . '-' . substr(md5(serialize($block['attrs'])), 0, 8);

            if ($block_instance_id === $instance_id) {
                // Initialize attributes if not set
                if (!isset($block['attrs']['voteCount'])) {
                    $block['attrs']['voteCount'] = 0;
                }
                if (!isset($block['attrs']['voters'])) {
                    $block['attrs']['voters'] = array();
                }

                // Check if user has already voted for THIS specific block
                $has_voted = in_array($email_address, $block['attrs']['voters']);

                if ($has_voted) {
                    wp_send_json_error(array(
                        'message' => 'You have already voted for this item.',
                        'code' => 'already_voted'
                    ));
                }

                // Attempt newsletter subscription (before counting vote)
                if (function_exists('extrachill_multisite_subscribe')) {
                    $subscription_result = extrachill_multisite_subscribe($email_address, 'image_voting');
                    if (!$subscription_result['success']) {
                        error_log(sprintf(
                            'Image voting newsletter subscription failed for %s: %s',
                            $email_address,
                            $subscription_result['message']
                        ));
                        // Continue - vote counts even if newsletter subscription fails
                    }
                }

                // Count the vote
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

    // Convert blocks back to post content
    $updated_content = serialize_blocks($updated_blocks);

    // Update the post
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

// Localize frontend script with AJAX data
// Assets are auto-enqueued via block.json viewScript property
add_action('wp_enqueue_scripts', 'extrachill_blocks_image_voting_localize_script', 20);

/**
 * Localize frontend script with AJAX data
 * Priority 20 ensures this runs after WordPress auto-enqueues scripts from block.json
 */
function extrachill_blocks_image_voting_localize_script() {
    if (has_block('extrachill-blocks/image-voting')) {
        // WordPress auto-generates handle: extrachill-blocks-image-voting-view-script-0
        // for the first viewScript file (frontend.js)
        wp_localize_script(
            'extrachill-blocks-image-voting-view-script-0',
            'extraChillBlocksImageVoting',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('extrachill_blocks_vote_nonce')
            )
        );
    }
}