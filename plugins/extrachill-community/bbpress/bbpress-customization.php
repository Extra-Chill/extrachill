<?php
// assorted customization functions for bbpress

/*Disable bbPress default search bar */
add_filter('bbp_allow_search', '__return_false');



function wp_surgeon_generate_profile_link($user_id, $profile_type) {
    $post_id = wp_surgeon_has_profile_post($user_id, $profile_type);

    // Format the profile type for display
    $formatted_profile_type = ucwords(str_replace('_', ' ', $profile_type)); // Replaces underscores with spaces and capitalizes words

    if ($post_id) {
        $link_text = 'Edit ' . $formatted_profile_type . ' Profile';
        $link_url = "/edit-profile?post_id={$post_id}";
    } else {
        $link_text = 'Create ' . $formatted_profile_type . ' Profile';
        $link_url = "/create-profile/?profile_type={$profile_type}";
    }

    return '<a href="' . home_url($link_url) . '">' . esc_html($link_text) . '</a>';
}

add_action('show_user_profile', 'wp_surgeon_add_profile_edit_links');
add_action('edit_user_profile', 'wp_surgeon_add_profile_edit_links');


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


function bbp_add_custom_forum_description_meta_box() {
    add_meta_box(
        'bbp_custom_description',            // ID of the meta box
        'Custom Forum Description',          // Title of the meta box
        'bbp_custom_forum_description_callback', // Callback function
        'forum',                             // Post type
        'normal',                            // Context
        'high'                               // Priority
    );
}
add_action('add_meta_boxes', 'bbp_add_custom_forum_description_meta_box');

function bbp_custom_forum_description_callback($post) {
    // Add a nonce field for security
    wp_nonce_field('bbp_save_custom_description', 'bbp_custom_description_nonce');

    // Get existing value
    $value = get_post_meta($post->ID, '_bbp_custom_description', true);

    // Display the form, using the current value.
    echo '<textarea style="width:100%;" rows="5" name="bbp_custom_description">' . esc_textarea($value) . '</textarea>';
}

function bbp_save_custom_forum_description($post_id) {
    // Check for nonce for security
    if (!isset($_POST['bbp_custom_description_nonce']) || !wp_verify_nonce($_POST['bbp_custom_description_nonce'], 'bbp_save_custom_description')) {
        return;
    }

    // Check if the current user has permission to edit the post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Update the meta field in the database.
    if (isset($_POST['bbp_custom_description'])) {
        update_post_meta($post_id, '_bbp_custom_description', sanitize_text_field($_POST['bbp_custom_description']));
    }
}
add_action('save_post', 'bbp_save_custom_forum_description');


function custom_bbp_single_forum_description($description, $args, $unused) {
    // Get the custom description
    $custom_description = get_post_meta(bbp_get_forum_id(), '_bbp_custom_description', true);

    // Prepend the custom description to the existing one
    if (!empty($custom_description)) {
        $description = "<div class='bbp-custom-description'><p>" . esc_html($custom_description) . "</p></div>" . $description;
    }

    return $description;
}
add_filter('bbp_get_single_forum_description', 'custom_bbp_single_forum_description', 10, 3);

function override_bbp_user_forum_role( $role, $user_id ) {
    // Get the custom title if it exists
    $custom_title = get_user_meta( $user_id, 'ec_custom_title', true );

    // Return custom title if it's set, otherwise return the default role
    return !empty( $custom_title ) ? $custom_title : $role;
}
add_filter( 'bbp_get_user_display_role', 'override_bbp_user_forum_role', 10, 2 );

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

        // Initialize message
        $message = "A new update has been posted for the Extra Chill Team. The link is below, but remember you must sign in to view. \n\n";

        if (bbp_is_topic($post_id)) {
            // It's a topic
            $topic = get_post($post_id);
            $permalink = get_permalink($post_id);
            $message .= "Topic: " . $topic->post_title . "\n\n";
            $message .= wp_trim_words($topic->post_content, 50, '...') . "\n\n";
        } elseif (bbp_is_reply($post_id)) {
            // It's a reply
            $reply = get_post($post_id);
            $topic_permalink = get_permalink($topic_id);
            $permalink = $topic_permalink . '#post-' . $post_id;
            $message .= "Reply in topic: " . get_the_title($topic_id) . "\n\n";
            $message .= wp_trim_words($reply->post_content, 50, '...') . "\n\n";
        }

        $message .= "Link to Full Update: $permalink\n\n";

        // Email headers
        $headers = array('Content-Type: text/plain; charset=UTF-8', 'From: Extra Chill <no-reply@community.extrachill.com>');

        foreach ($team_members as $member) {
            wp_mail($member->user_email, $subject, $message, $headers);
        }
    }
}

function record_user_activity() {
    $user_id = get_current_user_id();
    if ($user_id) {
        // Update last_active for logged-in users
        $current_time = current_time('timestamp');
        update_user_meta($user_id, 'last_active', $current_time);
    }

    // Fetch the current online user count
    $current_online = get_online_users_count();

    // Retrieve or initialize the most ever online record
    $most_ever_online = get_option('most_ever_online', ['count' => 0, 'date' => '']);

    // Correcting type casting to ensure proper comparison
    $most_ever_online_count = intval($most_ever_online['count']);

    if ($current_online > $most_ever_online_count) {
        // Update only if the current online count exceeds the "Most Ever Online"
        $most_ever_online = [
            'count' => $current_online,
            // Adjusted the date format here
            'date' => current_time('m/d/Y')
        ];
        update_option('most_ever_online', $most_ever_online);
    }
    // Eliminated unnecessary else clause to simplify logic
}
add_action('wp', 'record_user_activity');

function get_online_users_count() {
    global $wpdb;
    $time_limit = 15 * MINUTE_IN_SECONDS; // 15 minutes ago
    $time_threshold = current_time('timestamp') - $time_limit;

    $users = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key = 'last_active' AND meta_value > %d",
        $time_threshold
    ));

    return intval($users);
}

function display_online_users_stats() {
    $online_users = get_online_users_count();
    $most_ever_online = get_option('most_ever_online', ['count' => 0, 'date' => '']);

    // Ensure we have the 'count' as an integer and 'date' as a string
    $most_ever_online_count = isset($most_ever_online['count']) ? (int)$most_ever_online['count'] : 0;
    $most_ever_online_date = isset($most_ever_online['date']) ? date('m/d/Y', strtotime($most_ever_online['date'])) : 'N/A';

    // Use WP_User_Query to get a total count of users
  $user_query = new WP_User_Query(['count_total' => true, 'fields' => 'ID']);;
    $total_members = $user_query->get_total();

    echo "<div class='online-stats'>";
    echo "<p><span class='label'>Users Currently Online:</span> <span class='count'>" . $online_users . "</span></p>";
    echo "<p><span class='label'>Most Ever Online:</span> <span class='count'>" . $most_ever_online_count . "</span> on <span class='date'>" . $most_ever_online_date . "</span></p>";
    // Add Total Members to the output
    echo "<p><span class='label'>Total Members:</span> <span class='count'>" . $total_members . "</span></p>";
    echo "</div>";
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



