<?php
/**
 * Recent Feed Query Functions
 *
 * Centralized database queries for the Recent Activity Feed page template.
 * Handles activity feed with both topics and replies in chronological order.
 *
 * @package ExtraChillCommunity
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('ExtraChill_Community_Feed_Query')) {
    /**
     * Lightweight pagination helper mimicking WP_Query shape for template compatibility.
     */
    class ExtraChill_Community_Feed_Query {
        /** @var int */
        public $found_posts = 0;

        /** @var int */
        public $max_num_pages = 0;

        /** @var array */
        public $query_vars = array();

        /**
         * @param int $total_posts
         * @param int $per_page
         * @param int $paged
         */
        public function __construct($total_posts, $per_page, $paged) {
            $this->found_posts   = (int) $total_posts;
            $this->max_num_pages = $per_page > 0 ? (int) ceil($this->found_posts / $per_page) : 0;
            $this->query_vars    = array(
                'posts_per_page' => (int) $per_page,
                'paged'          => (int) $paged,
            );
        }

        /**
         * Mirror WP_Query::get() behaviour for pagination helper compatibility.
         *
         * @param string $key
         * @param mixed  $default
         *
         * @return mixed
         */
        public function get($key, $default = null) {
            if (isset($this->query_vars[$key])) {
                return $this->query_vars[$key];
            }

            return $default;
        }
    }
}

/**
 * Get avatar URL with custom avatar support.
 *
 * Unlike get_avatar_url(), this ensures the pre_get_avatar filter is triggered
 * so custom avatars work in multisite recent feed contexts.
 *
 * @param int $user_id User ID
 * @param int $size Avatar size
 * @return string Avatar URL
 */
function extrachill_get_avatar_url_with_custom_support($user_id, $size = 80) {
    $avatar_html = get_avatar($user_id, $size);
    if (preg_match('/src=["\']([^"\']+)["\']/', $avatar_html, $matches)) {
        return $matches[1];
    }
    return get_avatar_url($user_id, array('size' => $size));
}

function extrachill_get_recent_replies_args($per_page = 15, $paged = 1) {
    return array(
        'post_type'      => array('topic', 'reply'),
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'post_status'    => array('publish', 'closed', 'acf-disabled', 'private', 'hidden')
    );
}

function extrachill_get_recent_feed_query($per_page = 15, $paged = null) {
    if (!function_exists('bbp_get_reply_post_type')) {
        return false;
    }

    $per_page = max(1, (int) $per_page);
    $bbp_paged = function_exists('bbp_get_paged') ? bbp_get_paged() : max(1, (int) get_query_var('paged', 1));
    $paged     = null === $paged ? $bbp_paged : max(1, (int) $paged);

    $community_blog_id = 2; // community.extrachill.com
    $artist_blog_id    = 4; // artist.extrachill.com
    $blog_ids          = array($community_blog_id, $artist_blog_id);
    $items             = array();
    $total_posts       = 0;
    $fetch_limit       = $per_page * $paged;

    foreach ($blog_ids as $blog_id) {
        $switched = false;

        if ($blog_id !== get_current_blog_id()) {
            switch_to_blog($blog_id);
            $switched = true;
        }

        $args = extrachill_get_recent_replies_args($fetch_limit, 1);

        if (empty($args)) {
            if ($switched) {
                restore_current_blog();
            }

            continue;
        }

        $args['no_found_rows'] = false;

        $query = new WP_Query($args);

        $total_posts += (int) $query->found_posts;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $author_id = get_the_author_meta('ID');
                $post_id   = get_the_ID();
                $post_type = get_post_type();

                // Get topic and forum IDs while on correct blog
                $topic_id = ($post_type === 'topic') ? $post_id : get_post_meta($post_id, '_bbp_topic_id', true);
                if (!$topic_id) {
                    $topic_id = wp_get_post_parent_id($post_id);
                }
                $forum_id = get_post_meta($topic_id ? $topic_id : $post_id, '_bbp_forum_id', true);

                $items[] = array(
                    'post'              => get_post(),
                    'blog_id'           => $blog_id,
                    'timestamp'         => (int) get_post_time('U', true),
                    'author_id'         => $author_id,
                    'author_name'       => get_the_author(),
                    'author_avatar_url' => extrachill_get_avatar_url_with_custom_support($author_id, 80),
                    'topic_id'          => $topic_id,
                    'topic_url'         => $topic_id ? get_permalink($topic_id) : '',
                    'topic_title'       => $topic_id ? get_the_title($topic_id) : '',
                    'forum_id'          => $forum_id,
                    'forum_url'         => $forum_id ? get_permalink($forum_id) : '',
                    'forum_title'       => $forum_id ? get_the_title($forum_id) : '',
                );
            }
            wp_reset_postdata();
        }

        if ($switched) {
            restore_current_blog();
        }
    }

    if (empty($items) && 0 === $total_posts) {
        return false;
    }

    usort(
        $items,
        function ($a, $b) {
            $a_time = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
            $b_time = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;

            return $b_time <=> $a_time;
        }
    );

    $offset      = ($paged - 1) * $per_page;
    $paged_items = array_slice($items, $offset, $per_page);
    $pagination  = new ExtraChill_Community_Feed_Query($total_posts, $per_page, $paged);

    return array(
        'items'      => $paged_items,
        'pagination' => $pagination,
    );
}

function extrachill_get_recent_activity_query($per_page = 15, $paged = 1) {
    $args = extrachill_get_recent_replies_args($per_page, $paged);

    if (empty($args)) {
        return new WP_Query(array('post__in' => array(0)));
    }

    return new WP_Query($args);
}
