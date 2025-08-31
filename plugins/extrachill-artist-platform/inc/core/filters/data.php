<?php
/**
 * Centralized data helper functions for ExtraChill Artist Platform
 * 
 * Single source of truth for common data retrieval patterns.
 * Replaces scattered get_post_meta calls throughout the codebase.
 */


/**
 * Get all artist profile IDs for a user
 * 
 * @param int $user_id User ID (defaults to current user)
 * @return array Array of artist profile IDs
 */
function ec_get_user_artist_ids( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return array();
    }
    
    $artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
    if ( ! is_array( $artist_ids ) ) {
        return array();
    }
    
    return array_map( 'intval', $artist_ids );
}

/**
 * Get the forum ID associated with an artist profile
 * 
 * @param int $artist_id Artist profile post ID
 * @return int|false Forum ID or false if not found
 */
function ec_get_forum_for_artist( $artist_id ) {
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return false;
    }
    
    $forum_id = get_post_meta( $artist_id, '_artist_forum_id', true );
    return $forum_id ? (int) $forum_id : false;
}

/**
 * Check if a user is a member of a specific artist profile
 * 
 * @param int $user_id User ID (defaults to current user)
 * @param int $artist_id Artist profile ID
 * @return bool True if user is a member
 */
function ec_is_user_artist_member( $user_id = null, $artist_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id || ! $artist_id ) {
        return false;
    }
    
    $user_artist_ids = ec_get_user_artist_ids( $user_id );
    return in_array( (int) $artist_id, $user_artist_ids );
}

/**
 * Get all followed artist profile IDs for a user
 * 
 * @param int $user_id User ID (defaults to current user)
 * @return array Array of followed artist profile IDs
 */
function ec_get_user_followed_artists( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return array();
    }
    
    $followed_ids = get_user_meta( $user_id, '_followed_artist_profile_ids', true );
    if ( ! is_array( $followed_ids ) ) {
        return array();
    }
    
    return array_map( 'intval', $followed_ids );
}

/**
 * Check if a user is following a specific artist
 * 
 * @param int $user_id User ID (defaults to current user)
 * @param int $artist_id Artist profile ID
 * @return bool True if user is following the artist
 */
function ec_is_user_following_artist( $user_id = null, $artist_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id || ! $artist_id ) {
        return false;
    }
    
    $followed_artists = ec_get_user_followed_artists( $user_id );
    return in_array( (int) $artist_id, $followed_artists );
}

/**
 * Get the link page ID associated with an artist profile
 * 
 * @param int $artist_id Artist profile post ID
 * @return int|false Link page ID or false if not found
 */
function ec_get_link_page_for_artist( $artist_id ) {
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return false;
    }
    
    // Query for link page with this artist association
    $link_pages = get_posts( array(
        'post_type' => 'artist_link_page',
        'meta_key' => '_associated_artist_profile_id',
        'meta_value' => (string) $artist_id,
        'posts_per_page' => 1,
        'fields' => 'ids'
    ) );
    
    return ! empty( $link_pages ) ? (int) $link_pages[0] : false;
}

/**
 * Get all artist profiles for current user (with caching)
 * 
 * @param int $user_id User ID (defaults to current user)
 * @return array Array of WP_Post objects for artist profiles
 */
function ec_get_user_artist_profiles( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return array();
    }
    
    $artist_ids = ec_get_user_artist_ids( $user_id );
    if ( empty( $artist_ids ) ) {
        return array();
    }
    
    return get_posts( array(
        'post_type' => 'artist_profile',
        'post__in' => $artist_ids,
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ) );
}

/**
 * Get all subscribers for an artist profile
 * 
 * @param int $artist_id Artist profile post ID
 * @param array $args Optional arguments for pagination/filtering
 * @return array Array of subscriber data
 */
function ec_get_artist_subscribers( $artist_id, $args = array() ) {
    global $wpdb;
    
    if ( ! $artist_id ) {
        return array();
    }
    
    $defaults = array(
        'per_page' => 20,
        'page' => 1,
        'include_exported' => false
    );
    $args = wp_parse_args( $args, $defaults );
    
    $table_name = $wpdb->prefix . 'artist_subscribers';
    $offset = ( $args['page'] - 1 ) * $args['per_page'];
    
    $where_clause = $wpdb->prepare( "WHERE artist_profile_id = %d", $artist_id );
    if ( ! $args['include_exported'] ) {
        $where_clause .= " AND (exported = 0 OR exported IS NULL)";
    }
    
    $sql = $wpdb->prepare(
        "SELECT * FROM {$table_name} {$where_clause} ORDER BY subscription_date DESC LIMIT %d OFFSET %d",
        $args['per_page'],
        $offset
    );
    
    return $wpdb->get_results( $sql );
}

