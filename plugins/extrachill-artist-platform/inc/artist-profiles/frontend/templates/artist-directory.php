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
    <div id="primary" class="content-area">
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
                        $user_artist_ids = ec_get_user_accessible_artists( $current_user_id );
                        $is_artist_or_pro = ( get_user_meta( $current_user_id, 'user_is_artist', true ) === '1' || 
                                              get_user_meta( $current_user_id, 'user_is_professional', true ) === '1' );
                        
                        if ( !empty($user_artist_ids) || $is_artist_or_pro ) : ?>
                            <div class="artist-directory-actions">
                                <a href="<?php echo home_url('/manage-artist-profiles/'); ?>" class="button">
                                    <?php echo !empty($user_artist_ids) ? 'Manage My Artists' : 'Create Artist Profile'; ?>
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
    </div><!-- #primary -->
</div><!-- .container -->

<?php get_footer(); ?> 