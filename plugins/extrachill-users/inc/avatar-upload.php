<?php
/**
 * Custom Avatar Upload System
 *
 * Business logic, bbPress profile edit form field, and conditional asset loading.
 * Stores attachment ID in custom_avatar_id user meta.
 * Business logic called by REST API endpoint in extrachill-api plugin.
 *
 * @package ExtraChill\Users
 */

/**
 * Process avatar upload for a user.
 *
 * Validates file type, handles upload, creates attachment, and updates user meta.
 * Called by REST API endpoint in extrachill-api plugin.
 *
 * @param int   $user_id User ID to upload avatar for.
 * @param array $files   Uploaded files array from $_FILES or $request->get_file_params().
 * @return array|WP_Error Success array with 'url' and 'attachment_id' or WP_Error on failure.
 */
function extrachill_process_avatar_upload($user_id, $files) {
	if (!$user_id || !get_userdata($user_id)) {
		return new WP_Error('invalid_user', 'Invalid user ID');
	}

	if (!isset($files['file']) || empty($files['file']['name'])) {
		return new WP_Error('no_file', 'No file uploaded');
	}

	$uploaded_file = $files['file'];
	$allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
	$file_type = wp_check_filetype_and_ext($uploaded_file['tmp_name'], $uploaded_file['name']);

	if (!in_array($file_type['type'], $allowed_types)) {
		return new WP_Error(
			'invalid_file_type',
			'Invalid file type. Only JPG, PNG, GIF, and WebP files are allowed.'
		);
	}

	if (!function_exists('wp_handle_upload')) {
		require_once(ABSPATH . 'wp-admin/includes/file.php');
	}

	$upload_overrides = array('test_form' => false);
	$movefile = wp_handle_upload($uploaded_file, $upload_overrides);

	if (!$movefile || isset($movefile['error'])) {
		return new WP_Error(
			'upload_failed',
			isset($movefile['error']) ? $movefile['error'] : 'Unknown upload error'
		);
	}

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

	update_user_option($user_id, 'custom_avatar_id', $attach_id, true);

	return array(
		'url' => wp_get_attachment_url($attach_id),
		'attachment_id' => $attach_id
	);
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
		array(),
		filemtime(EXTRACHILL_USERS_PLUGIN_DIR . 'assets/js/avatar-upload.js'),
		true
	);

	wp_localize_script('extrachill-avatar-upload', 'ecAvatarUpload', array(
		'spriteUrl' => get_template_directory_uri() . '/assets/fonts/extrachill.svg'
	));

	wp_enqueue_script('wp-api');
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_avatar_upload_assets');
