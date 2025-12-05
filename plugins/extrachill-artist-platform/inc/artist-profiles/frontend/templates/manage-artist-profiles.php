<?php
/**
 * Template Name: Manage Artist Profile
 * Description: A page template for users to create or edit an artist profile.
 */

// Manage Artist Profile Template

get_header(); ?>

	<div class="main-content">
		<main id="main" class="site-main">
			<?php do_action( 'extrachill_before_body_content' ); ?>

            <?php extrachill_breadcrumbs(); ?>

            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="inside-article">
                    <header>
                        <?php // Display appropriate title based on mode - will be set later ?>
                    </header>

                    <div class="entry-content" itemprop="text">
                        <?php
                        // Initialize variables
                        $artist_post_title = '';
                        $artist_post_content = '';
                        $current_local_city = '';
                        $current_genre = '';
                        $prefill_user_avatar_id = null;
                        $prefill_user_avatar_thumbnail_url = '';
                        $prefill_artist_name = ''; // For create mode prefill
                        $prefill_artist_bio = '';  // For create mode prefill

                        $edit_mode = false;
                        $target_artist_id = 0;
                        $artist_post = null;
                        $can_proceed = false;
                        $form_title = '';
                        $nonce_action = '';
                        $nonce_name = '';
                        $submit_value = '';
                        $submit_name = '';

                        // --- Determine Mode (Create or Edit) --- 
                        if ( isset( $_GET['artist_id'] ) ) {
                            $target_artist_id = apply_filters('ec_get_artist_id', $_GET);
                            if ( $target_artist_id > 0 ) {
                                $artist_post = get_post( $target_artist_id );
                                if ( $artist_post && $artist_post->post_type === 'artist_profile' ) {
                                    $edit_mode = true;
                                }
                            }
                        }

                        // --- Permission Checks & Setup --- 
                        if ( $edit_mode ) {
                            // EDIT MODE
                            if ( ec_can_manage_artist( get_current_user_id(), $target_artist_id ) ) {
                                $can_proceed = true;
                                $form_title = sprintf(__( 'Edit Artist Profile: %s', 'extrachill-artist-platform' ), esc_html($artist_post->post_title));
                                $nonce_action = 'bp_edit_artist_profile_action';
                                $nonce_name = 'bp_edit_artist_profile_nonce';
                                $submit_value = __( 'Save', 'extrachill-artist-platform' );
                                $submit_name = 'submit_edit_artist_profile';

                                // Fetch existing meta for pre-filling
                                $current_genre = get_post_meta( $target_artist_id, '_genre', true );
                                $current_local_city = get_post_meta( $target_artist_id, '_local_city', true );
                                                $current_website_url = get_post_meta( $target_artist_id, '_website_url', true );
                                                $current_spotify_url = get_post_meta( $target_artist_id, '_spotify_url', true );
                                                $current_apple_music_url = get_post_meta( $target_artist_id, '_apple_music_url', true );
                                                $current_bandcamp_url = get_post_meta( $target_artist_id, '_bandcamp_url', true );

                                // Populate form fields from existing post
                                $artist_post_title = $artist_post->post_title;
                                $artist_post_content = $artist_post->post_content;
                                $current_profile_image_id = get_post_thumbnail_id( $target_artist_id );
                                $current_profile_image_url = $current_profile_image_id ? wp_get_attachment_image_url( $current_profile_image_id, 'thumbnail' ) : '';
                                $current_header_image_id = get_post_meta( $target_artist_id, '_artist_profile_header_image_id', true );
                                $current_header_image_url = $current_header_image_id ? wp_get_attachment_image_url( $current_header_image_id, 'large' ) : '';

                            } else {
                                echo '<p>' . __( 'You do not have permission to edit this artist profile.', 'extrachill-artist-platform' ) . '</p>';
                            }
                        } else {
                            // CREATE MODE
                            // Check if user has permission to create artist profiles (e.g., is an artist or pro)
                            // The capability 'create_artist_profiles' is handled via user_has_cap filter.
                            if ( ec_can_create_artist_profiles( get_current_user_id() ) ) {
                                $can_proceed = true;
                                $form_title = __( 'Create Artist Profile', 'extrachill-artist-platform' );
                                $nonce_action = 'bp_create_artist_profile_action';
                                $nonce_name = 'bp_create_artist_profile_nonce';
                                $submit_value = __( 'Save', 'extrachill-artist-platform' );
                                $submit_name = 'submit_create_artist_profile';

                                // Initialize variables for create mode (will be pre-filled with user data below)
                                $current_genre = '';
                                $current_local_city = '';
                                                $current_website_url = '';
                                                $current_spotify_url = '';
                                                $current_apple_music_url = '';
                                                $current_bandcamp_url = '';
                                $artist_post = (object) ['post_title' => '', 'post_content' => '']; // Mock post object for value fields

                            } else {
                                echo '<p>' . __( 'You do not have permission to create an artist profile.', 'extrachill-artist-platform' ) . '</p>';
                            }
                        }

                        // Prepare variables for pre-filling in create mode
                        $prefill_artist_name = '';
                        $prefill_artist_bio = '';
                        $prefill_user_avatar_id = null;
                        $prefill_user_avatar_thumbnail_url = '';

                        if ( ! $edit_mode ) {
                            $current_user_for_prefill = wp_get_current_user();
                            if ( $current_user_for_prefill && $current_user_for_prefill->ID > 0 ) {
                                $prefill_artist_name = $current_user_for_prefill->display_name;
                                $prefill_artist_bio = get_user_meta( $current_user_for_prefill->ID, 'description', true );
                                $prefill_user_avatar_id = get_user_meta( $current_user_for_prefill->ID, 'custom_avatar_id', true );
                                if ( $prefill_user_avatar_id ) {
                                    $prefill_user_avatar_thumbnail_url = wp_get_attachment_image_url( $prefill_user_avatar_id, 'thumbnail' );
                                }
                            }
                        }

                        // Consolidate values for title and content
                        $display_artist_name = $edit_mode ? $artist_post_title : $prefill_artist_name;
                        $display_artist_bio = $edit_mode ? $artist_post_content : $prefill_artist_bio;
                        $display_profile_image_url = $edit_mode ? $current_profile_image_url : $prefill_user_avatar_thumbnail_url;
                        $display_header_image_url = $edit_mode ? $current_header_image_url : ''; // Header image not prefilled

                        if ( $can_proceed ) :
                            ?>
                            
                            <?php 
                            // Set the H1 title of the page dynamically
                            echo '<h1 class="page-title">' . esc_html( $form_title ) . '</h1>'; 
                            ?>

                            <?php
                            // --- Artist Switcher (Shared Component) ---
                            echo ec_render_template( 'artist-switcher', array(
                                'switcher_id' => 'artist-switcher-select',
                                'base_url' => get_permalink(),
                                // Use the artist being edited (0 in create mode)
                                'current_artist_id' => (int) $target_artist_id,
                                'user_id' => get_current_user_id()
                            ) );
                            // --- End Artist Switcher ---
                            ?>

            <form id="bp-manage-artist-form" method="post" action="" enctype="multipart/form-data">
                                <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
                                <?php if ($edit_mode) : ?>
                                    <input type="hidden" name="artist_id" value="<?php echo esc_attr( $target_artist_id ); ?>">
                                <?php endif; ?>
                                <?php if ( isset($_GET['from_join']) && $_GET['from_join'] === 'true' ) : ?>
                                    <input type="hidden" name="from_join" value="true">
                                <?php endif; ?>

                                <!-- TOP SUBMIT BUTTON -->
                                <?php if ( ! $edit_mode ) : ?>
                                    <div class="form-group submit-group">
                                        <input type="submit" name="<?php echo esc_attr( $submit_name ); ?>" class="button-1 button-large" value="<?php echo esc_attr( $submit_value ); ?>" />
                                    </div>
                                <?php endif; ?>

                                <!-- Accordion Items Container -->
                                <div class="shared-tabs-component">
                                    <div class="shared-tabs-buttons-container">
                                        <!-- Item 1: Artist Info -->
                                        <div class="shared-tab-item">
                                            <button type="button" class="shared-tab-button active" data-tab="manage-artist-profile-info-content">
                                                <?php esc_html_e( 'Artist Info', 'extrachill-artist-platform' ); ?>
                                                <span class="shared-tab-arrow open"></span>
                                            </button>
                                            <div id="manage-artist-profile-info-content" class="shared-tab-pane">
                                                <?php
                                                echo ec_render_template('manage-artist-profile-tab-info', array(
                                                    'edit_mode' => (bool) $edit_mode,
                                                    'target_artist_id' => (int) $target_artist_id,
                                                    'display_artist_name' => $display_artist_name,
                                                    'display_artist_bio' => $display_artist_bio,
                                                    'display_profile_image_url' => $display_profile_image_url,
                                                    'display_header_image_url' => $display_header_image_url,
                                                    'current_profile_image_id' => $edit_mode ? $current_profile_image_id : null,
                                                    'current_header_image_id' => $edit_mode ? $current_header_image_id : null,
                                                    // Also pass fields referenced inside the tab template
                                                    'current_local_city' => $current_local_city,
                                                    'current_genre' => $current_genre,
                                                    'prefill_user_avatar_thumbnail_url' => $prefill_user_avatar_thumbnail_url,
                                                    'prefill_user_avatar_id' => $prefill_user_avatar_id
                                                ));
                                                ?>
                                            </div>
                                        </div>

                                        <?php if ( $edit_mode ) : ?>
                                        <!-- Item 2: Profile Managers -->
                                        <div class="shared-tab-item">
                                            <button type="button" class="shared-tab-button" data-tab="manage-artist-profile-managers-content">
                                                <?php esc_html_e( 'Profile Managers', 'extrachill-artist-platform' ); ?>
                                                <span class="shared-tab-arrow"></span>
                                            </button>
                                            <div id="manage-artist-profile-managers-content" class="shared-tab-pane">
                                                <?php
                                                echo ec_render_template('manage-artist-profile-tab-profile-managers', array(
                                                    'edit_mode' => (bool) $edit_mode,
                                                    'target_artist_id' => (int) $target_artist_id,
                                                    'artist_post_title' => $artist_post_title
                                                ));
                                                ?>
                                            </div>
                                        </div>

                                        <!-- Item 3: Subscribers -->
                                        <div class="shared-tab-item">
                                            <button type="button" class="shared-tab-button" data-tab="manage-artist-profile-followers-content">
                                                <?php esc_html_e( 'Subscribers', 'extrachill-artist-platform' ); ?>
                                                <span class="shared-tab-arrow"></span>
                                            </button>
                                            <div id="manage-artist-profile-followers-content" class="shared-tab-pane">
                                                <?php 
                                                echo ec_render_template('manage-artist-profile-tab-subscribers', array(
                                                    'target_artist_id' => $target_artist_id
                                                ));
                                                ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="shared-desktop-tab-content-area" style="display: none;"></div>
                                </div>
                                
                                <!-- Submission -->
                                <div class="form-group submit-group">
                                    <input type="submit" name="<?php echo esc_attr( $submit_name ); ?>" class="button-1 button-large" value="<?php echo esc_attr( $submit_value ); ?>" />
                                    <?php if ( $edit_mode && isset($target_artist_id) && $target_artist_id > 0 ) : ?>
                                        <a href="<?php echo esc_url( get_permalink( $target_artist_id ) ); ?>" class="button-2 button-large" target="_blank"><?php esc_html_e( 'View Artist Profile', 'extrachill-artist-platform' ); ?></a>
                                    <?php endif; ?>
                                </div>
                            </form>

                        <?php
                        endif; // end $can_proceed

                        // Handle case where edit mode was requested but artist profile not found
                        if ( isset( $_GET['artist_id'] ) && ! $edit_mode ) {
                            echo '<p>' . __( 'Artist profile not found or you do not have permission to view it here.', 'extrachill-artist-platform' ) . '</p>';
                        }
                        ?>
                    </div><!-- .entry-content -->
                </div><!-- .inside-article -->
            </article><!-- #post-## -->

			<?php do_action( 'extrachill_after_body_content' ); ?>
		</main><!-- #main -->
	</div><!-- .main-content -->

<?php
do_action( 'extra_chill_after_primary_content_area' );
// Sidebar removed for standalone theme
get_footer(); 
?> 