/**
 * Get comprehensive link page data for management interface
 * 
 * Single source of truth for all link page settings, CSS variables, links, and social data.
 * Replaces scattered get_post_meta calls throughout templates and JavaScript.
 * 
 * @param int $artist_id Artist profile post ID
 * @param int $link_page_id Link page post ID (will be determined if not provided)
 * @param array $overrides Optional override data from live preview form changes
 * @return array Comprehensive link page data array
 */
function ec_get_link_page_data( $artist_id, $link_page_id = null, $overrides = array() ) {
    if ( ! $artist_id ) {
        return array();
    }
    
    // Get link page ID if not provided
    if ( ! $link_page_id ) {
        $link_page_id = ec_get_link_page_for_artist( $artist_id );
    }
    
    if ( ! $link_page_id ) {
        return array();
    }
    
    // Get all link page meta data
    $all_meta = get_post_meta( $link_page_id );
    
    // Structure the data into logical sections
    $data = array(
        // Basic IDs
        'artist_id' => (int) $artist_id,
        'link_page_id' => (int) $link_page_id,
        
        // CSS Variables (from _link_page_custom_css_vars meta)
        'css_vars' => array(),
        
        // Links data (from _link_page_links meta)
        'links' => array(),
        
        // Social links (from _artist_profile_social_links meta on artist profile)
        'socials' => array(),
        
        // Advanced settings
        'settings' => array(
            // Expiration settings
            'link_expiration_enabled' => false,
            'weekly_notifications_enabled' => false,
            
            // Redirect settings  
            'redirect_enabled' => false,
            'redirect_target_url' => '',
            
            // Feature toggles
            'youtube_embed_enabled' => true, // Default true
            
            // Analytics
            'meta_pixel_id' => '',
            'google_tag_id' => '',
            'google_tag_manager_id' => '',
            
            // Display settings
            'subscribe_display_mode' => 'icon_modal',
            'subscribe_description' => '',
            'social_icons_position' => 'above',
            'profile_image_shape' => 'circle',
            'overlay_enabled' => true
        )
    );
    
    // Parse CSS variables from meta with defaults as single source of truth
    $css_vars_raw = array();
    if ( isset( $all_meta['_link_page_custom_css_vars'][0] ) ) {
        $css_vars_raw = maybe_unserialize( $all_meta['_link_page_custom_css_vars'][0] );
        if ( ! is_array( $css_vars_raw ) ) {
            $css_vars_raw = array();
        }
    }
    
    // Merge with defaults from centralized system
    $default_css_vars = ec_get_link_page_defaults_for( 'styles' );
    $data['css_vars'] = array_merge( $default_css_vars, $css_vars_raw );
    
    // Set button hover text color to match button text color (link text color) 
    $data['css_vars']['--link-page-button-hover-text-color'] = $data['css_vars']['--link-page-link-text-color'];
    
    // Store raw font values separately for form population (before font stack processing)
    $data['raw_font_values'] = array(
        'title_font' => $data['css_vars']['--link-page-title-font-family'] ?? '',
        'body_font' => $data['css_vars']['--link-page-body-font-family'] ?? ''
    );
    
    // Process fonts through dedicated font filter for CSS output
    if ( class_exists( 'ExtraChillArtistPlatform_Fonts' ) ) {
        $font_manager = ExtraChillArtistPlatform_Fonts::instance();
        $data['css_vars'] = $font_manager->process_font_css_vars( $data['css_vars'] );
    }
    
    // Parse links data from meta
    if ( isset( $all_meta['_link_page_links'][0] ) ) {
        $links_raw = maybe_unserialize( $all_meta['_link_page_links'][0] );
        if ( is_array( $links_raw ) ) {
            $data['links'] = $links_raw;
        }
    }
    
    // Get social links from artist profile (not link page) - check both possible meta keys
    $artist_social_links = get_post_meta( $artist_id, '_artist_profile_social_links', true );
    $artist_social_links = maybe_unserialize( $artist_social_links ); // Explicit unserialize
    if ( ! is_array( $artist_social_links ) || empty( $artist_social_links ) ) {
        $social_json = get_post_meta( $artist_id, '_artist_social_links_json', true );
        if ( ! empty( $social_json ) ) {
            $artist_social_links = json_decode( $social_json, true );
        }
    }
    if ( is_array( $artist_social_links ) ) {
        $data['socials'] = $artist_social_links;
    }
    
    // Parse advanced settings with proper defaults
    $data['settings']['link_expiration_enabled'] = isset( $all_meta['_link_expiration_enabled'][0] ) && $all_meta['_link_expiration_enabled'][0] === '1';
    $data['settings']['weekly_notifications_enabled'] = isset( $all_meta['_link_page_enable_weekly_notifications'][0] ) && $all_meta['_link_page_enable_weekly_notifications'][0] === '1';
    $data['settings']['redirect_enabled'] = isset( $all_meta['_link_page_redirect_enabled'][0] ) && $all_meta['_link_page_redirect_enabled'][0] === '1';
    $data['settings']['redirect_target_url'] = $all_meta['_link_page_redirect_target_url'][0] ?? '';
    $data['settings']['youtube_embed_enabled'] = ! isset( $all_meta['_enable_youtube_inline_embed'][0] ) || $all_meta['_enable_youtube_inline_embed'][0] !== '0';
    $data['settings']['meta_pixel_id'] = $all_meta['_link_page_meta_pixel_id'][0] ?? '';
    $data['settings']['google_tag_id'] = $all_meta['_link_page_google_tag_id'][0] ?? '';
    $data['settings']['google_tag_manager_id'] = $all_meta['_link_page_google_tag_manager_id'][0] ?? '';
    $data['settings']['subscribe_display_mode'] = $all_meta['_link_page_subscribe_display_mode'][0] ?? 'icon_modal';
    $data['settings']['subscribe_description'] = $all_meta['_link_page_subscribe_description'][0] ?? '';
    $data['settings']['social_icons_position'] = $all_meta['_link_page_social_icons_position'][0] ?? 'above';
    $data['settings']['profile_image_shape'] = $all_meta['_link_page_profile_img_shape'][0] ?? 'circle';
    
    // Parse overlay setting from CSS vars (special case)
    if ( isset( $data['css_vars']['overlay'] ) ) {
        $data['settings']['overlay_enabled'] = $data['css_vars']['overlay'] === '1';
    }
    
    // Build display data for template consumption (with override support)
    $display_data = array(
        // Basic display fields (with override support - only use override if it exists and has value)
        'display_title' => (isset($overrides['artist_profile_title']) && $overrides['artist_profile_title'] !== '') ? $overrides['artist_profile_title'] : ($artist_id ? get_the_title($artist_id) : ''),
        'bio' => (isset($overrides['link_page_bio_text']) && $overrides['link_page_bio_text'] !== '') ? $overrides['link_page_bio_text'] : ($artist_id ? get_post($artist_id)->post_content : ''),
        'profile_img_url' => (isset($overrides['profile_img_url']) && $overrides['profile_img_url'] !== '') ? $overrides['profile_img_url'] : ($artist_id ? (get_the_post_thumbnail_url($artist_id, 'large') ?: '') : ''),
        
        // Social links (with override support)
        'social_links' => isset($overrides['social_links']) ? $overrides['social_links'] : $data['socials'],
        
        // Process social links JSON if present in overrides
        'socials' => $data['socials'], // Keep original structure
        
        // Link sections (with override support)
        'link_sections' => isset($data['links'][0]['links']) || empty($data['links']) ? $data['links'] : array(array('section_title' => '', 'links' => $data['links'])),
        
        // CSS Variables (with override support)
        'css_vars' => $data['css_vars'],
        
        // Background type extracted from CSS vars for preview template data-bg-type attribute
        'background_type' => $data['css_vars']['--link-page-background-type'] ?? 'color',
        
        // Background style (computed from CSS vars)
        'background_style' => '', // Will be computed from CSS vars if needed
        
        // Settings and metadata
        'powered_by' => true,
        'artist_id' => $artist_id,
        'link_page_id' => $link_page_id,
        
        // Profile image shape setting
        'profile_img_shape' => $data['settings']['profile_image_shape'],
        
        // Social icons position
        '_link_page_social_icons_position' => $data['settings']['social_icons_position'],
        
        // Subscribe settings
        '_link_page_subscribe_display_mode' => $data['settings']['subscribe_display_mode'],
        '_link_page_subscribe_description' => $data['settings']['subscribe_description'],
        
        // Link page ID for templates
        '_actual_link_page_id_for_template' => $link_page_id,
        
        // Artist profile object
        'artist_profile' => $artist_id ? get_post($artist_id) : null,
        
        // Original structured data for complex operations
        'settings' => $data['settings'],
        'links' => $data['links'],
        
        // Raw font values for form population (before font stack processing)
        'raw_font_values' => $data['raw_font_values'],
    );
    
    // Handle social links JSON override
    if (isset($overrides['artist_profile_social_links_json'])) {
        $social_decoded = json_decode($overrides['artist_profile_social_links_json'], true);
        if (is_array($social_decoded)) {
            $display_data['social_links'] = $social_decoded;
            $display_data['socials'] = $social_decoded;
        }
    }
    
    // Handle links JSON override
    if (isset($overrides['link_page_links_json'])) {
        $links_decoded = json_decode($overrides['link_page_links_json'], true);
        if (is_array($links_decoded)) {
            $display_data['links'] = $links_decoded;
            $display_data['link_sections'] = isset($links_decoded[0]['links']) || empty($links_decoded) ? $links_decoded : array(array('section_title' => '', 'links' => $links_decoded));
        }
    }
    
    // Handle CSS vars JSON override
    if (isset($overrides['css_vars'])) {
        $display_data['css_vars'] = is_array($overrides['css_vars']) ? $overrides['css_vars'] : array();
    }
    
    // Apply WordPress filter for extensibility
    return apply_filters( 'extrch_get_link_page_data', $display_data, $artist_id, $link_page_id, $overrides );
}

