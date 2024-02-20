<?php
// Fan Profiles Management Page

$current_user = wp_get_current_user();
$existing_post_id = wp_surgeon_has_profile_post($current_user->ID, 'fan_profile');
$fan_profile = $existing_post_id ? get_post($existing_post_id) : null;
$title = $fan_profile ? $fan_profile->post_title : '';
$content = $fan_profile ? $fan_profile->post_content : '';

$bio_content = ''; // Initialize bio content variable
$favorite_artists_content = ''; // Initialize favorite artists content variable
$is_bio = true; // Flag to toggle between bio and favorite artists

if ($content) {
    $blocks = parse_blocks($content);
    foreach ($blocks as $block) {
        if ($block['blockName'] === 'core/paragraph') {
            // Check if the block is bio or favorite artists
            if ($is_bio) {
                $bio_content .= strip_tags(render_block($block));
                $is_bio = false; // Toggle to indicate the next block is favorite artists
            } else {
                $favorite_artists_content .= strip_tags(render_block($block));
                $is_bio = true; // Reset for next pair of blocks
            }
        }
    }
}

// Get the thumbnail image URL instead of the full image
$existing_image_url = $existing_post_id ? get_the_post_thumbnail_url($existing_post_id, 'thumbnail') : '';
?>

<?php
// Check if the correct template is being used
if (is_page_template('page-templates/create-profiles-template.php') || is_page_template('page-templates/edit-profiles-template.php')) {
    ?>
<div id="fan-profile-form-container">
    <p>Note: This functionality is under construction and will be evolving in the coming weeks.</p>
    <form id="fan-profile-form" method="post" enctype="multipart/form-data">
        <label for="name">Name:</label>
        <input type="text" id="name" name="name" value="<?php echo esc_attr($title); ?>" required><br>

        <label for="profile_image">Profile Image:</label>
        <div id="current-image">
            <?php if ($existing_image_url): ?>
                <img src="<?php echo esc_url($existing_image_url); ?>" alt="Profile Image" style="max-width:100px; max-height:100px;"><br>
                <span><?php echo basename($existing_image_url); ?></span><br>
            <?php endif; ?>
        </div>
        <input type="file" id="profile_image" name="profile_image" accept="image/*" onchange="updateThumbnail(this)"><br>


<span><b>Bio</b></span><br>
<label for="bio">Introduce yourself and share your love for music.</label>
<textarea id="bio" name="bio"><?php echo esc_textarea($bio_content); ?></textarea><br>

<span><b>Favorite Artists</b></span><br>
<label for="favorite_artists">Who are your top 5 favorite artists?</label>
<textarea id="favorite_artists" name="favorite_artists"><?php echo esc_textarea($favorite_artists_content); ?></textarea><br>

        <input type="hidden" name="post_id" value="<?php echo esc_attr($existing_post_id); ?>">
        <?php wp_nonce_field('wp_rest', '_wpnonce', true, true); ?>
        <input type="submit" value="<?php echo $existing_post_id ? 'Update' : 'Create'; ?> Profile">
    </form>
    <div id="form-success-message"></div>
</div>

    <?php
}
?>
<?php
// Register the REST API route for managing fan profiles
add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/manage_fan_profile', array(
        'methods' => 'POST',
        'callback' => 'handle_manage_fan_profile_request',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ));
});
 if (!function_exists('handle_manage_fan_profile_request')) {

    function handle_manage_fan_profile_request(WP_REST_Request $request) {
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_Error('rest_nonce_verification_failed', esc_html__('Nonce verification failed.', 'extrachill'), array('status' => 403));
        }

        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', esc_html__('You are not authorized to manage profiles.', 'extrachill'), array('status' => 401));
        }

        $name = sanitize_text_field($request->get_param('name'));

        $bio = wp_kses_post($request->get_param('bio')); // Sanitize and keep HTML for Gutenberg blocks
        $favorite_artists = wp_kses_post($request->get_param('favorite_artists')); // Sanitize and keep HTML for Gutenberg blocks

        $post_id = sanitize_text_field($request->get_param('post_id'));

        $current_user = wp_get_current_user();
        $is_update = !empty($post_id);
        $post_content = '';
        $attachment_id = null;

        // Retrieve existing image ID for updates
        $existing_image_id = $is_update ? get_post_thumbnail_id($post_id) : null;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_image'];
            $file_type = wp_check_filetype($file['name']);

            if (strpos($file_type['type'], 'image') !== false) {
                $upload_overrides = array('test_form' => false);
                $movefile = wp_handle_upload($file, $upload_overrides);

                if ($movefile && empty($movefile['error'])) {
                    $attachment = array(
                        'post_title'     => $name,
                        'post_mime_type' => $movefile['type'],
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                    );

                    $attachment_id = wp_insert_attachment($attachment, $movefile['file']);
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    $attachment_data = wp_generate_attachment_metadata($attachment_id, $movefile['file']);
                    wp_update_attachment_metadata($attachment_id, $attachment_data);

                    if ($is_update && $existing_image_id != $attachment_id) {
                        wp_delete_attachment($existing_image_id, true);
                    }

                    set_post_thumbnail($post_id, $attachment_id);
                    $image_url = wp_get_attachment_url($attachment_id);
                    $post_content .= '<!-- wp:image {"id":' . $attachment_id . '} -->' . PHP_EOL;
                    $post_content .= '<figure class="wp-block-image"><img src="' . esc_url($image_url) . '" alt="" class="wp-image-' . $attachment_id . '"/></figure>' . PHP_EOL;
                    $post_content .= '<!-- /wp:image -->' . PHP_EOL;
                }
            }
        }

        // If an update and no new image is uploaded, add existing image block
        if ($is_update && !$attachment_id && $existing_image_id) {
            $image_url = wp_get_attachment_url($existing_image_id);
            $post_content .= '<!-- wp:image {"id":' . $existing_image_id . '} -->' . PHP_EOL;
            $post_content .= '<figure class="wp-block-image"><img src="' . esc_url($image_url) . '" alt="" class="wp-image-' . $existing_image_id . '"/></figure>' . PHP_EOL;
            $post_content .= '<!-- /wp:image -->' . PHP_EOL;
        }

