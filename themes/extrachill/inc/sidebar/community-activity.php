<?php
/**
 * Sidebar Community Activity
 *
 * Clean, hook-based community activity display for sidebar.
 * Shows recent forum activity from the community multisite.
 *
 * @package ExtraChill
 * @since 1.0
 */

if ( ! function_exists( 'extrachill_sidebar_community_activity' ) ) :
    function extrachill_sidebar_community_activity() {
        $transient_name = 'extrachill_recent_activity';
        $activities = get_transient($transient_name);

        if ($activities === false) {
            $activities = ec_fetch_recent_activity_multisite( 10 );

            if ( empty( $activities ) ) {
                return;
            }

            set_transient($transient_name, $activities, 10 * MINUTE_IN_SECONDS);
        }

        if (!empty($activities)) {
            echo '<div class="sidebar-card">';
            echo '<div class="widget extrachill-recent-activity-widget">';
            echo '<h3 class="widget-title"><span>Community Activity</span></h3>';
            echo '<div class="extrachill-recent-activity">';
            echo '<ul>';

            $counter = 0;
            foreach ($activities as $activity) {
                if (!is_array($activity)) {
                    continue;
                }
                $counter++;

                $post_date = isset($activity['post_date']) ? $activity['post_date'] : '';
                $post_title = isset($activity['post_title']) ? esc_html($activity['post_title']) : '';
                $post_name = isset($activity['post_name']) ? $activity['post_name'] : '';
                $author = isset($activity['author']) ? esc_html($activity['author']) : '';
                $post_type = isset($activity['post_type']) ? $activity['post_type'] : '';

                if (empty($post_title) || empty($author)) {
                    continue;
                }

                $time_string = '';
                if (!empty($post_date)) {
                    $time_string = custom_human_time_diff(strtotime($post_date));
                }

                $post_url = '';
                if ($post_type === 'topic') {
                    $post_url = 'https://community.extrachill.com/t/' . $post_name;
                } elseif ($post_type === 'reply') {
                    $post_url = 'https://community.extrachill.com/r/' . $post_name;
                }

                echo '<li>';
                if (!empty($post_url)) {
                    echo '<a href="' . esc_url($post_url) . '" class="activity-link" target="_blank" rel="noopener" aria-label="' . esc_attr($post_title) . ' by ' . esc_attr($author) . ', opens in new tab">';
                }

                echo '<strong>' . $post_title . '</strong>';
                echo '<span class="activity-meta"> by ' . $author;
                if (!empty($time_string)) {
                    echo ' • ' . $time_string;
                }
                echo '</span>';

                if (!empty($post_url)) {
                    echo '</a>';
                }
                echo '</li>';
            }

            echo '</ul>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
    }
endif;

// Hook into sidebar middle
add_action( 'extrachill_sidebar_middle', 'extrachill_sidebar_community_activity', 10 );