<?php
function huberpress_quick_post_form() {
    if (!is_user_logged_in()) {
        error_log("Quick post: User is not logged in.");
        return;
    }

    // Initialize errors array
    $errors = array();

    // Start output buffering to prevent header issues
    ob_start();

    // Always show the form (with possible error messages)
    echo '<div id="quick-post-container">';
    echo '<button id="quick-post-toggle">Quick Post</button>';

    echo '<div id="quick-post-form" style="display:none;">';
    echo '<form id="quick-post-topic" name="quick-post-topic" method="post">';
    
    // Include an element to display form errors
    echo '<div id="form-errors" style="color: red;"></div>';

    bbp_topic_form_fields(); // This includes the nonce fields

    // Add nonce field for security
    wp_nonce_field('quick_post_form', 'quick_post_form_nonce');
    
    // Add hidden field to identify quick post form
    echo '<input type="hidden" name="is_quick_post" value="1">';

    // Display errors or set value if the form was submitted
    $content_value = isset($_POST['bbp_topic_content']) ? esc_textarea($_POST['bbp_topic_content']) : '';

    // Content field with error message
    echo '<p><label for="bbp_topic_content">Content:</label><br />';
    wp_editor($content_value, 'bbp_topic_content', array('textarea_name' => 'bbp_topic_content')); // Use wp_editor for TinyMCE
    if (!empty($errors['bbp_topic_content'])) {
        echo '<span class="error" style="color: red;">' . esc_html($errors['bbp_topic_content']) . '</span>';
        error_log("Quick post: Content error - " . $errors['bbp_topic_content']);
    }
    echo '</p>';

    // Forum dropdown with error message
    echo '<p><label for="bbp_forum_id">Forum:</label><br />';
    huberpress_custom_forum_dropdown();
    if (!empty($errors['bbp_forum_id'])) {
        echo '<span class="error" style="color: red;">' . esc_html($errors['bbp_forum_id']) . '</span>';
        error_log("Quick post: Forum error - " . $errors['bbp_forum_id']);
    }
    echo '</p>';

    echo '<p><input type="submit" id="bbp_topic_submit" name="bbp_topic_submit" value="Submit"></p>';
    echo '</form></div></div>';

    // Flush the output buffer
    ob_end_flush();
}

function huberpress_handle_quick_post_form_submission() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_post_form_nonce']) && wp_verify_nonce($_POST['quick_post_form_nonce'], 'quick_post_form') && isset($_POST['is_quick_post']) && $_POST['is_quick_post'] == '1') {
        $errors = array();
        error_log("Handling quick post form submission.");

        // Validate content
        if (empty($_POST['bbp_topic_content'])) {
            $errors['bbp_topic_content'] = 'Please enter some content.';
            error_log("Content validation failed: Content is empty.");
        }

        // Validate forum selection
        if (empty($_POST['bbp_forum_id']) || $_POST['bbp_forum_id'] === '0') {
            $errors['bbp_forum_id'] = 'Please select a forum.';
            error_log("Forum validation failed: No forum selected.");
        }

        if (empty($errors)) {
            $user_id = get_current_user_id();
            $forum_id = intval($_POST['bbp_forum_id']);
            $content = wp_kses_post($_POST['bbp_topic_content']);

            error_log("Creating a new reply: user_id = $user_id, forum_id = $forum_id");

            // Create a new reply
            $reply_id = bbp_insert_reply(array(
                'post_parent'   => $forum_id,
                'post_content'  => $content,
                'post_author'   => $user_id,
                'post_status'   => bbp_get_public_status_id()
            ));

            if (!is_wp_error($reply_id)) {
                // Success, redirect to the reply within the context of the topic
                $reply_url = bbp_get_reply_url($reply_id);
                error_log("Reply created successfully: reply_id = $reply_id, redirecting to $reply_url");
                wp_safe_redirect($reply_url);
                exit;
            } else {
                $errors['submit'] = 'There was an error submitting your reply. Please try again.';
                error_log("Reply creation failed: " . $reply_id->get_error_message());
            }
        }

        return $errors;
    }
}

function huberpress_force_enqueue_quick_post_script() {
    // Conditionally enqueue script only on the homepage or user-dashboard page.
    if (is_front_page() || is_home() || is_page('user-dashboard')) {
        wp_enqueue_script('huberpress-quick-post-js', get_stylesheet_directory_uri() . '/js/quick-post.js', array('jquery'), null, true);
        wp_enqueue_script('jquery'); // Ensure jQuery is loaded
        error_log("Quick post: Script enqueued.");
    } else {
        error_log("Quick post: Script not enqueued - not on homepage or user-dashboard.");
    }
}
add_action('wp_enqueue_scripts', 'huberpress_force_enqueue_quick_post_script');

function huberpress_custom_forum_dropdown() {
    $user_id = get_current_user_id();
    $artist_boards = get_independent_artist_boards($user_id);

    echo '<select id="bbp_forum_id" name="bbp_forum_id">';
    echo '<option value="">Select Forum</option>';

    // Include user's independent artist boards
    if (!empty($artist_boards)) {
        echo '<optgroup label="Your Independent Artist Spaces">';
        foreach ($artist_boards as $board_id) {
            echo '<option value="' . $board_id . '">' . get_the_title($board_id) . '</option>';
        }
        echo '</optgroup>';
    }

    // Include specific topics for replies
    $specific_topics = [5922, 4977, 5395, 781, 5883];
    if (!empty($specific_topics)) {
        echo '<optgroup label="Specific Topics">';
        foreach ($specific_topics as $topic_id) {
            echo '<option value="' . $topic_id . '">' . get_the_title($topic_id) . '</option>';
        }
        echo '</optgroup>';
    }

    echo '</select>';
    error_log("Quick post: Forum dropdown generated for user_id = $user_id.");
}

// Use the appropriate hooks to add the quick post form where needed
add_action('bbp_template_before_forums_loop', 'huberpress_quick_post_form');
add_action('chill_before_user_dashboard', 'huberpress_quick_post_form');

// Use template_redirect hook to handle form submission before the template is loaded
add_action('template_redirect', 'huberpress_handle_quick_post_form_submission');
