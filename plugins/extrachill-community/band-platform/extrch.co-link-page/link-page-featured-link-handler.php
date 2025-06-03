<?php
/**
 * Featured Link Handler for Link Pages
 *
 * Handles saving and rendering logic for the Featured Link functionality.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saves featured link settings (custom title, description, thumbnail) from the form POST data.
 * Assumes _enable_featured_link and _featured_link_original_id (now a URL) are handled by the main form handler.
 *
 * @param int $link_page_id The ID of the link page CPT.
 * @param array $post_data The $_POST array.
 * @param array $files_data The $_FILES array.
 */
function extrch_save_featured_link_settings($link_page_id, $post_data, $files_data) {
    if (empty($link_page_id) || !is_array($post_data) || !is_array($files_data)) {
        return;
    }

    $is_feature_enabled_globally = get_post_meta($link_page_id, '_enable_featured_link', true) === '1';
    
    // Define these upfront for clarity and use throughout the function
    $new_link_url_from_post = isset($post_data['featured_link_original_id']) ? sanitize_text_field($post_data['featured_link_original_id']) : null;
    $current_link_url_from_meta = get_post_meta($link_page_id, '_featured_link_original_id', true);
    $was_thumbnail_explicitly_removed = isset($post_data['featured_link_thumbnail_id_action']) && $post_data['featured_link_thumbnail_id_action'] === 'remove';
    $is_new_thumbnail_uploaded = !empty($files_data['featured_link_thumbnail_upload']['tmp_name']);

    if ($is_feature_enabled_globally) {
        if (isset($post_data['featured_link_custom_description'])) {
            update_post_meta($link_page_id, '_featured_link_custom_description', wp_kses_post(wp_unslash($post_data['featured_link_custom_description'])));
        }

        if ($is_new_thumbnail_uploaded) {
            $max_file_size = 5 * 1024 * 1024; // 5MB
            if ($files_data['featured_link_thumbnail_upload']['size'] > $max_file_size) {
                // Error: file too large
            } else {
                if (!function_exists('wp_handle_upload')) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                }
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                
                $old_custom_thumb_id = get_post_meta($link_page_id, '_featured_link_thumbnail_id', true);
                $new_custom_thumb_id = media_handle_upload('featured_link_thumbnail_upload', $link_page_id);

                if (is_numeric($new_custom_thumb_id) && !is_wp_error($new_custom_thumb_id)) {
                    update_post_meta($link_page_id, '_featured_link_thumbnail_id', $new_custom_thumb_id);
                    if ($old_custom_thumb_id && $old_custom_thumb_id != $new_custom_thumb_id) {
                        wp_delete_attachment($old_custom_thumb_id, true);
                    }
                    delete_post_meta($link_page_id, '_featured_link_fetched_thumbnail_url');
                } else {
                    if (is_wp_error($new_custom_thumb_id)) {
                        error_log('[Featured Link Handler] Thumbnail upload error: ' . $new_custom_thumb_id->get_error_message());
                    }
                }
            }
        } else {
            // No new thumbnail uploaded. Handle URL change or explicit removal.
            $url_has_changed = ($new_link_url_from_post !== $current_link_url_from_meta);

            if ($url_has_changed || $was_thumbnail_explicitly_removed) {
                $old_custom_thumb_id_to_clear = get_post_meta($link_page_id, '_featured_link_thumbnail_id', true);
                if ($old_custom_thumb_id_to_clear) {
                    if ($was_thumbnail_explicitly_removed) { 
                        wp_delete_attachment($old_custom_thumb_id_to_clear, true);
                    }
                    delete_post_meta($link_page_id, '_featured_link_thumbnail_id');
                }
                delete_post_meta($link_page_id, '_featured_link_fetched_thumbnail_url');
            }
            
            $current_custom_thumb_id_after_potential_clear = get_post_meta($link_page_id, '_featured_link_thumbnail_id', true);

            if (!$current_custom_thumb_id_after_potential_clear && !empty($new_link_url_from_post) && function_exists('extrch_fetch_remote_og_image')) {
                // Attempt to fetch OG image for the new_link_url_from_post
                $fetched_og_image_url = extrch_fetch_remote_og_image($new_link_url_from_post);
                if ($fetched_og_image_url) {
                    update_post_meta($link_page_id, '_featured_link_fetched_thumbnail_url', esc_url_raw($fetched_og_image_url));
                } else {
                    // If fetch fails for the current $new_link_url_from_post, unconditionally delete any stored fetched thumbnail URL.
                    delete_post_meta($link_page_id, '_featured_link_fetched_thumbnail_url');
                }
            }
        }
    } else {
        delete_post_meta($link_page_id, '_featured_link_custom_description');
        
        $existing_thumb_id = get_post_meta($link_page_id, '_featured_link_thumbnail_id', true);
        if ($existing_thumb_id) {
            delete_post_meta($link_page_id, '_featured_link_thumbnail_id');
        }
        delete_post_meta($link_page_id, '_featured_link_fetched_thumbnail_url');
    }
}

