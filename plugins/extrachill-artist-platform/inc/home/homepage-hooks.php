<?php
/**
 * Homepage Action Hooks
 *
 * Centralized hook registrations for artist platform homepage functionality.
 *
 * @package ExtraChillArtistPlatform
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Override homepage template for artist.extrachill.com
 *
 * Uses WordPress native template_include filter via theme's universal routing system.
 * The extrachill_template_homepage filter is provided by theme's template-router.php.
 *
 * @param string $template Default template path from theme
 * @return string Modified template path
 */
function ec_artist_platform_override_homepage( $template ) {
    // Only override on artist.extrachill.com using blog ID
    if ( get_current_blog_id() === 4 ) {
        return EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/home/templates/homepage.php';
    }

    return $template;
}
add_filter( 'extrachill_template_homepage', 'ec_artist_platform_override_homepage' );

/**
 * Render the homepage hero section
 *
 * Includes the hero template with welcome messages based on user state.
 *
 * @param WP_User $current_user     Current user object
 * @param bool    $is_logged_in     Whether user is logged in
 * @param bool    $can_create_artists Whether user can create artist profiles
 * @param array   $user_artist_ids  Array of artist profile IDs for current user
 */
function ec_render_artist_home_hero( $current_user, $is_logged_in, $can_create_artists, $user_artist_ids ) {
    include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/home/templates/hero.php';
}
add_action( 'extrachill_artist_home_hero', 'ec_render_artist_home_hero', 10, 4 );

/**
 * Render support buttons section
 *
 * Displays centered "Support Forum" and "Contact" buttons above the artist grid.
 */
function ec_render_support_buttons() {
    ?>
    <div class="welcome-actions">
        <a href="https://artist.extrachill.com/extra-chill" class="button-1 button-large"><?php esc_html_e( 'Support Forum', 'extrachill-artist-platform' ); ?></a>
        <a href="https://extrachill.com/contact-us" class="button-1 button-large"><?php esc_html_e( 'Contact', 'extrachill-artist-platform' ); ?></a>
    </div>
    <?php
}
add_action( 'extrachill_above_artist_grid', 'ec_render_support_buttons', 15 );

/**
 * Render the your artists section
 *
 * Displays the user's artist profiles in a grid.
 *
 * @param array $user_artist_ids Array of artist profile IDs for current user
 */
function ec_render_your_artists( $user_artist_ids ) {
    include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/home/templates/your-artists.php';
}
add_action( 'extrachill_above_artist_grid', 'ec_render_your_artists', 10, 1 );
