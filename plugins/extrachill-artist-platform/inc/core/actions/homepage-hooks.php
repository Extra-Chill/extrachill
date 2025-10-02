<?php
/**
 * Homepage Integration Hooks
 *
 * Handles integration with community pages via WordPress action hooks.
 * Provides the artist platform and support forum buttons for the forum homepage.
 *
 * @package ExtraChillArtistPlatform
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add homepage buttons to the extrachill_community_home_after_forums hook
 *
 * Displays the Support Forum and Artist Platform buttons on the community homepage
 * based on user login status and permissions.
 */
function ec_add_homepage_buttons() {
    ?>
    <div class="artist-platform-homepage-actions">
        <a href="/artists/extra-chill" class="button support-forum-btn">
            <?php esc_html_e( 'Support Forum', 'extrachill-artist-platform' ); ?>
        </a>

        <?php if ( is_user_logged_in() ) :
            // For logged-in users, show link to artist platform home
            $current_user = wp_get_current_user();
            $can_create_artists = ec_can_create_artist_profiles( $current_user->ID );

            if ( $can_create_artists ) :
                $user_artists = ec_get_user_owned_artists( $current_user->ID );

                // Always show "Artist Platform" regardless of user's artist count ?>
                <a href="<?php echo esc_url( get_page_link( get_page_by_path( 'artist-platform' ) ) ); ?>" class="button artist-platform-btn">
                    <?php esc_html_e( 'Artist Platform', 'extrachill-artist-platform' ); ?>
                </a>
            <?php endif;
        else :
            // For visitors, show join/sign up call to action ?>
            <a href="<?php echo esc_url( home_url( '/login/#tab-register?from_join=true' ) ); ?>" class="button artist-platform-btn">
                <?php esc_html_e( 'Join Artist Platform', 'extrachill-artist-platform' ); ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
}

// Hook into the community plugin's action
add_action( 'extrachill_community_home_after_forums', 'ec_add_homepage_buttons' );