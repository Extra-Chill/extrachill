<?php
/**
 * Sidebar Community Activity
 *
 * Displays recent community activity using shared helper from inc/core/templates/community-activity.php.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! function_exists( 'extrachill_sidebar_community_activity' ) ) :
    function extrachill_sidebar_community_activity() {
        $activities = extrachill_get_community_activity_items(5);

        if (empty($activities)) {
            return;
        }

        echo '<div class="sidebar-card">';
        echo '<div class="widget extrachill-recent-activity-widget">';
        echo '<h3 class="widget-title"><span>Community Activity</span></h3>';
        echo '<div class="extrachill-recent-activity">';

        extrachill_render_community_activity(array(
            'render_wrapper' => false,
            'item_class'     => 'sidebar-activity-card',
            'empty_class'    => 'sidebar-activity-card sidebar-activity-empty',
            'limit'          => 5,
            'items'          => $activities,
        ));

        echo '</div>';
        echo '<div class="widget-button-wrapper">';
        echo '<a href="https://community.extrachill.com/recent" class="button-2 button-medium">View All</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
endif;

// Hook into sidebar middle
add_action( 'extrachill_sidebar_middle', 'extrachill_sidebar_community_activity', 10 );