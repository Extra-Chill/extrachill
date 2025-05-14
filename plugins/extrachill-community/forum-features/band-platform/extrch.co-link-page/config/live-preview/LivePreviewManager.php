<?php
/**
 * LivePreviewManager: Centralized data prep for link page live preview.
 *
 * Usage: $preview_data = LivePreviewManager::get_preview_data($link_page_id, $band_id, $overrides);
 */
class LivePreviewManager {
    public static function get_preview_data($link_page_id, $band_id, $overrides = array()) {
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
        $data['custom_css_vars_json'] = $get_val('custom_css_vars_json', null, '_link_page_custom_css_vars');

        // Profile image shape
        // Ensure 'rectangle' (old value) defaults to 'square'
        $profile_img_shape_meta_key = '_link_page_profile_img_shape';
        $current_shape = $get_val('profile_img_shape', 'square', $profile_img_shape_meta_key);
        if ($current_shape === 'rectangle') {
            $current_shape = 'square'; // Convert old value
        }
        $data['profile_img_shape'] = $current_shape;

        // --- CSS Vars Normalization ---
        $css_vars = array();
        $overlay_val = null;
        if ( !empty($data['custom_css_vars_json']) ) {
            $decoded_json = json_decode($data['custom_css_vars_json'], true);
            if (is_array($decoded_json)) {
                $css_vars = $decoded_json;
                if (isset($decoded_json['overlay'])) {
                    $overlay_val = $decoded_json['overlay'];
                }
            }
        } else {
            // If no saved CSS vars, load defaults from our centralized function.
            // extrch_get_default_link_page_styles() now returns only non-color-scheme-dependent defaults.
            if (function_exists('extrch_get_default_link_page_styles')) {
                $css_vars = extrch_get_default_link_page_styles();
            } else {
                // Minimal fallback if the function isn't available for some reason.
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
            require_once dirname(__DIR__, 2) . '/config/link-page-font-config.php';
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
        // Removed hardcoded color fallbacks. If colors are not in $css_vars (i.e., not user-customized),
        // they should not be added here, allowing theme CSS to control them.
        // The $css_vars array will now only contain user-defined values or structural defaults like font-family and radius.
        $data['css_vars'] = $css_vars;
        // Background data
        $data['background_type'] = $get_val('link_page_background_type', 'color', '_link_page_background_type');
        $data['background_color'] = $get_val('link_page_background_color', '#1a1a1a', '_link_page_background_color');
        if (isset($overrides['background_image_url']) && $data['background_type'] === 'image') {
            $data['background_image_url'] = $overrides['background_image_url'];
            $data['background_image_id'] = 'temp_preview_image';
        } else {
            $bg_image_id = get_post_meta($link_page_id, '_link_page_background_image_id', true);
            $data['background_image_id'] = $bg_image_id;
            $data['background_image_url'] = $bg_image_id ? wp_get_attachment_image_url($bg_image_id, 'large') : '';
        }
        // Align with keys saved by form-handler.php
        $data['background_gradient_start'] = $get_val('background_gradient_start_color', '#0b5394', '_link_page_background_gradient_start_color');
        $data['background_gradient_end'] = $get_val('background_gradient_end_color', '#53940b', '_link_page_background_gradient_end_color');
        $data['background_gradient_direction'] = $get_val('background_gradient_direction', 'to right', '_link_page_background_gradient_direction'); // This key was already correct in form handler if it used 'link_page_background_gradient_direction'

        if ($data['background_type'] === 'image' && !empty($data['background_image_url'])) {
            $data['background_style'] = 'background-image: url(' . esc_url($data['background_image_url']) . '); background-size: cover; background-position: center; background-repeat: no-repeat;';
        } elseif ($data['background_type'] === 'gradient') {
            $data['background_style'] = 'background: linear-gradient(' . esc_attr($data['background_gradient_direction']) . ', ' . esc_attr($data['background_gradient_start']) . ', ' . esc_attr($data['background_gradient_end']) . ');';
        } else {
            $data['background_style'] = 'background-color: ' . esc_attr($data['background_color']) . ';';
        }
        // Final preview_data array, ensuring all necessary keys are explicitly returned
        $return_data = array(
            'display_title'     => $data['display_title'],
            'bio'               => $data['bio'],
            'profile_img_url'   => $data['profile_img_url'],
            'social_links'      => $data['social_links'],
            'link_sections'     => (isset($data['links'][0]['links']) || empty($data['links'])) ? $data['links'] : array(array('section_title' => '', 'links' => $data['links'])),
            'powered_by'        => isset($data['powered_by']) ? (bool)$data['powered_by'] : true,
            
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
        );
        return $return_data;
    }
}

// After this change, the following code in manage-link-page.php and AJAX handlers becomes redundant:
// - All manual $preview_data array building
// - Any direct use of custom_css_vars_json for the preview
// - Any scattered normalization of CSS vars or config for the preview
// Use LivePreviewManager::get_preview_data() everywhere instead. 