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
                                <a href="<?php echo esc_url( home_url('/manage-artist-profiles/') ); ?>" class="button">
                                    <?php echo !empty($user_artist_ids) ? esc_html__( 'Manage My Artists', 'extrachill-artist-platform' ) : esc_html__( 'Create Artist Profile', 'extrachill-artist-platform' ); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ( have_posts() ) : ?>
                    
                    <div class="artist-cards-grid">
                        
                        <?php while ( have_posts() ) : the_post(); ?>
                            
                            <?php
                            // Use the artist-profile-card component via unified template system
                            echo ec_render_template( 'artist-profile-card', array(
                                'artist_id' => get_the_ID(),
                                'context' => 'directory'
                            ) );
                            ?>
                            
                        <?php endwhile; ?>
                        
                    </div><!-- .artist-cards-grid -->
                    
                    <?php
                    // Theme-consistent pagination using WordPress native .page-numbers classes
                    $pagination_links = paginate_links( array(
                        'prev_text' => __( '&laquo; Previous', 'extrachill-artist-platform' ),
                        'next_text' => __( 'Next &raquo;', 'extrachill-artist-platform' ),
                        'type'      => 'plain'
                    ) );
                    
                    if ( $pagination_links ) : ?>
                        <div class="bbp-pagination">
                            <div class="bbp-pagination-count">
                                <?php
                                global $wp_query;
                                $total = $wp_query->found_posts;
                                $paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
                                $per_page = get_query_var( 'posts_per_page' );
                                $start = ( $paged - 1 ) * $per_page + 1;
                                $end = min( $paged * $per_page, $total );
                                
                                printf( 
                                    _n( 
                                        'Viewing %1$d artist', 
                                        'Viewing %1$d to %2$d (of %3$d total)', 
                                        $total, 
                                        'extrachill-artist-platform' 
                                    ),
                                    $total == 1 ? 1 : $start,
                                    $end,
                                    $total
                                );
                                ?>
                            </div>
                            <div class="bbp-pagination-links">
                                <?php echo $pagination_links; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else : ?>
                    
                    <div class="no-artists-found">
                        <h2><?php esc_html_e( 'No Artists Found', 'extrachill-artist-platform' ); ?></h2>
                        <p><?php esc_html_e( 'No artists have joined the platform yet.', 'extrachill-artist-platform' ); ?></p>
                        
                        <?php if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) : ?>
                            <p>
                                <a href="<?php echo esc_url( home_url( '/manage-artist-profiles/' ) ); ?>" class="button button-primary">
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