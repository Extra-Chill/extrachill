<?php
/**
 * Main include file for the extrch.co Link Page feature.
 * Handles CPT registration, asset enqueuing, and future modular includes.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

$link_page_dir = get_stylesheet_directory() . '/forum-features/band-platform/extrch.co-link-page/';

// Core Functionality
require_once $link_page_dir . 'cpt-band-link-page.php';
require_once $link_page_dir . 'create-link-page.php';
require_once $link_page_dir . 'link-page-rewrites.php';
// require_once $link_page_dir . 'link-page-assets.php'; // REMOVED - Asset enqueuing is in this file
require_once $link_page_dir . 'link-page-analytics-db.php'; // Include the new DB file
require_once $link_page_dir . 'link-page-analytics-tracking.php'; // Include analytics tracking logic

// Configuration & Handlers
require_once $link_page_dir . 'config/link-page-font-config.php';
require_once $link_page_dir . 'config/link-page-form-handler.php';
require_once $link_page_dir . 'config/live-preview/LivePreviewManager.php';
require_once $link_page_dir . 'config/live-preview/live-preview-ajax.php';



global $extrch_link_page_fonts;

// --- Enqueue assets for the management template ---
function extrch_link_page_enqueue_assets() {
    global $extrch_link_page_fonts; // Make the global variable available within this function's scope

    if ( is_page_template( 'page-templates/manage-link-page.php' ) ) {
        $theme_dir = get_stylesheet_directory();
        $theme_uri = get_stylesheet_directory_uri();
        $feature_dir = '/forum-features/band-platform/extrch.co-link-page';
        $js_dir = $feature_dir . '/js';
        $css_dir = $feature_dir . '/css';

        // UI Utilities JS (Tabs, Copy URL, etc.)
        $utils_js = $js_dir . '/manage-link-page-ui-utils.js';
        if ( file_exists( $theme_dir . $utils_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-ui-utils',
                $theme_uri . $utils_js,
                array('jquery'), // 'jquery' might not be strictly needed by these utils, but often a safe default
                filemtime( $theme_dir . $utils_js ),
                true
            );
        }

        // Main management JS
        $main_js = $js_dir . '/manage-link-page.js';
        if ( file_exists( $theme_dir . $main_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page',
                $theme_uri . $main_js,
                array('jquery'),
                filemtime( $theme_dir . $main_js ),
                true
            );
        }

        // Font Management JS (NEW) - Must be enqueued before customization.js
        $fonts_js_path = $js_dir . '/manage-link-page-fonts.js';
        if ( file_exists( $theme_dir . $fonts_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-fonts',
                $theme_uri . $fonts_js_path,
                array('extrch-manage-link-page'), // Depends on the main manager script
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

        // Customization JS
        $custom_js = $js_dir . '/manage-link-page-customization.js';
        if ( file_exists( $theme_dir . $custom_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-customization',
                $theme_uri . $custom_js,
                array('extrch-manage-link-page', 'extrch-manage-link-page-fonts'), // Now depends on the fonts script
                filemtime( $theme_dir . $custom_js ),
                true
            );
            // DO NOT Pass the font config to customization.js again, it's handled by the fonts module
            // if ( isset( $extrch_link_page_fonts ) ) {
            //     wp_localize_script( 'extrch-manage-link-page-customization', 'extrchLinkPageFonts', array_values( $extrch_link_page_fonts ) );
            // }
        }

        // Link Sections JS
        $links_module_js = $js_dir . '/manage-link-page-links.js';
        if ( file_exists( $theme_dir . $links_module_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-links',
                $theme_uri . $links_module_js,
                array('extrch-manage-link-page'), // Depends on the main manager script
                filemtime( $theme_dir . $links_module_js ),
                true
            );
        }

        // Social Icons JS
        $socials_module_js = $js_dir . '/manage-link-page-socials.js';
        if ( file_exists( $theme_dir . $socials_module_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-socials',
                $theme_uri . $socials_module_js,
                array('extrch-manage-link-page'), // Depends on the main manager script
                filemtime( $theme_dir . $socials_module_js ),
                true
            );
        }

        // Background Management JS (NEW)
        $background_js_path = $js_dir . '/manage-link-page-background.js';
        if ( file_exists( $theme_dir . $background_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-background',
                $theme_uri . $background_js_path,
                array('extrch-manage-link-page'), // Depends on the main manager script
                filemtime( $theme_dir . $background_js_path ),
                true
            );
        }

        // Advanced Tab JS (NEW)
        $advanced_js_path = $js_dir . '/manage-link-page-advanced.js';
        if ( file_exists( $theme_dir . $advanced_js_path ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-advanced',
                $theme_uri . $advanced_js_path,
                array('extrch-manage-link-page'), // Depends on the main manager script
                filemtime( $theme_dir . $advanced_js_path ),
                true
            );
        }

        // Analytics Tab JS (NEW)
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
                array('extrch-manage-link-page', 'chart-js'), // Depends on main manager and Chart.js
                filemtime( $theme_dir . $analytics_js_path ),
                true
            );
            // We might need to localize data here later, like AJAX nonces specific to analytics actions
        }

        // Preview JS - DEPRECATED, logic moved to AJAX
        /*
        $preview_js = $js_dir . '/manage-link-page-preview.js';
        if ( file_exists( $theme_dir . $preview_js ) ) {
            wp_enqueue_script(
                'extrch-manage-link-page-preview',
                $theme_uri . $preview_js,
                array('extrch-manage-link-page','extrch-manage-link-page-customization'),
                filemtime( $theme_dir . $preview_js ),
                true
            );
        }
        */
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
            }
        }
    }
}
add_action( 'wp_enqueue_scripts', 'extrch_link_page_enqueue_assets' );

