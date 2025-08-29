<?php
/**
 * Centralized Link Page Save Operations
 * 
 * Consolidates all link page save logic into a single, robust function.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central function to handle all link page save operations
 *
 * @param int $link_page_id The link page ID
 * @param array $save_data Array of data to save
 * @param array $files_data $_FILES array if applicable
 * @return bool|WP_Error True on success, WP_Error on failure
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
        
        // Backward compatibility for overlay
        if ( isset( $save_data['css_vars']['overlay'] ) ) {
            update_post_meta( $link_page_id, '_link_page_overlay_toggle', $save_data['css_vars']['overlay'] === '1' ? '1' : '0' );
        }
    }

    // Advanced settings
    $advanced_fields = array(
        'link_expiration_enabled' => '_link_expiration_enabled',
        'weekly_notifications_enabled' => '_link_page_enable_weekly_notifications',
        'redirect_enabled' => '_link_page_redirect_enabled',
        'redirect_target_url' => '_link_page_redirect_target_url',
        'highlighting_enabled' => '_link_page_enable_highlighting',
        'youtube_embed_enabled' => '_enable_youtube_inline_embed',
        'meta_pixel_id' => '_link_page_meta_pixel_id',
        'google_tag_id' => '_link_page_google_tag_id',
        'featured_link_enabled' => '_enable_featured_link',
        'featured_link_url' => '_featured_link_original_id',
        'social_icons_position' => '_link_page_social_icons_position',
        'overlay_toggle' => '_link_page_overlay_toggle',
        'profile_img_shape' => '_link_page_profile_img_shape',
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

    // Handle file uploads if present
    if ( ! empty( $files_data ) ) {
        ec_handle_link_page_file_uploads( $link_page_id, $files_data );
    }

    // Fire the save action hook for extensibility
    do_action( 'ec_link_page_save', $link_page_id );

    return true;
}

/**
 * Hook sync system into link page saves
 */
function ec_sync_after_link_page_save( $link_page_id ) {
    // Get associated artist profile ID
    $artist_id = ec_get_artist_for_link_page( $link_page_id );
    if ( $artist_id && get_post_type( $artist_id ) === 'artist_profile' ) {
        ec_handle_artist_platform_sync( $artist_id );
    }
}
add_action( 'ec_link_page_save', 'ec_sync_after_link_page_save', 100, 1 );

/**
 * Handle file uploads for link pages
 *
 * @param int $link_page_id The link page ID
 * @param array $files_data $_FILES array
 */
