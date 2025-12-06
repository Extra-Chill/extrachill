<?php
/**
 * Custom Avatar Upload UI
 *
 * Renders bbPress profile edit form field and handles conditional asset loading.
 * Upload logic handled by unified REST endpoint: POST /wp-json/extrachill/v1/media
 *
 * @package ExtraChill\Users
 */

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
		'spriteUrl' => get_template_directory_uri() . '/assets/fonts/extrachill.svg',
		'restNonce' => wp_create_nonce('wp_rest'),
		'userId'    => get_current_user_id(),
	));
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_avatar_upload_assets');
