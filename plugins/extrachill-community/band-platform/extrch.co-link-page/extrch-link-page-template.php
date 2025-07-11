<?php
/**
 * Partial: extrch-link-page-template.php
 * Shared markup for extrch.co Link Page (public and preview).
 *
 * @param string $profile_img_url
 * @param string $display_title
 * @param string $bio
 * @param array  $links
 * @param array  $social_links (optional)
 * @param bool   $powered_by (optional, default true)
 */

// error_log('[DEBUG TEMPLATE] extrch-link-page-template.php loaded. Current Post ID in global $post: ' . (isset($GLOBALS['post']) ? $GLOBALS['post']->ID : 'Not set'));
// REMOVED: error_log('[DEBUG TEMPLATE] Query vars: ' . print_r(get_defined_vars(), true)); // THIS WAS CAUSING MEMORY EXHAUSTION

// If $data is not already set by the caller (e.g., AJAX handler or direct include with params), then fetch it.
if ( ! isset( $data ) || ! is_array( $data ) ) {
    // Ensure $link_page_id and $band_id are available if called directly (e.g., by single-band_link_page.php or initial preview render)
    // These might be global or passed if this template is included by a function that sets them up.
    // For robustness, ensure they are available or fetch them if absolutely necessary (though ideally passed).
    if ( ! isset( $link_page_id ) || ! isset( $band_id ) ) {
        // Fallback: This might occur if the template is included unexpectedly without setup.
        // Attempt to get them from global $post if on a relevant page, but this is not ideal for an AJAX context.
        global $post;
        if ( ! isset( $link_page_id ) && isset( $post->ID ) ) $link_page_id = $post->ID; 
        if ( ! isset( $band_id ) && isset( $post->ID ) ) $band_id = get_post_meta( $post->ID, '_associated_band_profile_id', true );
    }

    // Ensure LinkPageDataProvider class is available.
    if ( ! class_exists( 'LinkPageDataProvider' ) ) {
        $data_provider_path = dirname( __FILE__ ) . '/data/LinkPageDataProvider.php';
        if ( file_exists( $data_provider_path ) ) {
            require_once $data_provider_path;
        }
    }

    if ( class_exists( 'LinkPageDataProvider' ) && isset($link_page_id) && isset($band_id) ) {
        // When this template is included directly (e.g., by single-band_link_page.php or initial preview render),
        // there are no $preview_data_overrides.
        $data = LinkPageDataProvider::get_data( $link_page_id, $band_id, array() );
    } else {
        // Fallback if LinkPageDataProvider isn't available or IDs are missing.
        // This might indicate an issue with how the template is being included.
        $data = array( // Provide minimal default structure to avoid errors
            'display_title' => 'Error: Link Page Data Unavailable',
            'bio' => '',
            'profile_img_url' => '',
            'social_links' => array(),
            'link_sections' => array(), // Use link_sections directly
            'powered_by' => true,
            'css_vars' => array(),
            'background_style' => 'background-color: #f0f0f0;', // Default error background
        );
    }
}

// If $extrch_link_page_template_data is set (passed explicitly), use it as $data
if (isset($extrch_link_page_template_data) && is_array($extrch_link_page_template_data)) {
    $data = $extrch_link_page_template_data;
}

// Ensure essential keys exist in $data to prevent undefined index errors,
// especially if LinkPageDataProvider::get_data() might not return them all in some edge case.
$data['powered_by'] = isset($data['powered_by']) ? $data['powered_by'] : true;
$data['display_title'] = isset($data['display_title']) ? $data['display_title'] : '';
$data['bio'] = isset($data['bio']) ? $data['bio'] : '';
$data['profile_img_url'] = isset($data['profile_img_url']) ? $data['profile_img_url'] : '';
$data['social_links'] = isset($data['social_links']) && is_array($data['social_links']) ? $data['social_links'] : [];

// LinkPageDataProvider::get_data() now returns 'link_sections' directly.
$link_sections = isset($data['link_sections']) && is_array($data['link_sections']) ? $data['link_sections'] : [];

// Determine the inline style for the container.
// For the preview iframe, it receives 'initial_container_style_for_php_preview' via query_var.
// For the public page, the container's background is now primarily controlled by CSS
// (e.g., transparent to show body background, or a card color via CSS var).
// We only apply the direct $data['background_style'] here if it's the preview iframe.

