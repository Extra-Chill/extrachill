<?php
/**
 * Link Expiration Cron Cleanup for extrch.link Link Pages
 */
add_action('extrachill_artist_cleanup_expired_links_event', function() {
    $args = array(
        'post_type'      => 'artist_link_page',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    );
    $link_pages = get_posts($args);
    $now = current_time('timestamp');
    foreach ($link_pages as $link_page_id) {
        $artist_id = apply_filters('ec_get_artist_id', $link_page_id);
        if (!$artist_id) {
            continue;
        }

        $data = ec_get_link_page_data($artist_id, $link_page_id);
        $expiration_enabled = $data['settings']['link_expiration_enabled'] ?? false;
        if (!$expiration_enabled) {
            continue;
        }

        $links = $data['links'] ?? [];
        if (!is_array($links)) {
            continue;
        }

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
            do_action('ec_link_page_save', $link_page_id);
        }
    }
});
if (!wp_next_scheduled('extrachill_artist_cleanup_expired_links_event')) {
    wp_schedule_event(time(), 'hourly', 'extrachill_artist_cleanup_expired_links_event');
}
