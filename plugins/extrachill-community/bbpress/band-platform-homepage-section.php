<?php
/**
 * Band Platform Homepage Section
 * 
 * Displays active bands on the forum homepage to encourage discovery and engagement
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Query for active band profiles
$band_args = array(
    'post_type'      => 'band_profile',
    'post_status'    => 'publish',
    'posts_per_page' => 8, // Show 8 bands on homepage
    'orderby'        => 'date', // Most recent bands first
    'order'          => 'DESC'
);

$bands_query = new WP_Query( $band_args );

// Sort bands by forum activity using existing function
if ( $bands_query->have_posts() ) {
    $bands = $bands_query->posts;
    
    // Sort by forum activity timestamp (most recent first)
    usort( $bands, function( $a, $b ) {
        $activity_a = function_exists('bp_get_band_profile_last_activity_timestamp') 
                     ? bp_get_band_profile_last_activity_timestamp( $a->ID ) 
                     : get_post_modified_time('U', false, $a->ID);
        $activity_b = function_exists('bp_get_band_profile_last_activity_timestamp') 
                     ? bp_get_band_profile_last_activity_timestamp( $b->ID ) 
                     : get_post_modified_time('U', false, $b->ID);
        
        $activity_a = $activity_a ?: 0;
        $activity_b = $activity_b ?: 0;
        
        return $activity_b - $activity_a; // Descending order (most recent first)
    });
    
    // Update the query's posts with sorted array
    $bands_query->posts = $bands;
}

if ( $bands_query->have_posts() ) : ?>

    <span class="forum-title-with-icon">
        <h2 class="forum-front-ec">
            <i class="fa-solid fa-users-line" style="margin-right: 8px;"></i>
            Band Platform
        </h2>
        <i id="band-platform-collapse" class="fa-solid fa-square-minus" onclick="toggleForumCollapse(this, 'band-platform-container')"></i>
    </span>
    
    <p style="margin:15px 0;">Discover active bands and join their community discussions. 
       <a href="<?php echo bbp_get_forum_permalink(5432); ?>" style="font-weight: 500;">View all bands »</a>
    </p>
    
    <div class="band-platform-container">
        <div class="band-platform-homepage-grid">
            <?php while ( $bands_query->have_posts() ) : $bands_query->the_post(); ?>
                <?php 
                $band_id = get_the_ID();
                $band_forum_id = get_post_meta( $band_id, '_band_forum_id', true );
                $view_count = get_post_meta( $band_id, '_band_profile_view_count', true );
                $view_count = $view_count ? intval($view_count) : 0;
                
                // Get some basic info
                $genre = get_post_meta( $band_id, '_genre', true );
                $city = get_post_meta( $band_id, '_local_city', true );
                
                
                // Get latest forum topic
                $latest_topic_title = '';
                $latest_topic_url = '';
                if ( $band_forum_id ) {
                    $latest_topic_args = array(
                        'post_type' => bbp_get_topic_post_type(),
                        'posts_per_page' => 1,
                        'orderby' => 'date',
                        'order' => 'DESC',
                        'post_status' => array('publish', 'closed'),
                        'meta_query' => array(
                            array(
                                'key' => '_bbp_forum_id',
                                'value' => $band_forum_id,
                                'compare' => '='
                            )
                        ),
                        'no_found_rows' => true,
                        'update_post_term_cache' => false,
                        'update_post_meta_cache' => false,
                    );
                    
                    $latest_topic_query = new WP_Query( $latest_topic_args );
                    if ( $latest_topic_query->have_posts() ) {
                        $latest_topic_query->the_post();
                        $latest_topic_title = get_the_title();
                        $latest_topic_url = get_the_permalink();
                        wp_reset_postdata();
                    }
                }
                ?>
                
                <div class="band-homepage-card">
                    <a href="<?php echo esc_url( get_permalink( $band_id ) ); ?>" class="band-homepage-card-link">
                        
                        <?php if ( has_post_thumbnail( $band_id ) ) : ?>
                            <div class="band-homepage-avatar">
                                <?php echo get_the_post_thumbnail( $band_id, 'thumbnail', array('class' => 'band-homepage-pic') ); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="band-homepage-info">
                            <h4 class="band-homepage-name"><?php echo esc_html( get_the_title( $band_id ) ); ?></h4>
                            
                            <?php if ( $genre ) : ?>
                                <p class="band-homepage-genre"><?php echo esc_html( $genre ); ?></p>
                            <?php endif; ?>
                            
                            
                            <?php if ( $view_count > 0 ) : ?>
                                <p class="band-homepage-views">
                                    <i class="fa-regular fa-eye"></i>
                                    <?php echo number_format( $view_count ); ?> views
                                </p>
                            <?php endif; ?>
                        </div>
                        
                    </a>
                    
                    <?php if ( !empty( $latest_topic_title ) ) : ?>
                        <div class="band-homepage-latest-topic-section">
                            <div class="band-homepage-latest-topic">
                                <i class="fa-regular fa-comment"></i>
                                <a href="<?php echo esc_url( $latest_topic_url ); ?>" class="latest-topic-link">
                                    <?php echo esc_html( $latest_topic_title ); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php endwhile; ?>
        </div>
        
        <div class="band-platform-homepage-footer">
            <a href="<?php echo bbp_get_forum_permalink(5432); ?>" class="button band-platform-view-all">
                View All Bands
            </a>
            
            <?php if ( is_user_logged_in() ) : ?>
                <?php 
                $current_user_id = get_current_user_id();
                $user_band_ids = get_user_meta( $current_user_id, '_band_profile_ids', true );
                $user_band_ids = !empty($user_band_ids) && is_array($user_band_ids) ? $user_band_ids : array();
                $is_artist_or_pro = ( get_user_meta( $current_user_id, 'user_is_artist', true ) === '1' || 
                                      get_user_meta( $current_user_id, 'user_is_professional', true ) === '1' );
                
                // Find the most recently modified band
                $latest_band_id = 0;
                if ( !empty($user_band_ids) ) {
                    $latest_modified_timestamp = 0;
                    foreach ( $user_band_ids as $band_id ) {
                        $band_id_int = absint($band_id);
                        if ( $band_id_int > 0 ) {
                            $post_modified_gmt = get_post_field( 'post_modified_gmt', $band_id_int, 'raw' );
                            if ( $post_modified_gmt ) {
                                $current_timestamp = strtotime( $post_modified_gmt );
                                if ( $current_timestamp > $latest_modified_timestamp ) {
                                    $latest_modified_timestamp = $current_timestamp;
                                    $latest_band_id = $band_id_int;
                                }
                            }
                        }
                    }
                }
                
                if ( !empty($user_band_ids) || $is_artist_or_pro ) : 
                    $manage_bands_url = home_url('/manage-band-profiles/');
                    if ( $latest_band_id > 0 ) {
                        $manage_bands_url = add_query_arg( 'band_id', $latest_band_id, $manage_bands_url );
                    }
                ?>
                    <?php if ( !empty($user_band_ids) ) : ?>
                        <!-- User has bands - show manage button -->
                        <a href="<?php echo esc_url( $manage_bands_url ); ?>" class="button band-platform-manage">
                            Manage My Bands
                        </a>
                    <?php elseif ( $is_artist_or_pro ) : ?>
                        <!-- Artist/Pro with no bands - show creation button with link-in-bio mention -->
                        <a href="<?php echo esc_url( $manage_bands_url ); ?>" class="button band-platform-manage" title="Create your band profile and get access to extrachill.link - our free link-in-bio tool">
                            Create Band Profile + Free Link Page
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

<?php endif; 

wp_reset_postdata(); ?> 