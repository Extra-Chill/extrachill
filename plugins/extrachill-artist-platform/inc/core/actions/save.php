<?php
/**
 * Centralized Save System
 *
 * WordPress native form processing with data preparation, file handling, and security validation.
 * Preparation functions sanitize, handler functions execute, action hooks trigger sync.
 */

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . '../filters/upload.php';

/**
 * Handle link page save operations (links, CSS, settings, files)
 */
function ec_handle_link_page_save( $link_page_id, $save_data = array(), $files_data = array() ) {
    
    if ( ! $link_page_id || get_post_type( $link_page_id ) !== 'artist_link_page' ) {
        return new WP_Error( 'invalid_link_page', 'Invalid link page ID' );
    }

    // Core link page data
    if ( isset( $save_data['links'] ) && is_array( $save_data['links'] ) ) {
        update_post_meta( $link_page_id, '_link_page_links', $save_data['links'] );
    }

    if ( isset( $save_data['css_vars'] ) && is_array( $save_data['css_vars'] ) ) {
        update_post_meta( $link_page_id, '_link_page_custom_css_vars', $save_data['css_vars'] );
        
    }

    // Advanced settings (Advanced tab fields only)
    $advanced_fields = array(
        'link_expiration_enabled' => '_link_expiration_enabled',
        'weekly_notifications_enabled' => '_link_page_enable_weekly_notifications',
        'redirect_enabled' => '_link_page_redirect_enabled',
        'redirect_target_url' => '_link_page_redirect_target_url',
        'highlighting_enabled' => '_link_page_enable_highlighting',
        'youtube_embed_enabled' => '_enable_youtube_inline_embed',
        'meta_pixel_id' => '_link_page_meta_pixel_id',
        'google_tag_id' => '_link_page_google_tag_id',
        'subscribe_display_mode' => '_link_page_subscribe_display_mode',
        'subscribe_description' => '_link_page_subscribe_description'
    );

    foreach ( $advanced_fields as $key => $meta_key ) {
        if ( array_key_exists( $key, $save_data ) ) {
            if ( $save_data[$key] === '' || $save_data[$key] === null ) {
                delete_post_meta( $link_page_id, $meta_key );
            } else {
                update_post_meta( $link_page_id, $meta_key, $save_data[$key] );
            }
        }
    }

    // Handle file uploads and removal if present
    if ( ! empty( $files_data ) || isset( $save_data['remove_profile_image'] ) ) {
        $upload_error = ec_handle_link_page_file_uploads( $link_page_id, $files_data, $save_data );
        if ( $upload_error ) {
            return new WP_Error( 'upload_error', 'File upload error', array( 'error_code' => $upload_error ) );
        }
    }

    // Handle social icons if present
    if ( isset( $save_data['social_icons'] ) && is_array( $save_data['social_icons'] ) ) {
        $artist_id = apply_filters('ec_get_artist_id', $link_page_id);
        if ( $artist_id ) {
            $social_manager = extrachill_artist_platform_social_links();
            $social_result = $social_manager->save( $artist_id, $save_data['social_icons'] );
            
            if ( is_wp_error( $social_result ) ) {
                // Don't fail entire save for social issues
            }
        }
    }

    /**
     * Fires when a link page needs post-save processing.
     * 
     * This action hook triggers essential post-save operations like data
     * synchronization. Other plugins and theme functions can also hook in
     * to perform additional operations after the link page data is saved.
     * 
     * Hooked functions should check for errors and may prevent save completion
     * by returning WP_Error or throwing exceptions.
     * 
     * @since 1.0.0
     * 
     * @param int $link_page_id The ID of the link page that was saved.
     */
    do_action( 'ec_link_page_save', $link_page_id );

    return true;
}

/**
 * Link page save completion handler
 * 
 * Triggered by ec_link_page_save action to complete the save process
 * by triggering the sync action for associated artist profile.
 * 
 * @param int $link_page_id The saved link page ID
 * @return void
 */
function ec_handle_link_page_save_completion( $link_page_id ) {
    $artist_id = apply_filters('ec_get_artist_id', $link_page_id);
    if ( $artist_id && get_post_type( $artist_id ) === 'artist_profile' ) {
        do_action( 'ec_artist_platform_sync', $artist_id );
    }
}
add_action( 'ec_link_page_save', 'ec_handle_link_page_save_completion', 10, 1 );


