<?php

function bbp_track_topic_views($post_id) {
    if (bbp_is_single_topic()) {
        $views = (int) get_post_meta($post_id, 'bbp_topic_views', true);
        update_post_meta($post_id, 'bbp_topic_views', $views + 1);
    }
}
add_action('wp_head', function() {
    if (is_singular('topic')) {
        bbp_track_topic_views(get_the_ID());
    }
});
