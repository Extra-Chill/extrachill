<?php
/**
 * User Avatar Menu Component
 *
 * Extensible avatar dropdown with ec_avatar_menu_items filter for plugin integration.
 *
 * @package ExtraChill\Users
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display user avatar dropdown menu with plugin extensibility.
 */
function extrachill_display_user_avatar_menu() {
    if (!is_user_logged_in()) {
        return;
    }

    $current_user_id = get_current_user_id();
    $current_user = wp_get_current_user();
    ?>
    <div class="user-avatar-container header-right-icon">
        <a href="https://community.extrachill.com/u/<?php echo esc_attr($current_user->user_login); ?>/" class="user-avatar-link">
            <?php echo get_avatar($current_user_id, 40); ?>
        </a>

        <div class="user-dropdown-menu">
            <ul>
                <li><a href="https://community.extrachill.com/u/<?php echo esc_attr($current_user->user_login); ?>/">View Profile</a></li>
                <li><a href="https://community.extrachill.com/u/<?php echo esc_attr($current_user->user_login); ?>/edit/">Edit Profile</a></li>

                <?php
                $user_artist_ids = ec_get_artists_for_user( $current_user_id );
                $artist_count    = count( $user_artist_ids );

                $base_manage_url = 'https://artist.extrachill.com/manage-artist-profiles/';

                if ( $artist_count > 0 ) {
                    $latest_artist_id = ec_get_latest_artist_for_user( $current_user_id );
                    $link_page_count  = ec_get_link_page_count_for_user( $current_user_id );

                    $final_manage_url = add_query_arg( 'artist_id', $latest_artist_id, $base_manage_url );
                    $artist_label     = $artist_count === 1
                        ? esc_html__( 'Manage Artist', 'extrachill-users' )
                        : esc_html__( 'Manage Artists', 'extrachill-users' );

                    printf(
                        '<li><a href="%s">%s</a></li>',
                        esc_url( $final_manage_url ),
                        $artist_label
                    );

                    $base_link_page_manage_url  = 'https://artist.extrachill.com/manage-link-page/';
                    $final_link_page_manage_url = add_query_arg( 'artist_id', $latest_artist_id, $base_link_page_manage_url );

                    if ( $link_page_count === 0 ) {
                        $link_page_label = esc_html__( 'Create Link Page', 'extrachill-users' );
                    } elseif ( $link_page_count === 1 ) {
                        $link_page_label = esc_html__( 'Manage Link Page', 'extrachill-users' );
                    } else {
                        $link_page_label = esc_html__( 'Manage Link Pages', 'extrachill-users' );
                    }

                    printf(
                        '<li><a href="%s">%s</a></li>',
                        esc_url( $final_link_page_manage_url ),
                        $link_page_label
                    );
                } elseif ( function_exists( 'ec_can_create_artist_profiles' ) && ec_can_create_artist_profiles( $current_user_id ) ) {
                    printf(
                        '<li><a href="%s">%s</a></li>',
                        esc_url( $base_manage_url ),
                        esc_html__( 'Create Artist Profile', 'extrachill-users' )
                    );
                }

                /**
                 * Filter for plugins to inject custom menu items with priority sorting.
                 *
                 * @param array $custom_menu_items Empty array
                 * @param int   $current_user_id   Current user ID
                 */
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

                <li><a href="https://community.extrachill.com/settings/"><?php esc_html_e( 'Settings', 'extrachill-users' ); ?></a></li>
                <li><a href="<?php echo wp_logout_url( home_url() ); ?>">Log Out</a></li>
            </ul>
        </div>
    </div>
    <?php
}

add_action('extrachill_header_top_right', 'extrachill_display_user_avatar_menu', 30);