$initial_container_style_attr = '';
$container_classes = 'extrch-link-page-container'; // Base class
$is_preview_iframe_context = (bool) get_query_var('is_extrch_preview_iframe', false); // This query var should be set in live-preview/preview.php

if ( $is_preview_iframe_context ) {
    $container_classes .= ' extrch-link-page-preview-container'; // Add preview-specific class
    // For the preview iframe, ensure it behaves as a flex container filling height.
    // All other styles (background, colors, fonts) are applied via CSS variables injected
    // into the iframe's :root by preview.php.
    $initial_container_style_attr = ' style="display:flex; flex-direction:column; height:100%; min-height:100%; box-sizing:border-box;"';
} else {
    // For the public page (single-band_link_page.php)
    // The .extrch-link-page-container's background should be handled by css/extrch-links.css.
    // It might be transparent (if body has the main bg) or a card color (using a CSS var).
    // We no longer apply $data['background_style'] directly here for the public page,
    // nor do we append CSS variable definitions to its inline style.
    // If a specific inline style is ever needed for the public container beyond what CSS can do,
    // it would be constructed carefully here, but typically it won't be for background.
    // For now, no inline style is applied to the public page container from this template.
}

// Note: The $data['css_vars'] are outputted as a :root style block in single-band_link_page.php
// and are used by extrch-links.css. They are not applied as inline styles here.

// Determine profile image shape class
$profile_img_shape_class = 'shape-square'; // Default to square
if (isset($data['profile_img_shape'])) {
    if ($data['profile_img_shape'] === 'circle') {
        $profile_img_shape_class = 'shape-circle';
    } elseif ($data['profile_img_shape'] === 'rectangle') {
        $profile_img_shape_class = 'shape-rectangle';
    } elseif ($data['profile_img_shape'] === 'square') {
        $profile_img_shape_class = 'shape-square';
    } else {
        $profile_img_shape_class = 'shape-square'; // fallback for unknown values
    }
}

$overlay_enabled = true;
if (isset($data['overlay'])) {
    $overlay_enabled = $data['overlay'] === '1';
}
$wrapper_class = 'extrch-link-page-content-wrapper' . ($overlay_enabled ? '' : ' no-overlay');

// Ensure all variables used in data attributes are defined in the scope
// For the page share button:
// $link_page_id_for_permalink = isset($link_page_id) ? $link_page_id : (isset($post) ? $post->ID : 0);
// Let's ensure we use the ID from the $data array if possible, or what single-band_link_page.php would have set.
// $single_band_link_page_id is the ID of the actual link page CPT entry. This is what we should use.
$single_band_link_page_id = isset($data['_actual_link_page_id_for_template']) ? $data['_actual_link_page_id_for_template'] : (isset($link_page_id) ? $link_page_id : 0);
if (empty($single_band_link_page_id) && isset($extrch_link_page_template_data['original_link_page_id'])) {
    $single_band_link_page_id = $extrch_link_page_template_data['original_link_page_id'];
}

// Manually construct the extrachill.link URL using the band slug
$band_slug = isset($data['band_profile']->post_name) ? $data['band_profile']->post_name : '';
$share_page_url = !empty($band_slug) ? 'https://extrachill.link/' . $band_slug : home_url('/'); // Fallback to home_url if slug is empty
error_log('[DEBUG TEMPLATE] Share Page URL determined as: ' . $share_page_url . ' based on band slug: ' . $band_slug);

// If we're on extrachill.link and no session token exists, check if user came from management interface
$current_host = strtolower($_SERVER['HTTP_HOST'] ?? '');
if ($current_host === 'extrachill.link' && empty($_COOKIE['ecc_user_session_token'])) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, 'community.extrachill.com/manage-link-page') !== false || 
        strpos($referer, 'community.extrachill.com/manage-band-profile') !== false) {
        // User came from management interface but has no session token on extrachill.link
        // This suggests they need cross-domain session synchronization
        error_log('[DEBUG TEMPLATE] User on extrachill.link with no session token, came from management interface: ' . $referer);
    }
}

$bg_type = isset($data['css_vars']['--link-page-background-type']) ? $data['css_vars']['--link-page-background-type'] : 'color';

