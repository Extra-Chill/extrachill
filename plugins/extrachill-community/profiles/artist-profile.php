<?php
// Artist Profiles Management Page

// Check if the user already has an artist profile
$current_user = wp_get_current_user();
$existing_post_id = wp_surgeon_has_profile_post($current_user->ID, 'artist_profile');

// Fetch the existing profile if it exists
$artist_profile = $existing_post_id ? get_post($existing_post_id) : null;
$title = $artist_profile ? $artist_profile->post_title : '';
$content = $artist_profile ? $artist_profile->post_content : '';

?>
<?php
// Check if the correct template is being used
if (is_page_template('page-templates/create-profiles-template.php') || is_page_template('page-templates/edit-profiles-template.php')) {
    ?>
<div id="artist-profile-form-container">
    <p>Note: This functionality is under construction and will be evolving in the coming months.</p>
    <form id="artist-profile-form" method="post">
        <label for="name">Name:</label>
        <input type="text" id="name" name="name" value="<?php echo esc_attr($title); ?>" required><br>
        <label for="bio">Bio:</label>
        <textarea id="bio" name="bio"><?php echo esc_textarea($content); ?></textarea><br>
        <input type="hidden" name="post_id" value="<?php echo esc_attr($existing_post_id); ?>">
        <?php wp_nonce_field('wp_rest', '_wpnonce', true, true); ?>
        <input type="submit" value="<?php echo $existing_post_id ? 'Update' : 'Create'; ?> Profile">
    </form>
    <div id="form-success-message"></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('artist-profile-form');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var apiRoute = '<?php echo esc_url(rest_url('extrachill/v1/manage_artist_profile')); ?>';

        fetch(apiRoute, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': formData.get('_wpnonce')
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.post_id) {
                document.getElementById('form-success-message').innerHTML = data.message + ' <a href="' + data.profile_url + '">View profile.</a>';
            } else {
                document.getElementById('form-success-message').innerHTML = 'Error: ' + data.message;
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    });
});
</script>
    <?php
}
?>
<?php
// Register the REST API route for managing artist profiles
add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/manage_artist_profile', array(
        'methods' => 'POST',
        'callback' => 'handle_manage_artist_profile_request',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ));
});
if (!function_exists('handle_manage_artist_profile_request')) {
function handle_manage_artist_profile_request(WP_REST_Request $request) {
    if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
        return new WP_Error('rest_nonce_verification_failed', esc_html__('Nonce verification failed.', 'extrachill'), array('status' => 403));
    }

    if (!is_user_logged_in()) {
        return new WP_Error('rest_forbidden', esc_html__('You are not authorized to manage profiles.', 'extrachill'), array('status' => 401));
    }

    // Extract data and profile type from the request
    $name = sanitize_text_field($request->get_param('name'));
    $bio = sanitize_textarea_field($request->get_param('bio'));
    $post_id = sanitize_text_field($request->get_param('post_id'));

    $current_user = wp_get_current_user();
    $is_update = !empty($post_id);

    // Check if the user already has an artist profile
    $existing_post_id = wp_surgeon_has_profile_post($current_user->ID, 'artist_profile');

    // Prepare the post data
    $post_data = array(
        'post_type'    => 'artist_profile',
        'post_title'   => $name,
        'post_content' => $bio,
        'post_status'  => 'publish',
        'post_author'  => $current_user->ID,
    );

    if ($existing_post_id) {
        // Update existing post if it exists
        $post_data['ID'] = $existing_post_id;
        $result_post_id = wp_update_post($post_data, true);
    } else {
        // Create new post
        $result_post_id = wp_insert_post($post_data, true);
    }

    // Check for errors
    if (is_wp_error($result_post_id)) {
        $error_message = $result_post_id->get_error_message();
        return new WP_Error('rest_post_failed', $error_message, array('status' => 500));
    }

    // Generate the profile URL
    $profile_url = home_url('?p=' . $result_post_id);
    $success_message = $is_update ? 'Artist profile updated successfully' : 'Artist profile created successfully';

    // Return success response
    return new WP_REST_Response(['message' => $success_message, 'post_id' => $result_post_id, 'profile_url' => $profile_url], 200);

}
}
?>
