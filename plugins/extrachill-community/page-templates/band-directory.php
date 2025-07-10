<?php
/**
 * Template Name: Band Directory
 * 
 * Public directory page for browsing all band profiles
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

get_header(); ?>

<div class="container">
    <div id="primary" class="content-area">
        <main id="main" class="site-main">

            <div class="entry-content">
                
                <div class="band-directory-header">
                    <h1 class="page-title">Band Directory</h1>
                    <p class="page-description">
                        Discover amazing bands, connect with artists, and join their community discussions.
                    </p>
                    
                    <?php if ( is_user_logged_in() ) : ?>
                        <?php 
                        $current_user_id = get_current_user_id();
                        $user_band_ids = get_user_meta( $current_user_id, '_band_profile_ids', true );
                        $is_artist_or_pro = ( get_user_meta( $current_user_id, 'user_is_artist', true ) === '1' || 
                                              get_user_meta( $current_user_id, 'user_is_professional', true ) === '1' );
                        
                        if ( !empty($user_band_ids) || $is_artist_or_pro ) : ?>
                            <div class="band-directory-actions">
                                <a href="<?php echo home_url('/manage-band-profiles/'); ?>" class="button">
                                    <?php echo !empty($user_band_ids) ? 'Manage My Bands' : 'Create Band Profile'; ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Load the band profiles loop (same as used on Forum 5432) -->
                <div id="bbpress-forums" class="bbpress-wrapper band-directory-wrapper">
                    <?php bbp_get_template_part( 'loop', 'band-profiles' ); ?>
                </div>

            </div><!-- .entry-content -->

        </main><!-- #main -->
    </div><!-- #primary -->
</div><!-- .container -->

<?php get_footer(); ?> 