/**
 * Process social icon form fields (social_type[], social_url[]) into structured array
 */
function ec_process_social_form_fields( $post_data ) {
    $social_data = array();

    $social_types = isset( $post_data['social_type'] ) ? $post_data['social_type'] : array();
    $social_urls = isset( $post_data['social_url'] ) ? $post_data['social_url'] : array();
    
    // Process each social icon - use foreach to preserve DOM/submission order
    foreach ( $social_types as $social_idx => $social_type ) {
        $social_type = sanitize_text_field( $social_type );
        $social_url = isset( $social_urls[$social_idx] ) ? esc_url_raw( wp_unslash( $social_urls[$social_idx] ) ) : '';
        
        // Skip empty social icons
        if ( empty( $social_type ) && empty( $social_url ) ) {
            continue;
        }
        
        // Only add if we have both type and URL
        if ( ! empty( $social_type ) && ! empty( $social_url ) ) {
            $social_data[] = array(
                'type' => $social_type,
                'url' => $social_url
            );
        }
    }
    
    return $social_data;
}

/**
 * Process individual link form fields into structured array
 *
 * Converts nested form field arrays into structured link data with
 * sections, individual links, and expiration times. Handles complex
 * multi-dimensional form structure from drag-and-drop interface.
 *
 * @param array $post_data $_POST array containing form fields
 * @return array Structured links array with sections and links
 */
function ec_process_link_form_fields( $post_data ) {
    $links_data = array();
    
    // Check for section titles
    $section_titles = isset( $post_data['link_section_title'] ) ? $post_data['link_section_title'] : array();
    
    // Check for link texts and URLs
    $link_texts = isset( $post_data['link_text'] ) ? $post_data['link_text'] : array();
    $link_urls = isset( $post_data['link_url'] ) ? $post_data['link_url'] : array();
    $link_expires = isset( $post_data['link_expires_at'] ) ? $post_data['link_expires_at'] : array();
    $link_ids = isset( $post_data['link_id'] ) ? $post_data['link_id'] : array();
    
    // Process each section - use foreach to preserve DOM/submission order
    foreach ( $section_titles as $section_idx => $section_title_value ) {
        $section_data = array(
            'section_title' => sanitize_text_field( wp_unslash( $section_title_value ) ),
            'links' => array()
        );
        
        // Process links for this section
        if ( isset( $link_texts[$section_idx] ) && is_array( $link_texts[$section_idx] ) ) {
            foreach ( $link_texts[$section_idx] as $link_idx => $link_text ) {
                $link_url = isset( $link_urls[$section_idx][$link_idx] ) ? esc_url_raw( wp_unslash( $link_urls[$section_idx][$link_idx] ) ) : '';
                $link_text = sanitize_text_field( wp_unslash( $link_text ) );
                
                // Skip empty links
                if ( empty( $link_text ) && empty( $link_url ) ) {
                    continue;
                }
                
                $link_data = array(
                    'link_text' => $link_text,
                    'link_url' => $link_url,
                    'id' => isset( $link_ids[$section_idx][$link_idx] ) ? sanitize_text_field( $link_ids[$section_idx][$link_idx] ) : 'link_' . time() . '_' . wp_rand()
                );
                
                // Add expiration if available
                if ( isset( $link_expires[$section_idx][$link_idx] ) ) {
                    $expires_at = sanitize_text_field( wp_unslash( $link_expires[$section_idx][$link_idx] ) );
                    if ( ! empty( $expires_at ) ) {
                        $link_data['expires_at'] = $expires_at;
                    }
                }
                
                $section_data['links'][] = $link_data;
            }
        }
        
        // Only add section if it has content
        if ( ! empty( $section_data['section_title'] ) || ! empty( $section_data['links'] ) ) {
            $links_data[] = $section_data;
        }
    }
    
    return $links_data;
}

