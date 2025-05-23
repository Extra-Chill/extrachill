<?php
/**
 * Handles saving of band link page links and social links from the frontend management form.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the saving of all link page data from the frontend management form.
 */
function extrch_handle_save_link_page_data() {
    if (
        !isset($_POST['bp_save_link_page']) ||
        !isset($_POST['bp_save_link_page_nonce']) ||
        !wp_verify_nonce($_POST['bp_save_link_page_nonce'], 'bp_save_link_page_action')
    ) {
        return; // Nonce check failed or form not submitted.
    }

    $link_page_id = 0;
    $band_id = 0; // Initialize band_id

    if (isset($_GET['band_id'])) {
        $band_id = absint($_GET['band_id']);
        $link_page_id = get_post_meta($band_id, '_extrch_link_page_id', true);
    }

    if (!$link_page_id || get_post_type($link_page_id) !== 'band_link_page') {
        // Fallback: try to get from hidden field or bail
        if (isset($_POST['link_page_id'])) {
            $link_page_id = absint($_POST['link_page_id']);
            // If we got link_page_id from POST, try to get associated band_id if not already set
            if ( !$band_id && $link_page_id ) {
                $associated_band_id = get_post_meta($link_page_id, '_associated_band_profile_id', true);
                if ($associated_band_id) {
                    $band_id = absint($associated_band_id);
                }
            }
        }
    }

    if (!$link_page_id) {
        wp_die(__('Could not determine Link Page ID.', 'generatepress_child'));
    }

    // --- Save regular links ---
    $links_json = isset($_POST['link_page_links_json']) ? wp_unslash($_POST['link_page_links_json']) : '[]';
    $links_array = json_decode($links_json, true);

    // --- Save Advanced Tab Settings ---
    // Link Expiration (Moved from Links tab)
    $expiration_enabled = isset($_POST['link_expiration_enabled_advanced']) && $_POST['link_expiration_enabled_advanced'] == '1' ? '1' : '0';
    update_post_meta($link_page_id, '_link_expiration_enabled', $expiration_enabled);

    // Weekly Notifications (Placeholder)
    $weekly_notifications_enabled = isset($_POST['link_page_enable_weekly_notifications']) && $_POST['link_page_enable_weekly_notifications'] == '1' ? '1' : '0';
    update_post_meta($link_page_id, '_link_page_enable_weekly_notifications', $weekly_notifications_enabled);

    // Temporary Redirect (Placeholder)
    $redirect_enabled = isset($_POST['link_page_redirect_enabled']) && $_POST['link_page_redirect_enabled'] == '1' ? '1' : '0';
    update_post_meta($link_page_id, '_link_page_redirect_enabled', $redirect_enabled);

    if ($redirect_enabled === '1' && isset($_POST['link_page_redirect_target_url'])) {
        $redirect_url = esc_url_raw(wp_unslash($_POST['link_page_redirect_target_url']));
        update_post_meta($link_page_id, '_link_page_redirect_target_url', $redirect_url);
    } else {
        // Clear the target URL if redirect is disabled
        delete_post_meta($link_page_id, '_link_page_redirect_target_url');
    }

    // Link Highlighting Feature Toggle (Placeholder)
    $highlighting_enabled = isset($_POST['link_page_enable_highlighting']) && $_POST['link_page_enable_highlighting'] == '1' ? '1' : '0';
    update_post_meta($link_page_id, '_link_page_enable_highlighting', $highlighting_enabled);

    // --- Meta Pixel ID ---
    if (isset($_POST['link_page_meta_pixel_id'])) {
        $meta_pixel_id_raw = trim(wp_unslash($_POST['link_page_meta_pixel_id']));
        // Meta Pixel IDs are usually numeric strings. Allow empty to clear.
        if (empty($meta_pixel_id_raw)) {
            delete_post_meta($link_page_id, '_link_page_meta_pixel_id');
        } elseif (ctype_digit($meta_pixel_id_raw)) {
            update_post_meta($link_page_id, '_link_page_meta_pixel_id', $meta_pixel_id_raw);
        } else {
            // Invalid format, perhaps log an error or set a transient to show a notice.
            // For now, we just don't update it if it's not empty and not digits.
            // Or, we could be stricter and delete if invalid.
            // Let's choose to not update if invalid and not empty for now.
        }
    }
    // --- End Meta Pixel ID ---

    // --- Google Tag ID ---
    if (isset($_POST['link_page_google_tag_id'])) {
        $google_tag_id_raw = trim(wp_unslash($_POST['link_page_google_tag_id']));
        // Google Tag IDs usually start with G- or AW- followed by alphanumeric characters.
        // Allow empty to clear.
        if (empty($google_tag_id_raw)) {
            delete_post_meta($link_page_id, '_link_page_google_tag_id');
        } elseif (preg_match('/^(G|AW)-[a-zA-Z0-9]+$/', $google_tag_id_raw)) {
            update_post_meta($link_page_id, '_link_page_google_tag_id', $google_tag_id_raw);
        } else {
            // Invalid format, do not update if not empty and invalid.
            // Consider adding an admin notice here in the future.
        }
    }
    // --- End Google Tag ID ---

    // --- Save Overlay Toggle ---
    if (isset($_POST['link_page_overlay_toggle_present'])) {
        $overlay = isset($_POST['link_page_overlay_toggle']) && $_POST['link_page_overlay_toggle'] === '1' ? '1' : '0';
        update_post_meta($link_page_id, '_link_page_overlay_toggle', $overlay);
    }

    // --- End Advanced Tab Settings ---

    // Process link expiration based on the (potentially updated) setting
    if ($expiration_enabled === '1' && is_array($links_array)) {
        $now = current_time('timestamp');
        foreach ($links_array as $section_idx => &$section) {
            if (isset($section['links']) && is_array($section['links'])) {
                foreach ($section['links'] as $link_idx => $link) {
                    if (!empty($link['expires_at'])) {
                        $expires = strtotime($link['expires_at']);
                        if ($expires !== false && $expires <= $now) {
                            unset($section['links'][$link_idx]);
                        }
                    }
                }
                if (isset($section['links'])) { // Re-check as it might have become empty
                    $section['links'] = array_values($section['links']);
                }
            }
        }
        $links_array = array_values(array_filter($links_array, function($section) {
            return !empty($section['links']);
        }));
    } elseif ($expiration_enabled === '0' && is_array($links_array)) {
        foreach ($links_array as &$section) {
            if (isset($section['links']) && is_array($section['links'])) {
                foreach ($section['links'] as &$link) {
                    unset($link['expires_at']);
                }
            }
        }
        unset($section, $link); // break reference
    }
    update_post_meta($link_page_id, '_link_page_links', $links_array);

    // --- Save social links (to band_profile) ---
    if ($band_id) { // Ensure band_id is valid
        $social_links_json = isset($_POST['band_profile_social_links_json']) ? wp_unslash($_POST['band_profile_social_links_json']) : '[]';
        $social_links_array = json_decode($social_links_json, true);
        if (is_array($social_links_array)) {
            update_post_meta($band_id, '_band_profile_social_links', $social_links_array);
        }
    }

    // --- Save customization meta for link page ---
    if (isset($_POST['link_page_custom_css_vars_json'])) {
        $css_vars_json_string = wp_unslash($_POST['link_page_custom_css_vars_json']);
        // Attempt to decode to ensure it's valid JSON. If not, don't save it or save an empty JSON object.
        $decoded_vars = json_decode($css_vars_json_string, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_vars)) {
            // Optionally, you could iterate here and sanitize individual known keys if necessary.
            // For now, we'll save the validated (because it decoded) and unslashed JSON string.
            update_post_meta($link_page_id, '_link_page_custom_css_vars', $css_vars_json_string); 

            // Also update overlay meta for backward compatibility or direct access
            if (isset($decoded_vars['overlay'])) {
                update_post_meta($link_page_id, '_link_page_overlay_toggle', $decoded_vars['overlay'] === '1' ? '1' : '0');
            }
        } else {
            // Handle invalid JSON - e.g., log an error, or save an empty JSON object as a default state.
            // For now, we'll not update if JSON is invalid to prevent saving corrupted data.
            // error_log('Invalid JSON received for link_page_custom_css_vars_json: ' . json_last_error_msg());
            // update_post_meta($link_page_id, '_link_page_custom_css_vars', '{}'); // Optionally save empty JSON
        }
    }

    // --- Handle File Uploads ---
    // Background Image for Link Page
    if (!empty($_FILES['link_page_background_image_upload']['tmp_name'])) {
        $max_file_size = 5 * 1024 * 1024; // 5MB in bytes
        if ($_FILES['link_page_background_image_upload']['size'] > $max_file_size) {
            // Redirect back with an error message
            $redirect_url = add_query_arg(array('band_id' => $band_id, 'bp_link_page_error' => 'background_image_size'), wp_get_referer() ?: site_url('/manage-link-page/'));
            wp_safe_redirect($redirect_url);
            exit;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $old_bg_image_id = get_post_meta($link_page_id, '_link_page_background_image_id', true);
        $new_bg_image_id = media_handle_upload('link_page_background_image_upload', $link_page_id);
        if (is_numeric($new_bg_image_id)) {
            update_post_meta($link_page_id, '_link_page_background_image_id', $new_bg_image_id);
            if ($old_bg_image_id && $old_bg_image_id != $new_bg_image_id) {
                wp_delete_attachment($old_bg_image_id, true);
            }
        }
    }

    // Profile Image (syncs to band_profile CPT and _link_page_profile_image_id on link_page CPT)
    if ($band_id && !empty($_FILES['link_page_profile_image_upload']['tmp_name'])) {
        $max_file_size = 5 * 1024 * 1024; // 5MB in bytes
        if ($_FILES['link_page_profile_image_upload']['size'] > $max_file_size) {
            // Redirect back with an error message
            $redirect_url = add_query_arg(array('band_id' => $band_id, 'bp_link_page_error' => 'profile_image_size'), wp_get_referer() ?: site_url('/manage-link-page/'));
            wp_safe_redirect($redirect_url);
            exit;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        // Upload and associate with band_profile (sets as featured image)
        $attach_id = media_handle_upload('link_page_profile_image_upload', $band_id);
        if (is_numeric($attach_id)) {
            $old_link_page_profile_image_id = get_post_meta($link_page_id, '_link_page_profile_image_id', true);
            set_post_thumbnail($band_id, $attach_id); // Sync to band_profile featured image
            update_post_meta($link_page_id, '_link_page_profile_image_id', $attach_id); // Save on link_page as well
            if ($old_link_page_profile_image_id && $old_link_page_profile_image_id != $attach_id) {
                 wp_delete_attachment($old_link_page_profile_image_id, true); // Delete old link page specific image if different
            }
        }
    } elseif ($band_id && isset($_POST['remove_link_page_profile_image']) && $_POST['remove_link_page_profile_image'] === '1') {
        $current_link_page_profile_image_id = get_post_meta($link_page_id, '_link_page_profile_image_id', true);
        if ($current_link_page_profile_image_id) {
            delete_post_meta($link_page_id, '_link_page_profile_image_id');
            // Do not delete from media library, just disassociate. User might want to use band_profile's featured image.
        }
         // If we remove the link page specific image, we might want to ensure the band_profile's featured image is NOT cleared
         // unless explicitly told to. For now, this only removes the override.
    }


    // --- Sync other data to band_profile CPT ---
    if ($band_id && get_post_type($band_id) === 'band_profile') {
        // Sync bio (content)
        if (isset($_POST['link_page_bio_text'])) {
            $bio = wp_kses_post(wp_unslash($_POST['link_page_bio_text']));
            if (get_post_field('post_content', $band_id) !== $bio) {
                 wp_update_post(array('ID' => $band_id, 'post_content' => $bio));
            }
        }
        // Sync band name (title)
        if (isset($_POST['band_profile_title'])) {
            $new_title = sanitize_text_field(wp_unslash($_POST['band_profile_title']));
            if ($new_title && get_the_title($band_id) !== $new_title) {
                wp_update_post(array('ID' => $band_id, 'post_title' => $new_title));
            }
        }
    }

    // --- Redirect back with success ---
    $redirect_url = add_query_arg(array('band_id' => $band_id, 'bp_link_page_updated' => '1'), wp_get_referer() ?: site_url('/manage-link-page/'));
    wp_safe_redirect($redirect_url);
    exit;
}
add_action('template_redirect', 'extrch_handle_save_link_page_data');

// The redundant init hook and its logic are now removed.