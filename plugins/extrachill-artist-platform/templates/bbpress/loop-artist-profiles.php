<?php
/**
 * Custom bbPress template for artist profiles loop
 * 
 * This template replaces the standard bbPress loop-topics.php when viewing
 * the artist directory (Forum ID 5432). It displays artist_profile posts
 * using the established card styling system.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Query artist_profile posts for public display
$args = array(
    'post_type' => 'artist_profile',
    'post_status' => 'publish',
    'posts_per_page' => 12, // Reasonable limit for directory display
    'orderby' => 'date',
    'order' => 'DESC',
    'no_found_rows' => false, // Allow pagination
);

$artist_query = new WP_Query( $args );

if ( $artist_query->have_posts() ) : ?>

    <div class="artist-cards-grid">
        <?php while ( $artist_query->have_posts() ) : $artist_query->the_post(); 
            $artist_id = get_the_ID();
            $artist_name = get_the_title();
            $artist_url = get_permalink();
            $artist_bio = get_the_content();
            
            // Get artist meta data
            $profile_image_id = get_post_meta( $artist_id, '_artist_profile_image_id', true );
            $profile_image_url = $profile_image_id ? wp_get_attachment_image_url( $profile_image_id, 'thumbnail' ) : '';
            $genre = get_post_meta( $artist_id, '_genre', true );
            $local_city = get_post_meta( $artist_id, '_local_city', true );
            $link_page_id = get_post_meta( $artist_id, '_extrch_link_page_id', true );
            $forum_id = get_post_meta( $artist_id, '_artist_forum_id', true );
            
            // Get activity information
            $activity_timestamp = bp_get_artist_profile_last_activity_timestamp( $artist_id );
            $activity_date = date_i18n( get_option( 'date_format' ), $activity_timestamp );
        ?>
            <div class="artist-profile-card public-artist-card">
                <div class="artist-card-header">
                    <?php if ( $profile_image_url ) : ?>
                        <div class="artist-profile-image">
                            <a href="<?php echo esc_url( $artist_url ); ?>">
                                <img src="<?php echo esc_url( $profile_image_url ); ?>" alt="<?php echo esc_attr( $artist_name ); ?>" />
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="artist-card-info">
                        <h4 class="artist-name">
                            <a href="<?php echo esc_url( $artist_url ); ?>"><?php echo esc_html( $artist_name ); ?></a>
                        </h4>
                        
                        <?php if ( $genre || $local_city ) : ?>
                            <div class="artist-meta">
                                <?php if ( $genre ) : ?>
                                    <span class="artist-genre"><?php echo esc_html( $genre ); ?></span>
                                <?php endif; ?>
                                
                                <?php if ( $genre && $local_city ) : ?>
                                    <span class="meta-separator">•</span>
                                <?php endif; ?>
                                
                                <?php if ( $local_city ) : ?>
                                    <span class="artist-location"><?php echo esc_html( $local_city ); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ( $artist_bio ) : ?>
                    <div class="artist-card-bio">
                        <p><?php echo esc_html( wp_trim_words( $artist_bio, 15, '...' ) ); ?></p>
                    </div>
                <?php endif; ?>
                
                <div class="artist-card-stats">
                    <?php if ( $activity_date ) : ?>
                        <div class="stat-item">
                            <strong><?php _e( 'Last Active', 'extrachill-artist-platform' ); ?></strong>
                            <span><?php echo esc_html( $activity_date ); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ( $link_page_id ) : ?>
                        <div class="stat-item">
                            <span class="status-indicator active"><?php _e( 'Link Page', 'extrachill-artist-platform' ); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="artist-card-actions">
                    <a href="<?php echo esc_url( $artist_url ); ?>" class="button button-primary">
                        <?php _e( 'View Profile', 'extrachill-artist-platform' ); ?>
                    </a>
                    
                    <?php if ( $forum_id && function_exists( 'bbp_get_forum_permalink' ) ) : ?>
                        <a href="<?php echo esc_url( bbp_get_forum_permalink( $forum_id ) ); ?>" class="button">
                            <?php _e( 'Forum', 'extrachill-artist-platform' ); ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ( $link_page_id ) : ?>
                        <?php 
                        $link_page_post = get_post( $link_page_id );
                        if ( $link_page_post ) {
                            $link_page_slug = $link_page_post->post_name;
                            $link_page_url = 'https://extrachill.link/' . $link_page_slug;
                        ?>
                            <a href="<?php echo esc_url( $link_page_url ); ?>" class="button" target="_blank">
                                <?php _e( 'Links', 'extrachill-artist-platform' ); ?>
                            </a>
                        <?php } ?>
                    <?php endif; ?>
                </div>
                
                <?php 
                // Hook for additional card content
                do_action( 'extrachill_artist_card_after_content', array(
                    'id' => $artist_id,
                    'name' => $artist_name,
                    'url' => $artist_url,
                    'genre' => $genre,
                    'local_city' => $local_city,
                    'forum_id' => $forum_id,
                    'link_page_id' => $link_page_id,
                ) );
                ?>
            </div>
        <?php endwhile; ?>
    </div>

    <?php
    // Display pagination if needed
    if ( $artist_query->max_num_pages > 1 ) :
        $big = 999999999; // Need an unlikely integer
        echo '<div class="artist-directory-pagination">';
        echo paginate_links( array(
            'base' => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
            'format' => '?paged=%#%',
            'current' => max( 1, get_query_var( 'paged' ) ),
            'total' => $artist_query->max_num_pages,
            'prev_text' => '&laquo; ' . __( 'Previous', 'extrachill-artist-platform' ),
            'next_text' => __( 'Next', 'extrachill-artist-platform' ) . ' &raquo;'
        ) );
        echo '</div>';
    endif;
    ?>

    <?php wp_reset_postdata(); ?>

<?php else : ?>
    
    <div class="no-artists-found">
        <p><?php _e( 'No artists have joined the platform yet.', 'extrachill-artist-platform' ); ?></p>
        
        <?php if ( is_user_logged_in() && current_user_can( 'create_artist_profiles' ) ) : ?>
            <p>
                <a href="<?php echo esc_url( home_url( '/manage-artist-profiles/' ) ); ?>" class="button button-primary">
                    <?php _e( 'Create the First Artist Profile', 'extrachill-artist-platform' ); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

<?php endif; ?>