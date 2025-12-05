<?php
/**
 * Template Name: Artist Directory
 * 
 * Public directory page for browsing all artist profiles
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

get_header(); ?>

<div class="container">
    <div class="main-content">
        <main id="main" class="site-main">

            <div class="entry-content">
                
                <div class="artist-directory-header">
                    <h1 class="page-title">Artist Directory</h1>
                    <p class="page-description">
                        Discover amazing artists, connect with them, and join their community discussions.
                    </p>
                    
                    <?php if ( is_user_logged_in() ) : ?>
                        <?php 
                        $current_user_id = get_current_user_id();
                        $user_artist_ids = ec_get_artists_for_user( $current_user_id );
                        $artist_count    = count( $user_artist_ids );
                        
                        if ( $artist_count > 0 ) :
                            $latest_artist_id = ec_get_latest_artist_for_user( $current_user_id );
                            $manage_url       = home_url( '/manage-artist-profiles/?artist_id=' . $latest_artist_id );
                            $artist_label     = $artist_count === 1
                                ? esc_html__( 'Manage Artist', 'extrachill-artist-platform' )
                                : esc_html__( 'Manage Artists', 'extrachill-artist-platform' );
                        ?>
                            <div class="artist-directory-actions">
                                <a href="<?php echo esc_url( $manage_url ); ?>" class="button-2 button-medium">
                                    <?php echo $artist_label; ?>
                                </a>
                            </div>
                        <?php elseif ( ec_can_create_artist_profiles( $current_user_id ) ) : ?>
                            <div class="artist-directory-actions">
                                <a href="<?php echo esc_url( home_url( '/manage-artist-profiles/' ) ); ?>" class="button-2 button-medium">
                                    <?php esc_html_e( 'Create Artist Profile', 'extrachill-artist-platform' ); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Load the artist profiles loop (same as used on Forum 5432) -->
                <div id="bbpress-forums" class="bbpress-wrapper artist-directory-wrapper">
                    <?php bbp_get_template_part( 'loop', 'artist-profiles' ); ?>
                </div>

            </div><!-- .entry-content -->

        </main><!-- #main -->
    </div><!-- .main-content -->
</div><!-- .container -->

<?php get_footer(); ?> 