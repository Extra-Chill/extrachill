<?php
// assorted customization functions for bbpress

/*Disable bbPress default search bar */
add_filter('bbp_allow_search', '__return_false');

// Utility function to get the edit profile URL
function wp_surgeon_get_edit_profile_url($user_id, $profile_type) {
    // This function should return the URL for editing the specified profile type.
    return home_url("/edit-profile/?profile_type={$profile_type}&user_id={$user_id}");
}


// Function to add a dropdown to the forum edit page
function add_section_dropdown_to_forum_edit() {
    $value = get_post_meta(get_the_ID(), '_bbp_forum_section', true);
    ?>
    <p>
        <label for="bbp_forum_section"><?php _e('Section', 'bbpress'); ?></label>
        <select name="_bbp_forum_section" id="bbp_forum_section">
            <option value="top" <?php selected($value, 'top'); ?>>Top</option>
            <option value="middle" <?php selected($value, 'middle'); ?>>Middle</option>
            <option value="bottom" <?php selected($value, 'bottom'); ?>>Bottom</option>
            <!-- Added Private option -->
            <option value="private" <?php selected($value, 'private'); ?>>Private</option>
        </select>
    </p>
    <?php
}
add_action('bbp_forum_metabox', 'add_section_dropdown_to_forum_edit');

// Function to save the dropdown selection
function save_forum_section( $forum_id ) {
    if (isset($_POST['_bbp_forum_section'])) {
        update_post_meta($forum_id, '_bbp_forum_section', sanitize_text_field($_POST['_bbp_forum_section']));
    }
}
add_action('bbp_forum_attributes_metabox_save', 'save_forum_section');

// Remove topic tags in bbPress
function remove_bbpress_topic_tags() {
    return false;
}

add_filter('bbp_allow_topic_tags', 'remove_bbpress_topic_tags');


// Load the function after bbPress is fully loaded
add_action( 'after_setup_theme', 'override_bbp_user_role_after_bbp_load' );

function override_bbp_user_role_after_bbp_load() {
    // Hook into bbPress filter after it's available
    add_filter( 'bbp_get_user_display_role', 'override_bbp_user_forum_role', 10, 2 );
}

function override_bbp_user_forum_role( $role, $user_id ) {
    // Ensure bbPress functions are available
    if ( function_exists( 'bbp_is_user_keymaster' ) && function_exists( 'bbp_get_user_display_role' ) ) {

        // Get the custom title if it exists
        $custom_title = get_user_meta( $user_id, 'ec_custom_title', true );

        // Return custom title if set, otherwise return "Extra Chillian" for regular users
        return ! empty( $custom_title ) ? $custom_title : 'Extra Chillian';
    }

    // Fallback if bbPress is not loaded properly
    return $role;
}



function save_ec_custom_title( $user_id ) {
    if ( isset( $_POST['ec_custom_title'] ) ) {
        update_user_meta( $user_id, 'ec_custom_title', sanitize_text_field( $_POST['ec_custom_title'] ) );
    }
}
add_action( 'personal_options_update', 'save_ec_custom_title' );
add_action( 'edit_user_profile_update', 'save_ec_custom_title' );

// Hook into BBPress forms to add the checkbox
function wp_surgeon_add_notify_team_checkbox() {
    // Check if the current user is an admin and if the forum requires the Extra Chill Team
    $forum_id = bbp_get_forum_id();
    $require_extrachill_team = get_post_meta($forum_id, '_require_extrachill_team', true) == '1';

    if (current_user_can('administrator') && $require_extrachill_team) {
        echo '<p>';
        echo '<input type="checkbox" name="notify_extrachill_team" id="notify_extrachill_team" value="1" />';
        echo '<label for="notify_extrachill_team">Notify Team</label>';
        echo '</p>';
    }
}
add_action('bbp_theme_before_topic_form_submit_wrapper', 'wp_surgeon_add_notify_team_checkbox');
add_action('bbp_theme_before_reply_form_submit_wrapper', 'wp_surgeon_add_notify_team_checkbox');

// Hook into topic and reply creation
add_action('bbp_new_topic', 'wp_surgeon_notify_team_on_new_post', 10, 4);
add_action('bbp_new_reply', 'wp_surgeon_notify_team_on_new_post', 10, 7);

