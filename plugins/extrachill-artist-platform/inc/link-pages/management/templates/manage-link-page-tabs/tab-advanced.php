<?php
/**
 * Template Part: Advanced Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

defined( 'ABSPATH' ) || exit;

// Use the data passed from the parent template
$settings = isset($data['settings']) ? $data['settings'] : array();

// Extract settings with defaults (centralized system returns proper booleans)
$link_expiration_enabled = $settings['link_expiration_enabled'] ?? false;
$redirect_enabled = $settings['redirect_enabled'] ?? false;
$redirect_target_url = $settings['redirect_target_url'] ?? '';
$is_youtube_embed_actually_enabled = $settings['youtube_embed_enabled'] ?? true;
$should_disable_checkbox_be_checked = !$is_youtube_embed_actually_enabled;
$meta_pixel_id = $settings['meta_pixel_id'] ?? '';

?>
<div class="link-page-content-card">
    <h2><?php esc_html_e('General Settings', 'extrachill-artist-platform'); ?></h2>
    <div class="bp-link-settings-section">

        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600;">
            <input type="checkbox" name="link_expiration_enabled_advanced" id="bp-enable-link-expiration-advanced" value="1" <?php checked($link_expiration_enabled); ?> />
            <?php esc_html_e('Enable Link Expiration Dates', 'extrachill-artist-platform'); ?>
        </label>
        <p class="description" style="margin:0.5em 0 1.5em 1.8em; color:#888; font-size:0.97em;"><?php esc_html_e('When enabled, you can set expiration dates for individual links in the "Links" tab. Expired links will be deleted automatically.', 'extrachill-artist-platform'); ?></p>

        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600;">
            <input type="checkbox" name="link_page_redirect_enabled" id="bp-enable-temporary-redirect" value="1" <?php checked($redirect_enabled); ?> />
            <?php esc_html_e('Enable Temporary Redirect', 'extrachill-artist-platform'); ?>
        </label>
        <p class="description" style="margin:0.5em 0 0 1.8em; color:#aaa; font-size:0.97em;"><?php esc_html_e('Redirect visitors from your main extrachill.link URL to a specific link temporarily.', 'extrachill-artist-platform'); ?></p>
        <div id="bp-temporary-redirect-target-container" style="margin:0.5em 0 1.5em 1.8em; <?php echo $redirect_enabled ? '' : 'display:none;'; ?>">
            <label for="bp-temporary-redirect-target" style="display:block; margin-bottom: 0.3em;"><?php esc_html_e('Redirect To:', 'extrachill-artist-platform'); ?></label>
            <select name="link_page_redirect_target_url" id="bp-temporary-redirect-target" style="min-width: 300px;" data-php-redirect-url="<?php echo esc_attr($redirect_target_url); ?>">
                <option value=""><?php esc_html_e('-- Select a Link --', 'extrachill-artist-platform'); ?></option>
                <?php if (!empty($all_links_for_dropdowns)) : ?>
                    <?php foreach ($all_links_for_dropdowns as $link_item) : ?>
                        <?php 
                        $link_url = isset($link_item['link_url']) ? $link_item['link_url'] : '';
                        $link_text = isset($link_item['link_text']) ? $link_item['link_text'] : '';
                        if (!empty($link_url) && !empty($link_text)) :
                        ?>
                        <option value="<?php echo esc_attr($link_url); ?>" <?php selected($redirect_target_url, $link_url); ?>>
                            <?php echo esc_html(stripslashes($link_text)); ?> (<?php echo esc_url($link_url); ?>)
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php // Options are pre-populated by PHP. JavaScript may update this list if links are changed in the "Links" tab. ?>
            </select>
            <p class="description" style="margin-top: 0.3em; color:#aaa; font-size:0.97em;"><?php esc_html_e('Select one of your existing links to redirect visitors to.', 'extrachill-artist-platform'); ?></p>
        </div>

        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600; margin-top: 1.5em;">
            <input type="checkbox" name="disable_youtube_inline_embed" id="bp-disable-youtube-inline-embed" value="1" <?php checked($should_disable_checkbox_be_checked); ?> />
            <?php esc_html_e('Disable Inline YouTube Video Player', 'extrachill-artist-platform'); ?>
        </label>
        <p class="description" style="margin:0.5em 0 1.5em 1.8em; color:#aaa; font-size:0.97em;"><?php esc_html_e('By default, YouTube links play directly on the page. Check this box if you prefer YouTube links to navigate to YouTube.com instead.', 'extrachill-artist-platform'); ?></p>
        
        <?php
        // Add other advanced settings here as needed
        ?>
    </div>
</div>

<div class="link-page-content-card">
    <h2><?php esc_html_e('Subscription Settings', 'extrachill-artist-platform'); ?></h2>
    <div class="bp-link-settings-section">
        <?php
        // Get current value from centralized data
        $subscribe_display_mode = $settings['subscribe_display_mode'] ?? '';

        // Define available options and their labels
        $subscribe_options = array(
            'icon_modal' => __( 'Show Subscribe Icon (opens modal)', 'extrachill-artist-platform' ),
            'inline_form' => __( 'Show Inline Subscribe Form (below links)', 'extrachill-artist-platform' ),
            'disabled' => __( 'Disable Subscription Feature', 'extrachill-artist-platform' ),
        );

        // Set a default if no value is saved yet (e.g., 'icon_modal' or 'disabled')
        if (empty($subscribe_display_mode)) {
            $subscribe_display_mode = 'icon_modal'; // Default to icon/modal
        }
        ?>
        <p style="margin-bottom: 1em;"><?php esc_html_e('Choose how the email subscription option is displayed on your public link page.', 'extrachill-artist-platform'); ?></p>
        <div class="bp-radio-group">
            <?php foreach ($subscribe_options as $value => $label) : ?>
                <label style="display:block; margin-bottom: 0.5em;">
                    <input type="radio" name="link_page_subscribe_display_mode" value="<?php echo esc_attr($value); ?>" <?php checked($subscribe_display_mode, $value); ?> />
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;">
            <?php esc_html_e('This setting controls the appearance of the email subscription feature on your live link page.', 'extrachill-artist-platform'); ?>
        </p>
        <?php
        // Get current value from centralized data
        $subscribe_description = $settings['subscribe_description'] ?? '';
        $artist_name = isset($data['display_title']) && $data['display_title'] ? $data['display_title'] : __('this artist', 'extrachill-artist-platform');
        $subscribe_description_default = sprintf( __( 'Enter your email address to receive occasional news and updates from %s.', 'extrachill-artist-platform' ), $artist_name );
        $subscribe_description_to_show = $subscribe_description !== '' ? $subscribe_description : $subscribe_description_default;
        ?>
        <div class="bp-link-setting-item" style="margin-top:1.5em;">
            <label for="link_page_subscribe_description" style="display:block; font-weight:600; margin-bottom:0.3em;">
                <?php esc_html_e('Subscribe Form Description', 'extrachill-artist-platform'); ?>
            </label>
            <textarea name="link_page_subscribe_description" id="link_page_subscribe_description" rows="2" class="regular-text" style="width:100%;max-width:500px;min-height:80px;resize:vertical;"><?php echo esc_textarea($subscribe_description_to_show); ?></textarea>
            <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;">
                <?php esc_html_e('This text appears in the subscribe modal or inline form on your public link page.', 'extrachill-artist-platform'); ?>
            </p>
        </div>
    </div>
</div>

<div class="link-page-content-card">
    <h2 style="margin-bottom: 0.8em;"><?php esc_html_e('Tracking Pixels', 'extrachill-artist-platform'); ?></h2>
    <div class="bp-link-settings-section">
        <div class="bp-link-setting-item" style="margin-bottom: 1.5em;">
            <label for="link_page_meta_pixel_id" style="display:block; font-weight:600; margin-bottom: 0.3em;"><?php esc_html_e('Meta Pixel ID', 'extrachill-artist-platform'); ?></label>
            <input type="text" name="link_page_meta_pixel_id" id="link_page_meta_pixel_id" value="<?php echo esc_attr($meta_pixel_id); ?>" class="regular-text" placeholder="e.g., 123456789012345" />
            <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;"><?php esc_html_e('Enter your Meta (Facebook) Pixel ID to track page views and events.', 'extrachill-artist-platform'); ?></p>
        </div>

        <?php
        // Get current value from centralized data
        $google_tag_id = $settings['google_tag_id'] ?? '';
        ?>
        <div class="bp-link-setting-item" style="margin-bottom: 1.5em;">
            <label for="link_page_google_tag_id" style="display:block; font-weight:600; margin-bottom: 0.3em;"><?php esc_html_e('Google Tag ID (GA4 / Ads)', 'extrachill-artist-platform'); ?></label>
            <input type="text" name="link_page_google_tag_id" id="link_page_google_tag_id" value="<?php echo esc_attr($google_tag_id); ?>" class="regular-text" placeholder="e.g., G-XXXXXXXXXX or AW-XXXXXXXXXX" />
            <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;"><?php esc_html_e('Enter your Google Tag ID for Google Analytics 4 or Google Ads. This enables tracking page views, events, and allows for targeted advertising campaigns.', 'extrachill-artist-platform'); ?></p>
        </div>
    </div>
</div>