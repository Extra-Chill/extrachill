<?php
/**
 * User Links - Dynamic Social/Music Links System
 *
 * Handles dynamic user profile links with unlimited add/remove/reorder capability.
 * Replaces static individual meta keys with unified array storage.
 * Template rendering function used by bbPress profile edit template via extrachill_render_user_links_field().
 *
 * @package ExtraChillCommunity
 */

/**
 * Save user profile dynamic links
 * Processes user_links array from profile edit form
 */
function extrachill_save_user_profile_links($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Verify nonce for security
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update-user_' . $user_id)) {
        return false;
    }

    // Process dynamic links array
    if (isset($_POST['user_links']) && is_array($_POST['user_links'])) {
        $sanitized_links = array();

        foreach ($_POST['user_links'] as $link) {
            if (empty($link['url'])) {
                continue; // Skip empty URLs
            }

            $sanitized_link = array(
                'type_key' => sanitize_text_field(wp_unslash($link['type_key'])),
                'url' => esc_url_raw(wp_unslash($link['url']))
            );

            // Add custom label if provided
            if (!empty($link['custom_label'])) {
                $sanitized_link['custom_label'] = sanitize_text_field(wp_unslash($link['custom_label']));
            }

            $sanitized_links[] = $sanitized_link;
        }

        update_user_meta($user_id, '_user_profile_dynamic_links', $sanitized_links);
    } else {
        // No links submitted, clear the meta
        delete_user_meta($user_id, '_user_profile_dynamic_links');
    }
}
add_action('personal_options_update', 'extrachill_save_user_profile_links');
add_action('edit_user_profile_update', 'extrachill_save_user_profile_links');

/**
 * Render user links form field
 * Template helper for profile edit pages
 */
function extrachill_render_user_links_field() {
    $user_id = bbp_get_displayed_user_id();
    $dynamic_links = get_user_meta($user_id, '_user_profile_dynamic_links', true);

    if (!is_array($dynamic_links)) {
        $dynamic_links = array();
    }
    ?>
    <div id="user-dynamic-links-container" data-nonce="<?php echo esc_attr(wp_create_nonce('user_dynamic_link_nonce')); ?>" data-sprite-url="<?php echo esc_url(get_template_directory_uri() . '/assets/fonts/extrachill.svg'); ?>">
        <p class="description"><?php esc_html_e('Add links to your website, social media, streaming, etc.', 'bbpress'); ?></p>
        <div id="user-links-list"></div>
        <button type="button" id="user-add-link-button" class="button button-2"><?php echo ec_icon('plus'); ?> <?php esc_html_e('Add Link', 'bbpress'); ?></button>
    </div>
    <?php
}

/**
 * Enqueue user links management assets
 */
function extrachill_enqueue_user_links_assets() {
    if (!bbp_is_single_user_edit()) {
        return;
    }

    wp_enqueue_script(
        'manage-user-profile-links',
        EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/manage-user-profile-links.js',
        array(),
        filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/manage-user-profile-links.js'),
        true
    );

    $user_id = bbp_get_displayed_user_id();
    $existing_links = get_user_meta($user_id, '_user_profile_dynamic_links', true);

    if (!is_array($existing_links)) {
        $existing_links = array();
    }

    // Define available link types (icon = extrachill.svg symbol ID)
    $link_types = array(
        'website' => array('label' => 'Website', 'icon' => 'globe'),
        'facebook' => array('label' => 'Facebook', 'icon' => 'facebook'),
        'instagram' => array('label' => 'Instagram', 'icon' => 'instagram'),
        'twitter' => array('label' => 'Twitter', 'icon' => 'x-twitter'),
        'youtube' => array('label' => 'YouTube', 'icon' => 'youtube'),
        'tiktok' => array('label' => 'TikTok', 'icon' => 'tiktok'),
        'spotify' => array('label' => 'Spotify', 'icon' => 'spotify'),
        'soundcloud' => array('label' => 'SoundCloud', 'icon' => 'soundcloud'),
        'bandcamp' => array('label' => 'Bandcamp', 'icon' => 'bandcamp'),
        'github' => array('label' => 'GitHub', 'icon' => 'github'),
        'other' => array('label' => 'Other', 'icon' => 'link', 'has_custom_label' => true)
    );

    // Localize script with data
    wp_localize_script('manage-user-profile-links', 'userProfileLinksData', array(
        'existingLinks' => $existing_links,
        'linkTypes' => $link_types,
        'text' => array(
            'removeLink' => __('Remove Link', 'extra-chill-community'),
            'customLinkLabel' => __('Custom Link Label', 'extra-chill-community')
        )
    ));
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_user_links_assets');
