<?php
/**
 * Homepage Artist Card Actions
 *
 * Adds management buttons to artist cards when the current user
 * has permission to manage the artist profile.
 *
 * Hooks into: ec_artist_card_actions
 *
 * @package ExtraChillArtistPlatform
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add management buttons to artist cards for authorized users
 *
 * Displays "Manage Artist Profile" and "Manage Link Page" buttons
 * when the current user has permission to manage the artist.
 *
 * @param int $artist_id The artist profile post ID
 */
function ec_add_artist_card_management_buttons( $artist_id ) {
    // Only show for logged-in users
    if ( ! is_user_logged_in() ) {
        return;
    }

    // Check if user can manage this artist
    if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
        return;
    }

    // Get management URLs
    $manage_artist_page = get_page_by_path( 'manage-artist-profiles' );
    $manage_link_page = get_page_by_path( 'manage-link-page' );

    // Build URLs with artist_id parameter
    $manage_artist_url = $manage_artist_page ?
        add_query_arg( 'artist_id', $artist_id, get_permalink( $manage_artist_page ) ) :
        '';

    $manage_link_url = $manage_link_page ?
        add_query_arg( 'artist_id', $artist_id, get_permalink( $manage_link_page ) ) :
        '';

    // Output management buttons
    ?>
    <?php if ( $manage_artist_url ) : ?>
        <a href="<?php echo esc_url( $manage_artist_url ); ?>" class="button-2 button-medium" data-action-button>
            <?php esc_html_e( 'Manage Artist Profile', 'extrachill-artist-platform' ); ?>
        </a>
    <?php endif; ?>

    <?php if ( $manage_link_url ) : ?>
        <a href="<?php echo esc_url( $manage_link_url ); ?>" class="button-2 button-medium" data-action-button>
            <?php esc_html_e( 'Manage Link Page', 'extrachill-artist-platform' ); ?>
        </a>
    <?php endif; ?>
    <?php
}
add_action( 'ec_artist_card_actions', 'ec_add_artist_card_management_buttons', 10, 1 );