/**
 * Prepare save data from POST array for link pages
 * 
 * Sanitizes and validates all form data for link page saves.
 * Processes links, social icons, CSS variables, advanced settings,
 * and converts form values to appropriate storage formats.
 * 
 * @param array $post_data $_POST array from form submission
 * @return array Prepared and sanitized save data
 */
function ec_prepare_link_page_save_data( $post_data ) {
    $save_data = array();

    // Links data - Process individual form fields instead of JSON
    $save_data['links'] = ec_process_link_form_fields( $post_data );

    // Social icons data - Process individual form fields instead of JSON
    $save_data['social_icons'] = ec_process_social_form_fields( $post_data );

    // CSS variables - Read directly from form inputs (no JSON intermediary)
    $css_vars = array();
    
    // Colors
    if ( isset( $post_data['link_page_button_color'] ) ) {
        $css_vars['--link-page-button-bg-color'] = sanitize_hex_color( $post_data['link_page_button_color'] );
    }
    if ( isset( $post_data['link_page_text_color'] ) ) {
        $css_vars['--link-page-text-color'] = sanitize_hex_color( $post_data['link_page_text_color'] );
    }
    if ( isset( $post_data['link_page_link_text_color'] ) ) {
        $css_vars['--link-page-link-text-color'] = sanitize_hex_color( $post_data['link_page_link_text_color'] );
    }
    if ( isset( $post_data['link_page_hover_color'] ) ) {
        $css_vars['--link-page-button-hover-bg-color'] = sanitize_hex_color( $post_data['link_page_hover_color'] );
    }
    if ( isset( $post_data['link_page_button_border_color'] ) ) {
        $css_vars['--link-page-button-border-color'] = sanitize_hex_color( $post_data['link_page_button_border_color'] );
    }
    
    // Background
    if ( isset( $post_data['link_page_background_type'] ) ) {
        $bg_type = sanitize_text_field( $post_data['link_page_background_type'] );
        if ( in_array( $bg_type, array( 'color', 'gradient', 'image' ) ) ) {
            $css_vars['--link-page-background-type'] = $bg_type;
        }
    }
    if ( isset( $post_data['link_page_background_color'] ) ) {
        $css_vars['--link-page-background-color'] = sanitize_hex_color( $post_data['link_page_background_color'] );
    }
    if ( isset( $post_data['link_page_background_gradient_start'] ) ) {
        $css_vars['--link-page-background-gradient-start'] = sanitize_hex_color( $post_data['link_page_background_gradient_start'] );
    }
    if ( isset( $post_data['link_page_background_gradient_end'] ) ) {
        $css_vars['--link-page-background-gradient-end'] = sanitize_hex_color( $post_data['link_page_background_gradient_end'] );
    }
    if ( isset( $post_data['link_page_background_gradient_direction'] ) ) {
        $direction = sanitize_text_field( $post_data['link_page_background_gradient_direction'] );
        if ( in_array( $direction, array( 'to right', 'to bottom', '135deg' ) ) ) {
            $css_vars['--link-page-background-gradient-direction'] = $direction;
        }
    }
    
    
    // Typography
    if ( isset( $post_data['link_page_title_font_family'] ) ) {
        $css_vars['--link-page-title-font-family'] = sanitize_text_field( $post_data['link_page_title_font_family'] );
    }
    if ( isset( $post_data['link_page_title_font_size'] ) ) {
        // Convert slider value (0-100) to em using original formula
        $slider_percentage = absint( $post_data['link_page_title_font_size'] );
        $font_size_min_em = 0.8;
        $font_size_max_em = 3.5;
        $em_value = $font_size_min_em + ($font_size_max_em - $font_size_min_em) * ($slider_percentage / 100);
        $css_vars['--link-page-title-font-size'] = round($em_value, 2) . 'em';
    }
    if ( isset( $post_data['link_page_body_font_family'] ) ) {
        $css_vars['--link-page-body-font-family'] = sanitize_text_field( $post_data['link_page_body_font_family'] );
    }
    // Removed body font size processing - uses theme default font size
    
    // Button styling
    if ( isset( $post_data['link_page_button_radius'] ) ) {
        $button_radius = absint( $post_data['link_page_button_radius'] );
        $css_vars['--link-page-button-radius'] = $button_radius . 'px';
    }
    
    // Profile image settings
    if ( isset( $post_data['link_page_profile_img_size'] ) ) {
        $profile_size = absint( $post_data['link_page_profile_img_size'] );
        $css_vars['--link-page-profile-img-size'] = $profile_size . '%';
    }
    if ( isset( $post_data['link_page_profile_img_shape'] ) ) {
        $profile_shape = sanitize_text_field( $post_data['link_page_profile_img_shape'] );
        if ( in_array( $profile_shape, array( 'circle', 'square', 'rectangle' ) ) ) {
            $css_vars['--link-page-profile-img-shape'] = $profile_shape;
        }
    }
    
    // Overlay
    if ( isset( $post_data['link_page_overlay_toggle_present'] ) ) {
        $css_vars['overlay'] = isset( $post_data['link_page_overlay_toggle'] ) && $post_data['link_page_overlay_toggle'] === '1' ? '1' : '0';
    }
    
    if ( ! empty( $css_vars ) ) {
        $save_data['css_vars'] = $css_vars;
    }

    // Advanced settings
    $save_data['link_expiration_enabled'] = isset( $post_data['link_expiration_enabled_advanced'] ) && $post_data['link_expiration_enabled_advanced'] == '1' ? '1' : '0';
    $save_data['weekly_notifications_enabled'] = isset( $post_data['link_page_enable_weekly_notifications'] ) && $post_data['link_page_enable_weekly_notifications'] == '1' ? '1' : '0';
    $save_data['redirect_enabled'] = isset( $post_data['link_page_redirect_enabled'] ) && $post_data['link_page_redirect_enabled'] == '1' ? '1' : '0';
    
    if ( $save_data['redirect_enabled'] === '1' && isset( $post_data['link_page_redirect_target_url'] ) ) {
        $save_data['redirect_target_url'] = esc_url_raw( wp_unslash( $post_data['link_page_redirect_target_url'] ) );
    }

    $save_data['highlighting_enabled'] = isset( $post_data['link_page_enable_highlighting'] ) && $post_data['link_page_enable_highlighting'] == '1' ? '1' : '0';
    
    // YouTube embed (inverted logic - checkbox disables feature)
    $save_data['youtube_embed_enabled'] = isset( $post_data['disable_youtube_inline_embed'] ) && $post_data['disable_youtube_inline_embed'] == '1' ? '0' : '1';

    // Tracking IDs
    if ( isset( $post_data['link_page_meta_pixel_id'] ) ) {
        $meta_pixel = trim( wp_unslash( $post_data['link_page_meta_pixel_id'] ) );
        $save_data['meta_pixel_id'] = empty( $meta_pixel ) ? '' : ( ctype_digit( $meta_pixel ) ? $meta_pixel : '' );
    }

    if ( isset( $post_data['link_page_google_tag_id'] ) ) {
        $google_tag = trim( wp_unslash( $post_data['link_page_google_tag_id'] ) );
        $save_data['google_tag_id'] = empty( $google_tag ) ? '' : ( preg_match( '/^(G|AW)-[a-zA-Z0-9]+$/', $google_tag ) ? $google_tag : '' );
    }

    // UI settings
    if ( isset( $post_data['link_page_social_icons_position'] ) ) {
        $position = sanitize_text_field( $post_data['link_page_social_icons_position'] );
        $save_data['social_icons_position'] = in_array( $position, array( 'above', 'below' ), true ) ? $position : 'above';
    }

    // Subscription settings
    if ( isset( $post_data['link_page_subscribe_display_mode'] ) ) {
        $mode = sanitize_text_field( $post_data['link_page_subscribe_display_mode'] );
        $save_data['subscribe_display_mode'] = in_array( $mode, array( 'icon_modal', 'inline_form', 'disabled' ), true ) ? $mode : '';
    }

    if ( isset( $post_data['link_page_subscribe_description'] ) ) {
        $description = trim( wp_unslash( $post_data['link_page_subscribe_description'] ) );
        $save_data['subscribe_description'] = $description !== '' ? $description : '';
    }

    // Profile image removal
    if ( isset( $post_data['remove_link_page_profile_image'] ) ) {
        $save_data['remove_profile_image'] = $post_data['remove_link_page_profile_image'] === '1';
    }

    return $save_data;
}

