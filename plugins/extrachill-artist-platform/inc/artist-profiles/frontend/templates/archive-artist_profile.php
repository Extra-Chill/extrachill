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
    <div id="primary" class="content-area">
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
                        $user_artist_ids = get_user_meta( $current_user_id, '_artist_profile_ids', true );
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
                    
                    <div class="artist-profiles-grid">
                        
                        <?php while ( have_posts() ) : the_post(); ?>
                            
                            <?php
                            // Use the artist-profile-card component - check if class exists first
                            if ( class_exists( 'ExtraChillArtistPlatform_Templates' ) ) {
                                ExtraChillArtistPlatform_Templates::load_artist_profile_card( get_the_ID(), 'directory' );
                            } else {
                                // Fallback: load template directly
                                if ( defined( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR' ) ) {
                                    $template_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/components/artist-profile-card.php';
                                    if ( file_exists( $template_path ) ) {
                                        $artist_id = get_the_ID();
                                        $context = 'directory';
                                        include $template_path;
                                    }
                                } else {
                                    // Last resort: basic fallback display
                                    echo '<div class="artist-profile-card-fallback">';
                                    echo '<h4><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h4>';
                                    echo '<p>' . esc_html( get_the_excerpt() ) . '</p>';
                                    echo '</div>';
                                }
                            }
                            ?>
                            
                        <?php endwhile; ?>
                        
                    </div><!-- .artist-profiles-grid -->
                    
                    <?php
                    // Pagination
                    $pagination = paginate_links( array(
                        'prev_text' => __( '&laquo; Previous', 'extrachill-artist-platform' ),
                        'next_text' => __( 'Next &raquo;', 'extrachill-artist-platform' ),
                        'type'      => 'array'
                    ) );
                    
                    if ( $pagination ) : ?>
                        <nav class="pagination-wrapper" aria-label="<?php esc_attr_e( 'Artists pagination', 'extrachill-artist-platform' ); ?>">
                            <ul class="pagination">
                                <?php foreach ( $pagination as $page_link ) : ?>
                                    <li><?php echo $page_link; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </nav>
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
    </div><!-- #primary -->
</div><!-- .container -->

<?php get_footer(); ?>