/**
 * Generate CSS variables style block (centralized CSS generation function)
 * 
 * @param array $css_vars CSS variables array
 * @param string $element_id CSS style element ID
 * @return string Generated CSS style block
 */
function ec_generate_css_variables_style_block( $css_vars, $element_id = 'link-page-custom-vars' ) {
    if ( empty( $css_vars ) || ! is_array( $css_vars ) ) {
        return '';
    }
    
    $output = '<style id="' . esc_attr( $element_id ) . '">:root {';
    foreach ( $css_vars as $key => $value ) {
        // Output all CSS variables to ensure JavaScript has complete structure
        // Only skip null/false values, but include empty strings (user may want empty values)
        if ( $value !== null && $value !== false ) {
            // CSS keys should be escaped as HTML, but CSS values should not be escaped
            // since they're inside <style> tags and may contain valid CSS syntax like quotes
            $output .= esc_html( $key ) . ':' . $value . ';';
        }
    }
    $output .= '}</style>';
    
    return $output;
}

/**
 * Render artist switcher component (Global Helper Function)
 * 
 * @param array $args Template arguments for the switcher
 *   - switcher_id: HTML element ID
 *   - base_url: URL to redirect to when switching artists  
 *   - current_artist_id: Currently selected artist ID
 *   - user_id: User ID to get artist list for
 *   - css_class: Additional CSS classes
 *   - label_text: Select option label
 */

