<?php
/**
 * Sidebar Community Activity
 *
 * Hook-based community activity display for sidebar.
 * Shows recent forum activity with caching and fallback handling.
 *
 * @package ExtraChill
 * @since 69.57
 */

if ( ! function_exists( 'extrachill_sidebar_community_activity' ) ) :
    /**
     * Display recent community activity in sidebar
     * Direct bbPress query with 10-minute caching
     */
    function extrachill_sidebar_community_activity() {
        $cache_key = 'extrachill_sidebar_activity';
        $activities = wp_cache_get($cache_key);

        if ($activities === false) {
            $current_blog_id = get_current_blog_id();
            $community_blog_id = 0;
            if (function_exists('get_blog_id_from_url')) {
                $resolved_blog_id = get_blog_id_from_url('community.extrachill.com', '/');
                if (!empty($resolved_blog_id)) {
                    $community_blog_id = (int) $resolved_blog_id;
                }
            }

            if (!$community_blog_id) {
                error_log('Community activity feed: unable to resolve community.extrachill.com blog ID');
                $activities = array();
            }

            $switched = false;
            if ($community_blog_id) {
                if ($community_blog_id !== $current_blog_id) {
                    switch_to_blog($community_blog_id);
                    $switched = true;
                }

                $args = array(
                    'post_type' => array('topic', 'reply'),
                    'post_status' => 'publish',
                    'posts_per_page' => 5,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => '_bbp_forum_id',
                            'value' => array(),
                            'compare' => 'NOT IN',
                        ),
                        array(
                            'key' => '_bbp_forum_id',
                            'value' => '1494',
                            'compare' => '!=',
                        ),
                    ),
                );

                $query = new WP_Query($args);
                $activities = array();

                if ($query->have_posts()) {
                    while ($query->have_posts()) {
                        $query->the_post();
                        $post_id = get_the_ID();
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
                        $forum_url = $forum_id ? get_permalink($forum_id) : '';
                        $topic_url = $topic_id ? get_permalink($topic_id) : '';
                        $username = get_the_author();
                        $user_profile_url = $author_id ? get_author_posts_url($author_id) : '';

                        if (!$topic_url || !$forum_url) {
                            continue;
                        }

                        $activities[] = array(
                            'id' => $post_id,
                            'type' => ('reply' === $post_type) ? 'Reply' : 'Topic',
                            'username' => $username,
                            'user_profile_url' => $user_profile_url,
                            'topic_title' => $topic_title,
                            'forum_title' => $forum_title,
                            'date_time' => $date_time,
                            'forum_url' => $forum_url,
                            'topic_url' => $topic_url,
                        );
                    }
                    wp_reset_postdata();
                }

                if ($switched) {
                    restore_current_blog();
                }
            }

            wp_cache_set($cache_key, $activities, '', 10 * MINUTE_IN_SECONDS);
        }

        if (!empty($activities)) {
            echo '<div class="sidebar-card">';
            echo '<div class="widget extrachill-recent-activity-widget">';
            echo '<h3 class="widget-title"><span>Community Activity</span></h3>';
            echo '<div class="extrachill-recent-activity">';

            $counter = 0;
            foreach ($activities as $activity) {
                if (!is_array($activity) || $counter >= 5) {
                    continue;
                }

                $dateFormatted = human_time_diff(strtotime($activity['date_time'])) . ' ago';
                $counter++;

                if ($activity['type'] === 'Reply') {
                    printf(
                        '<div class="home-3x3-card home-3x3-community-card"><a href="%s">%s</a> replied to <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</div>',
                        esc_url($activity['user_profile_url']),
                        esc_html($activity['username']),
                        $counter,
                        esc_url($activity['topic_url']),
                        esc_html($activity['topic_title']),
                        esc_url($activity['forum_url']),
                        esc_html($activity['forum_title']),
                        $dateFormatted
                    );
                } else {
                    printf(
                        '<div class="home-3x3-card home-3x3-community-card"><a href="%s">%s</a> posted <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</div>',
                        esc_url($activity['user_profile_url']),
                        esc_html($activity['username']),
                        $counter,
                        esc_url($activity['topic_url']),
                        esc_html($activity['topic_title']),
                        esc_url($activity['forum_url']),
                        esc_html($activity['forum_title']),
                        $dateFormatted
                    );
                }
            }

            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
    }
endif;

// Hook into sidebar middle
add_action( 'extrachill_sidebar_middle', 'extrachill_sidebar_community_activity', 10 );