/**
 * Renders the HTML for the featured link section on the public page.
 *
 * @param int $link_page_id The ID of the link page CPT.
 * @param array $link_sections The array of all link sections for the page.
 * @param array $css_vars The array of custom CSS variables for the page.
 * @return string The HTML for the featured link section, or empty string.
 */
function extrch_render_featured_link_section_html($link_page_id, $link_sections, $css_vars) {
    $is_featured_link_enabled = get_post_meta($link_page_id, '_enable_featured_link', true) === '1';
    if (!$is_featured_link_enabled) {
        return '';
    }

    $featured_link_original_url_from_meta = get_post_meta($link_page_id, '_featured_link_original_id', true);
    if (empty($featured_link_original_url_from_meta)) {
        return '';
    }

    $custom_desc = get_post_meta($link_page_id, '_featured_link_custom_description', true);
    $thumbnail_id = get_post_meta($link_page_id, '_featured_link_thumbnail_id', true);
    $fetched_thumbnail_url = get_post_meta($link_page_id, '_featured_link_fetched_thumbnail_url', true);
    $title_font_family = isset($css_vars['--link-page-title-font-family']) ? $css_vars['--link-page-title-font-family'] : "'WilcoLoftSans', sans-serif";
    $thumbnail_url_to_display = '';

    if ($thumbnail_id) {
        $thumbnail_url_to_display = wp_get_attachment_image_url($thumbnail_id, 'large');
    } elseif (!empty($fetched_thumbnail_url) && wp_http_validate_url($fetched_thumbnail_url)) {
        $thumbnail_url_to_display = esc_url($fetched_thumbnail_url);
    }

    $original_link_title_from_array = 'Link';
    $actual_link_url_from_array = '#';
    $link_item_id_for_share = 'featured-' . md5($featured_link_original_url_from_meta);
    $found_original_link = false;

    if (is_array($link_sections)) {
        foreach ($link_sections as $section) {
            if (isset($section['links']) && is_array($section['links'])) {
                foreach ($section['links'] as $link_item) {
                    $current_item_url = isset($link_item['link_url']) ? $link_item['link_url'] : (isset($link_item['url']) ? $link_item['url'] : null);
                    if ($current_item_url && trailingslashit($current_item_url) === trailingslashit($featured_link_original_url_from_meta)) {
                        $original_link_title_from_array = isset($link_item['link_text']) ? $link_item['link_text'] : (isset($link_item['title']) ? $link_item['title'] : 'Link');
                        $actual_link_url_from_array = $current_item_url;
                        if (!empty($link_item['id'])) {
                            $link_item_id_for_share = 'featured-' . $link_item['id'];
                        }
                        $found_original_link = true;
                        break 2;
                    }
                }
            }
        }
    }

    if (!$found_original_link) {
        return '';
    }

    $display_title = $original_link_title_from_array;
    $has_thumbnail_to_display = !empty($thumbnail_url_to_display);

    $share_button_html = '';
    $share_button_classes = 'extrch-share-trigger extrch-share-item-trigger extrch-share-featured-trigger';
    $share_button_html .= '<button class="' . esc_attr($share_button_classes) . '" ';
    $share_button_html .= 'aria-label="Share this link" ';
    $share_button_html .= 'data-share-type="link" ';
    $share_button_html .= 'data-share-url="' . esc_url($actual_link_url_from_array) . '" ';
    $share_button_html .= 'data-share-title="' . esc_attr($display_title) . '" ';
    $share_button_html .= 'data-share-item-id="' . esc_attr($link_item_id_for_share) . '"';
    $share_button_html .= '>';
    $share_button_html .= '<i class="fas fa-ellipsis-v"></i>';
    $share_button_html .= '</button>';

    $html = '<div class="link-page-featured-link-section">';
    $html .= '<a href="' . esc_url($actual_link_url_from_array) . '" target="_blank" rel="noopener" class="featured-link-anchor">';
    if ($has_thumbnail_to_display) {
        $html .= '<img src="' . esc_url($thumbnail_url_to_display) . '" alt="' . esc_attr($display_title) . '" class="featured-link-thumbnail">';
    }
    $html .= '<div class="featured-link-content">';
    $html .= '<div class="featured-link-title-row">';
    $html .= '<h3 class="featured-link-title" style="font-family: ' . esc_attr($title_font_family) . ';">' . esc_html($display_title) . '</h3>';
    $html .= $share_button_html;
    $html .= '</div>';
    if (!empty($custom_desc)) {
        $html .= '<p class="featured-link-description">' . wp_kses_post($custom_desc) . '</p>';
    }
    $html .= '</div>';
    $html .= '</a>';
    $html .= '</div>';

    return $html;
}