/**
 * Central function to handle all artist profile save operations
 *
 * Processes and saves artist profile data including post title/content,
 * meta fields, file uploads, and member management. Triggers post-save
 * synchronization via action hooks.
 *
 * @param int $artist_id The artist profile ID
 * @param array $save_data Array of prepared save data
 * @param array $files_data $_FILES array if applicable
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function ec_handle_artist_profile_save( $artist_id, $save_data = array(), $files_data = array() ) {
    
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return new WP_Error( 'invalid_artist_profile', 'Invalid artist profile ID' );
    }

    // Prepare post update data
    $post_data = array( 'ID' => $artist_id );
    $perform_post_update = false;

    // Handle title update
    if ( isset( $save_data['post_title'] ) && ! empty( $save_data['post_title'] ) ) {
        $current_title = get_the_title( $artist_id );
        if ( $current_title !== $save_data['post_title'] ) {
            $post_data['post_title'] = $save_data['post_title'];
            $perform_post_update = true;
        }
    }

    // Handle content (bio) update
    if ( isset( $save_data['post_content'] ) ) {
        $current_content = get_post_field( 'post_content', $artist_id );
        if ( $current_content !== $save_data['post_content'] ) {
            $post_data['post_content'] = $save_data['post_content'];
            $perform_post_update = true;
        }
    }

    // Perform post update if needed
    if ( $perform_post_update ) {
        $update_result = wp_update_post( $post_data, true );
        if ( is_wp_error( $update_result ) ) {
            return $update_result;
        }
    }

    // Handle meta data updates
    $meta_fields = array(
        'genre' => '_genre',
        'local_city' => '_local_city'
    );

    foreach ( $meta_fields as $key => $meta_key ) {
        if ( array_key_exists( $key, $save_data ) ) {
            if ( $save_data[$key] === '' || $save_data[$key] === null ) {
                delete_post_meta( $artist_id, $meta_key );
            } else {
                update_post_meta( $artist_id, $meta_key, $save_data[$key] );
            }
        }
    }

    // Handle file uploads if present
    if ( ! empty( $files_data ) ) {
        ec_handle_artist_profile_file_uploads( $artist_id, $files_data );
    }

    // Handle member management
    if ( isset( $save_data['remove_member_ids'] ) && ! empty( $save_data['remove_member_ids'] ) ) {
        $current_user_id = get_current_user_id();
        $user_ids_to_remove = array_filter( array_map( 'absint', explode( ',', $save_data['remove_member_ids'] ) ) );
        
        foreach ( $user_ids_to_remove as $user_id_to_remove ) {
            if ( $user_id_to_remove > 0 && $user_id_to_remove !== $current_user_id ) { 
                if ( function_exists( 'bp_remove_artist_membership' ) ) {
                    bp_remove_artist_membership( $user_id_to_remove, $artist_id );
                }
            }
        }
    }

    /**
     * Fires when an artist profile needs post-save processing.
     * 
     * This action hook triggers essential post-save operations like data
     * synchronization. Other plugins and theme functions can also hook in
     * to perform additional operations after the artist profile data is saved.
     * 
     * Hooked functions should check for errors and may prevent save completion
     * by returning WP_Error or throwing exceptions.
     * 
     * @since 1.0.0
     * 
     * @param int $artist_id The ID of the artist profile that was saved.
     */
    do_action( 'ec_artist_profile_save', $artist_id );

    return true;
}

