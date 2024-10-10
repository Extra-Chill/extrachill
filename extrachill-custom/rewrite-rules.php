<?php
// Remove the tag base for the 'Grateful Dead' tag
function remove_tag_base_for_grateful_dead() {
    add_rewrite_rule(
        '^grateful-dead/?$',
        'index.php?tag=grateful-dead',
        'top'
    );
    add_rewrite_rule(
        '^grateful-dead/feed/?$',
        'index.php?tag=grateful-dead&feed=rss2',
        'top'
    );
    // Add pagination support
    add_rewrite_rule(
        '^grateful-dead/page/([0-9]{1,})/?$',
        'index.php?tag=grateful-dead&paged=$matches[1]',
        'top'
    );
}
add_action('init', 'remove_tag_base_for_grateful_dead');

// Ensure the tag archive URL is correct for 'Grateful Dead' tag
function fix_grateful_dead_tag_link($termlink, $term, $taxonomy) {
    if ($taxonomy === 'post_tag' && $term->slug === 'grateful-dead') {
        return home_url('/grateful-dead');
    }
    return $termlink;
}
add_filter('term_link', 'fix_grateful_dead_tag_link', 10, 3);

// Redirect non-existent events to the calendar page
function redirect_nonexistent_events_to_calendar() {
    if (is_404()) {
        $url = esc_url($_SERVER['REQUEST_URI']);
        if (preg_match('/^\/event\//', $url)) {
            wp_redirect(home_url('/calendar'), 301);
            exit;
        }
    }
}
add_action('template_redirect', 'redirect_nonexistent_events_to_calendar');
