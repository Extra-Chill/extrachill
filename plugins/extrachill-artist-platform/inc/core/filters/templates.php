<?php
/**
 * WordPress Filter-Based Template System
 * 
 * Provides component template rendering using WordPress native filters.
 * Allows other plugins to modify template output and follows WordPress best practices.
 */

/**
 * Initialize template filters immediately
 */
add_filter( 'ec_render_template', 'ec_template_handler', 10, 3 );

/**
 * Universal template renderer using WordPress filters
 * 
 * @param string $template_name Template name (e.g., 'single-link', 'link-section')
 * @param array $args Template arguments
 * @return string Rendered HTML
 */
function ec_render_template( $template_name, $args = array() ) {
    return apply_filters( 'ec_render_template', '', $template_name, $args );
}

/**
 * Main template filter handler
 * 
 * @param string $output Current output (empty by default)
 * @param string $template_name Template name
 * @param array $args Template arguments
 * @return string Rendered HTML
 */
function ec_template_handler( $output, $template_name, $args = array() ) {
    // Don't render if already has output (allows other plugins to override)
    if ( ! empty( $output ) ) {
        return $output;
    }
    
    // Template validation and file mapping
    $template_map = array(
        'single-link' => array(
            'file' => 'inc/link-pages/management/templates/components/single-link.php',
            'required' => array()
        ),
        'link-section' => array(
            'file' => 'inc/link-pages/management/templates/components/link-section.php',
            'required' => array( 'links' )
        ),
        'social-icon' => array(
            'file' => 'inc/link-pages/management/templates/components/social-icon.php',
            'required' => array( 'social_data' )
        ),
        'social-icons-container' => array(
            'file' => 'inc/link-pages/management/templates/components/social-icons-container.php',
            'required' => array( 'social_links' )
        ),
        'artist-switcher' => array(
            'file' => 'inc/core/templates/artist-switcher.php',
            'required' => array()
        ),
        // Admin/Management Templates
        'link-item-editor' => array(
            'file' => 'inc/link-pages/management/templates/components/link-item-editor.php',
            'required' => array( 'sidx', 'lidx' )
        ),
        'link-section-editor' => array(
            'file' => 'inc/link-pages/management/templates/components/link-section-editor.php', 
            'required' => array( 'sidx' )
        ),
        'social-item-editor' => array(
            'file' => 'inc/link-pages/management/templates/components/social-item-editor.php',
            'required' => array( 'index' )
        ),
        // Subscription Templates
        'subscribe-inline-form' => array(
            'file' => 'inc/link-pages/live/templates/subscribe-inline-form.php',
            'required' => array( 'artist_id' )
        ),
        'subscribe-modal' => array(
            'file' => 'inc/link-pages/live/templates/subscribe-modal.php',
            'required' => array( 'artist_id' )
        ),
        // Management Tab Templates
        'manage-artist-profile-tab-info' => array(
            'file' => 'inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-info.php',
            'required' => array( 'edit_mode', 'target_artist_id', 'display_artist_name', 'display_artist_bio', 'display_profile_image_url', 'display_header_image_url' )
        ),
        'manage-artist-profile-tab-profile-managers' => array(
            'file' => 'inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-profile-managers.php',
            'required' => array( 'target_artist_id', 'artist_post_title' )
        ),
        'manage-artist-profile-tab-subscribers' => array(
            'file' => 'inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-subscribers.php',
            'required' => array( 'target_artist_id' )
        ),
        'manage-artist-profile-tab-forum' => array(
            'file' => 'inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-forum.php',
            'required' => array( 'target_artist_id' )
        ),
        // Live Preview Template
        'link-page-live-preview' => array(
            'file' => 'inc/link-pages/management/live-preview/preview.php',
            'required' => array( 'preview_data' )
        )
        ,
        // Share Modal Template (used by both live link page and preview)
        'share-modal' => array(
            'file' => 'inc/link-pages/live/templates/extrch-share-modal.php',
            'required' => array() // JS populates content dynamically
        ),
        'artist-card' => array(
            'file' => 'inc/artist-profiles/frontend/templates/artist-card.php',
            'required' => array( 'artist_id' )
        )
    );
    
    // Check if template exists
    if ( ! isset( $template_map[ $template_name ] ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            return '<!-- ec_render_template: unknown template ' . esc_html( $template_name ) . ' -->';
        }
        return '';
    }
    
    $template_config = $template_map[ $template_name ];
    
    // Validate required arguments
    foreach ( $template_config['required'] as $required_arg ) {
        if ( $required_arg === 'social_data' ) {
            $social_data = $args['social_data'] ?? array();
            if ( empty( $social_data['url'] ) || empty( $social_data['type'] ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    return '<!-- ec_render_template(' . esc_html( $template_name ) . '): missing social_data.url or social_data.type -->';
                }
                return '';
            }
        } elseif ( $required_arg === 'social_links' ) {
            if ( empty( $args[ $required_arg ] ) || ! is_array( $args[ $required_arg ] ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    return '<!-- ec_render_template(' . esc_html( $template_name ) . '): missing or invalid social_links (array required) -->';
                }
                return '';
            }
        } elseif ( $required_arg === 'links' ) {
            if ( ! isset( $args[ $required_arg ] ) || ! is_array( $args[ $required_arg ] ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    return '<!-- ec_render_template(' . esc_html( $template_name ) . '): missing or invalid links (array required) -->';
                }
                return '';
            }
        } elseif ( $required_arg === 'artist_id' ) {
            if ( empty( $args[ $required_arg ] ) || ! is_numeric( $args[ $required_arg ] ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    return '<!-- ec_render_template(' . esc_html( $template_name ) . '): missing or invalid artist_id -->';
                }
                return '';
            }
        } elseif ( $required_arg === 'target_artist_id' ) {
            // Presence-only check: can be 0 in create mode
            if ( ! array_key_exists( 'target_artist_id', $args ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    return '<!-- ec_render_template(' . esc_html( $template_name ) . '): target_artist_id not provided -->';
                }
                return '';
            }
    } elseif ( in_array( $required_arg, array( 'edit_mode', 'display_artist_name', 'display_artist_bio', 'display_profile_image_url', 'display_header_image_url', 'sidx', 'lidx', 'index' ), true ) ) {
            // Presence-only check: these fields can be empty strings or 0 (valid for indices)
            if ( ! array_key_exists( $required_arg, $args ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    return '<!-- ec_render_template(' . esc_html( $template_name ) . '): required key ' . esc_html( $required_arg ) . ' missing -->';
                }
                return '';
            }
        } else {
            if ( empty( $args[ $required_arg ] ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    return '<!-- ec_render_template(' . esc_html( $template_name ) . '): missing required arg ' . esc_html( $required_arg ) . ' -->';
                }
                return '';
            }
        }
    }
    
    // Render template
    $template_file = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $template_config['file'];
    if ( ! file_exists( $template_file ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            return '<!-- ec_render_template(' . esc_html( $template_name ) . '): file not found ' . esc_html( $template_config['file'] ) . ' -->';
        }
        return '';
    }
    
    ob_start();
    // Expose args to the included template without renaming/aliases
    if ( is_array( $args ) && ! empty( $args ) ) {
        // Use EXTR_SKIP to avoid overwriting any pre-set local vars
        extract( $args, EXTR_SKIP );
    }
    include $template_file;
    return ob_get_clean();
}