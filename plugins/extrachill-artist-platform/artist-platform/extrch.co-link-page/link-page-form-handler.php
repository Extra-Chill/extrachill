<?php
/**
 * Handles saving of artist link page links and social links from the frontend management form.
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
    $artist_id = 0; // Initialize artist_id

    if (isset($_GET['artist_id'])) {
        $artist_id = absint($_GET['artist_id']);
        $link_page_id = get_post_meta($artist_id, '_extrch_link_page_id', true);
    } elseif (isset($_GET['artist_id'])) {
        // Backward compatibility for old artist_id parameter
        $artist_id = absint($_GET['artist_id']);
        $link_page_id = get_post_meta($artist_id, '_extrch_link_page_id', true);
        
        // Additional debugging for new link pages
        if (!$link_page_id) {
        } else {
            $link_page_post_type = get_post_type($link_page_id);
            $link_page_status = get_post_status($link_page_id);
        }
    } else {
    }

    if (!$link_page_id || get_post_type($link_page_id) !== 'artist_link_page') {
        // Fallback: try to get from hidden field or bail
        if (isset($_POST['link_page_id'])) {
            $link_page_id = absint($_POST['link_page_id']);
            
            // If we got link_page_id from POST, try to get associated artist_id if not already set
            if ( !$artist_id && $link_page_id ) {
                $associated_artist_id = get_post_meta($link_page_id, '_associated_artist_profile_id', true);
                if ($associated_artist_id) {
                    $artist_id = absint($associated_artist_id);
                } else {
                }
            }
        } else {
        }
    }

    if (!$link_page_id) {
        wp_die(__('Could not determine Link Page ID.', 'extrachill-artist-platform'));
    }

    // CRITICAL SECURITY: Validate user permissions for this artist
    if (!empty($artist_id) && !current_user_can('manage_artist_members', $artist_id)) {
        wp_die(__('Permission denied: You do not have access to manage this artist.', 'extrachill-artist-platform'));
    }

    // Additional validation: ensure user is logged in for any save operation
    if (!is_user_logged_in()) {
        wp_die(__('Permission denied: You must be logged in to save changes.', 'extrachill-artist-platform'));
    }

    
    // Debug the incoming data

    // --- Save regular links ---
    $links_json = isset($_POST['link_page_links_json']) ? wp_unslash($_POST['link_page_links_json']) : '[]';
    
    $links_array = json_decode($links_json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
    }

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

    // --- YouTube Inline Embed Setting ---
    // The checkbox is named 'disable_youtube_inline_embed'.
    // If it's checked (present in POST and value '1'), the feature should be DISABLED (_enable_youtube_inline_embed = '0').
    // If it's unchecked (not present in POST), the feature should be ENABLED (_enable_youtube_inline_embed = '1').
    if (isset($_POST['disable_youtube_inline_embed']) && $_POST['disable_youtube_inline_embed'] == '1') {
        // "Disable" checkbox is checked, so set the feature to OFF.
        update_post_meta($link_page_id, '_enable_youtube_inline_embed', '0');
    } else {
        // "Disable" checkbox is NOT checked (or not present), so set the feature to ON.
        update_post_meta($link_page_id, '_enable_youtube_inline_embed', '1');
    }
    // --- End YouTube Inline Embed Setting ---

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

    // --- Featured Link Settings (Advanced Tab) ---
    $enable_featured_link_val = isset($_POST['enable_featured_link']) && $_POST['enable_featured_link'] == '1' ? '1' : '0';
    update_post_meta($link_page_id, '_enable_featured_link', $enable_featured_link_val);

    if ($enable_featured_link_val === '1' && isset($_POST['featured_link_original_id'])) {
        // The 'featured_link_original_id' field from POST now contains the URL of the link to feature.
        $featured_link_url = esc_url_raw(wp_unslash($_POST['featured_link_original_id']));
        update_post_meta($link_page_id, '_featured_link_original_id', $featured_link_url);
    } else {
        // If the feature is disabled, or no URL is selected, clear the meta.
        delete_post_meta($link_page_id, '_featured_link_original_id');
    }
    // Note: The custom title, description, and thumbnail for the featured link are handled by extrch_save_featured_link_settings()
    // which is called after this main handler, using $link_page_id, $_POST, and $_FILES.
    // --- End Featured Link Settings ---

    // --- Save Social Icons Position ---
    if (isset($_POST['link_page_social_icons_position'])) {
        $social_icons_position = sanitize_text_field($_POST['link_page_social_icons_position']);
        if (in_array($social_icons_position, array('above', 'below'), true)) {
            update_post_meta($link_page_id, '_link_page_social_icons_position', $social_icons_position);
        } else {
            // Default to 'above' if an invalid value is submitted
            update_post_meta($link_page_id, '_link_page_social_icons_position', 'above');
        }
    } else {
        // Default to 'above' if the setting is not present in POST (e.g. if user clears cookies or something)
        update_post_meta($link_page_id, '_link_page_social_icons_position', 'above');
    }
    // --- End Social Icons Position ---

    // --- Save Overlay Toggle ---
    if (isset($_POST['link_page_overlay_toggle_present'])) {
        $overlay = isset($_POST['link_page_overlay_toggle']) && $_POST['link_page_overlay_toggle'] === '1' ? '1' : '0';
        update_post_meta($link_page_id, '_link_page_overlay_toggle', $overlay);
    }

    // --- Save Profile Image Shape ---
    if (isset($_POST['link_page_profile_img_shape'])) {
        $profile_img_shape = sanitize_text_field($_POST['link_page_profile_img_shape']);
        if (in_array($profile_img_shape, array('circle', 'square', 'rectangle'), true)) {
            update_post_meta($link_page_id, '_link_page_profile_img_shape', $profile_img_shape);
        }
    }

    // --- Save Subscribe Display Mode and Description ---
    if (isset($_POST['link_page_subscribe_display_mode'])) {
        $subscribe_display_mode = sanitize_text_field($_POST['link_page_subscribe_display_mode']);
        if (in_array($subscribe_display_mode, array('icon_modal', 'inline_form', 'disabled'), true)) {
            update_post_meta($link_page_id, '_link_page_subscribe_display_mode', $subscribe_display_mode);
        } else {
            delete_post_meta($link_page_id, '_link_page_subscribe_display_mode');
        }
    }
    if (isset($_POST['link_page_subscribe_description'])) {
        $subscribe_description = trim(wp_unslash($_POST['link_page_subscribe_description']));
        if ($subscribe_description !== '') {
            update_post_meta($link_page_id, '_link_page_subscribe_description', $subscribe_description);
        } else {
            delete_post_meta($link_page_id, '_link_page_subscribe_description');
        }
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
    
    $links_save_result = update_post_meta($link_page_id, '_link_page_links', $links_array);
    
    // Verify the save worked
    $saved_links = get_post_meta($link_page_id, '_link_page_links', true);
    
    $current_time = microtime(true);

    // --- Save social links (to artist_profile) ---
    if (isset($_POST['artist_profile_social_links_json']) && !empty($artist_id)) {
        error_log('[LinkPageSave PHP] Processing social links for artist ID: ' . $artist_id);
        error_log('[LinkPageSave PHP] Raw POST data for social links: ' . print_r($_POST, true));
        
        $social_links_json = wp_unslash($_POST['artist_profile_social_links_json']);
        error_log('[LinkPageSave PHP] Received social links JSON string: ' . $social_links_json);
        error_log('[LinkPageSave PHP] JSON string length: ' . strlen($social_links_json));
        
        $social_links_array = json_decode($social_links_json, true);
        $json_error = json_last_error();
        error_log('[LinkPageSave PHP] JSON decode error code: ' . $json_error);
        error_log('[LinkPageSave PHP] json_decode result: ' . print_r($social_links_array, true));
        
        // Handle both valid arrays AND null (which happens when band has no existing social links)
        if ($json_error === JSON_ERROR_NONE) {
            // Convert null to empty array for artists with no existing social links
            if (is_null($social_links_array)) {
                $social_links_array = array();
                error_log('[LinkPageSave PHP] Converted null to empty array for new social links');
            }
            
            if (is_array($social_links_array)) {
                error_log('[LinkPageSave PHP] Social links array BEFORE update_post_meta: ' . print_r($social_links_array, true));
            update_post_meta($artist_id, '_artist_profile_social_links', $social_links_array);
                error_log('[LinkPageSave PHP] Social links updated for artist ID: ' . $artist_id);
            
                // Verify the save worked (for critical debugging only)
            $saved_links = get_post_meta($artist_id, '_artist_profile_social_links', true);
                error_log('[LinkPageSave PHP] Verified saved social links: ' . print_r($saved_links, true));
            } else {
                error_log('[LinkPageSave PHP] Social links data is not an array after null conversion');
            }
        } else {
            // Log only critical errors
            error_log('[LinkPageSave PHP] Failed to decode social links JSON. Error: ' . json_last_error_msg());
        }
    } else if (empty($artist_id)) {
        error_log('[LinkPageSave PHP] No valid artist_id found for saving social links');
    }

    // --- Save customization meta for link page ---
    if (isset($_POST['link_page_custom_css_vars_json'])) {
        $css_vars_json_string = wp_unslash($_POST['link_page_custom_css_vars_json']);
        
        // Attempt to decode to ensure it's valid JSON
        $decoded_vars = json_decode($css_vars_json_string, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_vars)) {
            
            // Save the decoded array (WordPress will serialize it properly)
            $save_result = update_post_meta($link_page_id, '_link_page_custom_css_vars', $decoded_vars);
            
            // Note: update_post_meta returns false if value unchanged (optimization)
            if ($save_result) {
            } else {
                // Verify data exists to distinguish between failure vs. no-change
                $existing_data = get_post_meta($link_page_id, '_link_page_custom_css_vars', true);
                if (is_array($existing_data) && count($existing_data) === count($decoded_vars)) {
                } else {
                }
            }

            // Also update overlay meta for backward compatibility or direct access
            if (isset($decoded_vars['overlay'])) {
                update_post_meta($link_page_id, '_link_page_overlay_toggle', $decoded_vars['overlay'] === '1' ? '1' : '0');
            }
        } else {
            // Handle invalid JSON
            $error_msg = 'Invalid JSON received for link_page_custom_css_vars_json: ' . json_last_error_msg();
        }
    }


    // --- Handle File Uploads ---
    // Background Image for Link Page
    if (!empty($_FILES['link_page_background_image_upload']['tmp_name'])) {
        // Check if image was already uploaded via AJAX by checking CSS variables
        $css_vars_json = isset($_POST['link_page_custom_css_vars_json']) ? wp_unslash($_POST['link_page_custom_css_vars_json']) : '{}';
        $css_vars = json_decode($css_vars_json, true);
        $current_bg_image_url = isset($css_vars['--link-page-background-image-url']) ? $css_vars['--link-page-background-image-url'] : '';
        
        // If the CSS variable contains a server URL (not a data URL), skip upload as it was handled via AJAX
        $is_server_url = !empty($current_bg_image_url) && 
                        strpos($current_bg_image_url, 'url(') === 0 && 
                        strpos($current_bg_image_url, 'data:') === false &&
                        (strpos($current_bg_image_url, site_url()) !== false || strpos($current_bg_image_url, wp_upload_dir()['baseurl']) !== false);
        
        if (!$is_server_url) {
            // Only upload if it wasn't already uploaded via AJAX
            $max_file_size = 5 * 1024 * 1024; // 5MB in bytes
            if ($_FILES['link_page_background_image_upload']['size'] > $max_file_size) {
                // Redirect back with an error message
                $redirect_url = add_query_arg(array('artist_id' => $artist_id, 'bp_link_page_error' => 'background_image_size'), wp_get_referer() ?: (($link_page_manage = get_page_by_path('manage-link-page')) ? get_permalink($link_page_manage) : home_url('/manage-link-page/')));
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
        // If $is_server_url is true, skip upload as it was already handled via AJAX
    }

    // Profile Image (syncs to artist_profile CPT and _link_page_profile_image_id on link_page CPT)
    if ($artist_id && !empty($_FILES['link_page_profile_image_upload']['tmp_name'])) {
        $max_file_size = 5 * 1024 * 1024; // 5MB in bytes
        if ($_FILES['link_page_profile_image_upload']['size'] > $max_file_size) {
            // Redirect back with an error message
            $redirect_url = add_query_arg(array('artist_id' => $artist_id, 'bp_link_page_error' => 'profile_image_size'), wp_get_referer() ?: (($link_page_manage = get_page_by_path('manage-link-page')) ? get_permalink($link_page_manage) : home_url('/manage-link-page/')));
            wp_safe_redirect($redirect_url);
            exit;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        // Upload and associate with artist_profile (sets as featured image)
        $attach_id = media_handle_upload('link_page_profile_image_upload', $artist_id);
        if (is_numeric($attach_id)) {
            $old_link_page_profile_image_id = get_post_meta($link_page_id, '_link_page_profile_image_id', true);
            set_post_thumbnail($artist_id, $attach_id); // Sync to artist_profile featured image
            update_post_meta($link_page_id, '_link_page_profile_image_id', $attach_id); // Save on link_page as well
            if ($old_link_page_profile_image_id && $old_link_page_profile_image_id != $attach_id) {
                 wp_delete_attachment($old_link_page_profile_image_id, true); // Delete old link page specific image if different
            }
        }
    } elseif ($artist_id && isset($_POST['remove_link_page_profile_image']) && $_POST['remove_link_page_profile_image'] === '1') {
        $current_link_page_profile_image_id = get_post_meta($link_page_id, '_link_page_profile_image_id', true);
        if ($current_link_page_profile_image_id) {
            delete_post_meta($link_page_id, '_link_page_profile_image_id');
            // Do not delete from media library, just disassociate. User might want to use artist_profile's featured image.
        }
         // If we remove the link page specific image, we might want to ensure the artist_profile's featured image is NOT cleared
         // unless explicitly told to. For now, this only removes the override.
    }


    // --- Sync other data to artist_profile CPT (MOVED TO END FOR ATOMIC OPERATION) ---
    // Collect sync data first, but don't execute wp_update_post until the very end
    $artist_profile_sync_data = array();
    if ($artist_id && get_post_type($artist_id) === 'artist_profile') {
        // Prepare bio sync (content)
        if (isset($_POST['link_page_bio_text'])) {
            $bio = wp_kses_post(wp_unslash($_POST['link_page_bio_text']));
            if (get_post_field('post_content', $artist_id) !== $bio) {
                $artist_profile_sync_data['post_content'] = $bio;
            }
        }
        // Prepare artist name sync (title)
        if (isset($_POST['artist_profile_title'])) {
            $new_title = sanitize_text_field(wp_unslash($_POST['artist_profile_title']));
            if ($new_title && get_the_title($artist_id) !== $new_title) {
                $artist_profile_sync_data['post_title'] = $new_title;
            }
        }
    }

    // NOTE: Social links are already saved above in the main social links section using _artist_profile_social_links
    // No need for duplicate save logic here

    // --- Featured Link Customization (Title, Desc, Thumbnail) --- 
    // This will now primarily handle the thumbnail and text fields.
    if (function_exists('extrch_save_featured_link_settings')) {
        extrch_save_featured_link_settings($link_page_id, $_POST, $_FILES);
    }

    // --- ATOMIC ARTIST PROFILE SYNC (FINAL STEP) ---
    // Execute artist profile sync at the very end to avoid triggering cascading hooks during save
    if (!empty($artist_profile_sync_data) && $artist_id) {
        
        // Temporarily disable data sync to prevent recursive hooks DURING the update
        if (class_exists('ArtistDataSyncManager')) {
            ArtistDataSyncManager::start_sync();
        } else {
        }
        
        // Perform the artist profile update atomically
        $artist_profile_sync_data['ID'] = $artist_id;
        $update_result = wp_update_post($artist_profile_sync_data);
        
        // Re-enable sync protection and manually trigger sync from artist profile to link page
        if (class_exists('ArtistDataSyncManager')) {
            ArtistDataSyncManager::stop_sync();
            
            // Now manually trigger the sync from artist profile to link page
            if (function_exists('extrch_sync_artist_profile_to_link_page')) {
                $artist_post = get_post($artist_id);
                if ($artist_post) {
                    extrch_sync_artist_profile_to_link_page($artist_id, $artist_post, true);
                } else {
                }
            } else {
            }
        }
        
        if (is_wp_error($update_result)) {
        } else {
        }
    } else {
    }


    // --- Redirect back with success ---
    $redirect_args = array('artist_id' => $artist_id, 'bp_link_page_updated' => '1');
    
    $link_page_manage = get_page_by_path('manage-link-page');
    $base_url = $link_page_manage ? get_permalink($link_page_manage) : home_url('/manage-link-page/');
    
    $redirect_url = add_query_arg($redirect_args, $base_url);
    
    if (!empty($_POST['tab'])) {
        $tab = sanitize_key($_POST['tab']);
        $redirect_url .= '#' . $tab;
    } elseif (!empty($_GET['tab'])) {
        $tab = sanitize_key($_GET['tab']);
        $redirect_url .= '#' . $tab;
    }
    
    
    // Check if the manage page actually exists
    $manage_page = get_page_by_path('manage-link-page');
    
    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_extrch_handle_save_link_page_data', 'extrch_handle_save_link_page_data');
add_action('admin_post_nopriv_extrch_handle_save_link_page_data', 'extrch_handle_save_link_page_data');

// NEW: Handle form submission on init hook for current page submissions (no wp-admin access needed)
function extrch_handle_link_page_form_init() {
    // Only process if this is a POST request with our specific action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
        isset($_POST['extrch_action']) && 
        $_POST['extrch_action'] === 'save_link_page_data' &&
        isset($_POST['bp_save_link_page_nonce'])) {
        
        error_log('[LinkPageSave INIT] Form submission detected via init hook');
        
        // Call the same handler function 
        extrch_handle_save_link_page_data();
    }
}
add_action('init', 'extrch_handle_link_page_form_init');

// Add AJAX handler for background image uploads (for large files)
function extrch_upload_background_image_ajax() {
    // Check nonce - use the same nonce as the main form
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bp_save_link_page_action')) {
        wp_send_json_error('Invalid nonce');
        return;
    }
    
    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error('User not logged in');
        return;
    }
    
    // Check if file was uploaded
    if (empty($_FILES['link_page_background_image_upload']['tmp_name'])) {
        wp_send_json_error('No file uploaded');
        return;
    }
    
    $link_page_id = isset($_POST['link_page_id']) ? absint($_POST['link_page_id']) : 0;
    
    // If no link page ID provided, try to get it from user's artists
    if (!$link_page_id) {
        $current_user_id = get_current_user_id();
        $user_artist_ids = get_user_meta($current_user_id, '_artist_profile_ids', true);
        if (is_array($user_artist_ids) && !empty($user_artist_ids)) {
            // Get the most recent artist's link page
            $artist_id = $user_artist_ids[0];
            $link_page_id = get_post_meta($artist_id, '_extrch_link_page_id', true);
        }
    }
    
    if (!$link_page_id || get_post_type($link_page_id) !== 'artist_link_page') {
        wp_send_json_error('Invalid link page ID');
        return;
    }
    
    // Check if user can edit this link page
    $associated_artist_id = get_post_meta($link_page_id, '_associated_artist_profile_id', true);
    if ($associated_artist_id) {
        $current_user_id = get_current_user_id();
        $user_artist_ids = get_user_meta($current_user_id, '_artist_profile_ids', true);
        if (!is_array($user_artist_ids) || !in_array($associated_artist_id, $user_artist_ids)) {
            wp_send_json_error('Permission denied');
            return;
        }
    }
    
    // Check file size (5MB limit)
    $max_file_size = 5 * 1024 * 1024; // 5MB in bytes
    if ($_FILES['link_page_background_image_upload']['size'] > $max_file_size) {
        wp_send_json_error('File is too large. Maximum size is 5MB.');
        return;
    }
    
    // Include required WordPress functions
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    // Get old background image ID to delete it later
    $old_bg_image_id = get_post_meta($link_page_id, '_link_page_background_image_id', true);
    
    // Upload the file
    $new_bg_image_id = media_handle_upload('link_page_background_image_upload', $link_page_id);
    
    if (is_wp_error($new_bg_image_id)) {
        wp_send_json_error('Upload failed: ' . $new_bg_image_id->get_error_message());
        return;
    }
    
    if (is_numeric($new_bg_image_id)) {
        // Update the meta with new image ID
        update_post_meta($link_page_id, '_link_page_background_image_id', $new_bg_image_id);
        
        // Delete old image if it exists and is different
        if ($old_bg_image_id && $old_bg_image_id != $new_bg_image_id) {
            wp_delete_attachment($old_bg_image_id, true);
        }
        
        // Get the image URL
        $image_url = wp_get_attachment_url($new_bg_image_id);
        
        if ($image_url) {
            wp_send_json_success(array(
                'url' => $image_url,
                'attachment_id' => $new_bg_image_id
            ));
        } else {
            wp_send_json_error('Failed to get image URL');
        }
    } else {
        wp_send_json_error('Upload failed');
    }
}
add_action('wp_ajax_extrch_upload_background_image_ajax', 'extrch_upload_background_image_ajax');
add_action('wp_ajax_nopriv_extrch_upload_background_image_ajax', 'extrch_upload_background_image_ajax');

// The redundant init hook and its logic are now removed.