<?php
/**
 * Custom Avatar Upload UI
 *
 * Renders bbPress profile edit form field and handles conditional asset loading.
 * Upload logic handled by unified REST endpoint: POST /wp-json/extrachill/v1/media
 *
 * @package ExtraChill\Community
 */

/**
 * Render avatar upload form field.
 */
function extrachill_render_avatar_upload_field() {
    ?>
    <div id="avatar-thumbnail">
        <h4>Current Avatar</h4>
        <p>This is the avatar you currently have set. Upload a new image to change it.</p>
        <?php echo get_avatar( get_current_user_id(), 100 ); ?>
    </div>
    <label for="custom-avatar-upload"><?php esc_html_e( 'Upload New Avatar', 'extra-chill-community' ); ?></label>
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
		EXTRACHILL_COMMUNITY_PLUGIN_URL . 'inc/assets/js/avatar-upload.js',
		array(),
		filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/assets/js/avatar-upload.js'),
		true
	);

	wp_localize_script('extrachill-avatar-upload', 'ecAvatarUpload', array(
		'spriteUrl' => get_template_directory_uri() . '/assets/fonts/extrachill.svg',
		'restNonce' => wp_create_nonce('wp_rest'),
		'userId'    => get_current_user_id(),
	));
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_avatar_upload_assets');
