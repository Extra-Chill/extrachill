<?php
// Fan Profiles Management Page

$current_user = wp_get_current_user();
$existing_post_id = wp_surgeon_has_profile_post($current_user->ID, 'fan_profile');
$fan_profile = $existing_post_id ? get_post($existing_post_id) : null;
$title = $fan_profile ? $fan_profile->post_title : '';
$content = $fan_profile ? $fan_profile->post_content : '';

$bio_content = ''; // Initialize bio content variable
if ($content) {
    $blocks = parse_blocks($content);
    foreach ($blocks as $block) {
        if ($block['blockName'] === 'core/paragraph') {
            // Extract text content only, stripping the paragraph tags
            $bio_content .= strip_tags(render_block($block));
        }
    }
}

// Get the thumbnail image URL instead of the full image
$existing_image_url = $existing_post_id ? get_the_post_thumbnail_url($existing_post_id, 'thumbnail') : '';
?>

<?php
// Check if the correct template is being used
if (is_page_template('profiles/create-profiles-template.php') || is_page_template('profiles/edit-profiles-template.php')) {
    ?>
<div id="fan-profile-form-container">
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


<label for="bio">Intro:</label>
<p>Introduce yourself and share your love for music:</p>
<textarea id="bio" name="bio"><?php echo esc_textarea($bio_content); ?></textarea><br>

        <input type="hidden" name="post_id" value="<?php echo esc_attr($existing_post_id); ?>">
        <?php wp_nonce_field('wp_rest', '_wpnonce', true, true); ?>
        <input type="submit" value="<?php echo $existing_post_id ? 'Update' : 'Create'; ?> Profile">
    </form>
    <div id="form-success-message"></div>
</div>
<script>
 function updateThumbnail(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();

        reader.onload = function(e) {
            var imgContainer = document.querySelector('#current-image');

            // Ensure the image container exists
            if (!imgContainer) {
                console.error('Image container not found.');
                return;
            }

            var imgElement = imgContainer.querySelector('img');

            // If an image element doesn't exist, create one
            if (!imgElement) {
                imgElement = document.createElement('img');
                imgElement.style.maxWidth = '100px';
                imgElement.style.maxHeight = '100px';
                imgContainer.prepend(imgElement);
            }

            // Update the image source
            imgElement.src = e.target.result;

            var filenameSpan = imgContainer.querySelector('span');
            if (!filenameSpan) {
                // Create a span for the filename if it doesn't exist
                filenameSpan = document.createElement('span');
                imgContainer.appendChild(filenameSpan);
            }
            
            // Update the filename
            filenameSpan.textContent = input.files[0].name;
        };

        reader.onerror = function(e) {
            console.error('Error reading file', e);
        };

        reader.readAsDataURL(input.files[0]);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('fan-profile-form');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);

        // Include file in the request
        var fileInput = document.getElementById('profile_image');
        if (fileInput.files[0]) {
            formData.append('profile_image', fileInput.files[0]);
        }

        var apiRoute = '<?php echo esc_url(rest_url('extrachill/v1/manage_fan_profile')); ?>';

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

         // Insert bio content as a Gutenberg paragraph block
$post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
$post_content .= '<p>' . $bio . '</p>' . PHP_EOL; // Bio is now correctly wrapped
$post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;

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