/**
 * Artist profile save completion handler
 * 
 * Triggered by ec_artist_profile_save action to complete the save process
 * by triggering cross-system synchronization.
 * 
 * @param int $artist_id The saved artist profile ID
 * @return void
 */
function ec_handle_artist_profile_save_completion( $artist_id ) {
    // Trigger sync action directly
    do_action( 'ec_artist_platform_sync', $artist_id );
}
add_action( 'ec_artist_profile_save', 'ec_handle_artist_profile_save_completion', 10, 1 );


/**
 * Prepare save data from POST array for artist profiles
 * 
 * Sanitizes and validates all form data for artist profile saves.
 * Handles title, bio, genre, location, forum settings, and member
 * management data with proper sanitization.
 * 
 * @param array $post_data $_POST array from form submission
 * @return array Prepared and sanitized save data
 */
function ec_prepare_artist_profile_save_data( $post_data ) {
    $save_data = array();

    // Title (required)
    if ( isset( $post_data['artist_title'] ) ) {
        $save_data['post_title'] = sanitize_text_field( $post_data['artist_title'] );
    }

    // Bio (Content)
    if ( isset( $post_data['artist_bio'] ) ) {
        $save_data['post_content'] = wp_kses_post( wp_unslash( $post_data['artist_bio'] ) );
    }

    // Genre
    if ( isset( $post_data['genre'] ) ) {
        $save_data['genre'] = sanitize_text_field( $post_data['genre'] );
    }

    // Local Scene (City)
    if ( isset( $post_data['local_city'] ) ) {
        $save_data['local_city'] = sanitize_text_field( $post_data['local_city'] );
    }

    // Member Management
    if ( isset( $post_data['remove_member_ids'] ) ) {
        $save_data['remove_member_ids'] = sanitize_text_field( $post_data['remove_member_ids'] );
    }

    return $save_data;
}

