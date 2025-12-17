<?php
/**
 * Your Artist Profiles Section
 *
 * Displays the user's artist profiles as minimal cards with action buttons.
 * Shows artist name and management options in a compact row layout.
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
    <div class="artist-cards-minimal">
        <?php
        foreach ( $user_artist_ids as $artist_id ) :
            $artist_post = get_post( $artist_id );
            if ( ! $artist_post ) {
                continue;
            }

            $artist_url = get_permalink( $artist_id );
            $profile_image_id = get_post_thumbnail_id( $artist_id );
            $profile_image_url = $profile_image_id ? wp_get_attachment_image_url( $profile_image_id, 'thumbnail' ) : '';
            $manage_artist_page = get_page_by_path( 'manage-artist' );
            $manage_link_page = get_page_by_path( 'manage-link-page' );
            $manage_artist_url = $manage_artist_page ? get_permalink( $manage_artist_page ) : '';
            $manage_link_url = $manage_link_page ? get_permalink( $manage_link_page ) : '';
            ?>
            <div class="artist-card-minimal">
                <div class="artist-card-minimal-info">
                    <?php if ( $profile_image_url ) : ?>
                        <img src="<?php echo esc_url( $profile_image_url ); ?>" 
                             alt="<?php echo esc_attr( $artist_post->post_title ); ?>" 
                             class="artist-card-minimal-avatar">
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $artist_url ); ?>" class="artist-card-minimal-name">
                        <?php echo esc_html( $artist_post->post_title ); ?>
                    </a>
                </div>
                <div class="artist-card-minimal-actions">
                    <a href="<?php echo esc_url( $artist_url ); ?>" class="button-1 button-small">
                        <?php esc_html_e( 'View Profile', 'extrachill-artist-platform' ); ?>
                    </a>
                    <?php if ( $manage_artist_url ) : ?>
                        <a href="<?php echo esc_url( $manage_artist_url ); ?>" class="button-2 button-small">
                            <?php esc_html_e( 'Manage Artist', 'extrachill-artist-platform' ); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ( $manage_link_url ) : ?>
                        <a href="<?php echo esc_url( $manage_link_url ); ?>" class="button-3 button-small">
                            <?php esc_html_e( 'Manage Link Page', 'extrachill-artist-platform' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php
        endforeach;
        ?>
    </div>
</div>
