<?php
/**
 * LinkPageDataProvider: Canonical provider for all link page data (live and preview).
 *
 * This class is the single source of truth for all data used to render a link page, whether for the public/live page or the management/preview interface. It normalizes, merges, and returns all relevant meta, post, and override data for a given link page and band profile.
 *
 * Usage: $data = LinkPageDataProvider::get_data($link_page_id, $band_id, $overrides);
 */
class LinkPageDataProvider {
    public static function get_data($link_page_id, $band_id, $overrides = array()) {
        // --- START Supported Link Types ---
        // Get supported link types from the dedicated config file
        require_once dirname(__FILE__) . '/../link-page-social-types.php';
        $supported_link_types = function_exists('bp_get_supported_social_link_types') ? bp_get_supported_social_link_types() : [];
        // --- END Supported Link Types ---

        // --- Data Fetching Logic (from link-page-data.php, refactored) ---
        $data = [];
        // Helper function to get value: override > post_meta > default
        $get_val = function($key, $default = '', $meta_key = null) use ($link_page_id, $overrides) {
            if (isset($overrides[$key])) {
                return $overrides[$key];
            }
            if ($meta_key === null) $meta_key = '_link_page_' . $key;
            
            // Fetch the meta value.
            $meta_val = get_post_meta($link_page_id, $meta_key, true);

            // Check if the meta key actually exists for this post.
            // This distinguishes between a key not existing and a key existing with an empty string value.
            if ( metadata_exists( 'post', $link_page_id, $meta_key ) ) {
                return $meta_val; // Return the saved value, even if it's an empty string.
            } else {
                return $default; // Meta key does not exist, so return the application-level default.
            }
        };
        $get_band_val = function($meta_key, $post_field = null) use ($band_id) {
            if (!$band_id) return '';
            if ($post_field) return get_post_field($post_field, $band_id);
            return get_post_meta($band_id, $meta_key, true);
        };
        // Profile image
        $custom_profile_img_url_override = isset($overrides['profile_img_url']) ? $overrides['profile_img_url'] : null;
        if ($custom_profile_img_url_override !== null) {
            $data['profile_img_url'] = $custom_profile_img_url_override;
        } else {
            $custom_profile_img_id = get_post_meta($link_page_id, '_link_page_profile_image_id', true);
            if ($custom_profile_img_id) {
                $data['profile_img_url'] = wp_get_attachment_image_url($custom_profile_img_id, 'large');
            } else if ($band_id && has_post_thumbnail($band_id)) {
                $data['profile_img_url'] = get_the_post_thumbnail_url($band_id, 'large');
            } else {
                $data['profile_img_url'] = '';
            }
        }
        // Bio
        $data['bio'] = isset($overrides['link_page_bio_text']) ? $overrides['link_page_bio_text'] : ($get_val('bio_text', $get_band_val(null, 'post_content'), '_link_page_bio_text'));
        // Display title
        $data['display_title'] = isset($overrides['band_profile_title']) ? $overrides['band_profile_title'] : ($get_val('display_title', $get_band_val(null, 'post_title'), '_link_page_display_title'));
        // Social links
        if (isset($overrides['band_profile_social_links_json'])) {
            $social_links_decoded = json_decode($overrides['band_profile_social_links_json'], true);
            $data['social_links'] = is_array($social_links_decoded) ? $social_links_decoded : [];
        } else {
            $social_links = $band_id ? get_post_meta($band_id, '_band_profile_social_links', true) : [];
            $data['social_links'] = is_array($social_links) ? $social_links : [];
        }
        // Link sections
        if (isset($overrides['link_page_links_json'])) {
            $links_decoded = json_decode($overrides['link_page_links_json'], true);
            $data['links'] = is_array($links_decoded) ? $links_decoded : [];
        } else {
            $links = get_post_meta($link_page_id, '_link_page_links', true);
            if (is_string($links)) $links = json_decode($links, true);
            $data['links'] = is_array($links) ? $links : [];
        }
        // Filter out expired links if expiration is enabled
        $expiration_enabled = get_post_meta($link_page_id, '_link_expiration_enabled', true);
        if ($expiration_enabled === '1' && isset($data['links']) && is_array($data['links'])) {
            $now = current_time('timestamp');
            foreach ($data['links'] as $section_idx => $section) {
                if (isset($section['links']) && is_array($section['links'])) {
                    foreach ($section['links'] as $link_idx => $link) {
                        if (!empty($link['expires_at'])) {
                            $expires = strtotime($link['expires_at']);
                            if ($expires !== false && $expires <= $now) {
                                unset($data['links'][$section_idx]['links'][$link_idx]);
                            }
                        }
                    }
                    if (isset($data['links'][$section_idx]['links'])) {
                        $data['links'][$section_idx]['links'] = array_values($data['links'][$section_idx]['links']);
                    }
                }
            }
            $data['links'] = array_values(array_filter($data['links'], function($section) {
                return !empty($section['links']);
            }));
        }
        // Customization meta
        $raw_css_vars = $get_val('custom_css_vars_json', null, '_link_page_custom_css_vars');
        
        // Handle both array (new format) and JSON string (legacy) formats
        if (is_array($raw_css_vars)) {
            // New format: stored as array, convert to JSON for compatibility
            $data['custom_css_vars_json'] = json_encode($raw_css_vars);
        } else {
            // Legacy format: stored as JSON string
            $data['custom_css_vars_json'] = $raw_css_vars;
        }

        // Profile image shape: Use JSON blob as canonical source
        $css_vars_for_shape = array();
        if (!empty($data['custom_css_vars_json'])) {
            $css_vars_for_shape = json_decode($data['custom_css_vars_json'], true);
        }
        if (is_array($css_vars_for_shape) && isset($css_vars_for_shape['_link_page_profile_img_shape'])) {
            $data['profile_img_shape'] = $css_vars_for_shape['_link_page_profile_img_shape'];
        } else {
            // Backwards compatibility: fall back to legacy meta if not present in JSON
            $legacy_shape = get_post_meta($link_page_id, '_link_page_profile_img_shape', true);
            $data['profile_img_shape'] = $legacy_shape ? $legacy_shape : 'circle';
        }

        // --- CSS Vars Normalization ---
        $css_vars = array();
        $overlay_val = null;

        // PRIORITIZE override from AJAX POST data
        $current_css_vars_json = isset($overrides['link_page_custom_css_vars_json']) && !empty($overrides['link_page_custom_css_vars_json']) ? $overrides['link_page_custom_css_vars_json'] : $data['custom_css_vars_json'];

        if ( !empty($current_css_vars_json) ) {
            $decoded_json = json_decode($current_css_vars_json, true);
            if (is_array($decoded_json)) {
                $css_vars = $decoded_json;
                if (isset($decoded_json['overlay'])) {
                    $overlay_val = $decoded_json['overlay'];
                }
            }
        } else {
            // Only use defaults if no custom_css_vars_json is provided (first load)
            if (function_exists('extrch_get_default_link_page_styles')) {
                $css_vars = extrch_get_default_link_page_styles();
            } else {
                $css_vars = array(
                    '--link-page-profile-img-radius' => '12px',
                    '--link-page-title-font-family' => 'WilcoLoftSans',
                );
            }
        }
        // Ensure overlay_val is always a string '1' or '0'. Default to '1' if missing or invalid.
        if ($overlay_val !== '0' && $overlay_val !== '1') {
            $overlay_val = '1';
        }
        
        // Ensure font stack is correctly derived if a 'value' is stored or defaulted.
        // This logic should only apply if '--link-page-title-font-family' is set in $css_vars.
        if (isset($css_vars['--link-page-title-font-family'])) {
            require_once dirname(__DIR__, 1) . '/link-page-font-config.php';
            global $extrch_link_page_fonts;
            $default_font_value = 'WilcoLoftSans';
            $default_font_stack = "'WilcoLoftSans', Helvetica, Arial, sans-serif";

            $current_font_setting = $css_vars['--link-page-title-font-family'];
            $final_font_stack = $default_font_stack;
            $font_config_found = false;

            if (is_array($extrch_link_page_fonts)) {
                foreach ($extrch_link_page_fonts as $font) {
                    if ($font['value'] === $current_font_setting || $font['stack'] === $current_font_setting) {
                        $final_font_stack = $font['stack'];
                        $font_config_found = true;
                        break;
                    }
                }
            }

            if (!$font_config_found && strpos($current_font_setting, ',') === false && strpos($current_font_setting, "'") === false && strpos($current_font_setting, '"') === false) {
                $final_font_stack = "'" . $current_font_setting . "', " . $default_font_stack;
            } elseif (!$font_config_found) {
                $final_font_stack = $current_font_setting ?: $default_font_stack;
            }
            $css_vars['--link-page-title-font-family'] = $final_font_stack;
        }

        // Ensure body font stack is correctly derived
        if (isset($css_vars['--link-page-body-font-family'])) {
            if (!isset($extrch_link_page_fonts)) { // Ensure font config is loaded if not already
                require_once dirname(__DIR__, 1) . '/link-page-font-config.php';
                global $extrch_link_page_fonts;
            }
            $default_body_font_value = 'Helvetica'; // Default if lookup fails
            $default_body_font_stack = "'Helvetica', Arial, sans-serif";

            $current_body_font_setting = $css_vars['--link-page-body-font-family'];
            $final_body_font_stack = $default_body_font_stack;
            $body_font_config_found = false;

            if (is_array($extrch_link_page_fonts)) {
                foreach ($extrch_link_page_fonts as $font) {
                    if ($font['value'] === $current_body_font_setting || $font['stack'] === $current_body_font_setting) {
                        $final_body_font_stack = $font['stack'];
                        $body_font_config_found = true;
                        break;
                    }
                }
            }

            if (!$body_font_config_found && strpos($current_body_font_setting, ',') === false && strpos($current_body_font_setting, "'") === false && strpos($current_body_font_setting, '"') === false) {
                // If it's a simple word (identifier) and not found, prepend it to the default stack
                $final_body_font_stack = "'" . $current_body_font_setting . "', " . $default_body_font_stack;
            } elseif (!$body_font_config_found) {
                // If it looks like a stack already or some other complex value, use it or fall back
                $final_body_font_stack = $current_body_font_setting ?: $default_body_font_stack;
            }
            $css_vars['--link-page-body-font-family'] = $final_body_font_stack;
        }

        // Ensure all expected CSS variables are present to prevent undefined array key errors
        $css_vars = self::ensureExpectedCssVariables($css_vars);

        // Removed hardcoded color fallbacks. If colors are not in $css_vars (i.e., not user-customized),
        // they should not be added here, allowing theme CSS to control them.
        // The $css_vars array will now only contain user-defined values or structural defaults like font-family and radius.
        $data['css_vars'] = $css_vars;
        // Extract background keys from $css_vars with defaults to prevent PHP warnings
        $data['background_type'] = isset($css_vars['--link-page-background-type']) ? $css_vars['--link-page-background-type'] : 'color';
        $data['background_color'] = isset($css_vars['--link-page-background-color']) ? $css_vars['--link-page-background-color'] : '#1a1a1a';
        $data['background_gradient_start'] = isset($css_vars['--link-page-background-gradient-start']) ? $css_vars['--link-page-background-gradient-start'] : '#0b5394';
        $data['background_gradient_end'] = isset($css_vars['--link-page-background-gradient-end']) ? $css_vars['--link-page-background-gradient-end'] : '#53940b';
        $data['background_gradient_direction'] = isset($css_vars['--link-page-background-gradient-direction']) ? $css_vars['--link-page-background-gradient-direction'] : 'to right';
        // Background data
        if (isset($overrides['background_image_url']) && $data['background_type'] === 'image') {
            $data['background_image_url'] = $overrides['background_image_url'];
            $data['background_image_id'] = 'temp_preview_image';
        } else {
            $bg_image_id = get_post_meta($link_page_id, '_link_page_background_image_id', true);
            $data['background_image_id'] = $bg_image_id;
            $data['background_image_url'] = $bg_image_id ? wp_get_attachment_image_url($bg_image_id, 'large') : '';
        }
        if ($data['background_type'] === 'image' && !empty($data['background_image_url'])) {
            $data['background_style'] = 'background-image: url(' . esc_url($data['background_image_url']) . '); background-size: cover; background-position: center; background-repeat: no-repeat;';
        } elseif ($data['background_type'] === 'gradient') {
            $data['background_style'] = 'background: linear-gradient(' . esc_attr($data['background_gradient_direction']) . ', ' . esc_attr($data['background_gradient_start']) . ', ' . esc_attr($data['background_gradient_end']) . ');';
        } else {
            $data['background_style'] = 'background-color: ' . esc_attr($data['background_color']) . ';';
        }
        // --- Subscribe Display Mode and Description ---
        $subscribe_display_mode = get_post_meta($link_page_id, '_link_page_subscribe_display_mode', true);
        if ($subscribe_display_mode === '' || !in_array($subscribe_display_mode, array('icon_modal', 'inline_form', 'disabled'), true)) {
            $subscribe_display_mode = 'icon_modal';
        }
        $subscribe_description = get_post_meta($link_page_id, '_link_page_subscribe_description', true);
        if (!is_string($subscribe_description)) {
            $subscribe_description = '';
        }

        // --- Social Icons Position ---
        $social_icons_position = get_post_meta($link_page_id, '_link_page_social_icons_position', true);
        if ($social_icons_position === '' || !in_array($social_icons_position, array('above', 'below'), true)) {
            $social_icons_position = 'above'; // Default to 'above'
        }

        // --- Powered By (not a CSS var, direct meta) ---
        $data['powered_by'] = ($get_val('powered_by', '1', '_link_page_powered_by') === '1');

        // --- Background image URL (used by preview.php for wrapper) ---
        $data['background_image_url'] = '';
        if ($data['css_vars']['--link-page-background-type'] === 'image' && !empty($data['css_vars']['--link-page-background-image'])) {
            // Extract URL from 'url(...)'
            preg_match('/url\(([^)]+)\)/i', $data['css_vars']['--link-page-background-image'], $matches);
            if (isset($matches[1])) {
                $data['background_image_url'] = trim($matches[1], " '\"");
            }
        }

        // --- Actual Link Page ID for template (used for share permalink) ---
        $data['_actual_link_page_id_for_template'] = $link_page_id;
        $data['band_profile'] = $band_id ? get_post($band_id) : null;

        // --- Social Icons Position (direct meta) ---
        $data['_link_page_social_icons_position'] = get_post_meta($link_page_id, '_link_page_social_icons_position', true) ?: 'above';

        // --- Subscribe Display Mode & Description (direct meta) ---
        $data['_link_page_subscribe_display_mode'] = get_post_meta($link_page_id, '_link_page_subscribe_display_mode', true) ?: 'icon_modal';
        $data['_link_page_subscribe_description'] = get_post_meta($link_page_id, '_link_page_subscribe_description', true);

        // --- Featured Link Data ---
        $data['featured_link_html'] = '';
        $data['featured_link_url_to_skip'] = null;
        if (function_exists('extrch_render_featured_link_section_html') && function_exists('extrch_get_featured_link_url_to_skip')) {
            // Use the processed $data['links'] (which are link_sections) and $data['css_vars']
            $link_sections_for_featured = isset($data['links']) && is_array($data['links']) ? $data['links'] : [];
            $css_vars_for_featured = isset($data['css_vars']) && is_array($data['css_vars']) ? $data['css_vars'] : [];
            $data['featured_link_html'] = extrch_render_featured_link_section_html($link_page_id, $link_sections_for_featured, $css_vars_for_featured);
            $featured_url = extrch_get_featured_link_url_to_skip($link_page_id);
            $data['featured_link_url_to_skip'] = $featured_url ? trailingslashit($featured_url) : null; // Always normalized
        }

        // Final preview_data array, ensuring all necessary keys are explicitly returned
        $return_data = array(
            'display_title'     => $data['display_title'],
            'bio'               => $data['bio'],
            'profile_img_url'   => $data['profile_img_url'],
            'social_links'      => $data['social_links'],
            'link_sections'     => (isset($data['links'][0]['links']) || empty($data['links'])) ? $data['links'] : array(array('section_title' => '', 'links' => $data['links'])),
            'powered_by'        => $data['powered_by'],
            
            // CSS Variables
            'css_vars' => $data['css_vars'], // For JS initialData and PHP initial style tag
            'custom_css_vars_json' => $data['custom_css_vars_json'], // Raw JSON if needed

            // Background components for JS initialData and PHP direct use
            'background_type'               => $data['background_type'],
            'background_color'              => $data['background_color'],
            'background_gradient_start'     => $data['background_gradient_start'],
            'background_gradient_end'       => $data['background_gradient_end'],
            'background_gradient_direction' => $data['background_gradient_direction'],
            'background_image_id'           => $data['background_image_id'],
            'background_image_url'          => $data['background_image_url'],
            
            // Composite style string
            'background_style'              => $data['background_style'], // For direct application (e.g. body, or initial preview container)

            // Alias for clarity in preview contexts if used
            'container_style_for_preview'    => $data['background_style'],
            'css_vars_for_preview_style_tag' => $data['css_vars'],

            // Profile Image Shape
            'profile_img_shape' => $data['profile_img_shape'],
            'overlay' => $overlay_val,

            // Add band_id and link_page_id to the returned data
            'band_id'           => $band_id,
            'link_page_id'      => $link_page_id,

            // Add supported link types for JS Social Icons module
            'supportedLinkTypes' => $supported_link_types,

            // Add subscribe display mode and description for template logic
            '_link_page_subscribe_display_mode' => $data['_link_page_subscribe_display_mode'],
            '_link_page_subscribe_description' => $data['_link_page_subscribe_description'],

            // Add social icons position for template logic
            '_link_page_social_icons_position' => $data['_link_page_social_icons_position'],

            // Add actual link page ID for template logic
            '_actual_link_page_id_for_template' => $data['_actual_link_page_id_for_template'],

            // Pass the band profile object
            'band_profile' => $data['band_profile'],

            // Add featured link data
            'featured_link_html' => $data['featured_link_html'],
            'featured_link_url_to_skip' => $data['featured_link_url_to_skip'],
        );
        return $return_data;
    }

