<?php
// Fan Profiles Management Page

if (!function_exists('wp_surgeon_register_meta')) {
    function wp_surgeon_register_meta() {
        $meta_fields = [
            'bio',
            'favorite_artists',
            'musical_memories',
            'desert_island_albums',
            'top_concerts',
            'featured_link_1',
            'featured_link_2',
            'featured_link_3',
            'local_city',
            'top_local_venues',
            'top_local_artists'
        ];

        foreach ($meta_fields as $meta_field) {
            register_post_meta('fan_profile', $meta_field, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
            ]);
        }
    }
}

add_action('init', 'wp_surgeon_register_meta');


$current_user = wp_get_current_user();
$existing_post_id = wp_surgeon_has_profile_post($current_user->ID, 'fan_profile');
$fan_profile = $existing_post_id ? get_post($existing_post_id) : null;
$title = $fan_profile ? $fan_profile->post_title : '';
$content = $fan_profile ? $fan_profile->post_content : '';

// Assuming $existing_post_id is set to the ID of the existing profile post
$bio_content = get_post_meta($existing_post_id, 'bio', true);
$favorite_artists_content = get_post_meta($existing_post_id, 'favorite_artists', true);
$musical_memories_content = get_post_meta($existing_post_id, 'musical_memories', true);
$desert_island_albums_content = get_post_meta($existing_post_id, 'desert_island_albums', true);
$top_concerts_content = get_post_meta($existing_post_id, 'top_concerts', true);
$featured_link_1_content = get_post_meta($existing_post_id, 'featured_link_1', true);
$featured_link_2_content = get_post_meta($existing_post_id, 'featured_link_2', true);
$featured_link_3_content = get_post_meta($existing_post_id, 'featured_link_3', true);
$local_city_content = get_post_meta($existing_post_id, 'local_city', true);
$top_local_venues_content = get_post_meta($existing_post_id, 'top_local_venues', true);
$top_local_artists_content = get_post_meta($existing_post_id, 'top_local_artists', true);
// Get the thumbnail image URL instead of the full image
$existing_image_url = $existing_post_id ? get_the_post_thumbnail_url($existing_post_id, 'thumbnail') : '';
?>

