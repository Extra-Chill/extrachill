<?php

function wp_surgeon_dynamic_menu_items($items, $args) {
    if ( $args->theme_location === 'primary' ) {
        // Always define the "Recent" menu item.
        $recent_url  = home_url('/recent/');
        $recent_label = 'Recent';
        $recent_item = '<li class="menu-item"><a href="' . esc_url($recent_url) . '">' . esc_html($recent_label) . '</a></li>';

        if ( is_user_logged_in() ) {
            // For logged-in users, define additional custom menu items.
            $profile_url   = bbp_get_user_profile_url( get_current_user_id() );

            $custom_items  = 
                '<li class="menu-item"><a href="' . esc_url('/following') . '">Following</a></li>';
            // Prepend the "Recent" item and the extra custom items.
            $items = $recent_item . $custom_items . $items;
        } else {
            // For non-logged-in users, prepend only the "Recent" item.
            $items = $recent_item . $items;
        }
    }
    return $items;
}
add_filter('wp_nav_menu_items', 'wp_surgeon_dynamic_menu_items', 10, 2);