/**
 * Render a single link using unified template system
 * 
 * @param array $link_data Link data with 'link_url' and 'link_text' keys
 * @param array $args Additional template arguments
 * @return string Rendered HTML
 */
function ec_render_single_link( $link_data, $args = array() ) {
    $template_args = array_merge( $args, $link_data );
    return ec_render_template( 'single-link', $template_args );
}

/**
 * Render a complete link section using unified template system
 * 
 * @param array $section_data Section data with 'section_title' and 'links' keys
 * @param array $args Additional template arguments
 * @return string Rendered HTML
 */
function ec_render_link_section( $section_data, $args = array() ) {
    $template_args = array_merge( $args, $section_data );
    return ec_render_template( 'link-section', $template_args );
}

/**
 * Render a single social icon using unified template system
 * 
 * @param array $social_data Social data with 'url' and 'type' keys
 * @param object $social_manager Optional social links manager instance
 * @return string Rendered HTML
 */
function ec_render_social_icon( $social_data, $social_manager = null ) {
    $template_args = array(
        'social_data' => $social_data,
        'social_manager' => $social_manager
    );
    return ec_render_template( 'social-icon', $template_args );
}

/**
 * Render a container with multiple social icons using unified template system
 * 
 * @param array $social_links Array of social link data
 * @param string $position Position class ('above' or 'below')
 * @param object $social_manager Optional social links manager instance
 * @return string Rendered HTML
 */
function ec_render_social_icons_container( $social_links, $position = 'above', $social_manager = null ) {
    $template_args = array(
        'social_links' => $social_links,
        'position' => $position,
        'social_manager' => $social_manager
    );
    return ec_render_template( 'social-icons-container', $template_args );
}