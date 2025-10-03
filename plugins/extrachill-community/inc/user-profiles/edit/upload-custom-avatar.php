<?php
/**
 * Custom Avatar Upload System - Edit Profile
 *
 * Handles avatar upload functionality for user profile editing.
 * AJAX upload processing, form rendering, and script enqueuing.
 *
 * @package ExtraChillCommunity
 */

/**
 * AJAX handler for custom avatar upload
 */
add_action('wp_ajax_custom_avatar_upload', 'extrachill_custom_avatar_upload');
function extrachill_custom_avatar_upload() {
    // Ensure file-handling functions exist
    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    // Validate file type
    $uploadedfile = $_FILES['custom_avatar'];
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    $file_type = wp_check_filetype_and_ext($uploadedfile['tmp_name'], $uploadedfile['name']);
    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error(array('message' => 'Error: Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.'));
        return;
    }

    // Handle the file upload
    $upload_overrides = array('test_form' => false);
    $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
        // Create the attachment post
        $attachment = array(
            'guid'           => $movefile['url'],
            'post_mime_type' => $movefile['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($movefile['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        // Insert attachment in the DB
        $attach_id = wp_insert_attachment($attachment, $movefile['file']);

        // Generate metadata using WordPress defaults (thumbnail, medium, large, etc.)
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Save the attachment ID to user meta
        update_user_meta(get_current_user_id(), 'custom_avatar_id', $attach_id);

        // Return success + full URL
        wp_send_json_success(array('url' => wp_get_attachment_url($attach_id)));
    } else {
        wp_send_json_error(array('message' => isset($movefile['error']) ? $movefile['error'] : 'Unknown error'));
    }
}

/**
 * Render avatar upload form field
 * Template helper function for profile edit pages
 */
function extrachill_render_avatar_upload_field() {
    $custom_avatar_id = get_user_meta(get_current_user_id(), 'custom_avatar_id', true);
    ?>
    <div id="avatar-thumbnail">
        <h4>Current Avatar</h4>
        <p>This is the avatar you currently have set. Upload a new image to change it.</p>
        <?php if ($custom_avatar_id && wp_attachment_is_image($custom_avatar_id)): ?>
            <?php
                $thumbnail_src = wp_get_attachment_image_url($custom_avatar_id, 'thumbnail');
                if($thumbnail_src): ?>
            <img src="<?php echo esc_url($thumbnail_src); ?>" alt="Current Avatar" style="max-width: 100px; max-height: 100px;" />
                <?php endif; ?>
        <?php endif; ?>
    </div>
    <label for="custom-avatar-upload"><?php esc_html_e( 'Upload New Avatar', 'bbpress' ); ?></label>
    <input type='file' id='custom-avatar-upload' name='custom_avatar' accept='image/*'>
    <div id="custom-avatar-upload-message"></div>
    <?php
}

/**
 * Enqueue avatar upload assets on profile edit pages
 */
function extrachill_enqueue_avatar_upload_assets() {
    if (!bbp_is_single_user_edit()) {
        return;
    }

    wp_enqueue_script(
        'extrachill-custom-avatar',
        EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/custom-avatar.js',
        array('jquery'),
        filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/custom-avatar.js'),
        true
    );

    wp_localize_script('extrachill-custom-avatar', 'extrachillCustomAvatar', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_custom_avatar_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_avatar_upload_assets');
