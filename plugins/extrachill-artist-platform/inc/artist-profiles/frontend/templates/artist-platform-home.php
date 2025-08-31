<?php
/**
 * Template Name: Artist Platform Home
 * Description: Home page for the artist platform with dashboard functionality.
 */


get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="main-content">
        <?php do_action( 'extra_chill_before_main_content' ); ?>

        <div class="breadcrumb-notice-container">
            <?php
            // Add breadcrumbs
            if ( function_exists( 'extrachill_breadcrumbs' ) ) {
                extrachill_breadcrumbs();
            }
            ?>
        </div>

        <?php while ( have_posts() ) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="inside-article">
                    <header class="entry-header">
                        <h1 class="entry-title page-title"><?php the_title(); ?></h1>
                    </header><!-- .entry-header -->

                    <div class="entry-content" itemprop="text">
                        <?php
                        // Initialize user data
                        $current_user = wp_get_current_user();
                        $is_logged_in = is_user_logged_in();
                        $can_create_artists = ec_can_create_artist_profiles(get_current_user_id());
                        $user_artist_ids = get_user_meta($current_user->ID, '_artist_profile_ids', true);
                        $user_artist_ids = is_array($user_artist_ids) ? $user_artist_ids : array();
                        ?>

                        <?php if ( ! $is_logged_in ) : ?>
                            <!-- Not Logged In Section -->
                            <div class="artist-platform-welcome">
                                <div class="welcome-hero">
                                    <h2><?php _e('Welcome to the Artist Platform', 'extrachill-artist-platform'); ?></h2>
                                    <p><?php _e('Create your artist profile, build a custom link page, connect with fans, and manage your music career all in one place.', 'extrachill-artist-platform'); ?></p>
                                    
                                    <div class="welcome-actions">
                                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="button button-primary">
                                            <?php _e('Log In', 'extrachill-artist-platform'); ?>
                                        </a>
                                        <a href="<?php echo esc_url(home_url('/login/#tab-register')); ?>" class="button">
                                            <?php _e('Sign Up', 'extrachill-artist-platform'); ?>
                                        </a>
                                    </div>
                                </div>

                                <div class="featured-artists-section">
                                    <h3><?php _e('Active Artists', 'extrachill-artist-platform'); ?></h3>
                                    <?php bp_display_artist_cards_grid( 6, false ); // Show 6 artists, don't exclude any ?>
                                    
                                    <div class="view-all-artists">
                                        <a href="<?php echo esc_url( get_post_type_archive_link( 'artist_profile' ) ); ?>" class="button">
                                            <?php _e('View All Artists', 'extrachill-artist-platform'); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>

                        <?php elseif ( empty($user_artist_ids) ) : ?>
                            <!-- Logged In, No Artists -->
                            <div class="artist-platform-getting-started">
                                <div class="welcome-user">
                                    <h2><?php printf(__('Welcome, %s!', 'extrachill-artist-platform'), esc_html($current_user->display_name)); ?></h2>
                                    <p><?php _e('Ready to get started with your artist journey? Create your first artist profile to unlock all platform features.', 'extrachill-artist-platform'); ?></p>
                                </div>

                                <?php if ( $can_create_artists ) : ?>
                                    <div class="getting-started-actions">
                                        <div class="primary-action-card">
                                            <h3><?php _e('Create Your First Artist Profile', 'extrachill-artist-platform'); ?></h3>
                                            <p><?php _e('Start by creating your artist profile. You can always add more details later.', 'extrachill-artist-platform'); ?></p>
                                            <a href="<?php echo esc_url(home_url('/manage-artist-profiles/')); ?>" class="button button-primary button-large">
                                                <?php _e('Create Artist Profile', 'extrachill-artist-platform'); ?>
                                            </a>
                                        </div>
                                    </div>
                                <?php else : ?>
                                    <div class="bp-notice bp-notice-info">
                                        <p><?php _e('To create artist profiles, you need artist or professional membership. Contact us to upgrade your account.', 'extrachill-artist-platform'); ?></p>
                                    </div>
                                <?php endif; ?>

                                <div class="featured-artists-section">
                                    <h3><?php _e('Discover Artists', 'extrachill-artist-platform'); ?></h3>
                                    <?php bp_display_artist_cards_grid( 8, false ); // Show 8 artists, don't exclude any ?>
                                    
                                    <div class="view-all-artists">
                                        <a href="<?php echo esc_url( get_post_type_archive_link( 'artist_profile' ) ); ?>" class="button">
                                            <?php _e('View All Artists', 'extrachill-artist-platform'); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>

                        <?php else : ?>
                            <!-- Logged In, Has Artists - Dashboard -->
                            <div class="artist-platform-dashboard">
                                <div class="dashboard-welcome">
                                    <h2><?php printf(__('Welcome back, %s!', 'extrachill-artist-platform'), esc_html($current_user->display_name)); ?></h2>
                                    <p><?php printf(_n('Manage your artist profile and platform features below.', 'Manage your %d artist profiles and platform features below.', count($user_artist_ids), 'extrachill-artist-platform'), count($user_artist_ids)); ?></p>
                                </div>

                                <!-- Quick Actions -->
                                <div class="quick-actions-section">
                                    <h3><?php _e('Quick Actions', 'extrachill-artist-platform'); ?></h3>
                                    <div class="quick-actions-grid">
                                        <?php if ( $can_create_artists ) : ?>
                                            <div class="action-card">
                                                <h4><?php _e('Create New Artist Profile', 'extrachill-artist-platform'); ?></h4>
                                                <p><?php _e('Add another artist to your portfolio', 'extrachill-artist-platform'); ?></p>
                                                <a href="<?php echo esc_url(home_url('/manage-artist-profiles/')); ?>" class="button button-primary">
                                                    <?php _e('Create New', 'extrachill-artist-platform'); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="action-card">
                                            <h4><?php _e('Browse Artist Directory', 'extrachill-artist-platform'); ?></h4>
                                            <p><?php _e('Discover other artists in the community', 'extrachill-artist-platform'); ?></p>
                                            <a href="<?php echo esc_url(home_url('/artist-directory/')); ?>" class="button">
                                                <?php _e('Explore', 'extrachill-artist-platform'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Artist Profiles -->
                                <div class="artist-profiles-section">
                                    <h3><?php _e('Your Artist Profiles', 'extrachill-artist-platform'); ?></h3>
                                    <div class="artist-cards-grid">
                                        <?php foreach ( $user_artist_ids as $artist_id ) : 
                                            $artist_post = get_post($artist_id);
                                            if ( ! $artist_post ) continue;
                                            
                                            // Get artist data
                                            $artist_name = $artist_post->post_title;
                                            $artist_url = get_permalink($artist_id);
                                            $profile_image_id = get_post_meta($artist_id, '_artist_profile_image_id', true);
                                            $profile_image_url = $profile_image_id ? wp_get_attachment_image_url($profile_image_id, 'thumbnail') : '';
                                            $link_page_id = apply_filters('ec_get_link_page_id', $artist_id);
                                            
                                            // Get subscriber count
                                            global $wpdb;
                                            $subscriber_count = $wpdb->get_var($wpdb->prepare(
                                                "SELECT COUNT(*) FROM {$wpdb->prefix}artist_subscribers WHERE artist_id = %d",
                                                $artist_id
                                            ));
                                            $subscriber_count = (int) $subscriber_count;
                                        ?>
                                            <div class="artist-profile-card">
                                                <div class="artist-card-header">
                                                    <?php if ( $profile_image_url ) : ?>
                                                        <div class="artist-profile-image">
                                                            <img src="<?php echo esc_url($profile_image_url); ?>" alt="<?php echo esc_attr($artist_name); ?>" />
                                                        </div>
                                                    <?php endif; ?>
                                                    <h4 class="artist-name">
                                                        <a href="<?php echo esc_url($artist_url); ?>"><?php echo esc_html($artist_name); ?></a>
                                                    </h4>
                                                </div>
                                                
                                                <div class="artist-card-stats">
                                                    <div class="stat-item">
                                                        <strong><?php echo esc_html($subscriber_count); ?></strong>
                                                        <span><?php echo _n('Subscriber', 'Subscribers', $subscriber_count, 'extrachill-artist-platform'); ?></span>
                                                    </div>
                                                    <?php if ( $link_page_id ) : ?>
                                                        <div class="stat-item">
                                                            <span class="status-indicator active"><?php _e('Link Page Active', 'extrachill-artist-platform'); ?></span>
                                                        </div>
                                                    <?php else : ?>
                                                        <div class="stat-item">
                                                            <span class="status-indicator inactive"><?php _e('No Link Page', 'extrachill-artist-platform'); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="artist-card-actions">
                                                    <a href="<?php echo esc_url(add_query_arg('artist_id', $artist_id, home_url('/manage-artist-profiles/'))); ?>" class="button">
                                                        <?php _e('Manage Profile', 'extrachill-artist-platform'); ?>
                                                    </a>
                                                    <?php if ( $link_page_id ) : ?>
                                                        <a href="<?php echo esc_url(add_query_arg('artist_id', $artist_id, home_url('/manage-link-page/'))); ?>" class="button">
                                                            <?php _e('Manage Link Page', 'extrachill-artist-platform'); ?>
                                                        </a>
                                                    <?php else : ?>
                                                        <a href="<?php echo esc_url(add_query_arg('artist_id', $artist_id, home_url('/manage-link-page/'))); ?>" class="button button-primary">
                                                            <?php _e('Create Link Page', 'extrachill-artist-platform'); ?>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="<?php echo esc_url($artist_url); ?>" class="button button-secondary" target="_blank">
                                                        <?php _e('View Profile', 'extrachill-artist-platform'); ?>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Featured Artists from Community -->
                                <div class="featured-artists-section">
                                    <h3><?php _e('Discover Other Artists', 'extrachill-artist-platform'); ?></h3>
                                    <?php bp_display_artist_cards_grid( 12, true ); // Show 12 artists, exclude user's own ?>
                                    
                                    <div class="view-all-artists">
                                        <a href="<?php echo esc_url( get_post_type_archive_link( 'artist_profile' ) ); ?>" class="button">
                                            <?php _e('View All Artists', 'extrachill-artist-platform'); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div><!-- .entry-content -->
                </div><!-- .inside-article -->
            </article><!-- #post-## -->
        <?php endwhile; ?>

        <?php do_action( 'extra_chill_after_main_content' ); ?>
    </main><!-- #main -->
</div><!-- #primary -->

<?php 
do_action( 'extra_chill_after_primary_content_area' );
get_footer(); 
?>