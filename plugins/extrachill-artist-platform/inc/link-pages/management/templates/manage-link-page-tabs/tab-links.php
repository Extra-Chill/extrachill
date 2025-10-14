<?php
/**
 * Template Part: Links Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

defined( 'ABSPATH' ) || exit;

// Extract arguments passed from ec_render_template
$data = $data ?? array();
$link_sections = $data['link_sections'] ?? [];

// Fetch canonical social links from artist meta
$artist_id = $data['artist_id'] ?? ($data['artist_id'] ?? 0); // Support both artist_id and artist_id
if (!$artist_id && isset($data['artist_profile']) && isset($data['artist_profile']->ID)) {
    $artist_id = $data['artist_profile']->ID;
} elseif (!$artist_id && isset($data['artist_profile']) && isset($data['artist_profile']->ID)) {
    // Backward compatibility
    $artist_id = $data['artist_profile']->ID;
}

// Use centralized social links manager for data loading
$social_manager = extrachill_artist_platform_social_links();
$social_links = !empty($artist_id) ? $social_manager->get($artist_id) : array();

// Get supported social link types from centralized manager
$supported_link_types = $social_manager->get_supported_types();

// Use centralized data for link expiration setting (single source of truth)
$current_link_page_id = isset(
    $link_page_id
) ? $link_page_id : (isset($data['link_page_id']) ? $data['link_page_id'] : 0);
$link_expiration_enabled = $data['settings']['link_expiration_enabled'] ?? false;

// Make $link_expiration_enabled available to JS
if (function_exists('wp_localize_script')) {
    // This assumes your main JS for this page is enqueued with a handle like 'manage-link-page-js'
    // You might need to adjust the handle or use wp_add_inline_script if that's more appropriate
    // For now, let's ensure the variable is available globally as a fallback, though less ideal.
}

// Ensure the expiration modal function is available
require_once dirname(dirname(__DIR__)) . '/advanced-tab/link-expiration.php';
?>
<div class="link-page-content-card">
    <div id="bp-social-icons-section">
        <h2><?php esc_html_e('Social Icons', 'extrachill-artist-platform'); ?></h2>
        <div id="bp-social-icons-list" data-supported-types="<?php echo esc_attr(json_encode($supported_link_types)); ?>">
            <?php foreach ($social_links as $idx => $social): ?>
                <div class="bp-social-row bp-social-item" data-idx="<?php echo esc_attr($idx); ?>">
                    <span class="bp-social-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <select class="bp-social-type-select" name="social_type[<?php echo esc_attr($idx); ?>]">
                        <?php foreach ($supported_link_types as $key => $type): ?>
                            <option value="<?php echo esc_attr($key); ?>"<?php selected($social['type'], $key); ?>><?php echo esc_html($type['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="url" class="bp-social-url-input" name="social_url[<?php echo esc_attr($idx); ?>]" placeholder="Profile URL" value="<?php echo esc_attr($social['url'] ?? ''); ?>">
                    <a href="#" class="bp-remove-social-btn bp-remove-item-link ml-auto" title="Remove Social Icon">&times;</a>
                </div>
            <?php endforeach; ?>
        </div>
        <!-- Social icons now processed via individual form fields -->
        <button type="button" id="bp-add-social-icon-btn" class="button-2 button-medium bp-add-social-icon-btn"><i class="fas fa-plus"></i> <?php esc_html_e('Add Social Icon', 'extrachill-artist-platform'); ?></button>

        <div class="bp-social-icons-position-setting" style="margin-top: 15px;">
            <h4><?php esc_html_e('Social Icons Position', 'extrachill-artist-platform'); ?></h4>
            <?php
            // Use centralized data for social icons position (single source of truth)
            $current_position = $data['settings']['social_icons_position'] ?? 'above';
            ?>
            <label style="margin-right: 10px;">
                <input type="radio" name="link_page_social_icons_position" value="above" <?php checked($current_position, 'above'); ?>>
                <?php esc_html_e('Above Links', 'extrachill-artist-platform'); ?>
            </label>
            <label>
                <input type="radio" name="link_page_social_icons_position" value="below" <?php checked($current_position, 'below'); ?>>
                <?php esc_html_e('Below Links', 'extrachill-artist-platform'); ?>
            </label>
            <input type="hidden" id="initial_social_icons_position" name="initial_social_icons_position" value="<?php echo esc_attr($current_position); ?>">
        </div>
    </div>
</div> 

<div class="link-page-content-card">
    <div id="bp-link-list-section">
        <h2><?php esc_html_e('Link Sections', 'extrachill-artist-platform'); ?></h2>
        <div id="bp-link-sections-list" data-expiration-enabled="<?php echo $link_expiration_enabled ? 'true' : 'false'; ?>" data-link-page-id="<?php echo esc_attr($current_link_page_id); ?>">
            <?php foreach ($link_sections as $sidx => $section): ?>
                <div class="bp-link-section" data-sidx="<?php echo esc_attr($sidx); ?>">
                    <div class="bp-link-section-header">
                        <span class="bp-section-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                        <input type="text" class="bp-link-section-title" name="link_section_title[<?php echo esc_attr($sidx); ?>]" placeholder="Section Title (optional)" value="<?php echo esc_attr($section['section_title'] ?? ''); ?>" data-sidx="<?php echo esc_attr($sidx); ?>">
                        <div class="bp-section-actions-group ml-auto">
                            <a href="#" class="bp-remove-link-section-btn bp-remove-item-link" data-sidx="<?php echo esc_attr($sidx); ?>" title="Remove Section">&times;</a>
                        </div>
                    </div>
                    <div class="bp-link-list">
                        <?php foreach (($section['links'] ?? []) as $lidx => $link): ?>
                            <?php
                            $link_item_classes = 'bp-link-item';
                            ?>
                            <div class="<?php echo esc_attr($link_item_classes); ?>" data-sidx="<?php echo esc_attr($sidx); ?>" data-lidx="<?php echo esc_attr($lidx); ?>" data-expires-at="<?php echo esc_attr($link['expires_at'] ?? ''); ?>" data-link-id="<?php echo esc_attr($link['id'] ?? ''); ?>">
                                <span class="bp-link-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                                <input type="text" class="bp-link-text-input" name="link_text[<?php echo esc_attr($sidx); ?>][<?php echo esc_attr($lidx); ?>]" placeholder="Link Text" value="<?php echo esc_attr($link['link_text'] ?? ''); ?>">
                                <input type="url" class="bp-link-url-input" name="link_url[<?php echo esc_attr($sidx); ?>][<?php echo esc_attr($lidx); ?>]" placeholder="URL" value="<?php echo esc_attr($link['link_url'] ?? ''); ?>">
                                <input type="hidden" name="link_id[<?php echo esc_attr($sidx); ?>][<?php echo esc_attr($lidx); ?>]" value="<?php echo esc_attr($link['id'] ?? ''); ?>">
                                <input type="hidden" name="link_expires_at[<?php echo esc_attr($sidx); ?>][<?php echo esc_attr($lidx); ?>]" value="<?php echo esc_attr($link['expires_at'] ?? ''); ?>">
                                <!-- Expiration icon will be added dynamically via JavaScript when enabled -->
                                <a href="#" class="bp-remove-link-btn bp-remove-item-link ml-auto" title="Remove Link">&times;</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button-2 button-medium bp-add-link-btn" data-sidx="<?php echo esc_attr($sidx); ?>"><i class="fas fa-plus"></i> Add Link</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="bp-add-link-section-btn" class="button-2 button-medium bp-add-link-section-btn"><i class="fas fa-plus"></i> Add Link Section</button>
    </div>
</div>

<!-- Links data now processed via individual form fields -->

<?php
// Templates now rendered via AJAX using ec_render_template() system
?>

<?php
// Output the expiration modal markup (hidden by default)
// ... existing code ...
?>