<?php
// Check if the correct template is being used
if (is_page_template('page-templates/create-profiles-template.php') || is_page_template('page-templates/edit-profiles-template.php')) {
    ?>
<div id="fan-profile-form-container">
    <p>Note: This functionality is under construction and will be evolving in the coming weeks. Currently everything works except for the featured links section. Give feedback on this stuff in the Community Feedback Forum.</p>
    <p>Your fan profile is your place to showcase your favorite music and what you love about it. Any fields you leave blank will simply not show up. You can come back and update your fan profile anytime.</p>
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

        <span><h2>Bio</h2></span>
        <label for="bio">Who are you, and what defines your music taste?</label>
        <textarea id="bio" name="bio"><?php echo esc_textarea($bio_content); ?></textarea><br>

        <span><h2>Favorite Artists</h2></span>
        <label for="favorite_artists">Who are your top 5 favorite artists?</label>
        <textarea id="favorite_artists" name="favorite_artists"><?php echo esc_textarea($favorite_artists_content); ?></textarea><br>

                <!-- Local Scene -->
        <span><h2>Local Scene</h2></span>
        <label for="local_city">City:</label>
        <input type="text" id="local_city" name="local_city" value="<?php echo esc_attr($local_city_content); ?>"><br>

        <label for="top_local_venues">Top Local Venues:</label>
        <textarea id="top_local_venues" name="top_local_venues"><?php echo esc_textarea($top_local_venues_content); ?></textarea><br>

        <label for="top_local_artists">Top Local Artists:</label>
        <textarea id="top_local_artists" name="top_local_artists"><?php echo esc_textarea($top_local_artists_content); ?></textarea><br>

       <!-- Top Concerts -->
        <span><h2>Top Concerts</h2></span>
        <label for="top_concerts">The best shows you've ever seen:</label>
        <textarea id="top_concerts" name="top_concerts"><?php echo esc_textarea($top_concerts_content); ?></textarea><br>

        <!-- Earliest Musical Memories -->
        <span><h2>Earliest Musical Memories</h2></span>
        <label for="musical_memories">How did you get into music?</label>
        <textarea id="musical_memories" name="musical_memories"><?php echo esc_textarea($musical_memories_content); ?></textarea><br>

        <!-- Desert Island Albums -->
        <span><h2>Desert Island Albums</h2></span>
        <label for="desert_island_albums">Five albums you'd take on a desert island:</label>
        <textarea id="desert_island_albums" name="desert_island_albums"><?php echo esc_textarea($desert_island_albums_content); ?></textarea><br>

        <!-- Featured Links -->
        <span><h2>Featured Music</h2></span>
        <p>This can be YouTube, Spotify, etc. Share music you're digging, and come back to update when you find new awesome stuff.</p>
        <label for="featured_link_1">Featured Link 1 (URL):</label>
        <input type="url" id="featured_link_1" name="featured_link_1" value="<?php echo esc_url($featured_link_1_content); ?>"><br>

        <label for="featured_link_2">Featured Link 2 (URL):</label>
        <input type="url" id="featured_link_2" name="featured_link_2" value="<?php echo esc_url($featured_link_2_content); ?>"><br>

        <label for="featured_link_3">Featured Link 3 (URL):</label>
        <input type="url" id="featured_link_3" name="featured_link_3" value="<?php echo esc_url($featured_link_3_content); ?>"><br>

        <input type="hidden" name="post_id" value="<?php echo esc_attr($existing_post_id); ?>">
        <?php wp_nonce_field('wp_rest', '_wpnonce', true, true); ?>
        <input type="submit" value="<?php echo $existing_post_id ? 'Update' : 'Create'; ?> Profile">
        <small>If you added an image, it will take a moment after pressing submit.</small>
    </form>
    <div id="form-success-message"></div>
</div>


<?php

}

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
        $post_id = sanitize_text_field($request->get_param('post_id'));
        $current_user = wp_get_current_user();
        $is_update = !empty($post_id);

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

      // Initialize an empty post content
        $post_content = '';

        // Retrieve user input for profile fields
        $bio = sanitize_text_field($request->get_param('bio'));
        $favorite_artists = sanitize_textarea_field($request->get_param('favorite_artists'));
        $musical_memories = sanitize_textarea_field($request->get_param('musical_memories'));
        $desert_island_albums = sanitize_textarea_field($request->get_param('desert_island_albums'));
        $top_concerts = sanitize_textarea_field($request->get_param('top_concerts'));
        $featured_link_1 = esc_url_raw($request->get_param('featured_link_1'));
        $featured_link_2 = esc_url_raw($request->get_param('featured_link_2'));
        $featured_link_3 = esc_url_raw($request->get_param('featured_link_3'));
        $local_city = sanitize_text_field($request->get_param('local_city'));
        $top_local_venues = sanitize_textarea_field($request->get_param('top_local_venues'));
        $top_local_artists = sanitize_textarea_field($request->get_param('top_local_artists'));

        // Add an image block if an image is uploaded
        if (!empty($image_url)) {
            $post_content .= '<!-- wp:image {"id":' . $attachment_id . '} -->' . PHP_EOL;
            $post_content .= '<figure class="wp-block-image"><img src="' . esc_url($image_url) . '" alt="" class="wp-image-' . $attachment_id . '"/></figure>' . PHP_EOL;
            $post_content .= '<!-- /wp:image -->' . PHP_EOL;
        }

        // Add Bio if it's not empty
        if (!empty($bio)) {
            $post_content .= '<!-- wp:heading {"level":2} -->' . PHP_EOL;
            $post_content .= '<h2>Bio</h2>' . PHP_EOL;
            $post_content .= '<!-- /wp:heading -->' . PHP_EOL;
            $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
            $post_content .= '<p>' . $bio . '</p>' . PHP_EOL;
            $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
        }

        // Add Favorite Artists if it's not empty
        if (!empty($favorite_artists)) {
            $post_content .= '<!-- wp:heading {"level":2} -->' . PHP_EOL;
            $post_content .= '<h2>Favorite Artists</h2>' . PHP_EOL;
            $post_content .= '<!-- /wp:heading -->' . PHP_EOL;
            $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
            $post_content .= '<p>' . $favorite_artists . '</p>' . PHP_EOL;
            $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
        }

        // Add Local Scene content if any field is filled
        if (!empty($local_city) || !empty($top_local_venues) || !empty($top_local_artists)) {
            $post_content .= '<!-- wp:heading {"level":2} -->' . PHP_EOL;
            $post_content .= '<h2>Local Scene</h2>' . PHP_EOL;

            if (!empty($local_city)) {
                $post_content .= '<!-- wp:heading {"level":3} -->' . PHP_EOL;
                $post_content .= '<h3>City</h3>' . PHP_EOL;
                $post_content .= '<!-- /wp:heading -->' . PHP_EOL;
                $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
                $post_content .= '<p>' . $local_city . '</p>' . PHP_EOL;
                $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
            }

            if (!empty($top_local_venues)) {
                $post_content .= '<!-- wp:heading {"level":3} -->' . PHP_EOL;
                $post_content .= '<h3>Top Local Venues</h3>' . PHP_EOL;
                $post_content .= '<!-- /wp:heading -->' . PHP_EOL;
                $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
                $post_content .= '<p>' . $top_local_venues . '</p>' . PHP_EOL;
                $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
            }

            if (!empty($top_local_artists)) {
                $post_content .= '<!-- wp:heading {"level":3} -->' . PHP_EOL;
                $post_content .= '<h3>Top Local Artists</h3>' . PHP_EOL;
                $post_content .= '<!-- /wp:heading -->' . PHP_EOL;
                $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
                $post_content .= '<p>' . $top_local_artists . '</p>' . PHP_EOL;
                $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
            }
        }

        // Add Top Concerts if it's not empty
        if (!empty($top_concerts)) {
            $post_content .= '<!-- wp:heading {"level":2} -->' . PHP_EOL;
            $post_content .= '<h2>Top Concerts</h2>' . PHP_EOL;
            $post_content .= '<!-- /wp:heading -->' . PHP_EOL;
            $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
            $post_content .= '<p>' . $top_concerts . '</p>' . PHP_EOL;
            $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
        }

        // Add Earliest Musical Memories if it's not empty
        if (!empty($musical_memories)) {
            $post_content .= '<!-- wp:heading {"level":2} -->' . PHP_EOL;
            $post_content .= '<h2>Earliest Musical Memories</h2>' . PHP_EOL;
            $post_content .= '<!-- /wp:heading -->' . PHP_EOL;
            $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
            $post_content .= '<p>' . $musical_memories . '</p>' . PHP_EOL;
            $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
        }

        // Add Desert Island Albums if it's not empty
        if (!empty($desert_island_albums)) {
            $post_content .= '<!-- wp:heading {"level":2} -->' . PHP_EOL;
            $post_content .= '<h2>Desert Island Albums</h2>' . PHP_EOL;
            $post_content .= '<!-- /wp:heading -->' . PHP_EOL;
            $post_content .= '<!-- wp:paragraph -->' . PHP_EOL;
            $post_content .= '<p>' . $desert_island_albums . '</p>' . PHP_EOL;
            $post_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
        }


