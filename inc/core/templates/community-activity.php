<?php
/**
 * Shared Community Activity Template Helpers
 *
 * Provides reusable data fetching and rendering helpers for community activity
 * output across the theme. Queries bbPress activity from community.extrachill.com
 * with caching applied to limit database queries.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('extrachill_get_community_activity_items')) {
    /**
     * Retrieve recent community activity items from community.extrachill.com.
     *
     * Queries bbPress topics/replies and caches results for 10 minutes.
     *
     * @param int $limit Number of items to return (max items from cached pool).
     * @return array[] Array of activity items with keys: id, type, username, user_profile_url,
     *                 topic_title, forum_title, date_time, forum_url, topic_url.
     */
    function extrachill_get_community_activity_items($limit = 5)
    {
        $limit = max(1, absint($limit));
        $cache_key = 'extrachill_community_activity_all';
        $activities = wp_cache_get($cache_key);

        if ($activities === false) {
            $current_blog_id = get_current_blog_id();
            $community_blog_id = function_exists('ec_get_blog_id') ? ec_get_blog_id('community') : null;
            $query_limit = 10;
            $activities = array();

            if (!$community_blog_id) {
                return array_slice($activities, 0, $limit);
            }

            $switched = false;
            if ($community_blog_id !== $current_blog_id) {
                switch_to_blog($community_blog_id);
                $switched = true;
            }

            $args = array(
                'post_type'      => array('topic', 'reply'),
                'post_status'    => 'publish',
                'posts_per_page' => $query_limit,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_bbp_forum_id',
                        'value'   => array(),
                        'compare' => 'NOT IN',
                    ),
                    array(
                        'key'     => '_bbp_forum_id',
                        'value'   => '1494',
                        'compare' => '!=',
                    ),
                ),
            );

            $query = new WP_Query($args);

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id   = get_the_ID();
                    $post_type = get_post_type($post_id);
                    $author_id = get_the_author_meta('ID');
                    $date_time = get_the_date('c');

                    $forum_id = absint(get_post_meta($post_id, '_bbp_forum_id', true));
                    $topic_id = ('reply' === $post_type)
                        ? absint(get_post_meta($post_id, '_bbp_topic_id', true))
                        : $post_id;

                    if ('reply' === $post_type && !$topic_id) {
                        $topic_id = absint(get_post_field('post_parent', $post_id));
                    }

                    if (!$forum_id && $topic_id) {
                        $forum_id = absint(get_post_meta($topic_id, '_bbp_forum_id', true));
                    }

                    $forum_title = $forum_id ? get_the_title($forum_id) : '';
                    $topic_title = $topic_id ? get_the_title($topic_id) : '';
                    $forum_url   = $forum_id ? get_permalink($forum_id) : '';
                    $topic_url   = $topic_id ? get_permalink($topic_id) : '';
                    $username    = get_the_author();
                    $user_profile_url = ($author_id && function_exists('ec_get_user_profile_url'))
                        ? ec_get_user_profile_url($author_id)
                        : '';

                    if (!$topic_url || !$forum_url) {
                        continue;
                    }

                    $activities[] = array(
                        'id'               => $post_id,
                        'type'             => ('reply' === $post_type) ? 'Reply' : 'Topic',
                        'username'         => $username,
                        'user_profile_url' => $user_profile_url,
                        'topic_title'      => $topic_title,
                        'forum_title'      => $forum_title,
                        'date_time'        => $date_time,
                        'forum_url'        => $forum_url,
                        'topic_url'        => $topic_url,
                    );
                }
                wp_reset_postdata();
            }

            if ($switched) {
                restore_current_blog();
            }

            wp_cache_set($cache_key, $activities, '', 10 * MINUTE_IN_SECONDS);
        }

        return array_slice($activities, 0, $limit);
    }
}

