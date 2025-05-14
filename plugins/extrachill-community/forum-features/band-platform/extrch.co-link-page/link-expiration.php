<?php
// Link Expiration Cron Cleanup for extrch.co Link Pages
add_action('extrch_cleanup_expired_links_event', function() {
    $args = array(
        'post_type'      => 'band_link_page',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    );
    $link_pages = get_posts($args);
    $now = current_time('timestamp');
    foreach ($link_pages as $link_page_id) {
        $expiration_enabled = get_post_meta($link_page_id, '_link_expiration_enabled', true);
        if ($expiration_enabled !== '1') continue;
        $links = get_post_meta($link_page_id, '_link_page_links', true);
        if (is_string($links)) $links = json_decode($links, true);
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
        }
    }
});
if (!wp_next_scheduled('extrch_cleanup_expired_links_event')) {
    wp_schedule_event(time(), 'hourly', 'extrch_cleanup_expired_links_event');
} 