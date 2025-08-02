<?php
/**
 * Main include file for the extrch.co Link Page feature.
 * Handles CPT registration, asset enqueuing, and future modular includes.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

$link_page_dir = get_stylesheet_directory() . '/band-platform/extrch.co-link-page/';

// Configuration & Handlers
require_once $link_page_dir . 'link-page-font-config.php';
require_once $link_page_dir . 'link-page-form-handler.php';
require_once $link_page_dir . 'data/LinkPageDataProvider.php';
require_once $link_page_dir . 'link-page-weekly-email.php'; // Include weekly email handler
require_once $link_page_dir . 'link-page-social-types.php';

// Include the QR code generation library classes
// require_once get_stylesheet_directory() . '/vendor/autoload.php'; // Assuming composer autoloader is used -- MOVED TO dedicated AJAX file

// Core Functionality
require_once $link_page_dir . 'cpt-band-link-page.php';
require_once $link_page_dir . 'create-link-page.php';
require_once $link_page_dir . 'link-page-rewrites.php';
// require_once $link_page_dir . 'link-page-assets.php'; // REMOVED - Asset enqueuing is in this file
require_once $link_page_dir . 'link-page-analytics-db.php'; // Include the new DB file
require_once $link_page_dir . 'link-page-analytics-tracking.php'; // Include analytics tracking logic
require_once $link_page_dir . 'link-page-session-validation.php'; // Include the session validation file
require_once $link_page_dir . 'link-page-head.php'; // Include the custom head logic for the public link page
require_once $link_page_dir . 'link-page-qrcode-ajax.php'; // Include the QR code AJAX handlers
require_once $link_page_dir . 'ajax-handlers.php'; // Include the new AJAX handlers for link title fetching
require_once __DIR__ . '/link-page-custom-vars-and-fonts-head.php';
require_once $link_page_dir . 'link-page-featured-link-handler.php'; // Corrected path

global $extrch_link_page_fonts;

// --- Enqueue assets for the management template ---
function extrch_link_page_enqueue_assets() {
    global $extrch_link_page_fonts; // Make the global variable available within this function's scope

    $current_band_id = isset( $_GET['band_id'] ) ? (int) $_GET['band_id'] : 0;
    $link_page_id = 0;

    if ( is_page_template( 'page-templates/manage-link-page.php' ) ) {
        $theme_dir = get_stylesheet_directory();
        $theme_uri = get_stylesheet_directory_uri();
        $feature_dir = '/band-platform/extrch.co-link-page';
        $js_dir = $feature_dir . '/live-preview/js';
        $css_dir = $feature_dir . '/css';

        // Core Manager Object Initialization (MUST be first of these JS files)
        $core_js_path = $js_dir . '/manage-link-page-core.js';
        if ( file_exists( $theme_dir . $core_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-core',
                $theme_uri . $core_js_path,
                array('jquery'), // Minimal dependency, jQuery usually available early.
                filemtime( $theme_dir . $core_js_path ),
                true
            );

            // Localize essential data for the main management script
            // The localized script will define window.extrchLinkPageConfig
            // supportedLinkTypes is now included here again.
            
            if ( $current_band_id > 0 && class_exists('LinkPageDataProvider') ) {
                // Attempt to get the link page ID associated with the band profile.
                // This assumes LinkPageDataProvider has a method or we use a helper.
                // For now, let's assume a direct meta field on band_profile or a helper:
                // Option 1: Direct meta field (if it exists and is reliable)
                // $link_page_id = (int) get_post_meta( $current_band_id, '_extrch_link_page_id', true );

                // Option 2: Using a function similar to how page template gets it (more robust)
                // This logic should ideally be centralized if used in multiple places.
                $associated_link_pages = get_posts(array(
                    'post_type' => 'band_link_page',
                    'posts_per_page' => 1,
                    'meta_key' => '_associated_band_profile_id',
                    'meta_value' => $current_band_id,
                    'fields' => 'ids',
                ));
                if ( !empty( $associated_link_pages ) ) {
                    $link_page_id = $associated_link_pages[0];
                }
            }
            
            // Fallback if link_page_id is still 0 (e.g. creating new link page from band profile)
            // The JavaScript should handle cases where link_page_id might initially be 0 if that's a valid state.
            // However, for analytics, a valid link_page_id is crucial.

            $supported_social_types = function_exists('bp_get_supported_social_link_types') ? bp_get_supported_social_link_types() : array();
            // Ensure all keys are strings and all values are strings (except for has_custom_label)
            $fixed_supported_social_types = array();
            foreach ($supported_social_types as $key => $type) {
                $fixed_type = array();
                foreach ($type as $k => $v) {
                    if ($k === 'has_custom_label') {
                        $fixed_type[$k] = $v; // keep as is (bool or int)
                    } else {
                        $fixed_type[$k] = (string) $v;
                    }
                }
                $fixed_supported_social_types[(string)$key] = $fixed_type;
            }

            // Fetch the raw, unfiltered links to pass to JS for its own source of truth
            $initial_link_sections_raw = [];
            if ($link_page_id) {
                $links_json = get_post_meta($link_page_id, '_link_page_links', true);
                if (is_string($links_json)) {
                    $decoded_links = json_decode($links_json, true);
                    if (is_array($decoded_links)) {
                        $initial_link_sections_raw = $decoded_links;
                    }
                }
            }

            wp_localize_script(
                'extrch-manage-link-page-core',
                'extrchLinkPageConfig',
                array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'extrch_link_page_ajax_nonce' ),
                    'fetch_link_title_nonce' => wp_create_nonce( 'fetch_link_meta_title_nonce' ),
                    'nonces'   => array(
                        'featured_link_nonce' => wp_create_nonce( 'extrch_link_page_featured_link_nonce' )
                    ),
                    'link_page_id' => $link_page_id,
                    'band_id' => $current_band_id,
                    'supportedLinkTypes' => $fixed_supported_social_types,
                    'initialLinkSections' => $initial_link_sections_raw, // Add the raw links here
                )
            );

        }

        // Enqueue SortableJS library (from CDN)
        wp_enqueue_script(
            'sortable-js',
            'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js', // Specify a version
            array(), // No WP dependencies
            '1.15.0', // Version number
            true // Load in footer
        );

        // UI Utilities JS (Tabs, Copy URL, etc.) - IIFE based
        $utils_js = $js_dir . '/manage-link-page-ui-utils.js';
        if ( file_exists( $theme_dir . $utils_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-ui-utils',
                $theme_uri . $utils_js,
                array('jquery', 'extrch-manage-link-page-core'),
                filemtime( $theme_dir . $utils_js ),
                true
            );
        }

        // Enqueue modular JS files BEFORE the main manager
        // Font Management JS (NEW) - IIFE based
        $fonts_js_path = $js_dir . '/manage-link-page-fonts.js';
        if ( file_exists( $theme_dir . $fonts_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-fonts',
                $theme_uri . $fonts_js_path,
                array('jquery', 'extrch-manage-link-page-core'),
                filemtime( $theme_dir . $fonts_js_path ),
                true
            );
            // Pass the font config to JS for the new fonts module as well
            if ( isset( $extrch_link_page_fonts ) && is_array( $extrch_link_page_fonts ) && ! empty( $extrch_link_page_fonts ) ) {
                wp_localize_script(
                    'extrch-manage-link-page-fonts', // Attach to this script's handle
                    'extrchLinkPageFonts',           // JavaScript object name (window.extrchLinkPageFonts)
                    array_values( $extrch_link_page_fonts )
                );
            } else {
                // Localize an empty array if font data isn't available, so window.extrchLinkPageFonts exists.
                wp_localize_script(
                    'extrch-manage-link-page-fonts',
                    'extrchLinkPageFonts',
                    array()
                );
            }
        }

        // Preview Updater JS (NEW - "Preview Engine") - IIFE based
        $preview_updater_js_path = $js_dir . '/manage-link-page-preview-updater.js';
        if ( file_exists( $theme_dir . $preview_updater_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-preview-updater',
                $theme_uri . $preview_updater_js_path,
                array('jquery', 'extrch-manage-link-page-core'),
                filemtime( $theme_dir . $preview_updater_js_path ),
                true
            );
        }

        // Customization JS ("The Brain") - IIFE based
        $custom_js = $js_dir . '/manage-link-page-customization.js';
        if ( file_exists( $theme_dir . $custom_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-customization',
                $theme_uri . $custom_js,
                array('jquery', 'extrch-manage-link-page-core', 'extrch-manage-link-page-fonts', 'extrch-manage-link-page-preview-updater'), 
                filemtime( $theme_dir . $custom_js ),
                true
            );
        }

        // Colors Management JS (NEW) - IIFE based
        $colors_js_path = $js_dir . '/manage-link-page-colors.js';
        if ( file_exists( $theme_dir . $colors_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-colors',
                $theme_uri . $colors_js_path,
                array('jquery', 'extrch-manage-link-page-core', 'extrch-manage-link-page-customization'), 
                filemtime( $theme_dir . $colors_js_path ),
                true
            );
        }

        // Sizing Management JS (NEW) - IIFE based
        $sizing_js_path = $js_dir . '/manage-link-page-sizing.js';
        if ( file_exists( $theme_dir . $sizing_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-sizing',
                $theme_uri . $sizing_js_path,
                array('jquery', 'extrch-manage-link-page-core', 'extrch-manage-link-page-customization'), 
                filemtime( $theme_dir . $sizing_js_path ),
                true
            );
        }

        // Link Page Content Renderer JS (NEW - "Content Engine") - IIFE based
        $content_renderer_js_path = $js_dir . '/manage-link-page-content-renderer.js';
        if ( file_exists( $theme_dir . $content_renderer_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-content-renderer',
                $theme_uri . $content_renderer_js_path,
                array('jquery', 'extrch-manage-link-page-core'), 
                filemtime( $theme_dir . $content_renderer_js_path ),
                true
            );
        }

        // Info Tab Management JS (NEW - "Info Brain") - Global Object
        $info_js_path = $js_dir . '/manage-link-page-info.js';
        if ( file_exists( $theme_dir . $info_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-info',
                $theme_uri . $info_js_path,
                array('jquery', 'extrch-manage-link-page-core', 'extrch-manage-link-page-content-renderer'), // Depends on core for global ExtrchLinkPageManager to exist when its init is called
                filemtime( $theme_dir . $info_js_path ),
                true
            );
        }

        // Link Sections JS ("Links Brain") - Global Object
        $links_module_js = $js_dir . '/manage-link-page-links.js';
        if ( file_exists( $theme_dir . $links_module_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-links',
                $theme_uri . $links_module_js,
                array('jquery', 'extrch-manage-link-page-core', 'extrch-manage-link-page-content-renderer', 'sortable-js'),
                filemtime( $theme_dir . $links_module_js ),
                true
            );
        }

        // Social Icons JS - Global Object
        $socials_module_js = $js_dir . '/manage-link-page-socials.js';
        if ( file_exists( $theme_dir . $socials_module_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-socials',
                $theme_uri . $socials_module_js,
                array('jquery', 'extrch-manage-link-page-core', 'extrch-manage-link-page-content-renderer', 'sortable-js'), // Removed dependency on extrch-manage-link-page
                filemtime( $theme_dir . $socials_module_js ),
                true
            );
        }

        // Background Management JS (NEW) - IIFE based
        $background_js_path = $js_dir . '/manage-link-page-background.js';
        if ( file_exists( $theme_dir . $background_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-background',
                $theme_uri . $background_js_path,
                array('jquery', 'extrch-manage-link-page-core'), 
                filemtime( $theme_dir . $background_js_path ),
                true
            );
        }

        // Advanced Tab JS (NEW) - IIFE or Global Object as needed
        $advanced_js_path = $js_dir . '/manage-link-page-advanced.js';
        if ( file_exists( $theme_dir . $advanced_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-advanced',
                $theme_uri . $advanced_js_path,
                array('jquery', 'extrch-manage-link-page-core'), // Depends on core if it uses ExtrchLinkPageManager
                filemtime( $theme_dir . $advanced_js_path ),
                true
            );
        }

        // QR Code Management JS (NEW) - IIFE based
        $qrcode_js_path = $js_dir . '/manage-link-page-qrcode.js';
        if ( file_exists( $theme_dir . $qrcode_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-qrcode',
                $theme_uri . $qrcode_js_path,
                array('jquery', 'extrch-manage-link-page-core'), // Depends on core if it uses ExtrchLinkPageManager or its config
                filemtime( $theme_dir . $qrcode_js_path ),
                true
            );
            // Localize AJAX config for QR code modal
            wp_localize_script(
                'extrch-manage-link-page-qrcode',
                'extrchLinkPagePreviewAJAX',
                array(
                    'nonce' => wp_create_nonce('extrch_link_page_ajax_nonce'),
                    'link_page_id' => $link_page_id,
                    'ajax_url' => admin_url('admin-ajax.php'),
                )
            );
        }

        // Save Handler JS (NEW) - IIFE based
        $save_js_path = $js_dir . '/manage-link-page-save.js';
        if ( file_exists( $theme_dir . $save_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-save',
                $theme_uri . $save_js_path,
                array('jquery', 'extrch-manage-link-page-core'), // Depends on core for global ExtrchLinkPageManager
                filemtime( $theme_dir . $save_js_path ),
                true
            );
        }

        // Analytics Tab JS (NEW) - Global Object
        $analytics_js_path = $js_dir . '/manage-link-page-analytics.js';
        if ( file_exists( $theme_dir . $analytics_js_path ) ) {
            // Enqueue Chart.js library (from CDN) - make sure handle is unique
            wp_enqueue_script(
                'chart-js',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', // Specify a version
                array(), // No WP dependencies
                '4.4.3', // Version number
                true // Load in footer
            );

            wp_enqueue_script(
                'extrch-manage-link-page-analytics',
                $theme_uri . $analytics_js_path,
                array('jquery', 'extrch-manage-link-page-core', 'chart-js', 'extrch-manage-link-page-socials'), // Ensure socials is loaded before analytics
                filemtime( $theme_dir . $analytics_js_path ),
                true
            );
            wp_localize_script(
                'extrch-manage-link-page-analytics',
                'extrchAnalyticsConfig',
                array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'extrch_link_page_ajax_nonce' ),
                    'link_page_id' => $link_page_id,
                    'band_id' => $current_band_id,
                )
            );
        }

        // Subscribe Management JS (NEW) - IIFE based
        $subscribe_js_path = $js_dir . '/manage-link-page-subscribe.js';
        if ( file_exists( $theme_dir . $subscribe_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-subscribe',
                $theme_uri . $subscribe_js_path,
                array('jquery', 'extrch-manage-link-page-core', 'extrch-manage-link-page-preview-updater'),
                filemtime( $theme_dir . $subscribe_js_path ),
                true
            );
        }

        // Main management JS (should be enqueued LAST)
        $main_js_deps = array(
            'jquery', 
            'sortable-js', 
            'extrch-manage-link-page-core', 
            'extrch-manage-link-page-ui-utils',
            'extrch-manage-link-page-fonts',
            'extrch-manage-link-page-preview-updater',
            'extrch-manage-link-page-customization',
            'extrch-manage-link-page-colors',
            'extrch-manage-link-page-sizing',
            'extrch-manage-link-page-content-renderer',
            'extrch-manage-link-page-info', 
            'extrch-manage-link-page-links', 
            'extrch-manage-link-page-background',
            'extrch-manage-link-page-advanced',
            'extrch-manage-link-page-qrcode',
            'extrch-manage-link-page-save',
            'extrch-manage-link-page-subscribe'
        );

        // Conditionally enqueue YouTube embed script for admin preview
        $youtube_embed_enabled_admin = $link_page_id ? (get_post_meta($link_page_id, '_enable_youtube_inline_embed', true) !== '0') : true; // Default true if no ID or meta not '0'
        $youtube_embed_js_path = $js_dir . '/link-page-youtube-embed.js';
        if ($youtube_embed_enabled_admin && file_exists( $theme_dir . $youtube_embed_js_path ) ) {
            wp_enqueue_script(
                'extrch-link-page-youtube-embed',
                $theme_uri . $youtube_embed_js_path,
                array('extrch-manage-link-page-core'), // Depends on core if it needs config or uses manager events
                filemtime( $theme_dir . $youtube_embed_js_path ),
                true
            );
            $main_js_deps[] = 'extrch-link-page-youtube-embed'; // Add as dependency for main manager if needed
        }

        $main_js = $js_dir . '/manage-link-page.js';
        if ( file_exists( $theme_dir . $main_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page',
                $theme_uri . $main_js,
                $main_js_deps,
                filemtime( $theme_dir . $main_js ),
                true
            );
        }

        // Management UI CSS
        $manage_css = $css_dir . '/manage-link-page.css';
        if ( file_exists( $theme_dir . $manage_css ) ) {
            wp_enqueue_style(
                'extrch-manage-link-page',
                $theme_uri . $manage_css,
                array('extra-chill-community-style'),
                filemtime( $theme_dir . $manage_css )
            );
        }
        // Public link page CSS for preview parity
        $public_css = $css_dir . '/extrch-links.css';
        if ( file_exists( $theme_dir . $public_css ) ) {
            wp_enqueue_style(
                'extrch-link-page-public',
                $theme_uri . $public_css,
                array('extrch-manage-link-page'),
                filemtime( $theme_dir . $public_css )
            );
        }

        // Share Modal CSS (needed for preview parity)
        $share_modal_css = $css_dir . '/extrch-share-modal.css';
        if ( file_exists( $theme_dir . $share_modal_css ) ) {
            wp_enqueue_style(
                'extrch-share-modal',
                $theme_uri . $share_modal_css,
                array('extrch-link-page-public'),
                filemtime( $theme_dir . $share_modal_css )
            );
        }
        // Share Modal JS (needed for preview parity)
        $share_modal_js = $js_dir . '/extrch-share-modal.js';
        if ( file_exists( $theme_dir . $share_modal_js ) ) {
            wp_enqueue_script(
                'extrch-share-modal',
                $theme_uri . $share_modal_js,
                array('jquery'),
                filemtime( $theme_dir . $share_modal_js ),
                true
            );
        }

        // Enqueue new manage-link-page-featured-link.js script
        wp_enqueue_script(
            'extrch-manage-link-page-featured-link',
            get_stylesheet_directory_uri() . '/band-platform/extrch.co-link-page/live-preview/js/manage-link-page-featured-link.js',
            array('jquery', 'extrch-manage-link-page'), // Depends on the main manager
            filemtime(get_stylesheet_directory() . '/band-platform/extrch.co-link-page/live-preview/js/manage-link-page-featured-link.js'),
            true
        );
    }

    // Enqueue Google Font if needed for AJAX previews.
    // For the public 'band_link_page', fonts are now handled by extrch_link_page_custom_head().
    if (defined('DOING_AJAX') && DOING_AJAX) {
        // Logic for AJAX context (e.g., live preview in admin)
        // This part of the logic can remain if AJAX previews need to enqueue fonts separately.
        // It requires 'post_id' to be part of the AJAX request.
        $current_post_id = null;
        if (isset($_REQUEST['post_id'])) {
            $current_post_id = intval($_REQUEST['post_id']);
        }

        if ($current_post_id && !empty($extrch_link_page_fonts)) { // Ensure $extrch_link_page_fonts is available
            $custom_vars_data = get_post_meta($current_post_id, '_link_page_custom_css_vars', true);
            $custom_vars = null;
            
            // Handle both array (new format) and JSON string (legacy) formats
            if (is_array($custom_vars_data)) {
                $custom_vars = $custom_vars_data;
            } elseif (is_string($custom_vars_data)) {
                $custom_vars = json_decode($custom_vars_data, true);
            }
            
                if (is_array($custom_vars) && !empty($custom_vars['--link-page-title-font-family'])) {
                    
                    // Determine the 'value' of the font, which might be a direct value or derived from a stack
                    $stored_font_setting = $custom_vars['--link-page-title-font-family'];
                    $font_value_for_google_lookup = null;

                    foreach ($extrch_link_page_fonts as $font_entry) {
                        if ($font_entry['value'] === $stored_font_setting || $font_entry['stack'] === $stored_font_setting) {
                            $font_value_for_google_lookup = $font_entry['value'];
                            break;
                        }
                    }
                    // If not found in config by stack or value, and it's a simple name, assume it's a value.
                    if (!$font_value_for_google_lookup && strpos($stored_font_setting, ',') === false && strpos($stored_font_setting, "'") === false && strpos($stored_font_setting, '"') === false) {
                        $font_value_for_google_lookup = $stored_font_setting;
                    }


                    $google_font_param_to_enqueue = null;
                    if ($font_value_for_google_lookup) {
                        foreach ($extrch_link_page_fonts as $font_entry) {
                            if ($font_entry['value'] === $font_value_for_google_lookup) {
                                $google_font_param_to_enqueue = $font_entry['google_font_param'];
                                break;
                            }
                        }
                    }

                    if ($google_font_param_to_enqueue && $google_font_param_to_enqueue !== 'local_default' && $google_font_param_to_enqueue !== 'inherit') {
                        $font_url = 'https://fonts.googleapis.com/css2?family=' . urlencode($google_font_param_to_enqueue) . '&display=swap';
                        wp_enqueue_style(
                            'extrch-link-page-title-google-font-' . sanitize_key($google_font_param_to_enqueue),
                            $font_url,
                            array(),
                            null
                        );
                    }
                }

                // --- Enqueue Body Font for AJAX Preview ---
                if (is_array($custom_vars) && !empty($custom_vars['--link-page-body-font-family'])) {
                    $stored_body_font_setting = $custom_vars['--link-page-body-font-family'];
                    $body_font_value_for_google_lookup = null;

                    foreach ($extrch_link_page_fonts as $font_entry) {
                        if ($font_entry['value'] === $stored_body_font_setting || $font_entry['stack'] === $stored_body_font_setting) {
                            $body_font_value_for_google_lookup = $font_entry['value'];
                            break;
                        }
                    }
                    if (!$body_font_value_for_google_lookup && strpos($stored_body_font_setting, ',') === false && strpos($stored_body_font_setting, "'") === false && strpos($stored_body_font_setting, '"') === false) {
                        $body_font_value_for_google_lookup = $stored_body_font_setting;
                    }

                    $google_body_font_param_to_enqueue = null;
                    if ($body_font_value_for_google_lookup) {
                        foreach ($extrch_link_page_fonts as $font_entry) {
                            if ($font_entry['value'] === $body_font_value_for_google_lookup) {
                                $google_body_font_param_to_enqueue = $font_entry['google_font_param'];
                                break;
                            }
                        }
                    }

                    if ($google_body_font_param_to_enqueue && $google_body_font_param_to_enqueue !== 'local_default' && $google_body_font_param_to_enqueue !== 'inherit') {
                        $font_url = 'https://fonts.googleapis.com/css2?family=' . urlencode($google_body_font_param_to_enqueue) . '&display=swap';
                        wp_enqueue_style(
                            'extrch-link-page-body-google-font-' . sanitize_key($google_body_font_param_to_enqueue),
                            $font_url,
                            array(),
                            null
                        );
                }
            }
        }
    }
}
add_action( 'wp_enqueue_scripts', 'extrch_link_page_enqueue_assets' );


// Enqueue public link page scripts and styles
add_action( 'wp_enqueue_scripts', 'extrch_enqueue_public_link_page_assets' );

function extrch_enqueue_public_link_page_assets() {

    // Only enqueue on the single band link page template
    if ( is_singular( 'band_link_page' ) ) {
        global $post;
        $link_page_id_public = $post ? $post->ID : 0;

        error_log('[DEBUG ENQUEUE] is_singular(\'band_link_page\') is true. Proceeding with enqueuing scripts.');
        // Enqueue Font Awesome if not already enqueued by the theme
        // Check if a Font Awesome script handle is already registered or enqueued
        if ( ! wp_script_is( 'font-awesome', 'registered' ) && ! wp_script_is( 'font-awesome', 'enqueued' ) ) {
            // Assuming Font Awesome is available via a CDN or locally
            wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.1/css/all.min.css', array(), '6.7.1' );
        }
        // Enqueue public link page stylesheet
        $css_file = get_stylesheet_directory() . '/band-platform/extrch.co-link-page/css/extrch-links.css';
        if ( file_exists( $css_file ) ) {
            wp_enqueue_style( 'extrch-links', get_stylesheet_directory_uri() . '/band-platform/extrch.co-link-page/css/extrch-links.css', array(), filemtime( $css_file ) );
        }
        // Enqueue public link page tracking script
        $tracking_js_file = get_stylesheet_directory() . '/band-platform/extrch.co-link-page/js/link-page-public-tracking.js';
        if ( file_exists( $tracking_js_file ) ) {
            wp_enqueue_script( 'extrch-link-page-public-tracking', get_stylesheet_directory_uri() . '/band-platform/extrch.co-link-page/js/link-page-public-tracking.js', array( 'jquery' ), filemtime( $tracking_js_file ), true );
            // Localize tracking data
            if ( $post && $post->ID ) {
                wp_localize_script( 'extrch-link-page-public-tracking', 'extrchTrackingData', array(
                    'ajax_url'     => admin_url( 'admin-ajax.php' ),
                    'link_page_id' => $post->ID,
                    // 'nonce'       => wp_create_nonce( 'extrch_record_link_event_nonce' ), // Example nonce
                ));
            }
        }
        
        // Enqueue subscribe JS for the public link page
        $subscribe_js_file = get_stylesheet_directory() . '/band-platform/extrch.co-link-page/js/link-page-subscribe.js';
        if ( file_exists( $subscribe_js_file ) ) {
            wp_enqueue_script(
                'extrch-link-page-subscribe',
                get_stylesheet_directory_uri() . '/band-platform/extrch.co-link-page/js/link-page-subscribe.js',
                array('jquery'),
                filemtime($subscribe_js_file),
                true
            );
            // Localize ajaxurl for the subscribe JS
            wp_localize_script('extrch-link-page-subscribe', 'ajaxurl', admin_url('admin-ajax.php'));
        }

        // Enqueue subscribe CSS if it exists (reuse extrch-links.css for now)
        // If you create a dedicated subscribe CSS, enqueue it here.
        
        // Conditionally enqueue YouTube embed script for public page
        $youtube_embed_enabled_public = $link_page_id_public ? (get_post_meta($link_page_id_public, '_enable_youtube_inline_embed', true) !== '0') : true; // Default true if no ID or meta not '0'
        $theme_dir_public = get_stylesheet_directory();
        $theme_uri_public = get_stylesheet_directory_uri();
        $youtube_embed_js_path_public = '/band-platform/extrch.co-link-page/js/link-page-youtube-embed.js';

        if ($youtube_embed_enabled_public && file_exists( $theme_dir_public . $youtube_embed_js_path_public ) ) {
            wp_enqueue_script( 
                'extrch-link-page-youtube-embed',
                $theme_uri_public . $youtube_embed_js_path_public,
                array(), // No specific dependencies for public page, runs standalone
                filemtime( $theme_dir_public . $youtube_embed_js_path_public ),
                true 
            );
        }

        // ... other assets ...
    }
}

// Enqueue public YouTube embed script for the isolated public link page (single-band_link_page.php)
function extrch_enqueue_public_youtube_embed_script($link_page_id, $band_id) {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();
    $youtube_embed_js_path = '/band-platform/extrch.co-link-page/js/link-page-youtube-embed.js';

    // Only output if enabled for this link page (default: enabled)
    $enabled = $link_page_id ? (get_post_meta($link_page_id, '_enable_youtube_inline_embed', true) !== '0') : true;
    if ($enabled && file_exists($theme_dir . $youtube_embed_js_path)) {
        echo '<script src="' . esc_url($theme_uri . $youtube_embed_js_path) . '?ver=' . esc_attr(filemtime($theme_dir . $youtube_embed_js_path)) . '" defer></script>';
    }
}
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_youtube_embed_script', 10, 2);

add_action('wp_head', function() {
    if (is_page_template('page-templates/manage-link-page.php')) {
        $band_id = isset($_GET['band_id']) ? absint($_GET['band_id']) : 0;
        if ($band_id) {
            // Find the associated link page using the canonical method (checking meta on the link page post)
            $associated_link_pages = get_posts(array(
                'post_type' => 'band_link_page',
                'posts_per_page' => 1,
                'meta_key' => '_associated_band_profile_id',
                'meta_value' => $band_id,
                'fields' => 'ids',
                'post_status' => 'publish', // Only get published link pages
            ));

            $link_page_id = !empty($associated_link_pages) ? $associated_link_pages[0] : 0;

            global $extrch_link_page_fonts;
            if ($link_page_id && function_exists('extrch_link_page_custom_vars_and_fonts_head')) {
                extrch_link_page_custom_vars_and_fonts_head($link_page_id, $extrch_link_page_fonts);
            }
        }
    }
}, 20);

// Function to get the current link page ID from query vars or post object
// ... existing code ...
