<?php
/**
 * Template Part: Advanced Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

// All advanced tab data should be hydrated from $data provided by LinkPageDataProvider.

defined( 'ABSPATH' ) || exit;

// Ensure variables from parent scope are available if needed.
// $link_page_id is likely needed to fetch current meta values.
global $post; // The main post object for the page template
$current_link_page_id = isset($link_page_id) ? $link_page_id : ($post ? $post->ID : 0);

// Fetch current values for the settings
$link_expiration_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_link_expiration_enabled', true) === '1') : false;
$weekly_notifications_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_link_page_enable_weekly_notifications', true) === '1') : false;
$redirect_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_link_page_redirect_enabled', true) === '1') : false;
$redirect_target_url = $current_link_page_id ? get_post_meta($current_link_page_id, '_link_page_redirect_target_url', true) : '';

// Fetch current value for YouTube inline embed setting
$is_youtube_embed_actually_enabled = $current_link_page_id ? (get_post_meta($current_link_page_id, '_enable_youtube_inline_embed', true) !== '0') : true; // Default true (feature ON)
$should_disable_checkbox_be_checked = !$is_youtube_embed_actually_enabled;

// Fetch current value for Meta Pixel ID
$meta_pixel_id = $current_link_page_id ? get_post_meta($current_link_page_id, '_link_page_meta_pixel_id', true) : '';

// --- Featured Link Setting ---
$enable_featured_link = $current_link_page_id ? (get_post_meta($current_link_page_id, '_enable_featured_link', true) === '1') : false;
$featured_link_original_url = $current_link_page_id ? get_post_meta($current_link_page_id, '_featured_link_original_id', true) : '';

// Fetch all links for populating dropdowns
$all_links_for_dropdowns = [];
if ($current_link_page_id) {
    $links_json_string = get_post_meta($current_link_page_id, '_link_page_links', true);
    $links_array = is_string($links_json_string) ? json_decode($links_json_string, true) : (is_array($links_json_string) ? $links_json_string : []);

    if (is_array($links_array)) {
        foreach ($links_array as $link_section) {
            if (isset($link_section['links']) && is_array($link_section['links'])) {
                foreach ($link_section['links'] as $link_item) {
                    // Ensure essential data is present, especially link_text and link_url. ID is no longer the primary concern for this dropdown.
                    if (isset($link_item['link_text']) && !empty($link_item['link_url'])) {
                        $all_links_for_dropdowns[] = $link_item;
                    }
                }
            }
        }
    }
}

?>
<div class="link-page-content-card">
    <h2><?php esc_html_e('General Settings', 'generatepress_child'); ?></h2>
    <div class="bp-link-settings-section">

        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600;">
            <input type="checkbox" name="enable_featured_link" id="bp-enable-featured-link" value="1" <?php checked($enable_featured_link); ?> />
            <?php esc_html_e('Enable Featured Link', 'generatepress_child'); ?>
        </label>
        <p class="description" style="margin:0.5em 0 0 1.8em; color:#aaa; font-size:0.97em; margin-bottom: 0.5em;"><?php esc_html_e('Highlight a specific link at the top of your page with a custom thumbnail, title, and description.', 'generatepress_child'); ?></p>
        <div id="bp-featured-link-select-container" style="margin:0.5em 0 1.5em 1.8em; <?php echo $enable_featured_link ? '' : 'display:none;'; ?>">
            <label for="bp-featured-link-original-id" style="display:block; margin-bottom: 0.3em;"><?php esc_html_e('Select Link to Feature:', 'generatepress_child'); ?></label>
            <select name="featured_link_original_id" id="bp-featured-link-original-id" style="min-width: 300px;" data-initial-selected-url="<?php echo esc_attr($featured_link_original_url); ?>">
                <option value=""><?php esc_html_e('-- Select a Link --', 'generatepress_child'); ?></option>
                <?php if (!empty($all_links_for_dropdowns)) : ?>
                    <?php foreach ($all_links_for_dropdowns as $link_item) : ?>
                        <option value="<?php echo esc_attr($link_item['link_url']); ?>" <?php selected($featured_link_original_url, $link_item['link_url']); ?>>
                            <?php echo esc_html(stripslashes($link_item['link_text'])); ?> (<?php echo esc_url($link_item['link_url']); ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php // Options are pre-populated by PHP. JavaScript may update this list if links are changed in the "Links" tab. ?>
            </select>
            <p class="description" style="margin-top: 0.3em; color:#aaa; font-size:0.97em;"><?php esc_html_e('Choose one of your existing links to feature. You can customize its appearance in the \'Customize\' tab.', 'generatepress_child'); ?></p>
        </div>

        <label style="display:flex;align-items:center;gap:0.5em;font-weight:600; margin-top:1.5em;">
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
            <select name="link_page_redirect_target_url" id="bp-temporary-redirect-target" style="min-width: 300px;" data-php-redirect-url="<?php echo esc_attr($redirect_target_url); ?>">
                <option value=""><?php esc_html_e('-- Select a Link --', 'generatepress_child'); ?></option>
                <?php if (!empty($all_links_for_dropdowns)) : ?>
                    <?php foreach ($all_links_for_dropdowns as $link_item) : ?>
                        <option value="<?php echo esc_attr($link_item['link_url']); ?>" <?php selected($redirect_target_url, $link_item['link_url']); ?>>
                            <?php echo esc_html(stripslashes($link_item['link_text'])); ?> (<?php echo esc_url($link_item['link_url']); ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php // Options are pre-populated by PHP. JavaScript may update this list if links are changed in the "Links" tab. ?>
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
    <h2><?php esc_html_e('Subscription Settings', 'generatepress_child'); ?></h2>
    <div class="bp-link-settings-section">
        <?php
        // Fetch current value for the subscribe display mode meta key
        $subscribe_display_mode = $current_link_page_id ? get_post_meta($current_link_page_id, '_link_page_subscribe_display_mode', true) : '';

        // Define available options and their labels
        $subscribe_options = array(
            'icon_modal' => __( 'Show Subscribe Icon (opens modal)', 'generatepress_child' ),
            'inline_form' => __( 'Show Inline Subscribe Form (below links)', 'generatepress_child' ),
            'disabled' => __( 'Disable Subscription Feature', 'generatepress_child' ),
        );

        // Set a default if no value is saved yet (e.g., 'icon_modal' or 'disabled')
        if (empty($subscribe_display_mode)) {
            $subscribe_display_mode = 'icon_modal'; // Default to icon/modal
        }
        ?>
        <p style="margin-bottom: 1em;"><?php esc_html_e('Choose how the email subscription option is displayed on your public link page.', 'generatepress_child'); ?></p>
        <div class="bp-radio-group">
            <?php foreach ($subscribe_options as $value => $label) : ?>
                <label style="display:block; margin-bottom: 0.5em;">
                    <input type="radio" name="link_page_subscribe_display_mode" value="<?php echo esc_attr($value); ?>" <?php checked($subscribe_display_mode, $value); ?> />
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;">
            <?php esc_html_e('This setting controls the appearance of the email subscription feature on your live link page.', 'generatepress_child'); ?>
        </p>
        <?php
        // Fetch current value for the subscribe form description
        $subscribe_description = $current_link_page_id ? get_post_meta($current_link_page_id, '_link_page_subscribe_description', true) : '';
        $subscribe_description_default = __( 'Enter your email address to receive occasional news and updates from this band.', 'generatepress_child' );
        ?>
        <div class="bp-link-setting-item" style="margin-top:1.5em;">
            <label for="link_page_subscribe_description" style="display:block; font-weight:600; margin-bottom:0.3em;">
                <?php esc_html_e('Subscribe Form Description', 'generatepress_child'); ?>
            </label>
            <textarea name="link_page_subscribe_description" id="link_page_subscribe_description" rows="2" class="regular-text" style="width:100%;max-width:500px;min-height:48px;resize:vertical;" placeholder="<?php echo esc_attr($subscribe_description_default); ?>"><?php echo esc_textarea($subscribe_description); ?></textarea>
            <p class="description" style="color:#888; font-size:0.97em; margin-top:0.5em;">
                <?php esc_html_e('This text appears in the subscribe modal or inline form on your public link page.', 'generatepress_child'); ?>
            </p>
        </div>
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