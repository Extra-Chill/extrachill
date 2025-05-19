<?php
/**
 * Template Name: Manage Band Profile
 * Description: A page template for users to create or edit a band profile.
 */

get_header(); ?>

	<div id="primary" <?php generate_do_element_classes( 'content' ); ?>>
		<main id="main" <?php generate_do_element_classes( 'main' ); ?>>
			<?php do_action( 'generate_before_main_content' ); ?>

            <div class="breadcrumb-notice-container">
                <?php
                // Add breadcrumbs here
                if ( function_exists( 'extrachill_breadcrumbs' ) ) {
                    extrachill_breadcrumbs();
                }
                ?>

                <?php
                // --- Success Message Check (after creation redirect) ---
                if ( isset( $_GET['bp_success'] ) && $_GET['bp_success'] === 'created' && isset( $_GET['new_band_id'] ) ) {
                    $created_band_id = absint( $_GET['new_band_id'] );
                    $created_band_profile_url = get_permalink( $created_band_id );
                    $created_link_page_id = isset( $_GET['new_link_page_id'] ) ? absint( $_GET['new_link_page_id'] ) : 0;
                    $manage_link_page_url_base = home_url('/manage-link-page/');

                    if ( $created_band_profile_url ) {
                        echo '<div class="bp-notice bp-notice-success">';
                        echo '<p>' . esc_html__( 'Band profile created successfully!', 'generatepress_child' ) . '</p>';
                        echo '<p>';
                        echo '<a href="' . esc_url( $created_band_profile_url ) . '" class="button">' . esc_html__( 'View Band Profile', 'generatepress_child' ) . '</a>';
                        if ( $created_link_page_id && $manage_link_page_url_base ) {
                            $manage_link_page_url = add_query_arg( 'band_id', $created_band_id, $manage_link_page_url_base );
                            echo ' ' . '<a href="' . esc_url( $manage_link_page_url ) . '" class="button">' . esc_html__( 'Manage extrachill.link Page', 'generatepress_child' ) . '</a>';
                        }
                        echo '</p>';
                        echo '</div>';
                    }
                }

                // --- Display Error Message (if any) ---
                // This error message block combines $_GET['bp_error'] parsing (done earlier in the script)
                // with other programmatically set $error_message values.
                if ( ! empty( $error_message ) ) {
                    // Add a simple CSS class for styling potential errors
                    echo '<div class="bp-notice bp-notice-error">';
                    echo '<p>' . esc_html( $error_message ) . '</p>';
                    echo '</div>';
                }
                ?>
            </div>

            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="inside-article">
                    <header class="entry-header">
                        <?php // Display appropriate title based on mode - will be set later ?>
                    </header><!-- .entry-header -->

                    <div class="entry-content" itemprop="text">
                        <?php 
                        // --- Band Profile Management Form --- 

                        // Initialize variables that will be used in tab-info.php and elsewhere
                        $band_post_title = '';
                        $band_post_content = '';
                        $current_local_city = '';
                        $current_genre = '';
                        $prefill_user_avatar_id = null;
                        $prefill_user_avatar_thumbnail_url = '';
                        $prefill_band_name = ''; // For create mode prefill
                        $prefill_band_bio = '';  // For create mode prefill

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
                                $current_user_id = get_current_user_id(); // Get current user ID once
                                $current_local_city = get_user_meta( $current_user_id, 'local_city', true );
                                $prefill_user_display_name = wp_get_current_user()->display_name; // Pre-fill with display name
                                $prefill_user_bio = get_user_meta( $current_user_id, 'description', true ); // Pre-fill with user bio
                                $prefill_user_avatar_id = get_user_meta( $current_user_id, 'custom_avatar_id', true );

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

                        // Prepare variables for pre-filling in create mode
                        $prefill_band_name = '';
                        $prefill_band_bio = '';
                        $prefill_user_avatar_id = null;
                        $prefill_user_avatar_thumbnail_url = '';

                        if ( ! $edit_mode ) {
                            $current_user_for_prefill = wp_get_current_user();
                            if ( $current_user_for_prefill && $current_user_for_prefill->ID > 0 ) {
                                $prefill_band_name = $current_user_for_prefill->display_name;
                                $prefill_band_bio = get_user_meta( $current_user_for_prefill->ID, 'description', true );
                                $prefill_user_avatar_id = get_user_meta( $current_user_for_prefill->ID, 'custom_avatar_id', true );
                                if ( $prefill_user_avatar_id ) {
                                    $prefill_user_avatar_thumbnail_url = wp_get_attachment_image_url( $prefill_user_avatar_id, 'thumbnail' );
                                }
                            }
                        }

                        // Consolidate values for title and content
                        if ($edit_mode) {
                            if ($band_post && property_exists($band_post, 'post_title')) {
                                $band_post_title = $band_post->post_title;
                            }
                            if ($band_post && property_exists($band_post, 'post_content')) {
                                $band_post_content = $band_post->post_content;
                            }
                            // $current_local_city and $current_genre are already set from get_post_meta in the $edit_mode block earlier
                        } else { // Create mode
                            $band_post_title = $prefill_band_name; // $prefill_band_name is from user data or empty string
                            $band_post_content = $prefill_band_bio; // $prefill_band_bio is from user data or empty string
                            // $current_local_city and $current_genre are already set for create mode earlier
                        }

                        // Final casting to string to prevent issues with esc_attr/esc_textarea
                        $band_post_title = strval($band_post_title);
                        $band_post_content = strval($band_post_content);
                        $current_local_city = strval($current_local_city); // Already initialized to '', then populated
                        $current_genre = strval($current_genre);       // Already initialized to '', then populated

                        // For featured image, tab-info.php will handle display logic based on edit_mode and prefill vars.

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
                                    <select name="band-switcher-select" id="band-switcher-select">
                                        <option value=""><?php esc_html_e( '-- Create New Band --', 'generatepress_child'); ?></option>
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

                                <!-- Accordion Items Container -->
                                <div class="shared-tabs-component">
                                    <div class="shared-tabs-buttons-container">
                                        <!-- Item 1: Band Info -->
                                        <div class="shared-tab-item">
                                            <button type="button" class="shared-tab-button active" data-tab="manage-band-profile-info-content">
                                                <?php esc_html_e( 'Band Info', 'generatepress_child' ); ?>
                                                <span class="shared-tab-arrow open"></span>
                                            </button>
                                            <div id="manage-band-profile-info-content" class="shared-tab-pane">
                                                <?php
                                                get_template_part('forum-features/band-platform/manage-band-profile-tabs/tab', 'info');
                                                ?>
                                            </div>
                                        </div>

                                        <?php if ( $edit_mode ) : ?>
                                        <!-- Item 2: Roster -->
                                        <div class="shared-tab-item">
                                            <button type="button" class="shared-tab-button" data-tab="manage-band-profile-roster-content">
                                                <?php esc_html_e( 'Roster', 'generatepress_child' ); ?>
                                                <span class="shared-tab-arrow"></span>
                                            </button>
                                            <div id="manage-band-profile-roster-content" class="shared-tab-pane">
                                                <?php
                                                get_template_part('forum-features/band-platform/manage-band-profile-tabs/tab', 'roster');
                                                ?>
                                            </div>
                                        </div>

                                        <!-- Item 3: Followers -->
                                        <div class="shared-tab-item">
                                            <button type="button" class="shared-tab-button" data-tab="manage-band-profile-followers-content">
                                                <?php esc_html_e( 'Followers', 'generatepress_child' ); ?>
                                                <span class="shared-tab-arrow"></span>
                                            </button>
                                            <div id="manage-band-profile-followers-content" class="shared-tab-pane">
                                                <?php 
                                                include( get_stylesheet_directory() . '/forum-features/band-platform/manage-band-profile-tabs/tab-followers.php' );
                                                ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="shared-desktop-tab-content-area" style="display: none;"></div>
                                </div>
                                
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

<?php // Script for tab functionality removed, will be handled by shared-tabs.js ?>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    // Logic for Band Switcher Dropdown - KEEP THIS SEPARATE SCRIPT
    const bandSwitcher = document.getElementById('band-switcher-select');
    if (bandSwitcher) {
        bandSwitcher.addEventListener('change', function() {
            const baseUrl = "<?php echo esc_url(get_permalink(get_the_ID())); ?>"; // Get current page's URL
            if (this.value) { // If a band ID is selected
                window.location.href = baseUrl + '?band_id=' + this.value;
            } else { // If '-- Create New Band --' (empty value) is selected
                window.location.href = baseUrl;
            }
        });
    }
});
</script>

<?php 
do_action( 'generate_after_primary_content_area' );
generate_construct_sidebars();
get_footer(); 
?> 