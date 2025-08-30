<?php
// Link Expiration Cron Cleanup for extrch.co Link Pages
add_action('extrch_cleanup_expired_links_event', function() {
    $args = array(
        'post_type'      => 'artist_link_page',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    );
    $link_pages = get_posts($args);
    $now = current_time('timestamp');
    foreach ($link_pages as $link_page_id) {
        // Use centralized data system (single source of truth)
        $artist_id = get_post_meta($link_page_id, '_associated_artist_profile_id', true);
        if (!$artist_id) continue;
        
        $data = ec_get_link_page_data($artist_id, $link_page_id);
        $expiration_enabled = $data['settings']['link_expiration_enabled'] ?? false;
        if (!$expiration_enabled) continue;
        
        $links = $data['links'] ?? [];
        if (!is_array($links)) continue;
        $changed = false;
        foreach ($links as $section_idx => &$section) {
            if (isset($section['links']) && is_array($section['links'])) {
                foreach ($section['links'] as $link_idx => $link) {
                    if (!empty($link['expires_at'])) {
                        $expires = strtotime($link['expires_at']);
                        if ($expires !== false && $expires <= $now) {
                            unset($section['links'][$link_idx]);
                            $changed = true;
                        }
                    }
                }
                if (isset($section['links'])) {
                    $section['links'] = array_values($section['links']);
                }
            }
        }
        $links = array_values(array_filter($links, function($section) {
            return !empty($section['links']);
        }));
        if ($changed) {
            update_post_meta($link_page_id, '_link_page_links', $links);
            // Fire save hook for consistency with centralized system
            do_action('ec_link_page_save', $link_page_id);
        }
    }
});
if (!wp_next_scheduled('extrch_cleanup_expired_links_event')) {
    wp_schedule_event(time(), 'hourly', 'extrch_cleanup_expired_links_event');
}

/**
 * Outputs the Link Expiration Modal markup for the Manage Link Page.
 * Call this function in the links tab template to include the modal in the DOM.
 */
function extrch_render_link_expiration_modal() {
    ?>
    <div id="bp-link-expiration-modal" class="bp-link-expiration-modal" style="display:none;">
        <div class="bp-link-expiration-modal-inner">
            <h3 class="bp-link-expiration-modal-title"><?php esc_html_e('Set Link Expiration', 'extrachill-artist-platform'); ?></h3>
            <label class="bp-link-expiration-modal-label">
                <?php esc_html_e('Expiration Date/Time:', 'extrachill-artist-platform'); ?><br>
                <input type="datetime-local" id="bp-link-expiration-datetime" class="bp-link-expiration-datetime">
            </label>
            <div class="bp-link-expiration-modal-actions">
                <button type="button" class="button button-primary" id="bp-save-link-expiration"><?php esc_html_e('Save', 'extrachill-artist-platform'); ?></button>
                <button type="button" class="button" id="bp-clear-link-expiration"><?php esc_html_e('Clear', 'extrachill-artist-platform'); ?></button>
                <button type="button" class="button" id="bp-cancel-link-expiration"><?php esc_html_e('Cancel', 'extrachill-artist-platform'); ?></button>
            </div>
        </div>
    </div>
    <?php
} 