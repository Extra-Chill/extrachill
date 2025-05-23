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
require_once $link_page_dir . 'config/link-page-font-config.php';
require_once $link_page_dir . 'config/link-page-form-handler.php';
require_once $link_page_dir . 'config/live-preview/LivePreviewManager.php';
require_once $link_page_dir . 'link-page-weekly-email.php'; // Include weekly email handler
require_once $link_page_dir . 'config/link-page-social-types.php';

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

global $extrch_link_page_fonts;

// --- Enqueue assets for the management template ---
function extrch_link_page_enqueue_assets() {
    global $extrch_link_page_fonts; // Make the global variable available within this function's scope

    $current_band_id = isset( $_GET['band_id'] ) ? (int) $_GET['band_id'] : 0;
    error_log('[PHP Debug] Value of $current_band_id after checking $_GET:' . $current_band_id);
    $link_page_id = 0;

    if ( is_page_template( 'page-templates/manage-link-page.php' ) ) {
        $theme_dir = get_stylesheet_directory();
        $theme_uri = get_stylesheet_directory_uri();
        $feature_dir = '/band-platform/extrch.co-link-page';
        $js_dir = $feature_dir . '/js';
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
            
            if ( $current_band_id > 0 && class_exists('LivePreviewManager') ) {
                // Attempt to get the link page ID associated with the band profile.
                // This assumes LivePreviewManager has a method or we use a helper.
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
            // No longer need to error_log here, can rely on browser console.
            // error_log('SUPPORTED SOCIAL TYPES: ' . print_r($fixed_supported_social_types, true));
            wp_localize_script(
                'extrch-manage-link-page-core',
                'extrchLinkPageConfig',
                array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'extrch_link_page_ajax_nonce' ),
                    'link_page_id' => $link_page_id,
                    'band_id' => $current_band_id,
                    'supportedLinkTypes' => $fixed_supported_social_types,
                )
            );
            // *** ADDED PHP DEBUG LOG HERE ***
            error_log('[PHP Debug] extrchLinkPageConfig localized with supportedLinkTypes:' . print_r($fixed_supported_social_types, true));
            // *** END ADDED PHP DEBUG LOG ***
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

        // Main management JS (should be enqueued LAST)
        $main_js = $js_dir . '/manage-link-page.js';
        if ( file_exists( $theme_dir . $main_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page',
                $theme_uri . $main_js,
                array(
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
                    'extrch-manage-link-page-qrcode'
                ),
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
                array('generatepress-child-style'),
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
            $custom_vars_json = get_post_meta($current_post_id, '_link_page_custom_css_vars', true);
            if ($custom_vars_json) {
                $custom_vars = json_decode($custom_vars_json, true);
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
}
add_action( 'wp_enqueue_scripts', 'extrch_link_page_enqueue_assets' );


// Enqueue public link page scripts and styles
add_action( 'wp_enqueue_scripts', 'extrch_enqueue_public_link_page_assets' );

function extrch_enqueue_public_link_page_assets() {

    // Only enqueue on the single band link page template
    if ( is_singular( 'band_link_page' ) ) {
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
            global $post;
            if ( $post && $post->ID ) {
                wp_localize_script( 'extrch-link-page-public-tracking', 'extrchTrackingData', array(
                    'ajax_url'     => admin_url( 'admin-ajax.php' ),
                    'link_page_id' => $post->ID,
                    // 'nonce'       => wp_create_nonce( 'extrch_record_link_event_nonce' ), // Example nonce
                ));
            }
        }
        
        // ... other assets ...
    }
}
