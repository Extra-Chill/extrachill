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
$is_preview_iframe_context = (bool) get_query_var('is_extrch_preview_iframe', false); // This query var should be set in config/live-preview/preview.php

if ( $is_preview_iframe_context ) {
    // For the preview iframe in manage-link-page.php
    $preview_specific_style = get_query_var('initial_container_style_for_php_preview', '');
    if ( !empty($preview_specific_style) ) {
        $initial_container_style_attr = ' style="' . esc_attr($preview_specific_style) . '"';
    }
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
if ( isset( $data['profile_img_shape'] ) && $data['profile_img_shape'] === 'circle' ) {
    $profile_img_shape_class = 'shape-circle';
}
// Ensure that if the value is 'rectangle' (old system), it still maps to 'shape-square'
// The LivePreviewManager already handles converting 'rectangle' to 'square' when fetching the data,
// so $data['profile_img_shape'] should ideally only be 'circle' or 'square' here.
// However, an explicit check for robustness doesn't hurt.
if ( isset( $data['profile_img_shape'] ) && $data['profile_img_shape'] === 'rectangle' ) {
    $profile_img_shape_class = 'shape-square';
}

$overlay_enabled = true;
if (isset($data['overlay'])) {
    $overlay_enabled = $data['overlay'] === '1';
}
$wrapper_class = 'extrch-link-page-content-wrapper' . ($overlay_enabled ? '' : ' no-overlay');

?>
<div class="extrch-link-page-container"<?php echo $initial_container_style_attr; // This will typically only have a style for the preview iframe ?>>
    <div class="<?php echo esc_attr($wrapper_class); ?>">
        <?php if (!empty($data['profile_img_url'])):
            // Add the dynamic shape class to the profile image container
            $img_container_classes = "extrch-link-page-profile-img " . $profile_img_shape_class;
        ?>
            <div class="<?php echo esc_attr($img_container_classes); ?>"><img src="<?php echo esc_url($data['profile_img_url']); ?>" alt="<?php echo esc_attr($data['display_title']); ?>"></div>
        <?php endif; ?>
        <h1 class="extrch-link-page-title"><?php echo esc_html($data['display_title']); ?></h1>
        <?php if (!empty($data['bio'])): ?><div class="extrch-link-page-bio"><?php echo esc_html($data['bio']); ?></div><?php endif; ?>
        <?php if (!empty($data['social_links']) && is_array($data['social_links'])): ?>
            <div class="extrch-link-page-socials">
                <?php foreach ($data['social_links'] as $icon):
                    if (empty($icon['url'])) continue;
                    // If 'icon' is present, use it; otherwise, build from 'type'
                    $icon_class = !empty($icon['icon']) ? $icon['icon'] : ('fab fa-' . preg_replace('/[^a-z0-9_-]/', '', strtolower($icon['type'])));
                    $icon_class = esc_attr($icon_class);
                    $url = esc_url($icon['url']);
                ?>
                    <a href="<?php echo $url; ?>" class="extrch-social-icon" target="_blank" rel="noopener">
                        <i class="<?php echo $icon_class; ?>" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php foreach ($link_sections as $section): ?>
            <?php if (!empty($section['section_title'])): ?>
                <div class="extrch-link-page-section-title"><?php echo esc_html($section['section_title']); ?></div>
            <?php endif; ?>
            <div class="extrch-link-page-links">
                <?php if (!empty($section['links']) && is_array($section['links'])):
                    foreach ($section['links'] as $link):
                        if (empty($link['link_url']) || empty($link['link_text'])) continue;
                        $url = esc_url($link['link_url']);
                        $text = esc_html($link['link_text']);
                        $is_active = isset($link['link_is_active']) ? (bool)$link['link_is_active'] : true;
                        if (!$is_active) continue;
                ?>
                    <a href="<?php echo $url; ?>" class="extrch-link-page-link" target="_blank" rel="noopener">
                        <?php echo $text; ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($data['powered_by']): ?>
        <div class="extrch-link-page-powered">
            <a href="https://extrch.co/extrachill" target="_blank" rel="noopener">Powered by Extra Chill</a>
        </div>
        <?php endif; ?>
    </div>
</div> 