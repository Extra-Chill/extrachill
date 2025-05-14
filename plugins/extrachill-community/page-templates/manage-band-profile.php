<?php
/**
 * Template Name: Manage Band Profile
 * Description: A page template for users to create or edit a band profile.
 */

get_header(); ?>

	<div id="primary" <?php generate_do_element_classes( 'content' ); ?>>
		<main id="main" <?php generate_do_element_classes( 'main' ); ?>>
			<?php do_action( 'generate_before_main_content' ); ?>

            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="inside-article">
                    <header class="entry-header">
                        <?php // Display appropriate title based on mode - will be set later ?>
                    </header><!-- .entry-header -->

                    <div class="entry-content" itemprop="text">
                        <?php 
                        // --- Band Profile Management Form --- 

                        $edit_mode = false;
                        $target_band_id = 0;
                        $band_post = null;
                        $can_proceed = false;
                        $form_title = '';
                        $nonce_action = '';
                        $nonce_name = '';
                        $submit_value = '';
                        $submit_name = '';
                        $error_message = ''; // Variable to hold potential error message

                        // --- Check for Error Messages from Redirect ---
                        if ( isset( $_GET['bp_error'] ) ) {
                            $error_code = sanitize_key( $_GET['bp_error'] );
                            switch ( $error_code ) {
                                case 'nonce_failure':
                                    $error_message = __( 'Security check failed. Please try again.', 'generatepress_child' );
                                    break;
                                case 'permission_denied_create':
                                    $error_message = __( 'You do not have permission to create a band profile.', 'generatepress_child' );
                                    break;
                                case 'permission_denied_edit':
                                    $error_message = __( 'You do not have permission to edit this band profile.', 'generatepress_child' );
                                    break;
                                case 'title_required':
                                    $error_message = __( 'Band Name (Title) is required.', 'generatepress_child' );
                                    break;
                                case 'duplicate_title':
                                    $error_message = __( 'A band profile with this name already exists. Please choose a different name.', 'generatepress_child' );
                                    break;
                                case 'image_upload_failed':
                                    $error_message = __( 'There was an error uploading the profile picture. Please check the file and try again.', 'generatepress_child' );
                                    break;
                                case 'header_image_upload_failed':
                                    $error_message = __( 'There was an error uploading the band header image. Please check the file and try again.', 'generatepress_child' );
                                    break;
                                case 'creation_failed':
                                    $error_message = __( 'Could not create the band profile due to an unexpected error. Please try again later.', 'generatepress_child' );
                                    break;
                                case 'update_failed':
                                    $error_message = __( 'Could not update the band profile due to an unexpected error. Please try again later.', 'generatepress_child' );
                                    break;
                                case 'invalid_band_id':
                                     $error_message = __( 'Invalid band profile selected for editing.', 'generatepress_child' );
                                     break;
                                default:
                                    $error_message = __( 'An unknown error occurred. Please try again.', 'generatepress_child' );
                            }
                        }

                        // --- Determine Mode (Create or Edit) --- 
                        if ( isset( $_GET['band_id'] ) ) {
                            $target_band_id = absint( $_GET['band_id'] );
                            if ( $target_band_id > 0 ) {
                                $band_post = get_post( $target_band_id );
                                if ( $band_post && $band_post->post_type === 'band_profile' ) {
                                    $edit_mode = true;
                                }
                            }
                        }

                        // --- Permission Checks & Setup --- 
                        if ( $edit_mode ) {
                            // EDIT MODE
                            if ( current_user_can( 'manage_band_members', $target_band_id ) ) {
                                $can_proceed = true;
                                $form_title = sprintf(__( 'Edit Band Profile: %s', 'generatepress_child' ), esc_html($band_post->post_title));
                                $nonce_action = 'bp_edit_band_profile_action';
                                $nonce_name = 'bp_edit_band_profile_nonce';
                                $submit_value = __( 'Update Band Profile', 'generatepress_child' );
                                $submit_name = 'submit_edit_band_profile';

                                // Fetch existing meta for pre-filling
                                $current_genre = get_post_meta( $target_band_id, '_genre', true );
                                $current_local_city = get_post_meta( $target_band_id, '_local_city', true );
                                $current_website_url = get_post_meta( $target_band_id, '_website_url', true );
                                $current_spotify_url = get_post_meta( $target_band_id, '_spotify_url', true );
                                $current_apple_music_url = get_post_meta( $target_band_id, '_apple_music_url', true );
                                $current_bandcamp_url = get_post_meta( $target_band_id, '_bandcamp_url', true );
                                $current_allow_public_topics = get_post_meta( $target_band_id, '_allow_public_topic_creation', true );

                            } else {
                                echo '<p>' . __( 'You do not have permission to edit this band profile.', 'generatepress_child' ) . '</p>';
                            }
                        } else {
                            // CREATE MODE
                            if ( current_user_can( 'create_band_profiles' ) ) {
                                $can_proceed = true;
                                $form_title = __( 'Create Your Band Profile', 'generatepress_child' );
                                $nonce_action = 'bp_create_band_profile_action';
                                $nonce_name = 'bp_create_band_profile_nonce';
                                $submit_value = __( 'Create Band Profile', 'generatepress_child' );
                                $submit_name = 'submit_create_band_profile'; // Match create handler check if needed

                                // Pre-fill from user meta
                                $current_local_city = get_user_meta( get_current_user_id(), 'local_city', true );
                                // Set others to empty for create mode
                                $current_genre = '';
                                $current_website_url = '';
                                $current_spotify_url = '';
                                $current_apple_music_url = '';
                                $current_bandcamp_url = '';
                                $current_allow_public_topics = '';
                                $band_post = (object) ['post_title' => '', 'post_content' => '']; // Mock post object for value fields

                            } else {
                                echo '<p>' . __( 'You do not have permission to create a band profile.', 'generatepress_child' ) . '</p>';
                            }
                        }

                        // --- Display Error Message (if any) ---
                        if ( ! empty( $error_message ) ) {
                            // Add a simple CSS class for styling potential errors
                            echo '<div class="bp-notice bp-notice-error">';
                            echo '<p>' . esc_html( $error_message ) . '</p>';
                            echo '</div>';
                        }

                        // --- Display Form if User Can Proceed --- 
                        if ( $can_proceed ) :
                            ?>
                            
                            <?php // Set the H1 title of the page dynamically ?>
                            <script type="text/javascript">document.title = "<?php echo esc_js( $form_title ); ?>";</script>
                            <?php // You might need a more robust way to set the H1 if the template uses the_title() early
                                echo '<h1 class="entry-title page-title">' . esc_html( $form_title ) . '</h1>'; 
                            ?>

                            <?php
                            // --- Band Switcher Dropdown ---
                            $current_user_id_for_switcher = get_current_user_id();
                            $user_band_ids_for_switcher = get_user_meta( $current_user_id_for_switcher, '_band_profile_ids', true );
                            
                            if ( is_array( $user_band_ids_for_switcher ) && count( $user_band_ids_for_switcher ) > 1 ) :
                                $current_page_url_for_switcher = get_permalink(); // Base URL for the management page
                                $current_selected_band_id_for_switcher = isset( $_GET['band_id'] ) ? absint( $_GET['band_id'] ) : 0;
                            ?>
                                <div class="band-switcher-container">
                                    <label for="band-switcher-select"><?php esc_html_e( 'Switch to Manage Another Band:', 'generatepress_child' ); ?></label>
                                    <select name="band-switcher-select" id="band-switcher-select">
                                        <option value=""><?php esc_html_e( '-- Select a Band --', 'generatepress_child'); ?></option>
                                        <?php
                                        foreach ( $user_band_ids_for_switcher as $user_band_id_item ) {
                                            $band_title_for_switcher = get_the_title( $user_band_id_item );
                                            // Only list bands that still exist and have a title
                                            if ( $band_title_for_switcher && get_post_status( $user_band_id_item ) === 'publish' ) {
                                                echo '<option value="' . esc_attr( $user_band_id_item ) . '" ' . selected( $current_selected_band_id_for_switcher, $user_band_id_item, false ) . '>' . esc_html( $band_title_for_switcher ) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            <?php
                            endif; // End Band Switcher Dropdown
                            // --- End Band Switcher ---
                            ?>

                            <form id="bp-manage-band-form" method="post" action="" enctype="multipart/form-data">
                                <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
                                <?php if ($edit_mode) : ?>
                                    <input type="hidden" name="band_id" value="<?php echo esc_attr( $target_band_id ); ?>">
                                <?php endif; ?>

                                <!-- Basic Info -->
                                <h2><?php esc_html_e( 'Basic Info', 'generatepress_child' ); ?></h2>

                                <!-- Featured Image / Profile Picture -->
                                <div class="form-group">
                                    <label><?php echo $edit_mode ? esc_html__( 'Current Profile Picture', 'generatepress_child' ) : esc_html__( 'Profile Picture (Featured Image)', 'generatepress_child' ); ?></label>
                                    <?php if ( $edit_mode && has_post_thumbnail( $target_band_id ) ) : ?>
                                        <div class="current-image-preview"><?php echo get_the_post_thumbnail( $target_band_id, 'thumbnail' ); ?></div>
                                    <?php elseif ( $edit_mode ) : ?>
                                         <p><?php esc_html_e( 'No current image set.', 'generatepress_child' ); ?></p>
                                    <?php endif; ?>
                                    <label for="featured_image" class="image-upload-label"><?php echo $edit_mode ? esc_html__( 'Upload New Profile Picture (Optional)', 'generatepress_child' ) : esc_html__( 'Upload Profile Picture', 'generatepress_child' ); ?></label>
                                    <input type="file" id="featured_image" name="featured_image" accept="image/*">
                                    <p class="description"><?php echo $edit_mode ? esc_html__( 'Upload a new image to replace the current one.', 'generatepress_child' ) : esc_html__( 'Upload an image for your band profile (e.g., logo, band photo).', 'generatepress_child' ); ?></p>
                                </div>

                                <!-- Band Header Image -->
                                <div class="form-group">
                                    <label><?php esc_html_e( 'Current Band Header Image', 'generatepress_child' ); ?></label>
                                    <?php 
                                    $current_header_image_id = $edit_mode ? get_post_meta( $target_band_id, '_band_profile_header_image_id', true ) : null;
                                    if ( $edit_mode && $current_header_image_id ) : ?>
                                        <div class="current-image-preview"><?php echo wp_get_attachment_image( $current_header_image_id, 'medium' ); ?></div>
                                    <?php elseif ( $edit_mode ) : ?>
                                        <p><?php esc_html_e( 'No current header image set.', 'generatepress_child' ); ?></p>
                                    <?php endif; ?>
                                    <label for="band_header_image" class="image-upload-label"><?php echo $edit_mode ? esc_html__( 'Upload New Band Header Image (Optional)', 'generatepress_child' ) : esc_html__( 'Upload Band Header Image', 'generatepress_child' ); ?></label>
                                    <input type="file" id="band_header_image" name="band_header_image" accept="image/*">
                                    <p class="description"><?php echo $edit_mode ? esc_html__( 'Upload a new image to replace the current band header.', 'generatepress_child' ) : esc_html__( 'Upload a wide image to be used as the header/banner for your band profile.', 'generatepress_child' ); ?></p>
                                </div>

                                <!-- Band Name -->
                                <div class="form-group">
                                    <label for="band_title"><?php esc_html_e( 'Band Name *', 'generatepress_child' ); ?></label>
                                    <input type="text" id="band_title" name="band_title" required value="<?php echo esc_attr( $band_post->post_title ); ?>">
                                </div>

                                <!-- City / Region (Moved Up) -->
                                <div class="form-group">
                                    <label for="local_city"><?php esc_html_e( 'City / Region', 'generatepress_child' ); ?></label>
                                    <input type="text" id="local_city" name="local_city" value="<?php echo esc_attr( $current_local_city ); ?>" placeholder="e.g., Austin, TX">
                                </div>

                                <div class="form-group">
                                    <label for="genre"><?php esc_html_e( 'Genre', 'generatepress_child' ); ?></label>
                                    <input type="text" id="genre" name="genre" value="<?php echo esc_attr( $current_genre ); ?>" placeholder="e.g., Indie Rock, Electronic, Folk">
                                </div>
                                <div class="form-group">
                                    <label for="band_bio"><?php esc_html_e( 'Band Bio', 'generatepress_child' ); ?></label>
                                    <textarea id="band_bio" name="band_bio" rows="10"><?php echo esc_textarea( $edit_mode ? $band_post->post_content : '' ); ?></textarea>
                                    <?php // wp_editor( $edit_mode ? $band_post->post_content : '', 'band_bio', array('textarea_name' => 'band_bio', 'media_buttons' => false, 'teeny' => true, 'textarea_rows' => 10) ); ?>
                                </div>

                                <!-- Social Links Section (Matching tab-links.php structure) -->
                                <div id="bp-social-icons-section">
                                    <h2><?php esc_html_e( 'Social Links', 'generatepress_child' ); ?></h2>
                                    <?php 
                                    $band_profile_social_links_data = array(); // Renamed to avoid conflict if var is used elsewhere
                                    if ( $edit_mode && $target_band_id > 0 ) {
                                        $band_profile_social_links_data = get_post_meta( $target_band_id, '_band_profile_social_links', true );
                                        if ( ! is_array( $band_profile_social_links_data ) ) {
                                            $band_profile_social_links_data = array();
                                        }
                                    }
                                    ?>
                                    <input type="hidden" name="band_profile_social_links_json" id="band_profile_social_links_json" value="<?php echo esc_attr(json_encode($band_profile_social_links_data)); ?>">
                                    
                                    <div id="bp-social-icons-list">
                                        <!-- JS will render the list of social icons here -->
                                    </div>
                                    <button type="button" id="bp-add-social-icon-btn" class="button button-secondary bp-add-social-icon-btn">
                                        <i class="fas fa-plus"></i> <?php esc_html_e('Add Social Icon', 'generatepress_child'); ?>
                                    </button>
                                </div>
                                <!-- End Social Links Section -->

                                <?php // --- MANAGE MEMBERS SECTION (Edit Mode Only) ---
                                if ( $edit_mode && $target_band_id > 0 ) : 
                                    $current_user_id = get_current_user_id(); 
                                    // $linked_members = bp_get_linked_members( $target_band_id ); // Moved to new function

                                    // --- Call the dedicated function to display the members section ---
                                    if ( function_exists( 'bp_display_manage_members_section' ) ) {
                                        bp_display_manage_members_section( $target_band_id, $current_user_id );
                                    } else {
                                        echo '<p>' . esc_html__('Error: Member management UI could not be loaded.', 'generatepress_child') . '</p>';
                                    }
                                    // --- END MANAGE MEMBERS SECTION ---
                                else : // Not in edit mode, or no target_band_id ?>
                                    <?php // Optionally display a message or nothing if not in edit mode ?>
                                <?php endif; // end if $edit_mode ?>
                                
                                <hr style="margin: 2em 0;">

                                <?php
                                // After permission checks and before or after the form, in edit mode:
                                if ( $edit_mode && $target_band_id > 0 ) {
                                    $link_page_id = get_post_meta( $target_band_id, '_extrch_link_page_id', true );
                                    $manage_url = add_query_arg( array( 'band_id' => $target_band_id ), site_url( '/manage-link-page/' ) );
                                    $label = empty( $link_page_id ) ? __( 'Create Link Page', 'generatepress_child' ) : __( 'Manage Link Page', 'generatepress_child' );
                                    echo '<div class="bp-notice bp-notice-info link-page-manage-button-container">';
                                    echo '<a href="' . esc_url( $manage_url ) . '" class="button button-primary">' . esc_html( $label ) . '</a>';
                                    echo '</div>';
                                }
                                ?>

                                <!-- Submission -->
                                <div class="form-group submit-group">
                                    <input type="submit" name="<?php echo esc_attr( $submit_name ); ?>" class="button button-primary" value="<?php echo esc_attr( $submit_value ); ?>" />
                                    <?php if ( $edit_mode && isset($target_band_id) && $target_band_id > 0 ) : ?>
                                        <a href="<?php echo esc_url( get_permalink( $target_band_id ) ); ?>" class="button button-secondary" target="_blank"><?php esc_html_e( 'View Band Profile', 'generatepress_child' ); ?></a>
                                    <?php endif; ?>
                                </div>
                            </form>

                        <?php 
                        endif; // end $can_proceed
                        
                        // Handle case where edit mode was requested but band not found
                        // Only show this if there wasn't a more specific error already displayed
                        if ( isset( $_GET['band_id'] ) && ! $edit_mode && empty($error_message) ) {
                             // Check if the specific error was 'invalid_band_id' which we already handled
                             $specific_error = isset($_GET['bp_error']) && sanitize_key($_GET['bp_error']) === 'invalid_band_id';
                             if (!$specific_error) {
                                echo '<p>' . __( 'Band profile not found or you do not have permission to view it here.', 'generatepress_child' ) . '</p>';
                             }
                        }
                        ?>
                    </div><!-- .entry-content -->
                </div><!-- .inside-article -->
            </article><!-- #post-## -->

			<?php do_action( 'generate_after_main_content' ); ?>
		</main><!-- #main -->
	</div><!-- #primary -->

<?php 
do_action( 'generate_after_primary_content_area' );
generate_construct_sidebars();
get_footer(); 
?> 