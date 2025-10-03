<?php
/**
 * User Avatar Menu Component
 *
 * Renders user avatar with dropdown menu. Exposes ec_avatar_menu_items
 * filter for plugins to inject custom menu items between profile and
 * settings sections with priority-based sorting.
 *
 * Filter location: Between "Edit Profile" and "Settings" menu items
 * Filter structure: array('url' => '', 'label' => '', 'priority' => 10)
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

function extrachill_display_user_avatar_menu() {
    if (!is_user_logged_in()) {
        return;
    }

    $current_user_id = get_current_user_id();
    ?>
    <div class="user-avatar-container">
        <a href="<?php echo function_exists('bbp_get_user_profile_url') ? bbp_get_user_profile_url($current_user_id) : get_author_posts_url($current_user_id); ?>" class="user-avatar-link">
            <?php echo get_avatar($current_user_id, 40); ?>
        </a>
        <button class="user-avatar-button"></button>

        <!-- Dropdown menu -->
        <div class="user-dropdown-menu">
            <ul>
                <li><a href="<?php echo bbp_get_user_profile_url($current_user_id); ?>">View Profile</a></li>
                <li><a href="<?php echo bbp_get_user_profile_edit_url($current_user_id); ?>">Edit Profile</a></li>

                <?php
                $custom_menu_items = apply_filters( 'ec_avatar_menu_items', array(), $current_user_id );

                if ( ! empty( $custom_menu_items ) && is_array( $custom_menu_items ) ) {
                    usort( $custom_menu_items, function( $a, $b ) {
                        $priority_a = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
                        $priority_b = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
                        return $priority_a <=> $priority_b;
                    });

                    foreach ( $custom_menu_items as $menu_item ) {
                        if ( isset( $menu_item['url'] ) && isset( $menu_item['label'] ) ) {
                            printf(
                                '<li><a href="%s">%s</a></li>',
                                esc_url( $menu_item['url'] ),
                                esc_html( $menu_item['label'] )
                            );
                        }
                    }
                }
                ?>

                <li><a href="<?php echo esc_url( home_url('/settings/') ); ?>"><?php esc_html_e( 'Settings', 'extra-chill-community' ); ?></a></li>
                <li><a href="<?php echo wp_logout_url( home_url() ); ?>">Log Out</a></li>
            </ul>
        </div>
    </div>
    <?php
}