if (isset($data) && is_array($data)) {
    $data['original_link_page_id'] = $link_page_id; 
}

// Fetch subscribe display mode setting
$subscribe_display_mode = $data['_link_page_subscribe_display_mode'] ?? 'icon_modal'; // Default to icon/modal if not set

// Fetch social icons position setting
$social_icons_position = $data['_link_page_social_icons_position'] ?? 'above'; // Default to above if not set

$body_bg_style = '';

// Fetch Featured Link Data (moved up to be available for the main structure)
$featured_link_html = '';
$featured_link_original_url_to_skip = null;

if (isset($link_page_id) && function_exists('extrch_render_featured_link_section_html') && function_exists('extrch_get_featured_link_url_to_skip')) {
    // $link_sections should be available from $data['link_sections'] as populated earlier
    // $data['css_vars'] should also be available
    $current_link_sections = isset($data['link_sections']) && is_array($data['link_sections']) ? $data['link_sections'] : [];
    $current_css_vars = isset($data['css_vars']) && is_array($data['css_vars']) ? $data['css_vars'] : [];

    $featured_link_html = extrch_render_featured_link_section_html($link_page_id, $current_link_sections, $current_css_vars);
    $featured_link_original_url_to_skip = extrch_get_featured_link_url_to_skip($link_page_id);
}

