<?php
/**
 * Template Part: Advanced Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

defined( 'ABSPATH' ) || exit;

// Ensure variables from parent scope are available if needed.
// $link_page_id is likely needed to fetch current meta values.
global $post; // The main post object for the page template
$current_link_page_id = isset($link_page_id) ? $link_page_id : 0; // Get from parent scope if set

// Fetch current values for the settings
$link_expiration_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_link_expiration_enabled', true) === '1') : false;
$weekly_notifications_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_link_page_enable_weekly_notifications', true) === '1') : false; // Placeholder meta key
$redirect_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_link_page_redirect_enabled', true) === '1') : false; // Placeholder meta key
$redirect_target_url = $current_link_page_id ? get_post_meta($current_link_page_id, '_link_page_redirect_target_url', true) : ''; // Placeholder meta key

// Fetch current value for YouTube inline embed setting
// _enable_youtube_inline_embed = '1' (feature ON), '0' (feature OFF/disabled)
// Default for the feature is ON. So if meta is not set, or is '1', the feature is ON.
// The checkbox is to "Disable", so it should be checked if the feature is OFF ('0').
$is_youtube_embed_actually_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_enable_youtube_inline_embed', true) !== '0') : true; // Default true (feature ON)
$should_disable_checkbox_be_checked = !$is_youtube_embed_actually_enabled; // Checked if feature is OFF

// Fetch current value for Meta Pixel ID
$meta_pixel_id = $current_link_page_id ? get_post_meta($current_link_page_id, '_link_page_meta_pixel_id', true) : '';

?>
<div class="link-page-content-card">
    <h2><?php esc_html_e('General Settings', 'generatepress_child'); ?></h2>
    <div class="bp-link-settings-section">
        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600;">
            <input type="checkbox" name="link_expiration_enabled_advanced" id="bp-enable-link-expiration-advanced" value="1" <?php checked($link_expiration_enabled); ?> />
            <?php esc_html_e('Enable Link Expiration Dates', 'generatepress_child'); ?>
        </label>
        <p class="description" style="margin:0.5em 0 1.5em 1.8em; color:#888; font-size:0.97em;"><?php esc_html_e('When enabled, you can set expiration dates for individual links in the "Links" tab. Expired links will be deleted automatically.', 'generatepress_child'); ?></p>

        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600;">
            <input type="checkbox" name="link_page_enable_weekly_notifications" id="bp-enable-weekly-notifications" value="1" <?php checked($weekly_notifications_enabled); ?> />
            <?php esc_html_e('Enable Weekly Performance Email', 'generatepress_child'); ?>
        </label>
        <p class="description" style="margin:0.5em 0 1.5em 1.8em; color:#aaa; font-size:0.97em;"><?php esc_html_e('Receive a weekly summary of your link page performance via email.', 'generatepress_child'); ?></p>

        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600;">
            <input type="checkbox" name="link_page_redirect_enabled" id="bp-enable-temporary-redirect" value="1" <?php checked($redirect_enabled); ?> />
            <?php esc_html_e('Enable Temporary Redirect', 'generatepress_child'); ?>
        </label>
        <p class="description" style="margin:0.5em 0 0 1.8em; color:#aaa; font-size:0.97em;"><?php esc_html_e('Redirect visitors from your main extrachill.link URL to a specific link temporarily.', 'generatepress_child'); ?></p>
        <div id="bp-temporary-redirect-target-container" style="margin:0.5em 0 1.5em 1.8em; <?php echo $redirect_enabled ? '' : 'display:none;'; ?>">
            <label for="bp-temporary-redirect-target" style="display:block; margin-bottom: 0.3em;"><?php esc_html_e('Redirect To:', 'generatepress_child'); ?></label>
            <select name="link_page_redirect_target_url" id="bp-temporary-redirect-target" style="min-width: 300px;">
                <option value=""><?php esc_html_e('-- Select a Link --', 'generatepress_child'); ?></option>
                <?php
                // This dropdown will be populated by JavaScript using window.bpLinkPageLinks
                // The currently saved $redirect_target_url will be used by JS to select the correct option.
                ?>
            </select>
            <p class="description" style="margin-top: 0.3em; color:#aaa; font-size:0.97em;"><?php esc_html_e('Select one of your existing links to redirect visitors to.', 'generatepress_child'); ?></p>
        </div>

        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600; margin-top: 1.5em;">
            <input type="checkbox" name="disable_youtube_inline_embed" id="bp-disable-youtube-inline-embed" value="1" <?php checked($should_disable_checkbox_be_checked); ?> />
            <?php esc_html_e('Disable Inline YouTube Video Player', 'generatepress_child'); ?>
        </label>
        <p class="description" style="margin:0.5em 0 1.5em 1.8em; color:#aaa; font-size:0.97em;"><?php esc_html_e('By default, YouTube links play directly on the page. Check this box if you prefer YouTube links to navigate to YouTube.com instead.', 'generatepress_child'); ?></p>
        
            <?php
        // Add other advanced settings here as needed
        ?>
    </div>
</div>

<div class="link-page-content-card">
    <h2 style="margin-bottom: 0.8em;"><?php esc_html_e('Tracking Pixels', 'generatepress_child'); ?></h2>
    <div class="bp-link-settings-section">
        <div class="bp-link-setting-item" style="margin-bottom: 1.5em;">
            <label for="link_page_meta_pixel_id" style="display:block; font-weight:600; margin-bottom: 0.3em;"><?php esc_html_e('Meta Pixel ID', 'generatepress_child'); ?></label>
            <input type="text" name="link_page_meta_pixel_id" id="link_page_meta_pixel_id" value="<?php echo esc_attr($meta_pixel_id); ?>" class="regular-text" placeholder="e.g., 123456789012345" />
            <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;"><?php esc_html_e('Enter your Meta (Facebook) Pixel ID to track page views and events.', 'generatepress_child'); ?></p>
        </div>

        <?php
        // Fetch current value for Google Tag ID
        $google_tag_id = $current_link_page_id ? get_post_meta($current_link_page_id, '_link_page_google_tag_id', true) : '';
        ?>
        <div class="bp-link-setting-item" style="margin-bottom: 1.5em;">
            <label for="link_page_google_tag_id" style="display:block; font-weight:600; margin-bottom: 0.3em;"><?php esc_html_e('Google Tag ID (GA4 / Ads)', 'generatepress_child'); ?></label>
            <input type="text" name="link_page_google_tag_id" id="link_page_google_tag_id" value="<?php echo esc_attr($google_tag_id); ?>" class="regular-text" placeholder="e.g., G-XXXXXXXXXX or AW-XXXXXXXXXX" />
            <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;"><?php esc_html_e('Enter your Google Tag ID for Google Analytics 4 or Google Ads. This enables tracking page views, events, and allows for targeted advertising campaigns.', 'generatepress_child'); ?></p>
        </div>

        <?php // Placeholder for Google Tag ID field in the future ?>
        <!--
        <div class="bp-link-setting-item" style="margin-bottom: 1.5em;">
            <label for="link_page_google_tag_id" style="display:block; font-weight:600; margin-bottom: 0.3em;"><?php esc_html_e('Google Tag ID (GA4 / Ads)', 'generatepress_child'); ?></label>
            <input type="text" name="link_page_google_tag_id" id="link_page_google_tag_id" value="" class="regular-text" placeholder="e.g., G-XXXXXXXXXX or AW-XXXXXXXXXX" />
            <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;"><?php esc_html_e('Enter your Google Tag ID for Google Analytics 4 or Google Ads.', 'generatepress_child'); ?></p>
        </div>
        -->
    </div>
</div>