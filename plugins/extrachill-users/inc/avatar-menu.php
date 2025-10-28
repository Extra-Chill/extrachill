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

                $base_manage_url = 'https://artist.extrachill.com/manage-artist-profiles/';

                if ( ! empty( $user_artist_ids ) ) {
                    $final_manage_url = $base_manage_url;
                    $latest_artist_id = 0;
                    $latest_modified_timestamp = 0;

                    switch_to_blog( 4 );
                        try {
                            foreach ( $user_artist_ids as $artist_id ) {
                                // Find link page for this artist profile
                                $link_pages = get_posts( array(
                                    'post_type' => 'artist_link_page',
                                    'meta_key' => '_associated_artist_profile_id',
                                    'meta_value' => (string) $artist_id,
                                    'posts_per_page' => 1,
                                    'fields' => 'ids'
                                ) );

                                if ( ! empty( $link_pages ) ) {
                                    $link_page_id = (int) $link_pages[0];
                                    $post_modified_gmt = get_post_field( 'post_modified_gmt', $link_page_id, 'raw' );
                                    if ( $post_modified_gmt ) {
                                        $current_timestamp = strtotime( $post_modified_gmt );
                                        if ( $current_timestamp > $latest_modified_timestamp ) {
                                            $latest_modified_timestamp = $current_timestamp;
                                            $latest_artist_id = $artist_id;
                                        }
                                    }
                                }
                            }
                        } finally {
                            restore_current_blog();
                        }

                    if ( $latest_artist_id > 0 ) {
                        $final_manage_url = add_query_arg( 'artist_id', $latest_artist_id, $base_manage_url );
                    }

                    printf(
                        '<li><a href="%s">%s</a></li>',
                        esc_url( $final_manage_url ),
                        esc_html__( 'Manage Artist Profile(s)', 'extrachill-users' )
                    );

                    $base_link_page_manage_url = 'https://artist.extrachill.com/manage-link-page/';
                    $final_link_page_manage_url = $base_link_page_manage_url;

                    if ( $latest_artist_id > 0 ) {
                        $final_link_page_manage_url = add_query_arg( 'artist_id', $latest_artist_id, $base_link_page_manage_url );
                    }

                    printf(
                        '<li><a href="%s">%s</a></li>',
                        esc_url( $final_link_page_manage_url ),
                        esc_html__( 'Manage Link Page(s)', 'extrachill-users' )
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