// Check and insert Bio if it's not empty
if (!empty($bio)) {
    // Insert bio heading as a Gutenberg H2 block
    $post_content .= '<!-- wp:heading {"level":2} -->' . PHP_EOL;
    $post_content .= '<h2>Bio</h2>' . PHP_EOL;
    $post_content .= '<!-- /wp:heading -->' . PHP_EOL;

    // Insert bio content as a Gutenberg paragraph block
    $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
    $post_content .= '<p>' . $bio . '</p>' . PHP_EOL;
    $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
}

// Check and insert Favorite Artists if it's not empty
if (!empty($favorite_artists)) {
    // Insert favorite artists heading as a Gutenberg H2 block
    $post_content .= '<!-- wp:heading {"level":2} -->' . PHP_EOL;
    $post_content .= '<h2>Favorite Artists</h2>' . PHP_EOL;
    $post_content .= '<!-- /wp:heading -->' . PHP_EOL;

    // Insert favorite artists content as a Gutenberg paragraph block
    $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
    $post_content .= '<p>' . $favorite_artists . '</p>' . PHP_EOL;
    $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
}

        // Prepare post data
        $post_data = array(
            'post_type'    => 'fan_profile',
            'post_title'   => $name,
            'post_content' => $post_content,
            'post_status'  => 'publish',
            'post_author'  => $current_user->ID,
        );

        // Update or create the post
        if ($is_update) {
            $post_data['ID'] = $post_id;
            $result_post_id = wp_update_post($post_data, true);
        } else {
            $result_post_id = wp_insert_post($post_data, true);
        }

        // Handle errors
        if (is_wp_error($result_post_id)) {
            return new WP_Error('rest_post_failed', $result_post_id->get_error_message(), array('status' => 500));
        }

        // Success response
        $profile_url = get_permalink($result_post_id);
        $success_message = $is_update ? 'Fan profile updated successfully' : 'Fan profile created successfully';

        return new WP_REST_Response(['message' => $success_message, 'post_id' => $result_post_id, 'profile_url' => $profile_url], 200);
    }
}