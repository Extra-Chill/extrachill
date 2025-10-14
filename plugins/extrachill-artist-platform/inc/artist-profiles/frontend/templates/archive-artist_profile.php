<?php
/**
 * Archive template for artist_profile CPT
 * 
 * Displays artist profiles using WordPress Loop with pagination
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

get_header(); ?>

<div class="container">
    <div class="main-content">
        <main id="main" class="site-main">

            <?php
            // Display breadcrumbs
            if ( function_exists( 'extrachill_breadcrumbs' ) ) {
                extrachill_breadcrumbs();
            }
            ?>

            <div class="entry-content">

                <div class="artist-directory-header">
                    <h1 class="page-title"><?php esc_html_e( 'Artists', 'extrachill-artist-platform' ); ?></h1>
                    <p class="page-description">
                        <?php esc_html_e( 'Discover amazing artists, connect with them, and join their community discussions.', 'extrachill-artist-platform' ); ?>
                    </p>
                    
                    <?php if ( is_user_logged_in() ) : ?>
                        <?php 
                        $current_user_id = get_current_user_id();
                        $user_artist_ids = ec_get_user_accessible_artists( $current_user_id );
                        $is_artist_or_pro = ( get_user_meta( $current_user_id, 'user_is_artist', true ) === '1' || 
                                              get_user_meta( $current_user_id, 'user_is_professional', true ) === '1' );
                        
                        if ( !empty($user_artist_ids) || $is_artist_or_pro ) : ?>
                            <div class="artist-directory-actions">
                                <a href="<?php echo esc_url( home_url('/manage-artist-profiles/') ); ?>" class="button-2 button-medium">
                                    <?php echo !empty($user_artist_ids) ? esc_html__( 'Manage My Artists', 'extrachill-artist-platform' ) : esc_html__( 'Create Artist Profile', 'extrachill-artist-platform' ); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ( have_posts() ) : ?>

                    <div class="artist-cards-grid">

                        <?php while ( have_posts() ) : the_post(); ?>

                            <?php include( EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/artist-card.php' ); ?>

                        <?php endwhile; ?>

                    </div><!-- .artist-cards-grid -->

                    <?php
                    // Centralized pagination system
                    extrachill_pagination( null, 'artist-directory' );
                    ?>
                    
                <?php else : ?>
                    
                    <div class="no-artists-found">
                        <h2><?php esc_html_e( 'No Artists Found', 'extrachill-artist-platform' ); ?></h2>
                        <p><?php esc_html_e( 'No artists have joined the platform yet.', 'extrachill-artist-platform' ); ?></p>
                        
                        <?php if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) : ?>
                            <p>
                                <a href="<?php echo esc_url( home_url( '/manage-artist-profiles/' ) ); ?>" class="button-2 button-medium">
                                    <?php esc_html_e( 'Create the First Artist Profile', 'extrachill-artist-platform' ); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                <?php endif; ?>

            </div><!-- .entry-content -->

        </main><!-- #main -->
    </div><!-- .main-content -->
</div><!-- .container -->

<?php get_footer(); ?>