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

    // Ensure LivePreviewManager class is available.
    // It should be included by link-page-includes.php or the AJAX handler.
    if ( ! class_exists( 'LivePreviewManager' ) ) {
        $live_preview_manager_path = dirname( __FILE__ ) . '/config/live-preview/LivePreviewManager.php';
        if ( file_exists( $live_preview_manager_path ) ) {
            require_once $live_preview_manager_path;
        } else {
            // Fallback or error if LivePreviewManager is critical and not found
            // For now, we'll let it potentially fail if not loaded by the caller.
        }
    }

    if ( class_exists( 'LivePreviewManager' ) && isset($link_page_id) && isset($band_id) ) {
        // When this template is included directly (e.g., by single-band_link_page.php or initial preview render),
        // there are no $preview_data_overrides.
        $data = LivePreviewManager::get_preview_data( $link_page_id, $band_id, array() );
    } else {
        // Fallback if LivePreviewManager isn't available or IDs are missing.
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
// especially if LivePreviewManager::get_preview_data() might not return them all in some edge case.
$data['powered_by'] = isset($data['powered_by']) ? $data['powered_by'] : true;
$data['display_title'] = isset($data['display_title']) ? $data['display_title'] : '';
$data['bio'] = isset($data['bio']) ? $data['bio'] : '';
$data['profile_img_url'] = isset($data['profile_img_url']) ? $data['profile_img_url'] : '';
$data['social_links'] = isset($data['social_links']) && is_array($data['social_links']) ? $data['social_links'] : [];

// LivePreviewManager::get_preview_data() now returns 'link_sections' directly.
$link_sections = isset($data['link_sections']) && is_array($data['link_sections']) ? $data['link_sections'] : [];

// Determine the inline style for the container.
// For the preview iframe, it receives 'initial_container_style_for_php_preview' via query_var.
// For the public page, the container's background is now primarily controlled by CSS
// (e.g., transparent to show body background, or a card color via CSS var).
// We only apply the direct $data['background_style'] here if it's the preview iframe.

$initial_container_style_attr = '';
$container_classes = 'extrch-link-page-container'; // Base class
$is_preview_iframe_context = (bool) get_query_var('is_extrch_preview_iframe', false); // This query var should be set in config/live-preview/preview.php

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

?>
<div class="<?php echo esc_attr($container_classes); ?>"<?php echo $initial_container_style_attr; ?>>
    <div class="<?php echo esc_attr($wrapper_class); ?>" style="flex-grow:1;">
        <div class="extrch-link-page-header-content">
            <?php 
            // Always output the profile image container and <img> in preview context for robust JS updates
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
            
            <button class="extrch-share-trigger extrch-share-page-trigger" aria-label="Share this page" data-share-type="page" data-share-url="<?php echo esc_url($share_page_url); ?>" data-share-title="<?php echo esc_attr($data['display_title']); ?>">
                <i class="fas fa-ellipsis-h"></i>
            </button>
        </div>

        <?php if (!empty($data['social_links']) && is_array($data['social_links'])): ?>
            <div class="extrch-link-page-socials">
                <?php 
                // Ensure the social types config file is included
                if ( ! function_exists( 'bp_get_supported_social_link_types' ) ) {
                     $social_types_path = dirname( __FILE__ ) . '/config/link-page-social-types.php';
                     if ( file_exists( $social_types_path ) ) {
                         require_once $social_types_path;
                     }
                 }
                
                 $supported_social_types = function_exists( 'bp_get_supported_social_link_types' ) ? bp_get_supported_social_link_types() : array();

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
        <?php endif; ?>
        <?php foreach ($link_sections as $section_key => $section): ?>
            <?php if (!empty($section['section_title'])): ?>
                <div class="extrch-link-page-section-title"><?php echo esc_html($section['section_title']); ?></div>
            <?php endif; ?>
            <div class="extrch-link-page-links">
                <?php if (!empty($section['links']) && is_array($section['links'])):
                    foreach ($section['links'] as $link_key => $link):
                        if (empty($link['link_url']) || empty($link['link_text'])) continue;
                        $url = $link['link_url'];
                        $text = $link['link_text'];
                        $is_active = isset($link['link_is_active']) ? (bool)$link['link_is_active'] : true;
                        if (!$is_active) continue;
                        $link_url_attr = esc_url($url);
                        $link_title_attr = esc_attr($text);
                ?>
                    <a href="<?php echo $link_url_attr; ?>" class="extrch-link-page-link" rel="noopener">
                        <span class="extrch-link-page-link-text"><?php echo esc_html($text); ?></span>
                        <span class="extrch-link-page-link-icon">
                            <button class="extrch-share-trigger extrch-share-item-trigger" 
                                    aria-label="Share this link" 
                                    data-share-type="link"
                                    data-share-url="<?php echo $link_url_attr; ?>" 
                                    data-share-title="<?php echo $link_title_attr; ?>">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        <?php endforeach; ?>
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
<?php
// Ensure all variables used in data attributes are defined in the scope
// For the page share button:
// $link_page_id_for_permalink = isset($link_page_id) ? $link_page_id : (isset($post) ? $post->ID : 0);
// For social links, we already have $data['social_links']
// For link sections, we already have $link_sections and iterate through $section['links'] as $link
?> 