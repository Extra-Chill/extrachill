<?php
function display_user_status_notices($user_id) {
    $artist_pending = get_user_meta($user_id, 'user_is_artist_pending', true) == '1';
    $artist = get_user_meta($user_id, 'user_is_artist', true) == '1'; // Verified artist

    $professional_pending = get_user_meta($user_id, 'user_is_professional_pending', true) == '1';
    $professional = get_user_meta($user_id, 'user_is_professional', true) == '1'; // Verified professional

    if ($artist_pending && !$artist) {
        echo '<div class="status-notice">Artist status pending admin verification.</div>';
    }

    if ($professional_pending && !$professional) {
        echo '<div class="status-notice">Industry Professional status pending admin verification.</div>';
    }
}

function wp_surgeon_add_user_role_fields($user) {
    $is_admin = current_user_can('administrator');
    $artist_pending = get_user_meta($user->ID, 'user_is_artist_pending', true) == '1';
    $artist = get_user_meta($user->ID, 'user_is_artist', true) == '1';
    $professional_pending = get_user_meta($user->ID, 'user_is_professional_pending', true) == '1';
    $professional = get_user_meta($user->ID, 'user_is_professional', true) == '1';

    ?>
    <div class="hideme">
        <h3><?php _e("Extra User Information", "wp_surgeon"); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="user_is_artist_pending"><?php _e("Artist (Pending)"); ?></label></th>
                <td>
                    <?php if ($is_admin): ?>
                        <input type="checkbox" name="user_is_artist_pending" id="user_is_artist_pending" <?php checked($artist_pending, true); ?> value="1">
                        <label for="user_is_artist"><?php _e("Verified"); ?></label>
                        <input type="checkbox" name="user_is_artist" id="user_is_artist" <?php checked($artist, true); ?> value="1">
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="user_is_professional_pending"><?php _e("Industry Professional (Pending)"); ?></label></th>
                <td>
                    <?php if ($is_admin): ?>
                        <input type="checkbox" name="user_is_professional_pending" id="user_is_professional_pending" <?php checked($professional_pending, true); ?> value="1">
                        <label for="user_is_professional"><?php _e("Verified"); ?></label>
                        <input type="checkbox" name="user_is_professional" id="user_is_professional" <?php checked($professional, true); ?> value="1">
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
    <?php
}

add_action('show_user_profile', 'wp_surgeon_add_user_role_fields');
add_action('edit_user_profile', 'wp_surgeon_add_user_role_fields');

function wp_surgeon_save_user_meta($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    if (current_user_can('administrator')) {
        // Update the meta only if the checkbox has been explicitly included in the form submission.
        update_user_meta($user_id, 'user_is_artist_pending', isset($_POST['user_is_artist_pending']) ? 1 : 0);
        update_user_meta($user_id, 'user_is_artist', isset($_POST['user_is_artist']) ? 1 : 0);
        update_user_meta($user_id, 'user_is_professional_pending', isset($_POST['user_is_professional_pending']) ? 1 : 0);
        update_user_meta($user_id, 'user_is_professional', isset($_POST['user_is_professional']) ? 1 : 0);
    }
    // No action needed for non-admins since the meta values should already be preserved by the hidden inputs
}

add_action('personal_options_update', 'wp_surgeon_save_user_meta');
add_action('edit_user_profile_update', 'wp_surgeon_save_user_meta');



function wp_surgeon_add_verification_tracker_widget() {
    wp_add_dashboard_widget(
        'wp_surgeon_verification_tracker_widget',         // Widget slug.
        'Verification Tracker',                           // Title.
        'wp_surgeon_verification_tracker_widget'          // Display function.
    );
}
add_action('wp_dashboard_setup', 'wp_surgeon_add_verification_tracker_widget');

function wp_surgeon_verification_tracker_widget() {
    // Initial query to fetch users with any pending status
    $args = [
        'meta_query' => [
            'relation' => 'OR', // Fetch users with either artist or professional pending status
            [
                'key'     => 'user_is_artist_pending',
                'value'   => '1',
                'compare' => '='
            ],
            [
                'key'     => 'user_is_professional_pending',
                'value'   => '1',
                'compare' => '='
            ]
        ],
        'fields' => 'ID', // Only need the user IDs
    ];

    $users_query = new WP_User_Query($args);
    $pending_users = $users_query->get_results();

    // Initialize the array for users needing verification
    $users_needing_verification = [];

    foreach ($pending_users as $user_id) {
        $is_artist_pending = get_user_meta($user_id, 'user_is_artist_pending', true) == '1';
        $is_artist = get_user_meta($user_id, 'user_is_artist', true) == '1';
        $is_professional_pending = get_user_meta($user_id, 'user_is_professional_pending', true) == '1';
        $is_professional = get_user_meta($user_id, 'user_is_professional', true) == '1';

        // Logic to determine if the user still needs verification
        if (
            ($is_artist_pending && !$is_artist) || // Artist pending and not verified
            ($is_professional_pending && !$is_professional) // Professional pending and not verified
        ) {
            // Add to array if any of the conditions are met
            $users_needing_verification[] = $user_id;
        }
    }

    echo '<p>Total users needing verification: ' . count($users_needing_verification) . '</p>';
    echo '<ul>';
    foreach ($users_needing_verification as $user_id) {
        echo '<li><a href="' . get_edit_user_link($user_id) . '">Edit Profile for User ID: ' . $user_id . '</a></li>';
    }
    echo '</ul>';
}

function handle_status_request_ajax() {
    $user_id = get_current_user_id();
    $type = $_POST['type'];
    if ($type == 'artist' || $type == 'professional') {
        $pending_meta_key = 'user_is_' . $type . '_pending';
        $already_pending = get_user_meta($user_id, $pending_meta_key, true);

        if ($already_pending != '1') { // Check if not already pending
            update_user_meta($user_id, $pending_meta_key, '1'); // Set status to pending

            // Send an email notification to the admin
            $admin_email = get_option('admin_email');
            $subject = "New {$type} Status Request";
            $message = "A user has requested {$type} status and is pending verification.\n\nUser ID: {$user_id}";
            wp_mail($admin_email, $subject, $message);

            wp_send_json_success(); // Send success to JavaScript
        } else {
            wp_send_json_error(['message' => "{$type} status request is already pending."]);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid request']);
    }
}

add_action('wp_ajax_request_status_change', 'handle_status_request_ajax');


