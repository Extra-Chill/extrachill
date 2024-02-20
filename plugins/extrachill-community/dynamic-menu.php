<?php

function wp_surgeon_dynamic_menu_items($items, $args) {
    if ($args->theme_location == 'primary') {
        // "Recent" button link and label
        $recent_url = home_url('/recent/');
        $recent_label = 'Recent';
        
        // "Why Join?" button link and label
        $why_join_url = home_url('/why-join/'); // Adjust the slug as necessary
        $why_join_label = 'Why Join?';
        
        // Prepend the "Recent" button to the menu items
        $recent_item = '<li class="menu-item"><a href="' . esc_url($recent_url) . '">' . esc_html($recent_label) . '</a></li>';
        // "Why Join?" menu item
        $why_join_item = '<li class="menu-item"><a href="' . esc_url($why_join_url) . '">' . esc_html($why_join_label) . '</a></li>';

        if (is_user_logged_in()) {
            // URLs and labels for logged-in users
            $dashboard_url = home_url('/user-dashboard/');
            $dashboard_label = 'Dashboard';
            $profile_url = bbp_get_user_profile_url(get_current_user_id());
            $profile_label = 'Profile';
            $logout_url = wp_surgeon_custom_logout_url(wp_logout_url(), '');
            $logout_label = 'Log Out';

            // Custom items for logged-in users
            $custom_items = '<li class="menu-item"><a href="' . esc_url('/following') . '">Following</a></li>'
                          . '<li class="menu-item"><a href="' . esc_url('/upvoted') . '">Upvoted</a></li>'
                          . '<li class="menu-item"><a href="' . esc_url($profile_url) . '">' . esc_html($profile_label) . '</a></li>'
                          . '<li class="menu-item"><a href="' . esc_url($dashboard_url) . '">' . esc_html($dashboard_label) . '</a></li>'
                          . '<li class="menu-item"><a href="' . esc_url($logout_url) . '">' . esc_html($logout_label) . '</a></li>';

            // Remove any existing 'Login' menu item and prepend "Recent" item
            $items = preg_replace('/<li.*?<a href=".*?">Login<\/a>.*?<\/li>/', '', $items);
            $items = $recent_item . $custom_items . $items;
        } else {
            // For non-logged-in users, prepend the "Why Join?" and "Recent" items
            $items = $why_join_item . $recent_item . $items;
        }
    }
    return $items;
}
add_filter('wp_nav_menu_items', 'wp_surgeon_dynamic_menu_items', 10, 2);
