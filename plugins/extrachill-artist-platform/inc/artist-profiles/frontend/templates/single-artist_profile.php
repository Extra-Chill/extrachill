<?php
/**
 * The Template for displaying all single Artist Profiles.
 */

get_header(); ?>

    <div class="main-content">
        <main id="main" class="site-main">
            <?php
            /**
             * Custom hook for before main content.
             */
            do_action( 'extra_chill_before_main_content' );

            // --- Display Breadcrumbs ---
            // Moved to be a direct child of <main>, before the while loop and <article>
            if ( function_exists( 'extrachill_breadcrumbs' ) ) {
                extrachill_breadcrumbs();
            }
            // --- End Breadcrumbs ---

            // --- Main Post Loop ---
            while ( have_posts() ) : the_post(); 

                $artist_profile_id = get_the_ID();

                // Get additional artist meta
                $genre = get_post_meta( $artist_profile_id, '_genre', true );
                $local_city = get_post_meta( $artist_profile_id, '_local_city', true );
                $website_url = get_post_meta( $artist_profile_id, '_website_url', true );
                $spotify_url = get_post_meta( $artist_profile_id, '_spotify_url', true );
                $apple_music_url = get_post_meta( $artist_profile_id, '_apple_music_url', true );
                $bandcamp_url = get_post_meta( $artist_profile_id, '_bandcamp_url', true );

                // --- Get Social Links --- 
                $artist_profile_social_links = get_post_meta( $artist_profile_id, '_artist_profile_social_links', true );
                if ( ! is_array( $artist_profile_social_links ) ) {
                    $artist_profile_social_links = array();
                }

            ?>

                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?> >
                    <div class="inside-article">
                        <?php
                        // --- Display Invitation Acceptance/Error Messages ---
                        if ( isset( $_GET['invite_accepted'] ) && $_GET['invite_accepted'] === '1' ) {
                            $invite_success_message = __( 'Invitation accepted! You are now a member of this artist.', 'extrachill-artist-platform' );
                            if ( isset( $_GET['invite_warning'] ) && $_GET['invite_warning'] === 'cleanup_failed' ) {
                                $invite_success_message .= ' ' . __( '(A small cleanup task for the invitation record encountered an issue, but your membership is confirmed. Please contact an admin if you notice any problems.)', 'extrachill-artist-platform' );
                            }
                            echo '<div class="artist-notice artist-notice-success">';
                            echo '<p>' . esc_html( $invite_success_message ) . '</p>';
                            echo '</div>';
                        } elseif ( isset( $_GET['invite_error'] ) ) {
                            $error_code = sanitize_key( $_GET['invite_error'] );
                            $invite_error_message = '';
                            switch ( $error_code ) {
                                case 'invalid_token':
                                    $invite_error_message = __( 'The invitation link is invalid or has expired. Please request a new invitation.', 'extrachill-artist-platform' );
                                    break;
                                case 'not_artist':
                                    $invite_error_message = __( 'Your account is not recognized as an artist account. Please contact support if you believe this is an error.', 'extrachill-artist-platform' );
                                    break;
                                case 'membership_failed':
                                    $invite_error_message = __( 'There was an error adding you to the artist. Please try again or contact support.', 'extrachill-artist-platform' );
                                    break;
                                default:
                                    $invite_error_message = __( 'An unknown error occurred while processing your invitation.', 'extrachill-artist-platform' );
                            }
                            if ( ! empty( $invite_error_message ) ) {
                                echo '<div class="artist-notice artist-notice-error">';
                                echo '<p>' . esc_html( $invite_error_message ) . '</p>';
                                echo '</div>';
                            }
                        }

                        // Removed default generate_before_content hook to place header manually
                        ?>

                        <?php 
                        // Prepare for Hero Section
                        $hero_background_style = '';
                        $header_image_id = get_post_meta( $artist_profile_id, '_artist_profile_header_image_id', true );

                        if ( $header_image_id ) {
                            $image_url = wp_get_attachment_image_url( $header_image_id, 'full' ); // Or 'large', depending on desired size
                            if ( $image_url ) {
                                $hero_background_style = 'style="background-image: url(\'' . esc_url( $image_url ) . '\');"';
                            }
                        } else {
                            // Fallback for no specific header image; class-based styling can apply
                            $hero_background_style = ''; 
                        }
                        ?>

                        <div class="artist-profile-header artist-hero" <?php echo $hero_background_style; ?>>
                            <div class="artist-hero-overlay"></div>
                            <div class="artist-hero-content">
                                <?php
                                // --- Manage Artist Button (Moved to top of hero content) ---
                                if ( is_user_logged_in() ) :
                                    // Display "Manage Artist Profile" button if the current user can manage members for this artist profile.
                                    // This uses the custom capability 'manage_artist_members'.
                                    if ( ec_can_manage_artist( get_current_user_id(), $artist_profile_id ) ) :
                                        $manage_artist_url = get_permalink( get_page_by_path( 'manage-artist-profiles' ) );
                                        if ( $manage_artist_url ) {
                                            $manage_artist_url_with_id = add_query_arg( 'artist_id', $artist_profile_id, $manage_artist_url );
                                            echo '<div class="artist-profile-actions">';
                                            echo '<a href="' . esc_url( $manage_artist_url_with_id ) . '" class="button-2 button-medium artist-manage-button">Manage Artist</a>';
                                            echo '</div>';
                                        }
                                    endif;
                                endif;
                                // --- End Manage Artist Button (Moved) ---
                                ?>
                                <?php // Display Profile Picture and then Title/Meta in a flex row
                                // The artist-hero-top-row container helps to align the image and the text content side-by-side.
                                ?>
                                <div class="artist-hero-top-row">
                                    <?php if ( has_post_thumbnail( $artist_profile_id ) ) : ?>
                                        <div class="artist-profile-featured-image">
                                            <?php echo get_the_post_thumbnail( $artist_profile_id, 'medium' ); // This is the actual profile picture ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="artist-hero-text-content">
                                        <h1 class="artist-hero-title" itemprop="headline"><?php echo esc_html( get_the_title( $artist_profile_id ) ); ?></h1>

                                        <?php if ( ! empty( $genre ) || ! empty( $local_city ) ) : ?>
                                            <p class="artist-meta-info">
                                                <?php if ( ! empty( $genre ) ) : ?>
                                                    <span class="artist-genre"><strong><?php esc_html_e( 'Genre:', 'extrachill-artist-platform' ); ?></strong> <?php echo esc_html( $genre ); ?></span>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $genre ) && ! empty( $local_city ) ) : ?>
                                                    <span class="artist-meta-separator">|</span>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $local_city ) ) : ?>
                                                    <span class="artist-local-scene"><strong><?php esc_html_e( 'Local Scene:', 'extrachill-artist-platform' ); ?></strong> <?php echo esc_html( $local_city ); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php 
                                // Use centralized social links rendering
                                $social_manager = extrachill_artist_platform_social_links();
                                echo $social_manager->render_social_icons( $artist_profile_id, array(
                                    'container_class' => 'artist-social-links',
                                    'icon_class' => 'extrch-social-icon'
                                ) );
                                ?>

                                <?php 
                                // --- Artist Follow Button & Count --- 
                                if ( function_exists('bp_get_artist_follower_count') && function_exists('bp_is_user_following_artist') ) : 
                                    $follower_count = bp_get_artist_follower_count( $artist_profile_id );
                                    $is_following = is_user_logged_in() ? bp_is_user_following_artist( get_current_user_id(), $artist_profile_id ) : false;
                                    $follow_button_text = $is_following ? __( 'Following', 'extrachill-artist-platform' ) : __( 'Follow', 'extrachill-artist-platform' );
                                    $follow_button_action = $is_following ? 'unfollow' : 'follow';
                                ?>
                                <div class="artist-follow-section">
                                    <span class="artist-follower-count" id="artist-follower-count-<?php echo esc_attr($artist_profile_id); ?>">
                                        <?php echo sprintf( _n( '%s follower', '%s followers', $follower_count, 'extrachill-artist-platform' ), number_format_i18n( $follower_count ) ); ?>
                                    </span>
                                    <?php if ( is_user_logged_in() ) : ?>
                                        <?php 
                                        // Check if the artist profile being viewed belongs to the current user - CANNOT FOLLOW OWN ARTIST
                                        $is_own_artist = false;
                                        $user_artists = ec_get_artists_for_user( get_current_user_id() );
                                        if ( in_array($artist_profile_id, $user_artists) ) {
                                            $is_own_artist = true;
                                        }
                                        ?>
                                        <?php if ( ! $is_own_artist ): ?>
                                            <button type="button" class="button-2 button-small artist-follow-button" 
                                                    data-action="<?php echo esc_attr( $follow_button_action ); ?>" 
                                                    data-artist-id="<?php echo esc_attr( $artist_profile_id ); ?>">
                                                <?php echo esc_html( $follow_button_text ); ?>
                                            </button>
                                        <?php else: ?>
                                            <?php // Optionally show a disabled button or nothing if it's their own artist ?>
                                            <span class="artist-own-indicator">(<?php esc_html_e('Your Artist', 'extrachill-artist-platform'); ?>)</span>
                                        <?php endif; ?>
                                    <?php else : // User not logged in ?>
                                        <a href="<?php echo esc_url( site_url('/login') ); ?>" class="button-2 button-small artist-follow-login-button">
                                            <?php esc_html_e( 'Follow', 'extrachill-artist-platform' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; // End check for functions exist ?>
                                <?php // --- End Artist Follow Button --- ?>

                                <?php
                                // --- Display Artist Link Page URL (New) ---
                                $link_page_id_for_url_display = apply_filters('ec_get_link_page_id', $artist_profile_id);
                                if ( $link_page_id_for_url_display && get_post_type( $link_page_id_for_url_display ) === 'artist_link_page' ) {
                                    global $post; // Ensure $post is the artist_profile CPT object
                                    if ( isset( $post ) && $post->post_type === 'artist_profile' ) {
                                        $artist_slug_for_url = $post->post_name;
                                        $public_url_href = ''; 
                                        $public_url_display_text = '';

                                        if ( defined('EXTRCH_LINKPAGE_DEV') && EXTRCH_LINKPAGE_DEV ) {
                                            $public_url_href = get_permalink( $link_page_id_for_url_display );
                                        } else {
                                            // Use canonical extrachill.link URL
                                            $public_url_href = 'https://extrachill.link/' . $artist_slug_for_url;
                                        }

                                        if ( ! empty( $public_url_href ) ) {
                                            $public_url_display_text = preg_replace( '#^https?://#', '', $public_url_href );

                                            echo '<div class="artist-public-link-display">';
                                            echo '<a href="' . esc_url( $public_url_href ) . '" class="button-3 button-medium" rel="noopener">' . esc_html( $public_url_display_text ) . '</a>';
                                            echo '</div>';
                                        }
                                    }
                                }
                                // --- End Display Artist Link Page URL (New) ---
                                ?>

                                <?php /* Moved edit button container 
                                <?php if ( current_user_can( 'edit_post', $artist_profile_id ) ) : ?>
                                    <?php 
                                    $manage_page = get_page_by_path( 'manage-artist-profiles' ); // Corrected slug
                                    if ( $manage_page ) { // Check if page exists before using its ID
                                        $edit_url = add_query_arg( 'artist_id', $artist_profile_id, get_permalink( $manage_page->ID ) );
                                        echo '<p class="artist-edit-link"><a href="' . esc_url( $edit_url ) . '" class="button-2 button-small">' . __( 'Edit Artist Profile', 'extrachill-artist-platform' ) . '</a></p>';
                                    }
                                    ?>
                                <?php endif; ?>
                                */ ?>
                            </div>
                        </div>

                        <?php
                        /**
                         * generate_after_entry_title hook.
                         *
                         * @since 0.1
                         * @hooked generate_post_meta - 10
                         */
                        // do_action( 'generate_after_entry_title' ); // Maybe hide default meta?
                        ?>

                        <div class="entry-content" itemprop="text">
                            <?php
                            // --- Artist Bio Section ---
                            $artist_name = get_the_title( $artist_profile_id );
                            $artist_bio = get_post_field( 'post_content', $artist_profile_id );
                            
                            echo '<div class="artist-bio-section">';
                            echo '<h2 class="section-title">' . esc_html( sprintf( __( 'About %s', 'extrachill-artist-platform' ), $artist_name ) ) . '</h2>';
                            if ( ! empty( $artist_bio ) ) {
                                echo '<div class="artist-bio">';
                                echo wpautop( $artist_bio );
                                echo '</div>';
                            } else {
                                echo '<p>' . __( 'No biography available yet.', 'extrachill-artist-platform' ) . '</p>';
                            }
                            echo '</div>'; // .artist-bio-section

                            // --- Display Blog Coverage Button ---
                            if ( function_exists( 'extrachill_artist_display_blog_coverage_button' ) ) {
                                global $post;
                                if ( isset( $post ) && $post->post_type === 'artist_profile' && ! empty( $post->post_name ) ) {
                                    extrachill_artist_display_blog_coverage_button( $post->post_name );
                                }
                            }
                            // --- End Blog Coverage Button ---
                            ?>
                        </div><!-- .entry-content -->

                        <?php
                        /**
                         * generate_after_content hook.
                         *
                         * @since 0.1
                         */
                        do_action( 'extra_chill_after_content' );
                        ?>
                    </div><!-- .inside-article -->
                </article><!-- #post-## -->

            <?php endwhile; // end of the loop. ?>

            <?php
            /**
             * generate_after_main_content hook.
             *
             * @since 0.1
             */
            do_action( 'extra_chill_after_main_content' );
            ?>
        </main><!-- #main -->
    </div><!-- .main-content -->

<?php
/**
 * generate_after_primary_content_area hook.
 *
 * @since 2.0
 */
do_action( 'extra_chill_after_primary_content_area' );

get_footer(); 