if (!function_exists('extrachill_render_community_activity')) {
    /**
     * Render community activity items with customizable HTML structure.
     *
     * @param array $args {
     *     Rendering arguments.
     *
     *     @type int    $limit          Number of items to display. Default 5.
     *     @type string $wrapper_tag    HTML tag for container. Default 'div'.
     *     @type string $wrapper_class  CSS class for container. Default 'community-activity-list'.
     *     @type string $item_class     CSS class for activity cards. Default ''.
     *     @type string $empty_class    CSS class for empty state. Default ''.
     *     @type bool   $render_wrapper Whether to render container element. Default true.
     *     @type int    $counter_offset Offset for topic ID counters. Default 0.
     *     @type array  $items          Pre-fetched items array (bypasses query). Default null.
     * }
     * @return void
     */
    function extrachill_render_community_activity($args = array())
    {
        $defaults = array(
            'limit'          => 5,
            'wrapper_tag'    => 'div',
            'wrapper_class'  => 'community-activity-list',
            'item_class'     => '',
            'empty_class'    => '',
            'render_wrapper' => true,
            'counter_offset' => 0,
            'items'          => null,
        );

        $args = wp_parse_args($args, $defaults);
        $items = is_array($args['items'])
            ? array_slice($args['items'], 0, $args['limit'])
            : extrachill_get_community_activity_items($args['limit']);

        $item_class = trim($args['item_class']);
        $item_class = $item_class ? 'community-activity-card ' . $item_class : 'community-activity-card';

        $empty_class = trim($args['empty_class']);
        $empty_class = $empty_class ? 'community-activity-empty ' . $empty_class : 'community-activity-empty';

        if (!empty($items)) {
            if ($args['render_wrapper']) {
                printf('<%1$s class="%2$s">', esc_attr($args['wrapper_tag']), esc_attr($args['wrapper_class']));
            }

            foreach ($items as $index => $activity) {
                if (!is_array($activity)) {
                    continue;
                }

                $counter = $index + 1 + (int) $args['counter_offset'];
                $time_text = sprintf(
                    esc_html__('%s ago', 'extrachill'),
                    human_time_diff(strtotime($activity['date_time']))
                );

                $username = esc_html($activity['username']);
                $username_html = $activity['user_profile_url']
                    ? sprintf('<a href="%1$s">%2$s</a>', esc_url($activity['user_profile_url']), $username)
                    : $username;

                $topic_html = sprintf(
                    '<a id="topic-%1$d" href="%2$s">%3$s</a>',
                    $counter,
                    esc_url($activity['topic_url']),
                    esc_html($activity['topic_title'])
                );

                $forum_html = sprintf(
                    '<a href="%1$s">%2$s</a>',
                    esc_url($activity['forum_url']),
                    esc_html($activity['forum_title'])
                );

                $content = ('Reply' === $activity['type'])
                    ? sprintf(
                        '%1$s %2$s %3$s %4$s %5$s',
                        $username_html,
                        esc_html__('replied to', 'extrachill'),
                        $topic_html,
                        esc_html__('in', 'extrachill'),
                        $forum_html
                    )
                    : sprintf(
                        '%1$s %2$s %3$s %4$s %5$s',
                        $username_html,
                        esc_html__('posted', 'extrachill'),
                        $topic_html,
                        esc_html__('in', 'extrachill'),
                        $forum_html
                    );

                printf(
                    '<div class="%1$s">%2$s - %3$s</div>',
                    esc_attr($item_class),
                    $content,
                    esc_html($time_text)
                );
            }

            if ($args['render_wrapper']) {
                printf('</%s>', esc_attr($args['wrapper_tag']));
            }
        } else {
            $empty_message = esc_html__('No recent activity.', 'extrachill');

            if ($args['render_wrapper']) {
                printf('<%1$s class="%2$s">', esc_attr($args['wrapper_tag']), esc_attr($args['wrapper_class']));
            }

            printf(
                '<div class="%1$s">%2$s</div>',
                esc_attr($empty_class),
                $empty_message
            );

            if ($args['render_wrapper']) {
                printf('</%s>', esc_attr($args['wrapper_tag']));
            }
        }
    }
}