?>
<div class="<?php echo esc_attr($container_classes); ?>"
     data-bg-type="<?php echo esc_attr($bg_type); ?>"
     <?php echo $initial_container_style_attr; ?>>
    <div class="<?php echo esc_attr($wrapper_class); ?>" style="flex-grow:1;">
        <div class="extrch-link-page-header-content">
            <?php 
            // Absolutely positioned bell (subscribe) and ellipses (share) triggers in top left/right
            if ($subscribe_display_mode === 'icon_modal') : ?>
                <button class="extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger" aria-label="Subscribe to this band">
                    <i class="fas fa-bell"></i>
                </button>
            <?php endif; ?>
            <button class="extrch-share-trigger extrch-share-page-trigger" aria-label="Share this page" data-share-type="page" data-share-url="<?php echo esc_url($share_page_url); ?>" data-share-title="<?php echo esc_attr($data['display_title']); ?>">
                <i class="fas fa-ellipsis-h"></i>
            </button>
            <!-- Main flex content below -->
            <?php 
            $img_container_classes = "extrch-link-page-profile-img " . $profile_img_shape_class;
            $no_image_class = empty($data['profile_img_url']) ? ' no-image' : '';
            if ($is_preview_iframe_context) : ?>
                <div class="<?php echo esc_attr($img_container_classes . $no_image_class); ?>">
                    <img src="<?php echo esc_url($data['profile_img_url']); ?>" alt="<?php echo esc_attr($data['display_title']); ?>">
                </div>
            <?php elseif (!empty($data['profile_img_url'])): ?>
                <div class="<?php echo esc_attr($img_container_classes); ?>"><img src="<?php echo esc_url($data['profile_img_url']); ?>" alt="<?php echo esc_attr($data['display_title']); ?>"></div>
            <?php endif; ?>
            <h1 class="extrch-link-page-title"><?php echo esc_html($data['display_title']); ?></h1>
            <?php if (!empty($data['bio'])): ?><div class="extrch-link-page-bio"><?php echo esc_html($data['bio']); ?></div><?php endif; ?>
        </div>

        <?php 
        // Conditionally render social icons ABOVE featured link and regular links
        if ($social_icons_position === 'above') {
            if (!empty($data['social_links']) && is_array($data['social_links'])){
                 // Ensure the social types config file is included
                if ( ! function_exists( 'bp_get_supported_social_link_types' ) ) {
                     $social_types_path = dirname( __FILE__ ) . '/link-page-social-types.php';
                     if ( file_exists( $social_types_path ) ) {
                         require_once $social_types_path;
                     }
                 }
                
                 $supported_social_types = function_exists( 'bp_get_supported_social_link_types' ) ? bp_get_supported_social_link_types() : array();
                ?>
                <div class="extrch-link-page-socials">
                    <?php
                    foreach ($data['social_links'] as $icon):
                        if (empty($icon['url']) || empty($icon['type'])) continue;
                        
                        $social_type = strtolower($icon['type']);
                        $icon_class = '';

                        if (isset($supported_social_types[$social_type]['icon'])) {
                            $icon_class = $supported_social_types[$social_type]['icon'];
                        } elseif (!empty($icon['icon'])) {
                            $icon_class = $icon['icon'];
                        } else {
                            $icon_class = 'fab fa-' . preg_replace('/[^a-z0-9_-]/', '', $social_type);
                        }

                        if (empty($icon_class)) continue;

                        $icon_class = esc_attr($icon_class);
                        $url = esc_url($icon['url']);
                    ?>
                        <a href="<?php echo $url; ?>" class="extrch-social-icon" rel="noopener">
                            <i class="<?php echo $icon_class; ?>" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php 
            } // end if !empty($data['social_links'])
        } // end if $social_icons_position === 'above'

        // Output Featured Link HTML if generated
        // This will now be after "above" socials and before regular links
        if (!empty($featured_link_html)) {
            echo $featured_link_html;
        }

        // Prepare for rendering actual link sections
        // ...
        ?>
        <?php if (!empty($link_sections)): ?>
            <?php foreach ($link_sections as $section): ?>
                <?php if (!empty($section['section_title'])): ?>
                    <div class="extrch-link-page-section-title"><?php echo esc_html($section['section_title']); ?></div>
                <?php endif; ?>

                <?php if (!empty($section['links']) && is_array($section['links'])):
                    // Initialize here for each section, in case a section is empty
                    $has_links_in_section_after_filter = false;
                ?>
                    <div class="extrch-link-page-links">
                        <?php 
                        $normalized_url_to_skip_for_public_page = $featured_link_original_url_to_skip ? trailingslashit($featured_link_original_url_to_skip) : null;
                        foreach ($section['links'] as $link_item):
                            if (empty($link_item['link_url']) || empty($link_item['link_text'])) continue;
                            // Skip if this link is the featured link (always use normalized URL)
                            $current_link_url_normalized_for_public_page = trailingslashit($link_item['link_url']);
                            if ($normalized_url_to_skip_for_public_page && $current_link_url_normalized_for_public_page === $normalized_url_to_skip_for_public_page) {
                                continue;
                            }
                            $has_links_in_section_after_filter = true; // Mark that we found at least one link to render in this section
                            
                            // --- Inline YouTube Embed logic ---
                            $link_classes = "extrch-link-page-link";
                            $is_youtube_link = false;
                            if (isset($link_page_id) && function_exists('extrch_is_youtube_embed_enabled') && extrch_is_youtube_embed_enabled($link_page_id)) {
                                if (preg_match('/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $link_item['link_url'], $matches)) {
                                    $link_classes .= " extrch-youtube-embed-trigger";
                                    $is_youtube_link = true;
                                }
                            }
                            // --- End Inline YouTube Embed logic ---
                            ?>
                            <a href="<?php echo esc_url($link_item['link_url']); ?>" class="<?php echo esc_attr($link_classes); ?>" rel="noopener">
                                <span class="extrch-link-page-link-text"><?php echo esc_html($link_item['link_text']); ?></span>
                                <span class="extrch-link-page-link-icon">
                                    <button class="extrch-share-trigger extrch-share-item-trigger" 
                                            aria-label="Share this link" 
                                            data-share-type="link"
                                            data-share-url="<?php echo esc_url($link_item['link_url']); ?>" 
                                            data-share-title="<?php echo esc_attr($link_item['link_text']); ?>">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
        // Output the inline subscribe form below all links if in inline_form mode
        if ($subscribe_display_mode === 'inline_form') {
            $band_name = $data['display_title'];
            require locate_template('band-platform/subscribe/subscribe-inline-form.php');
        }
        // Output the modal partial (but not the bell icon) if in icon_modal mode
        if ($subscribe_display_mode === 'icon_modal') {
            $band_name = $data['display_title'];
            require locate_template('band-platform/subscribe/subscribe-modal.php');
        }

        // Conditionally render social icons below links (and below subscribe form if present)
        if ($social_icons_position === 'below') {
            if (!empty($data['social_links']) && is_array($data['social_links'])):
                 // Ensure the social types config file is included
                if ( ! function_exists( 'bp_get_supported_social_link_types' ) ) {
                     $social_types_path = dirname( __FILE__ ) . '/link-page-social-types.php';
                     if ( file_exists( $social_types_path ) ) {
                         require_once $social_types_path;
                     }
                 }
                
                 $supported_social_types = function_exists( 'bp_get_supported_social_link_types' ) ? bp_get_supported_social_link_types() : array();
                ?>
                <div class="extrch-link-page-socials extrch-socials-below">
                    <?php
                    foreach ($data['social_links'] as $icon):
                        if (empty($icon['url']) || empty($icon['type'])) continue; // Ensure type is also present
                        
                        $social_type = strtolower($icon['type']);
                        $icon_class = '';

                        // Look up icon class from the supported types config
                        if (isset($supported_social_types[$social_type]['icon'])) {
                            $icon_class = $supported_social_types[$social_type]['icon'];
                        } elseif (!empty($icon['icon'])) {
                            // Fallback to stored icon class if type lookup fails but icon is present
                            $icon_class = $icon['icon'];
                        } else {
                            // Final fallback: attempt to construct based on type (less preferred)
                            $icon_class = 'fab fa-' . preg_replace('/[^a-z0-9_-]/', '', $social_type);
                        }

                        if (empty($icon_class)) continue; // Skip if no icon class could be determined

                        $icon_class = esc_attr($icon_class);
                        $url = esc_url($icon['url']);
                    ?>
                        <a href="<?php echo $url; ?>" class="extrch-social-icon" rel="noopener">
                            <i class="<?php echo $icon_class; ?>" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; 
        }
        ?>

        <?php if ($data['powered_by']): ?>
        <div class="extrch-link-page-powered" style="margin-top:auto; padding-top:1em; padding-bottom:1em;">
            <a href="https://extrachill.link" rel="noopener">Powered by Extra Chill</a>
        </div>
        <?php endif; ?>
    </div>

    <div id="extrch-share-modal" class="extrch-share-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="extrch-share-modal-main-title">
        <div class="extrch-share-modal-overlay"></div>
        <div class="extrch-share-modal-content">
            <button class="extrch-share-modal-close" aria-label="Close share modal">&times;</button>
            
            <div class="extrch-share-modal-header">
                <img src="" alt="Profile" class="extrch-share-modal-profile-img" style="display:none;">
                <h3 id="extrch-share-modal-main-title" class="extrch-share-modal-main-title">Share Page</h3>
                <p class="extrch-share-modal-subtitle"></p>
            </div>

            <div class="extrch-share-modal-options-grid">
                <button class="extrch-share-option-button extrch-share-option-copy-link" aria-label="Copy Link">
                    <span class="extrch-share-option-icon"><i class="fas fa-copy"></i></span>
                    <span class="extrch-share-option-label">Copy Link</span>
                </button>
                <button class="extrch-share-option-button extrch-share-option-native" aria-label="More sharing options">
                    <span class="extrch-share-option-icon"><i class="fas fa-ellipsis-h"></i></span>
                    <span class="extrch-share-option-label">More</span>
                </button>
                <a href="#" class="extrch-share-option-button extrch-share-option-facebook" rel="noopener" aria-label="Share on Facebook">
                    <span class="extrch-share-option-icon"><i class="fab fa-facebook-f"></i></span>
                    <span class="extrch-share-option-label">Facebook</span>
                </a>
                <a href="#" class="extrch-share-option-button extrch-share-option-twitter" rel="noopener" aria-label="Share on Twitter">
                    <span class="extrch-share-option-icon"><i class="fab fa-x-twitter"></i></span>
                    <span class="extrch-share-option-label">Twitter</span>
                </a>
                 <a href="#" class="extrch-share-option-button extrch-share-option-linkedin" rel="noopener" aria-label="Share on LinkedIn">
                    <span class="extrch-share-option-icon"><i class="fab fa-linkedin-in"></i></span>
                    <span class="extrch-share-option-label">LinkedIn</span>
                </a>
                <a href="#" class="extrch-share-option-button extrch-share-option-email" aria-label="Share via Email">
                    <span class="extrch-share-option-icon"><i class="fas fa-envelope"></i></span>
                    <span class="extrch-share-option-label">Email</span>
                </a>
                <!-- Add other social media buttons here if needed, following the same structure -->
            </div>
        </div>
    </div>
</div>