// --- Remove wp_head action for custom CSS vars ---
// The single-band_link_page.php template now handles this directly.
/*
add_action('wp_head', function() {
    if (is_singular('band_link_page')) {
        global $post;
        $custom_vars = get_post_meta($post->ID, '_link_page_custom_css_vars', true);
        if ($custom_vars) {
            $vars = json_decode($custom_vars, true);
            if (is_array($vars) && count($vars)) {
                echo '<style id="extrch-link-page-custom-vars">:root {';
                foreach ($vars as $k => $v) {
                    if ($v) echo esc_html($k) . ':' . esc_html($v) . ';';
                }
                echo '}</style>';
            }
        }
    }
});
*/

// --- Future includes (e.g., AJAX handlers, frontend display, etc.) ---
// require_once( __DIR__ . '/some-other-file.php' );

/**
 * Outputs the custom <head> content for the isolated extrachill.link page.
 * This replaces wp_head() for that specific template to keep it lightweight.
 *
 * @param int $band_id The ID of the associated band_profile post.
 * @param int $link_page_id The ID of the band_link_page post.
 */
function extrch_link_page_custom_head( $band_id, $link_page_id ) {
    global $extrch_link_page_fonts; // Ensure font config is available

    // Basic Meta
    echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';

    // Title and Description
    $band_title = $band_id ? get_the_title( $band_id ) : 'Link Page';
    $band_excerpt = $band_id ? get_the_excerpt( $band_id ) : 'All important links in one place.';
    echo '<title>' . esc_html( $band_title ) . ' | extrachill.link</title>'; // Or your site name
    echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $band_excerpt ) ) . '">';

    // Stylesheets
    $theme_uri = get_stylesheet_directory_uri();
    $theme_dir = get_stylesheet_directory();

    // $root_css_path = '/css/root.css'; // No longer needed for the isolated link page
    $extrch_links_css_path = '/forum-features/band-platform/extrch.co-link-page/css/extrch-links.css';

    // if ( file_exists( $theme_dir . $root_css_path ) ) { // Do not include global root.css
    //     echo '<link rel="stylesheet" href="' . esc_url( $theme_uri . $root_css_path ) . '?ver=' . esc_attr( filemtime( $theme_dir . $root_css_path ) ) . '">';
    // }
    if ( file_exists( $theme_dir . $extrch_links_css_path ) ) {
        echo '<link rel="stylesheet" href="' . esc_url( $theme_uri . $extrch_links_css_path ) . '?ver=' . esc_attr( filemtime( $theme_dir . $extrch_links_css_path ) ) . '">';
    }
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">'; // Consider self-hosting or a more privacy-friendly CDN

    // Inline body margin reset (from single-band_link_page.php)
    echo '<style>body{margin:0;padding:0;}</style>';

    // Custom CSS Variables and Google Font
    $custom_vars_json = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
    $final_custom_vars_style = '';
    $processed_vars = array();
    $default_title_font_value = 'WilcoLoftSans'; // From font config
    $default_title_font_stack = "'WilcoLoftSans', Helvetica, Arial, sans-serif"; // From font config
    $selected_title_font_value = $default_title_font_value;
    $selected_title_font_stack = $default_title_font_stack;

    if ( !empty( $custom_vars_json ) ) {
        $custom_vars = json_decode( $custom_vars_json, true );
        if ( is_array( $custom_vars ) ) {
            foreach ( $custom_vars as $k => $v ) {
                if ( $k === '--link-page-title-font-family' ) {
                    $font_found_in_config = false;
                    if (is_array($extrch_link_page_fonts)) {
                        foreach ( $extrch_link_page_fonts as $font ) {
                            if ( $font['value'] === $v || $font['stack'] === $v ) {
                                $selected_title_font_value = $font['value'];
                                $selected_title_font_stack = $font['stack'];
                                $processed_vars[$k] = $font['stack'];
                                $font_found_in_config = true;
                                break;
                            }
                        }
                    }
                    if (!$font_found_in_config) {
                         // Handle cases where the stored value might be a direct font name not in simple config
                        if (strpos($v, ',') === false && strpos($v, "'") === false && strpos($v, '"') === false) { // Likely a single font name
                            $processed_vars[$k] = "'" . $v . "', " . $default_title_font_stack; // Build a stack
                            $selected_title_font_value = $v; // Assume this is the value to check for Google Font
                            $selected_title_font_stack = $processed_vars[$k];
                        } else { // Already a stack or complex value
                            $processed_vars[$k] = $v ?: $default_title_font_stack;
                            // Attempt to parse the first font from the stack for Google Font check
                            $first_font_in_stack = explode(',', $v)[0];
                            $selected_title_font_value = trim($first_font_in_stack, " '\"");
                            $selected_title_font_stack = $v;
                        }
                    }
                } else {
                    $processed_vars[$k] = $v;
                }
            }
        }
    }

    // Fallback: if no font-family set in custom_vars, ensure default is processed
    if ( !isset( $processed_vars['--link-page-title-font-family'] ) ) {
        $processed_vars['--link-page-title-font-family'] = $default_title_font_stack;
        $selected_title_font_value = $default_title_font_value; // Ensure this is set for Google Font check
    }
    
    // Construct the :root CSS variables style tag
    if ( !empty( $processed_vars ) ) {
        $final_custom_vars_style .= '<style id="extrch-link-page-custom-vars">:root {';
        foreach ( $processed_vars as $k => $v ) {
            if ( isset( $v ) && $v !== '' ) {
                $key_sanitized = esc_html( $k );
                $value_trimmed = trim( $v );
                // Font family stack might contain unescaped characters if not from config, but should be safe if from config.
                // Other values are typically colors or simple strings.
                $final_custom_vars_style .= $key_sanitized . ':' . ( $k === '--link-page-title-font-family' ? $value_trimmed : esc_html( $value_trimmed ) ) . ';';
            }
        }
        $final_custom_vars_style .= '}</style>';
        echo $final_custom_vars_style;
    }

    // Google Font Link (based on the determined $selected_title_font_value)
    $google_font_param_to_enqueue = null;
    if (is_array($extrch_link_page_fonts)) {
        foreach ( $extrch_link_page_fonts as $font_entry ) {
            if ( $font_entry['value'] === $selected_title_font_value ) {
                $google_font_param_to_enqueue = $font_entry['google_font_param'];
                break;
            }
        }
    }

    if ( $google_font_param_to_enqueue && $google_font_param_to_enqueue !== 'local_default' && $google_font_param_to_enqueue !== 'inherit' ) {
        $font_url = 'https://fonts.googleapis.com/css2?family=' . urlencode( $google_font_param_to_enqueue ) . '&display=swap';
        echo '<link rel="stylesheet" href="' . esc_url( $font_url ) . '" media="print" onload="this.media=\'all\'">';
        echo '<noscript><link rel="stylesheet" href="' . esc_url( $font_url ) . '"></noscript>';
    }

    // Action hook for any other critical head items (use sparingly)
    do_action('extrch_link_page_minimal_head', $link_page_id, $band_id);

    // --- Embed Tracking Pixels ---
    // Meta Pixel
    $meta_pixel_id = get_post_meta($link_page_id, '_link_page_meta_pixel_id', true);
    if (!empty($meta_pixel_id) && ctype_digit($meta_pixel_id)) {
        echo "<!-- Meta Pixel Code -->\n";
        echo "<script>\n";
        echo "!function(f,b,e,v,n,t,s)\n";
        echo "{if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n";
        echo "n.callMethod.apply(n,arguments):n.queue.push(arguments)};\n";
        echo "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\n";
        echo "n.queue=[];t=b.createElement(e);t.async=!0;\n";
        echo "t.src=v;s=b.getElementsByTagName(e)[0];\n";
        echo "s.parentNode.insertBefore(t,s)}(window, document,'script',\n";
        echo "'https://connect.facebook.net/en_US/fbevents.js');\n";
        echo "fbq('init', '" . esc_js($meta_pixel_id) . "');\n";
        echo "fbq('track', 'PageView');\n";
        echo "</script>\n";
        echo "<noscript><img height=\"1\" width=\"1\" style=\"display:none\"\n";
        echo "src=\"https://www.facebook.com/tr?id=" . esc_attr($meta_pixel_id) . "&ev=PageView&noscript=1\"\n";
        echo "/></noscript>\n";
        echo "<!-- End Meta Pixel Code -->\n";
    }
    // End Meta Pixel

    // --- Google Tag (gtag.js) ---  
    $google_tag_id = get_post_meta($link_page_id, '_link_page_google_tag_id', true);
    if (!empty($google_tag_id) && preg_match('/^(G|AW)-[a-zA-Z0-9]+$/', $google_tag_id)) {
        echo "<!-- Google Tag Manager -->\n";
        echo "<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . esc_attr($google_tag_id) . "\"></script>\n";
        echo "<script>\n";
        echo "  window.dataLayer = window.dataLayer || [];\n";
        echo "  function gtag(){dataLayer.push(arguments);}\n";
        echo "  gtag('js', new Date());\n";
        echo "\n";
        echo "  gtag('config', '" . esc_js($google_tag_id) . "');\n";
        echo "</script>\n";
        echo "<!-- End Google Tag Manager -->\n";
    }
    // End Google Tag

    // Placeholder for Google Tag Manager / GA4 gtag.js in the future
    // $google_tag_id = get_post_meta($link_page_id, '_link_page_google_tag_id', true);
    // if (!empty($google_tag_id)) { ... }

}