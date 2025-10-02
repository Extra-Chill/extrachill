<?php
/**
 * Add "Recent" menu item to theme navigation
 *
 * Integrates with ExtraChill theme's hook-based menu system.
 *
 * @package ExtraChillCommunity
 */

function extrachill_community_add_recent_menu_item() {
    $recent_url = home_url('/recent/');
    echo '<li class="menu-item"><a href="' . esc_url($recent_url) . '">Recent</a></li>';
}
add_action('extrachill_navigation_main_menu', 'extrachill_community_add_recent_menu_item', 5);
