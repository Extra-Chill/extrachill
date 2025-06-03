<?php
/**
 * Template Part: Links Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

// All links tab data should be hydrated from $data provided by LinkPageDataProvider.

defined( 'ABSPATH' ) || exit;

// Ensure variables from parent scope are available if needed.
// For this tab, most content is JS-rendered, but PHP comments/structure are preserved.

// Use get_query_var('data') to access the canonical $data array
$data = get_query_var('data', []);
$link_sections = $data['link_sections'] ?? [];

// Fetch canonical social links from band meta
$band_id = $data['band_id'] ?? 0;
if (!$band_id && isset($data['band_profile']) && isset($data['band_profile']->ID)) {
    $band_id = $data['band_profile']->ID;
}
$social_links = $band_id ? get_post_meta($band_id, '_band_profile_social_links', true) : [];
if (!is_array($social_links)) $social_links = [];

// --- DEBUG: Output social links data on page load ---
error_log('[LinkPageLinksTab PHP] Social links fetched from meta (\'_band_profile_social_links\') for band ID ' . $band_id . ': ' . print_r($social_links, true));
// --- END DEBUG ---

// Fetch supported social link types
require_once dirname(__DIR__) . '/link-page-social-types.php';
$supported_link_types = function_exists('bp_get_supported_social_link_types') ? bp_get_supported_social_link_types() : [];

// Fetch link expiration enabled setting for this link page
$current_link_page_id = isset(
    $link_page_id
) ? $link_page_id : (isset($data['link_page_id']) ? $data['link_page_id'] : 0);
$link_expiration_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_link_expiration_enabled', true) === '1') : false;

// Make $link_expiration_enabled available to JS
if (function_exists('wp_localize_script')) {
    // This assumes your main JS for this page is enqueued with a handle like 'manage-link-page-js'
    // You might need to adjust the handle or use wp_add_inline_script if that's more appropriate
    // For now, let's ensure the variable is available globally as a fallback, though less ideal.
}

// Ensure the expiration modal function is available
require_once dirname(__DIR__) . '/link-expiration.php';
?>
<div class="link-page-content-card">
    <div id="bp-social-icons-section">
        <h2><?php esc_html_e('Social Icons', 'generatepress_child'); ?></h2>
        <div id="bp-social-icons-list">
            <?php foreach ($social_links as $idx => $social): ?>
                <div class="bp-social-row" data-idx="<?php echo esc_attr($idx); ?>">
                    <span class="bp-social-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <select class="bp-social-type-select">
                        <?php foreach ($supported_link_types as $key => $type): ?>
                            <option value="<?php echo esc_attr($key); ?>"<?php selected($social['type'], $key); ?>><?php echo esc_html($type['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="url" class="bp-social-url-input" placeholder="Profile URL" value="<?php echo esc_attr($social['url'] ?? ''); ?>">
                    <a href="#" class="bp-remove-social-btn bp-remove-item-link ml-auto" title="Remove Social Icon">&times;</a>
                </div>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="band_profile_social_links_json" id="band_profile_social_links_json" value="<?php echo esc_attr(json_encode($social_links)); ?>">
        <button type="button" id="bp-add-social-icon-btn" class="button button-secondary bp-add-social-icon-btn"><i class="fas fa-plus"></i> <?php esc_html_e('Add Social Icon', 'generatepress_child'); ?></button>

        <div class="bp-social-icons-position-setting" style="margin-top: 15px;">
            <h4><?php esc_html_e('Social Icons Position', 'generatepress_child'); ?></h4>
            <?php
            $current_position = 'above'; // Default
            if ($current_link_page_id) {
                $saved_position = get_post_meta($current_link_page_id, '_link_page_social_icons_position', true);
                if (!empty($saved_position)) {
                    $current_position = $saved_position;
                }
            }
            ?>
            <label style="margin-right: 10px;">
                <input type="radio" name="link_page_social_icons_position" value="above" <?php checked($current_position, 'above'); ?>>
                <?php esc_html_e('Above Links', 'generatepress_child'); ?>
            </label>
            <label>
                <input type="radio" name="link_page_social_icons_position" value="below" <?php checked($current_position, 'below'); ?>>
                <?php esc_html_e('Below Links', 'generatepress_child'); ?>
            </label>
            <input type="hidden" id="initial_social_icons_position" name="initial_social_icons_position" value="<?php echo esc_attr($current_position); ?>">
        </div>
    </div>
</div> 

<div class="link-page-content-card">
    <div id="bp-link-list-section">
        <h2><?php esc_html_e('Link Sections', 'generatepress_child'); ?></h2>
        <div id="bp-link-sections-list" data-expiration-enabled="<?php echo $link_expiration_enabled ? 'true' : 'false'; ?>">
            <?php foreach ($link_sections as $sidx => $section): ?>
                <div class="bp-link-section" data-sidx="<?php echo esc_attr($sidx); ?>">
                    <div class="bp-link-section-header">
                        <span class="bp-section-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                        <input type="text" class="bp-link-section-title" placeholder="Section Title (optional)" value="<?php echo esc_attr($section['section_title'] ?? ''); ?>" data-sidx="<?php echo esc_attr($sidx); ?>">
                        <div class="bp-section-actions-group ml-auto">
                            <a href="#" class="bp-remove-link-section-btn bp-remove-item-link" data-sidx="<?php echo esc_attr($sidx); ?>" title="Remove Section">&times;</a>
                        </div>
                    </div>
                    <div class="bp-link-list">
                        <?php foreach (($section['links'] ?? []) as $lidx => $link): ?>
                            <?php
                            // Determine if this link is the featured link (to be highlighted)
                            $is_featured_link = false;
                            $debug_link_url = !empty($link['link_url']) ? $link['link_url'] : '';
                            $debug_featured_url = !empty($data['featuredLinkUrlToSkip']) ? $data['featuredLinkUrlToSkip'] : '';
                            if (!empty($debug_featured_url) && !empty($debug_link_url)) {
                                $is_featured_link = (trailingslashit($debug_link_url) === trailingslashit($debug_featured_url));
                                error_log('[tab-links.php] Comparing link: ' . $debug_link_url . ' to featured: ' . $debug_featured_url . ' | Result: ' . ($is_featured_link ? 'MATCH' : 'NO MATCH'));
                            } else {
                                error_log('[tab-links.php] Skipping compare: link_url=' . $debug_link_url . ' featuredLinkUrlToSkip=' . $debug_featured_url);
                            }
                            $link_item_classes = 'bp-link-item';
                            if ($is_featured_link) {
                                $link_item_classes .= ' bp-editor-featured-link';
                            }
                            error_log('[tab-links.php] link_item_classes for link: ' . $debug_link_url . ' = ' . $link_item_classes);
                            ?>
                            <div class="<?php echo esc_attr($link_item_classes); ?>" data-sidx="<?php echo esc_attr($sidx); ?>" data-lidx="<?php echo esc_attr($lidx); ?>" data-expires-at="<?php echo esc_attr($link['expires_at'] ?? ''); ?>" data-link-id="<?php echo esc_attr($link['id'] ?? ''); ?>">
                                <span class="bp-link-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                                <input type="text" class="bp-link-text-input" placeholder="Link Text" value="<?php echo esc_attr($link['link_text'] ?? ''); ?>">
                                <input type="url" class="bp-link-url-input" placeholder="URL" value="<?php echo esc_attr($link['link_url'] ?? ''); ?>">
                                <?php if ($link_expiration_enabled): ?>
                                    <span class="bp-link-expiration-icon" title="Set expiration date" data-sidx="<?php echo esc_attr($sidx); ?>" data-lidx="<?php echo esc_attr($lidx); ?>">&#x23F3;</span>
                                <?php endif; ?>
                                <a href="#" class="bp-remove-link-btn bp-remove-item-link ml-auto" title="Remove Link">&times;</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary bp-add-link-btn" data-sidx="<?php echo esc_attr($sidx); ?>"><i class="fas fa-plus"></i> Add Link</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="bp-add-link-section-btn" class="button button-secondary bp-add-link-section-btn"><i class="fas fa-plus"></i> Add Link Section</button>
    </div>
</div>

<!-- Add hidden input for link_sections_json -->
<input type="hidden" name="link_page_links_json" id="link_page_links_json" value="<?php echo esc_attr(json_encode($link_sections)); ?>">

<?php
// Add a global JS variable for link expiration status (can be refined later if a centralized config object is preferred)
// This helps JS know whether to render expiration icons for dynamically added links
?>
<script type="text/javascript">
    // Make supported link types available to JavaScript
    window.extrchLinkPageConfig = window.extrchLinkPageConfig || {};
    window.extrchLinkPageConfig.linkExpirationEnabled = <?php echo $link_expiration_enabled ? 'true' : 'false'; ?>;
    window.extrchLinkPageConfig.supportedLinkTypes = <?php echo json_encode($data['supportedLinkTypes'] ?? []); ?>;
    // The featured_link_nonce is now localized globally in link-page-includes.php
    // Ensuring other nonces are initialized if not already present by the global localization.
    window.extrchLinkPageConfig.nonces = window.extrchLinkPageConfig.nonces || {}; 
</script>

<?php
// Output the expiration modal markup (hidden by default)
// ... existing code ...
?>