/**
 * Returns the URL of the link that should be skipped in the main loop if it's featured.
 *
 * @param int $link_page_id The ID of the link page CPT.
 * @return string|null The URL of the featured link to skip, or null.
 */
function extrch_get_featured_link_url_to_skip($link_page_id) {
    $is_featured_link_enabled = get_post_meta($link_page_id, '_enable_featured_link', true) === '1';
    if ($is_featured_link_enabled) {
        // _featured_link_original_id now stores the URL.
        return get_post_meta($link_page_id, '_featured_link_original_id', true);
    }
    return null;
}

/**
 * Fetches the Open Graph image URL from a remote URL.
 *
 * @param string $url The URL to fetch the OG image from.
 * @return string|false The OG image URL if found, otherwise false.
 */
function extrch_fetch_remote_og_image($url) {
    if (empty($url) || !wp_http_validate_url($url)) {
        return false;
    }

    $response = wp_safe_remote_get($url, array('timeout' => 10));

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return false;
    }

    $old_libxml_error_handling = libxml_use_internal_errors(true);
    
    $dom = new DOMDocument();
    @$dom->loadHTML($body);
    
    libxml_clear_errors();
    libxml_use_internal_errors($old_libxml_error_handling);

    $meta_tags = $dom->getElementsByTagName('meta');
    $og_image_url = false;

    foreach ($meta_tags as $meta_tag) {
        if ($meta_tag->getAttribute('property') === 'og:image' || $meta_tag->getAttribute('name') === 'og:image') {
            $og_image_url = $meta_tag->getAttribute('content');
            break;
        }
    }
    if (!$og_image_url) {
        foreach ($meta_tags as $meta_tag) {
            if ($meta_tag->getAttribute('property') === 'twitter:image' || $meta_tag->getAttribute('name') === 'twitter:image') {
                $og_image_url = $meta_tag->getAttribute('content');
                break;
            }
        }
    }

    if ($og_image_url && wp_http_validate_url($og_image_url)) {
        return esc_url_raw($og_image_url);
    }

    return false;
}

/**
 * AJAX handler to fetch the Open Graph image for a given URL.
 * Used by the link page manager to update the featured link preview.
 */
add_action('wp_ajax_extrch_fetch_og_image_for_preview', 'extrch_ajax_fetch_og_image_for_preview');
add_action('wp_ajax_nopriv_extrch_fetch_og_image_for_preview', 'extrch_ajax_fetch_og_image_for_preview'); // If access needed for non-logged-in (though unlikely for manager)

function extrch_ajax_fetch_og_image_for_preview() {
    check_ajax_referer('extrch_link_page_featured_link_nonce', 'security');

    $url_to_fetch = isset($_POST['url_to_fetch']) ? esc_url_raw(wp_unslash($_POST['url_to_fetch'])) : null;

    if (empty($url_to_fetch) || !wp_http_validate_url($url_to_fetch)) {
        wp_send_json_error(['message' => 'Invalid or missing URL.']);
        return;
    }

    $og_image_url = extrch_fetch_remote_og_image($url_to_fetch);

    if ($og_image_url) {
        wp_send_json_success(['og_image_url' => $og_image_url]);
    } else {
        wp_send_json_success(['og_image_url' => '']); // Send success with empty URL if not found, so JS can handle it gracefully
    }
}

?>

