<?php
/**
 * Custom Avatar Upload System
 *
 * AJAX upload handler, bbPress profile edit form field, and conditional asset loading.
 * Stores attachment ID in custom_avatar_id user meta.
 *
 * @package ExtraChill\Users
 */

add_action('wp_ajax_custom_avatar_upload', 'extrachill_custom_avatar_upload');
function extrachill_custom_avatar_upload() {
    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    $uploadedfile = $_FILES['custom_avatar'];
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    $file_type = wp_check_filetype_and_ext($uploadedfile['tmp_name'], $uploadedfile['name']);
    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error(array('message' => 'Error: Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.'));
        return;
    }

    $upload_overrides = array('test_form' => false);
    $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
        $attachment = array(
            'guid'           => $movefile['url'],
            'post_mime_type' => $movefile['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($movefile['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $movefile['file']);

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        update_user_option(get_current_user_id(), 'custom_avatar_id', $attach_id, true);

        wp_send_json_success(array('url' => wp_get_attachment_url($attach_id)));
    } else {
        wp_send_json_error(array('message' => isset($movefile['error']) ? $movefile['error'] : 'Unknown error'));
    }
}

/**
 * Render avatar upload form field.
 */
function extrachill_render_avatar_upload_field() {
    $custom_avatar_id = get_user_option('custom_avatar_id');
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
    <label for="custom-avatar-upload"><?php esc_html_e( 'Upload New Avatar', 'extrachill-users' ); ?></label>
    <input type='file' id='custom-avatar-upload' name='custom_avatar' accept='image/*'>
    <div id="custom-avatar-upload-message"></div>
    <?php
}

/**
 * Enqueue avatar upload assets on bbPress profile edit pages.
 */
function extrachill_enqueue_avatar_upload_assets() {
    if (!function_exists('bbp_is_single_user_edit') || !bbp_is_single_user_edit()) {
        return;
    }

    wp_enqueue_script(
        'extrachill-avatar-upload',
        EXTRACHILL_USERS_PLUGIN_URL . 'assets/js/avatar-upload.js',
        array('jquery'),
        filemtime(EXTRACHILL_USERS_PLUGIN_DIR . 'assets/js/avatar-upload.js'),
        true
    );

    wp_localize_script('extrachill-avatar-upload', 'extrachillCustomAvatar', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_custom_avatar_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_avatar_upload_assets');