function wp_surgeon_notify_team_on_new_post($post_id, $forum_id, $anonymous_data, $topic_id = 0) {
    if (isset($_POST['notify_extrachill_team']) && $_POST['notify_extrachill_team'] == '1') {
        $team_members = get_users(array('meta_key' => 'extrachill_team', 'meta_value' => '1'));

        // Consistent subject for all notifications
        $subject = 'Extra Chill - New Update';

        // Initialize message with HTML formatting
        $message = "<html><body>";
        $message .= "<p>A new update has been posted for the Extra Chill Team. The link is below, but remember you must sign in to view.</p>";

        if (bbp_is_topic($post_id)) {
            // It's a topic
            $topic = get_post($post_id);
            $permalink = get_permalink($post_id);
            $message .= "<p><strong>Topic:</strong> " . $topic->post_title . "</p>";
            $message .= wpautop($topic->post_content); // Automatic paragraph tags for the content
        } elseif (bbp_is_reply($post_id)) {
            // It's a reply
            $reply = get_post($post_id);
            $topic_permalink = get_permalink($topic_id);
            $permalink = $topic_permalink . '#post-' . $post_id;
            $message .= "<p><strong>Reply in topic:</strong> " . get_the_title($topic_id) . "</p>";
            $message .= wpautop($reply->post_content); // Automatic paragraph tags for the content
        }

        $message .= "<p>Link to Full Update: <a href='" . $permalink . "'>" . $permalink . "</a></p>";
        $message .= "</body></html>";

        // HTML email headers
        $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Chris Huber <chubes@community.extrachill.com>');

        foreach ($team_members as $member) {
            wp_mail($member->user_email, $subject, $message, $headers);
        }
    }
}


/*function wp_surgeon_bbp_form_posting_tips() {
    $posting_tips = "<a href='/posting-guidelines'>See posting guidelines</a>";
    echo "<div class='bbp-form-posting-tips'>{$posting_tips}</div>";
}

// Add the tips after the topic form content
add_action('bbp_theme_after_topic_form_content', 'wp_surgeon_bbp_form_posting_tips');

// Add the tips after the reply form content
add_action('bbp_theme_after_reply_form_content', 'wp_surgeon_bbp_form_posting_tips');
*/


// Add a filter to display custom message below edit form in bbPress
add_action('bbp_theme_after_topic_form', 'custom_message_below_edit_form');

function custom_message_below_edit_form() {
    // Get the current post ID
    $post_id = get_the_ID();
    
    // Check if the current post being viewed is post 138
    if (is_bbpress() && $post_id == 138) {
        // Display the conditional message below the edit form
        echo '<p>Are you an artist submitting your own music? See our <a href="/new-music-submission-guidelines">Music Submission Guidelines</a>.</p>';
    }
}

function remove_counts() {
    $args['show_topic_count'] = false;
    $args['show_reply_count'] = false;
    $args['count_sep'] = '';
return $args;
}
add_filter('bbp_before_list_forums_parse_args', 'remove_counts' );


// Remove reply and edit links from admin links
function ec_remove_reply_and_edit_from_admin_links( $links, $reply_id ) {
    if ( isset( $links['reply'] ) ) {
        unset( $links['reply'] ); // Remove Reply
    }
    if ( isset( $links['edit'] ) ) {
        unset( $links['edit'] ); // Remove Edit
    }
    return $links;
}
add_filter( 'bbp_reply_admin_links', 'ec_remove_reply_and_edit_from_admin_links', 10, 2 );
add_filter( 'bbp_topic_admin_links', 'ec_remove_reply_and_edit_from_admin_links', 10, 2 );


/**
 * Get a human-readable timestamp for a topic's last active time.
 *
 * @param int $topic_id The topic ID.
 * @return string Human-readable time difference (e.g., "5 minutes ago").
 */
function ec_get_topic_last_active_diff( $topic_id ) {
    // Get the last active time as stored by bbPress.
    $last_active_time = bbp_get_topic_last_active_time( $topic_id );
    if ( ! empty( $last_active_time ) ) {
        // Convert it to a timestamp.
        $timestamp = strtotime( $last_active_time );
        // Calculate the time difference from now.
        return human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ago';
    }
    return '';
}