/**
 * Admin post handler for link page form submissions
 * 
 * Handles secure form submission processing with nonce verification,
 * permission checks, data preparation, and save execution. Redirects
 * back to management interface with appropriate feedback.
 * 
 * @return void Dies on error, redirects on success
 */
function ec_admin_post_save_link_page() {
    // Verify nonce
    if ( ! isset( $_POST['bp_save_link_page_nonce'] ) || 
         ! wp_verify_nonce( $_POST['bp_save_link_page_nonce'], 'bp_save_link_page_action' ) ) {
        wp_die( __( 'Security check failed.', 'extrachill-artist-platform' ) );
    }
    
    // Check user is logged in
    if ( ! is_user_logged_in() ) {
        wp_die( __( 'Permission denied: You must be logged in to save changes.', 'extrachill-artist-platform' ) );
    }
    
    // Get link page and artist IDs directly from form data
    $link_page_id = absint($_POST['link_page_id']);
    $artist_id = absint($_POST['artist_id']);
    
    if ( ! $link_page_id || get_post_type( $link_page_id ) !== 'artist_link_page' ) {
        wp_die( __( 'Invalid link page.', 'extrachill-artist-platform' ) );
    }
    
    // Check user permissions
    if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
        wp_die( __( 'Permission denied: You do not have access to manage this artist.', 'extrachill-artist-platform' ) );
    }
    
    // Prepare and save data using centralized functions
    $save_data = ec_prepare_link_page_save_data( $_POST );
    $result = ec_handle_link_page_save( $link_page_id, $save_data, $_FILES );
    
    if ( is_wp_error( $result ) ) {
        // Handle upload errors with proper redirects
        $error_data = $result->get_error_data();
        if ( isset( $error_data['error_code'] ) ) {
            $redirect_args = array( 'artist_id' => $artist_id, 'bp_link_page_error' => $error_data['error_code'] );
        } else {
            $redirect_args = array( 'artist_id' => $artist_id, 'bp_link_page_error' => 'general' );
        }
        $manage_page = get_page_by_path( 'manage-link-page' );
        $base_url = $manage_page ? get_permalink( $manage_page ) : home_url( '/manage-link-page/' );
        $redirect_url = add_query_arg( $redirect_args, $base_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }
    
    // Redirect back with success message
    $redirect_args = array( 'artist_id' => $artist_id, 'bp_link_page_updated' => '1' );
    $manage_page = get_page_by_path( 'manage-link-page' );
    $base_url = $manage_page ? get_permalink( $manage_page ) : home_url( '/manage-link-page/' );
    $redirect_url = add_query_arg( $redirect_args, $base_url );
    
    wp_safe_redirect( $redirect_url );
    exit;
}
add_action( 'template_redirect', 'ec_handle_link_page_form_submission' );

function ec_handle_link_page_form_submission() {
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && 
         isset($_POST['action']) && 
         $_POST['action'] === 'ec_save_link_page' ) {
        ec_admin_post_save_link_page();
    }
}