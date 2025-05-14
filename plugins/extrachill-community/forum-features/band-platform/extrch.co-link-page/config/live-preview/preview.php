<?php
/**
 * Partial: preview.php (live preview partial)
 * Modular, isolated live preview for extrch.co Link Page customization.
 *
 * Expects query_vars:
 * - 'preview_template_data': Array containing all content data (title, bio, links, etc.)
 * - 'initial_container_style_for_php_preview': String for the main container's style attribute (primarily background).
 */

// Get data passed from the parent template (manage-link-page.php or AJAX handler)
$preview_template_data = get_query_var('preview_template_data', null);
$container_style_attr = get_query_var('initial_container_style_for_php_preview', '');

// Set a query var to indicate this is the preview iframe context
set_query_var('is_extrch_preview_iframe', true);

if (!isset($preview_template_data) || !is_array($preview_template_data)) {
    echo '<div class="bp-notice bp-notice-error">Preview data not provided correctly.</div>';
    return;
}

// Extract variables from the main data array for clarity in the template
$display_title   = isset($preview_template_data['display_title']) ? $preview_template_data['display_title'] : '';
$bio             = isset($preview_template_data['bio']) ? $preview_template_data['bio'] : '';
$profile_img_url = isset($preview_template_data['profile_img_url']) ? $preview_template_data['profile_img_url'] : '';
$social_links    = isset($preview_template_data['social_links']) && is_array($preview_template_data['social_links']) ? $preview_template_data['social_links'] : array();
$link_sections   = isset($preview_template_data['link_sections']) && is_array($preview_template_data['link_sections']) ? $preview_template_data['link_sections'] : array();
$powered_by      = isset($preview_template_data['powered_by']) ? (bool)$preview_template_data['powered_by'] : true;

$overlay_enabled = true;
if (isset($preview_template_data['overlay'])) {
    $overlay_enabled = $preview_template_data['overlay'] === '1';
}
$wrapper_class = 'extrch-link-page-content-wrapper' . ($overlay_enabled ? '' : ' no-overlay');

// CSS variables (like text color, button color, font) are now applied via a <style> tag
// in the parent document targeting the iframe's :root. This partial no longer injects them directly.
// The $container_style_attr is for the background (color, image, or gradient).
?>
<div class="extrch-link-page-preview-container extrch-link-page-container" style="<?php echo esc_attr(rtrim($container_style_attr, ';')); ?>; display:flex; flex-direction:column;">
    <div class="<?php echo esc_attr($wrapper_class); ?>" style="flex-grow:1;">
        <?php if (!empty($profile_img_url)): ?>
            <div class="extrch-link-page-profile-img"><img src="<?php echo esc_url($profile_img_url); ?>" alt="<?php echo esc_attr($display_title); ?>"></div>
        <?php endif; ?>
        <h1 class="extrch-link-page-title"><?php echo esc_html($display_title); ?></h1>
        <?php if (!empty($bio)): ?><div class="extrch-link-page-bio"><?php echo wp_kses_post($bio); ?></div><?php endif; ?>
        <?php if (!empty($social_links)): ?>
            <div class="extrch-link-page-socials">
                <?php foreach ($social_links as $icon):
                    if (empty($icon['url'])) continue;
                    // If 'icon' is present, use it; otherwise, build from 'type'
                    $icon_class = !empty($icon['icon']) ? $icon['icon'] : ('fab fa-' . preg_replace('/[^a-z0-9_-]/i', '', strtolower($icon['type'])));
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
                    <a href="<?php echo esc_url($url); ?>" class="extrch-link-page-link" target="_blank" rel="noopener">
                        <?php echo esc_html($text); ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($powered_by): ?>
        <div class="extrch-link-page-powered" style="margin-top:auto; padding-top:1em; padding-bottom:1em;">
            <a href="https://extrch.co/extrachill" target="_blank" rel="noopener">Powered by Extra Chill</a>
        </div>
        <?php endif; ?>
    </div>
</div> 