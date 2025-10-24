<?php
/**
 * Your Artist Profiles Section
 *
 * Displays the user's artist profiles in a grid.
 *
 * @package ExtraChillArtistPlatform
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get user artist IDs from parameters
$user_artist_ids = isset( $user_artist_ids ) ? $user_artist_ids : array();

if ( empty( $user_artist_ids ) ) {
    return;
}
?>

<!-- Artist Profiles -->
<div class="artist-profiles-section">
    <h3><?php esc_html_e( 'Your Artist Profiles', 'extrachill-artist-platform' ); ?></h3>
    <div class="artist-cards-grid">
        <?php
        foreach ( $user_artist_ids as $artist_id ) :
            $artist_post = get_post( $artist_id );
            if ( $artist_post ) :
                global $post;
                $post = $artist_post;
                setup_postdata( $post );
                include( EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/artist-card.php' );
                wp_reset_postdata();
            endif;
        endforeach;
        ?>
    </div>
</div>
