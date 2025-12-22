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
    $login_url    = home_url( '/login/' );
    $register_url = trailingslashit( $login_url ) . '#tab-register';

    $is_logged_in    = is_user_logged_in();
    $current_user_id = $is_logged_in ? get_current_user_id() : 0;
    $current_user    = $is_logged_in ? wp_get_current_user() : null;

    $avatar_markup = '';

    if ( $is_logged_in ) {
        $avatar_markup = get_avatar( $current_user_id, 40 );
    } else {
        $avatar_markup = ec_icon( 'user' );
    }
    ?>
    <div class="user-avatar-container header-right-icon">
        <button type="button" class="user-avatar-toggle" aria-expanded="false">
            <span class="screen-reader-text"><?php esc_html_e( 'Toggle account menu', 'extrachill-users' ); ?></span>
            <?php echo $avatar_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </button>

        <div class="user-dropdown-menu" role="menu">
            <ul>
                <?php if ( $is_logged_in && $current_user ) : ?>
                    <?php
                    $menu_items = function_exists( 'extrachill_users_get_avatar_menu_items' )
                        ? extrachill_users_get_avatar_menu_items( $current_user_id )
                        : array();

                    foreach ( $menu_items as $menu_item ) {
                        if ( empty( $menu_item['label'] ) || empty( $menu_item['url'] ) ) {
                            continue;
                        }

                        $class = ! empty( $menu_item['danger'] ) ? 'log-out-link' : '';

                        printf(
                            '<li><a class="%s" href="%s">%s</a></li>',
                            esc_attr( $class ),
                            esc_url( $menu_item['url'] ),
                            esc_html( $menu_item['label'] )
                        );
                    }
                    ?>
                <?php else : ?>
                    <li><a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log In', 'extrachill-users' ); ?></a></li>
                    <li><a href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Register', 'extrachill-users' ); ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <?php
}

add_action('extrachill_header_top_right', 'extrachill_display_user_avatar_menu', 30);