    private static function ensureExpectedCssVariables($css_vars) {
        // Ensure all expected CSS variables are present with meaningful defaults
        $expected_vars = [
            // Background & Page Styles
            '--link-page-background-color' => '#121212',
            '--link-page-background-type' => 'color',
            '--link-page-background-gradient-start' => '#0b5394',
            '--link-page-background-gradient-end' => '#53940b',
            '--link-page-background-gradient-direction' => 'to right',
            '--link-page-card-bg-color' => 'rgba(0, 0, 0, 0.4)',
            '--link-page-background-image' => '',
            '--link-page-background-image-url' => '',
            
            // Text & Overlay Styles
            '--link-page-text-color' => '#e5e5e5',
            '--link-page-muted-text-color' => '#aaa',
            '--link-page-overlay-color' => 'rgba(0, 0, 0, 0.5)',
            
            // Button & Link Styles
            '--link-page-button-bg-color' => '#0b5394',
            '--link-page-link-text-color' => '#ffffff',
            '--link-page-button-hover-bg-color' => '#53940b',
            '--link-page-button-hover-text-color' => '#ffffff',
            '--link-page-button-radius' => '8px',
            '--link-page-button-border-width' => '0px',
            '--link-page-button-border-color' => '#0b5394',
            
            // Font Styles
            '--link-page-title-font-family' => 'WilcoLoftSans',
            '--link-page-title-font-size' => '2.1em',
            '--link-page-body-font-family' => 'Helvetica',
            '--link-page-body-font-size' => '1em',
            
            // Profile Image Styles
            '--link-page-profile-img-size' => '30%',
            '--link-page-profile-img-border-radius' => '50%',
            '--link-page-profile-img-aspect-ratio' => '1/1',
            '--link-page-profile-img-radius' => '12px',
            
            // Non-CSS special keys that might be accessed
            '_link_page_profile_img_shape' => 'circle',
            'overlay' => '1'
        ];

        foreach ($expected_vars as $var => $default_value) {
            if (!isset($css_vars[$var])) {
                $css_vars[$var] = $default_value;
            }
        }

        return $css_vars;
    }
}

// After this change, the following code in manage-link-page.php and AJAX handlers becomes redundant:
// - All manual $preview_data array building
// - Any direct use of custom_css_vars_json for the preview
// - Any scattered normalization of CSS vars or config for the preview
// Use LinkPageDataProvider::get_data() everywhere instead. 