// Featured links
$featured_links = [$featured_link_1, $featured_link_2, $featured_link_3];

// Directly add URLs to the content, each on a new line
if (!empty($featured_link_1)) {
    $post_content .= $featured_link_1 . PHP_EOL; // Add the URL directly
}
if (!empty($featured_link_2)) {
    $post_content .= $featured_link_2 . PHP_EOL;
}
if (!empty($featured_link_3)) {
    $post_content .= $featured_link_3 . PHP_EOL;
}


        // Prepare post data for either creating or updating the profile
        $post_data = array(
            'post_type'    => 'fan_profile',
            'post_title'   => $name,
            'post_status'  => 'publish',
            'post_author'  => $current_user->ID,
            'post_content' => $post_content, // Set the dynamically generated content
        );

        if ($is_update) {
            $post_data['ID'] = $post_id;
            $result_post_id = wp_update_post($post_data, true);
        } else {
            $result_post_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result_post_id)) {
            return new WP_Error('rest_post_failed', $result_post_id->get_error_message(), array('status' => 500));
        }

        // Update custom meta fields
        $meta_fields = [
            'bio' => wp_kses_post($request->get_param('bio')),
            'favorite_artists' => wp_kses_post($request->get_param('favorite_artists')),
            'musical_memories' => wp_kses_post($request->get_param('musical_memories')),
            'desert_island_albums' => wp_kses_post($request->get_param('desert_island_albums')),
            'top_concerts' => wp_kses_post($request->get_param('top_concerts')),
            'featured_link_1' => esc_url_raw($request->get_param('featured_link_1')),
            'featured_link_2' => esc_url_raw($request->get_param('featured_link_2')),
            'featured_link_3' => esc_url_raw($request->get_param('featured_link_3')),
            'local_city' => sanitize_text_field($request->get_param('local_city')),
            'top_local_venues' => wp_kses_post($request->get_param('top_local_venues')),
            'top_local_artists' => wp_kses_post($request->get_param('top_local_artists')),
        ];

        foreach ($meta_fields as $key => $value) {
            update_post_meta($result_post_id, $key, $value);
        }

        // Return a success response
        $profile_url = get_permalink($result_post_id);
        $success_message = $is_update ? 'Fan profile updated successfully' : 'Fan profile created successfully';
        return new WP_REST_Response(['message' => $success_message, 'post_id' => $result_post_id, 'profile_url' => $profile_url], 200);
    }
}