function ec_handle_link_page_file_uploads( $link_page_id, $files_data ) {
    
    if ( ! function_exists( 'wp_handle_upload' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
    }

    $max_file_size = 5 * 1024 * 1024; // 5MB

    // Background image upload
    if ( ! empty( $files_data['link_page_background_image_upload']['tmp_name'] ) ) {
        if ( $files_data['link_page_background_image_upload']['size'] <= $max_file_size ) {
            $old_bg_image_id = get_post_meta( $link_page_id, '_link_page_background_image_id', true );
            $new_bg_image_id = media_handle_upload( 'link_page_background_image_upload', $link_page_id );
            
            if ( is_numeric( $new_bg_image_id ) ) {
                update_post_meta( $link_page_id, '_link_page_background_image_id', $new_bg_image_id );
                if ( $old_bg_image_id && $old_bg_image_id != $new_bg_image_id ) {
                    wp_delete_attachment( $old_bg_image_id, true );
                }
            }
        }
    }

    // Profile image upload
    if ( ! empty( $files_data['link_page_profile_image_upload']['tmp_name'] ) ) {
        if ( $files_data['link_page_profile_image_upload']['size'] <= $max_file_size ) {
            $associated_artist_id = ec_get_artist_for_link_page( $link_page_id );
            if ( $associated_artist_id ) {
                $attach_id = media_handle_upload( 'link_page_profile_image_upload', $associated_artist_id );
                if ( is_numeric( $attach_id ) ) {
                    $old_profile_image_id = get_post_meta( $link_page_id, '_link_page_profile_image_id', true );
                    set_post_thumbnail( $associated_artist_id, $attach_id );
                    update_post_meta( $link_page_id, '_link_page_profile_image_id', $attach_id );
                    if ( $old_profile_image_id && $old_profile_image_id != $attach_id ) {
                        wp_delete_attachment( $old_profile_image_id, true );
                    }
                }
            }
        }
    }
}

/**
 * Prepare save data from POST array
 * 
 * @param array $post_data $_POST array
 * @return array Prepared save data
 */
function ec_prepare_link_page_save_data( $post_data ) {
    $save_data = array();

    // Links data
    if ( isset( $post_data['link_page_links_json'] ) ) {
        $links_json = wp_unslash( $post_data['link_page_links_json'] );
        $links_array = json_decode( $links_json, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $links_array ) ) {
            $save_data['links'] = $links_array;
        }
    }

    // CSS variables
    if ( isset( $post_data['link_page_custom_css_vars_json'] ) ) {
        $css_vars_json = wp_unslash( $post_data['link_page_custom_css_vars_json'] );
        $css_vars = json_decode( $css_vars_json, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $css_vars ) ) {
            $save_data['css_vars'] = $css_vars;
        }
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

    // Featured link
    $save_data['featured_link_enabled'] = isset( $post_data['enable_featured_link'] ) && $post_data['enable_featured_link'] == '1' ? '1' : '0';
    if ( $save_data['featured_link_enabled'] === '1' && isset( $post_data['featured_link_original_id'] ) ) {
        $save_data['featured_link_url'] = esc_url_raw( wp_unslash( $post_data['featured_link_original_id'] ) );
    }

    // UI settings
    if ( isset( $post_data['link_page_social_icons_position'] ) ) {
        $position = sanitize_text_field( $post_data['link_page_social_icons_position'] );
        $save_data['social_icons_position'] = in_array( $position, array( 'above', 'below' ), true ) ? $position : 'above';
    }

    if ( isset( $post_data['link_page_overlay_toggle_present'] ) ) {
        $save_data['overlay_toggle'] = isset( $post_data['link_page_overlay_toggle'] ) && $post_data['link_page_overlay_toggle'] === '1' ? '1' : '0';
    }

    if ( isset( $post_data['link_page_profile_img_shape'] ) ) {
        $shape = sanitize_text_field( $post_data['link_page_profile_img_shape'] );
        $save_data['profile_img_shape'] = in_array( $shape, array( 'circle', 'square', 'rectangle' ), true ) ? $shape : '';
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

    return $save_data;
}

/**
 * Central function to handle all artist profile save operations
 *
 * @param int $artist_id The artist profile ID
 * @param array $save_data Array of data to save
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
        'local_city' => '_local_city',
        'allow_public_topic_creation' => '_allow_public_topic_creation',
        'forum_section_title_override' => '_forum_section_title_override',
        'forum_section_bio_override' => '_forum_section_bio_override'
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

    // Fire the save action hook for extensibility
    do_action( 'ec_artist_profile_save', $artist_id );

    return true;
}

/**
 * Hook sync system into artist profile saves
 */
function ec_sync_after_artist_profile_save( $artist_id ) {
    ec_handle_artist_platform_sync( $artist_id );
}
add_action( 'ec_artist_profile_save', 'ec_sync_after_artist_profile_save', 100, 1 );

/**
 * Handle file uploads for artist profiles
 *
 * @param int $artist_id The artist profile ID
 * @param array $files_data $_FILES array
 */
function ec_handle_artist_profile_file_uploads( $artist_id, $files_data ) {
    
    if ( ! function_exists( 'wp_handle_upload' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
    }

    $max_file_size = 5 * 1024 * 1024; // 5MB

    // Featured image upload
    if ( ! empty( $files_data['featured_image']['tmp_name'] ) ) {
        if ( $files_data['featured_image']['size'] <= $max_file_size && $files_data['featured_image']['error'] == UPLOAD_ERR_OK ) {
            $old_thumbnail_id = get_post_thumbnail_id( $artist_id );
            $new_image_id = media_handle_upload( 'featured_image', $artist_id );
            
            if ( is_numeric( $new_image_id ) ) {
                set_post_thumbnail( $artist_id, $new_image_id );
                if ( $old_thumbnail_id && $old_thumbnail_id != $new_image_id ) {
                    wp_delete_attachment( $old_thumbnail_id, true );
                }
            }
        }
    } elseif ( isset( $files_data['prefill_avatar_id'] ) && is_numeric( $files_data['prefill_avatar_id'] ) ) {
        // Handle prefill avatar for new artist profiles
        $prefill_avatar_id = absint( $files_data['prefill_avatar_id'] );
        if ( wp_attachment_is_image( $prefill_avatar_id ) ) {
            set_post_thumbnail( $artist_id, $prefill_avatar_id );
        }
    }

    // Artist header image upload
    if ( ! empty( $files_data['artist_header_image']['tmp_name'] ) ) {
        if ( $files_data['artist_header_image']['size'] <= $max_file_size && $files_data['artist_header_image']['error'] == UPLOAD_ERR_OK ) {
            $old_header_image_id = get_post_meta( $artist_id, '_artist_profile_header_image_id', true );
            $new_header_image_id = media_handle_upload( 'artist_header_image', $artist_id );
            
            if ( is_numeric( $new_header_image_id ) ) {
                update_post_meta( $artist_id, '_artist_profile_header_image_id', $new_header_image_id );
                if ( $old_header_image_id && $old_header_image_id != $new_header_image_id ) {
                    wp_delete_attachment( $old_header_image_id, true );
                }
            }
        }
    }
}

/**
 * Prepare save data from POST array for artist profiles
 * 
 * @param array $post_data $_POST array
 * @return array Prepared save data
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

    // Forum Settings - Convert checkbox logic
    if ( isset( $post_data['restrict_public_topics'] ) ) {
        $save_data['allow_public_topic_creation'] = '0'; // Checkbox checked means restrict
    } else {
        $save_data['allow_public_topic_creation'] = '1'; // Not checked means allow
    }

    // Forum Section Overrides
    if ( isset( $post_data['forum_section_title_override'] ) ) {
        $forum_title = sanitize_text_field( $post_data['forum_section_title_override'] );
        $save_data['forum_section_title_override'] = ! empty( $forum_title ) ? $forum_title : '';
    }

    if ( isset( $post_data['forum_section_bio_override'] ) ) {
        $forum_bio = wp_kses_post( wp_unslash( $post_data['forum_section_bio_override'] ) );
        $save_data['forum_section_bio_override'] = ! empty( $forum_bio ) ? $forum_bio : '';
    }

    // Member Management
    if ( isset( $post_data['remove_member_ids'] ) ) {
        $save_data['remove_member_ids'] = sanitize_text_field( $post_data['remove_member_ids'] );
    }

    return $save_data;
}