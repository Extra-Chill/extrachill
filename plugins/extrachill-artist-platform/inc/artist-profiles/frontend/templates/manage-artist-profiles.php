<?php
/**
 * Template Name: Manage Artist Profile
 * Description: A page template for users to create or edit an artist profile.
 */

// Debug logging for manage-artist-profile
global $wp_query, $post;
error_log('[DEBUG] Manage Artist Profile Template: Starting template load');
error_log('[DEBUG] Request URI: ' . $_SERVER['REQUEST_URI']);
error_log('[DEBUG] Query vars: ' . print_r($wp_query->query_vars, true));
error_log('[DEBUG] Is page: ' . ($wp_query->is_page ? 'yes' : 'no'));
error_log('[DEBUG] Is singular: ' . ($wp_query->is_singular ? 'yes' : 'no'));
error_log('[DEBUG] Is 404: ' . ($wp_query->is_404 ? 'yes' : 'no'));
if ($post) {
    error_log('[DEBUG] Global post ID: ' . $post->ID);
    error_log('[DEBUG] Global post type: ' . $post->post_type);
}

get_header(); ?>

	<div id="primary" class="content-area">
		<main id="main" class="main-content">
			<?php do_action( 'extra_chill_before_main_content' ); ?>

            <div class="breadcrumb-notice-container">
                <?php
                // Add breadcrumbs here
                if ( function_exists( 'extrachill_breadcrumbs' ) ) {
                    extrachill_breadcrumbs();
                }
                ?>

                <?php
                // --- Success Message Check (after creation redirect) ---
                if ( isset( $_GET['bp_success'] ) && $_GET['bp_success'] === 'created' && isset( $_GET['new_artist_id'] ) ) {
                    $created_artist_id = absint( $_GET['new_artist_id'] );
                    $created_artist_profile_url = get_permalink( $created_artist_id );
                    $created_link_page_id = isset( $_GET['new_link_page_id'] ) ? absint( $_GET['new_link_page_id'] ) : 0;
                    $manage_link_page_url_base = home_url('/manage-link-page/');

                    if ( $created_artist_profile_url ) {
                        echo '<div class="bp-notice bp-notice-success">';
                        echo '<p>' . esc_html__( 'Artist profile created successfully!', 'extrachill-artist-platform' ) . '</p>';
                        echo '<p>';
                        echo '<a href="' . esc_url( $created_artist_profile_url ) . '" class="button">' . esc_html__( 'View Artist Profile', 'extrachill-artist-platform' ) . '</a>';
                        if ( $created_link_page_id && $manage_link_page_url_base ) {
                            $manage_link_page_url = add_query_arg( 'artist_id', $created_artist_id, $manage_link_page_url_base );
                            echo ' ' . '<a href="' . esc_url( $manage_link_page_url ) . '" class="button">' . esc_html__( 'Manage extrachill.link Page', 'extrachill-artist-platform' ) . '</a>';
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

            <?php // Removed Redundant Join Flow Guidance Notice ?>

            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="inside-article">
                    <header class="entry-header">
                        <?php // Display appropriate title based on mode - will be set later ?>
                    </header><!-- .entry-header -->

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
                        $error_message = ''; // Variable to hold potential error message for this page, distinct from redirect errors

                        // --- Check for Error Messages from Redirect ---
                        if ( isset( $_GET['bp_error'] ) ) {
                            $error_code = sanitize_key( $_GET['bp_error'] );
                            switch ( $error_code ) {
                                case 'nonce_failure':
                                    $error_message = __( 'Security check failed. Please try again.', 'extrachill-artist-platform' );
                                    break;
                                case 'permission_denied_create':
                                    $error_message = __( 'You do not have permission to create an artist profile.', 'extrachill-artist-platform' );
                                    break;
                                case 'permission_denied_edit':
                                    $error_message = __( 'You do not have permission to edit this artist profile.', 'extrachill-artist-platform' );
                                    break;
                                case 'title_required':
                                    $error_message = __( 'Artist Name (Title) is required.', 'extrachill-artist-platform' );
                                    break;
                                case 'duplicate_title':
                                    $error_message = __( 'An artist profile with this name already exists. Please choose a different name.', 'extrachill-artist-platform' );
                                    break;
                                case 'invalid_artist_id':
                                    $error_message = __( 'Invalid artist profile ID provided.', 'extrachill-artist-platform' );
                                    break;
                                // Add other potential error codes here
                            }
                        }

                        // --- Determine Mode (Create or Edit) --- 
                        if ( isset( $_GET['artist_id'] ) ) {
                            $target_artist_id = absint( $_GET['artist_id'] );
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
                                $current_allow_public_topics = get_post_meta( $target_artist_id, '_allow_public_topic_creation', true );

                                // Populate form fields from existing post
                                $artist_post_title = $artist_post->post_title;
                                $artist_post_content = $artist_post->post_content;
                                $current_profile_image_id = get_post_meta( $target_artist_id, '_artist_profile_image_id', true );
                                $current_profile_image_url = $current_profile_image_id ? wp_get_attachment_image_url( $current_profile_image_id, 'thumbnail' ) : '';
                                $current_header_image_id = get_post_meta( $target_artist_id, '_artist_header_image_id', true );
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
                                $current_allow_public_topics = '';
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
                            
                            <?php // Set the H1 title of the page dynamically ?>
                            <script type="text/javascript">document.title = "<?php echo esc_js( $form_title ); ?>";</script>
                            <?php // You might need a more robust way to set the H1 if the template uses the_title() early
                                echo '<h1 class="entry-title page-title">' . esc_html( $form_title ) . '</h1>'; 
                            ?>

                            <?php
                            // --- Artist Switcher Dropdown ---
                            $current_user_id_for_switcher = get_current_user_id();
                            $user_artist_ids_for_switcher = get_user_meta( $current_user_id_for_switcher, '_artist_profile_ids', true );
                            
                            if ( is_array( $user_artist_ids_for_switcher ) && count( $user_artist_ids_for_switcher ) > 1 ) :
                                $current_page_url_for_switcher = get_permalink(); // Base URL for the management page
                                $current_selected_artist_id_for_switcher = isset( $_GET['artist_id'] ) ? absint( $_GET['artist_id'] ) : 0;
                            ?>
                                <div class="artist-switcher-container">
                                    <select name="artist-switcher-select" id="artist-switcher-select">
                                        <option value=""><?php esc_html_e( '-- Select an Artist --', 'extrachill-artist-platform'); ?></option>
                                        <?php
                                        foreach ( $user_artist_ids_for_switcher as $user_artist_id_item ) {
                                            $artist_title_for_switcher = get_the_title( $user_artist_id_item );
                                            echo '<option value="' . esc_attr( $user_artist_id_item ) . '" ' . selected( $current_selected_artist_id_for_switcher, $user_artist_id_item, false ) . '>' . esc_html( $artist_title_for_switcher ) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            <?php
                            endif; // End Artist Switcher Dropdown
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

                                <!-- ERROR MESSAGE INSIDE FORM -->
                                <?php if ( ! empty( $error_message ) ) : ?>
                                    <div class="bp-notice bp-notice-error" style="margin-bottom: 15px;">
                                        <p><?php echo esc_html( $error_message ); ?></p>
                                    </div>
                                <?php endif; ?>

                                <!-- TOP SUBMIT BUTTON AND INTRO -->
                                <?php if ( ! $edit_mode ) : ?>
                                    <div class="bp-intro bp-notice bp-notice-info" style="margin-bottom: 15px;">
                                        <p><?php esc_html_e( 'You can fill this out now, or just press Save to skip and go straight to customizing your link page. You can always come back and update your band profile later.', 'extrachill-artist-platform' ); ?></p>
                                    </div>
                                    <div class="form-group submit-group" style="margin-bottom: 20px;">
                                        <input type="submit" name="<?php echo esc_attr( $submit_name ); ?>" class="button button-primary" value="<?php echo esc_attr( $submit_value ); ?>" />
                                    </div>
                                <?php endif; ?>

                                <!-- Accordion Items Container -->
                                <div class="shared-tabs-component">
                                    <div class="shared-tabs-buttons-container">
                                        <!-- Item 1: Band Info -->
                                        <div class="shared-tab-item">
                                            <button type="button" class="shared-tab-button active" data-tab="manage-artist-profile-info-content">
                                                <?php esc_html_e( 'Artist Info', 'extrachill-artist-platform' ); ?>
                                                <span class="shared-tab-arrow open"></span>
                                            </button>
                                            <div id="manage-artist-profile-info-content" class="shared-tab-pane">
                                                <?php
                                                // --- START Join Flow Guidance Notice (Create Band Profile) ---
                                                // Display this notice if the user arrived from the join flow and is in create mode
                                                if ( isset($_GET['from_join']) && $_GET['from_join'] === 'true' && ! $edit_mode ) {
                                                    echo '<div class="bp-notice bp-notice-info" style="margin-top: 15px; margin-bottom: 15px;">'; // Added margin-top and margin-bottom for spacing
                                                    echo '<p>' . esc_html__( 'Welcome to the Extra Chill link page setup! To create your link page, you first need to create an Artist Profile. Fill out the details below to get started.', 'extrachill-artist-platform' ) . '</p>';
                                                    echo '</div>';
                                                }
                                                // --- END Join Flow Guidance Notice ---

                                                // Pass variables to the template part
                                                set_query_var('edit_mode', $edit_mode);
                                                set_query_var('target_artist_id', $target_artist_id);
                                                set_query_var('artist_post', $artist_post);
                                                set_query_var('current_genre', $current_genre);
                                                set_query_var('current_local_city', $current_local_city);
                                                set_query_var('current_website_url', $current_website_url);
                                                set_query_var('current_spotify_url', $current_spotify_url);
                                                set_query_var('current_apple_music_url', $current_apple_music_url);
                                                set_query_var('current_bandcamp_url', $current_bandcamp_url);
                                                set_query_var('current_allow_public_topics', $current_allow_public_topics);

                                                // Pass variables for pre-filling/display
                                                set_query_var('display_artist_name', $display_artist_name);
                                                set_query_var('display_artist_bio', $display_artist_bio);
                                                set_query_var('display_profile_image_url', $display_profile_image_url);
                                                set_query_var('display_header_image_url', $display_header_image_url);
                                                set_query_var('current_profile_image_id', $edit_mode ? $current_profile_image_id : null);
                                                set_query_var('current_header_image_id', $edit_mode ? $current_header_image_id : null);

                                                include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-info.php';
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
                                                // Pass variables to the template part
                                                set_query_var('target_artist_id', $target_artist_id);
                                                set_query_var('artist_post_title', $artist_post_title); // Pass artist name for emails
                                                include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-profile-managers.php';
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
                                                include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-subscribers.php';
                                                ?>
                                            </div>
                                        </div>

                                        <!-- Item 4: Forum -->
                                        <div class="shared-tab-item">
                                            <button type="button" class="shared-tab-button" data-tab="manage-artist-profile-forum-content">
                                                <?php esc_html_e( 'Forum', 'extrachill-artist-platform' ); ?>
                                                <span class="shared-tab-arrow"></span>
                                            </button>
                                            <div id="manage-artist-profile-forum-content" class="shared-tab-pane">
                                                <?php 
                                                include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-forum.php';
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
                                    <?php if ( $edit_mode && isset($target_artist_id) && $target_artist_id > 0 ) : ?>
                                        <a href="<?php echo esc_url( get_permalink( $target_artist_id ) ); ?>" class="button button-secondary" target="_blank"><?php esc_html_e( 'View Band Profile', 'extrachill-artist-platform' ); ?></a>
                                    <?php endif; ?>
                                </div>
                            </form>

                        <?php 
                        endif; // end $can_proceed
                        
                        // Handle case where edit mode was requested but band not found
                        // Only show this if there wasn't a more specific error already displayed
                        if ( isset( $_GET['artist_id'] ) && ! $edit_mode && empty($error_message) ) {
                             // Check if the specific error was 'invalid_artist_id' which we already handled
                             $specific_error = isset($_GET['bp_error']) && sanitize_key($_GET['bp_error']) === 'invalid_artist_id';
                             if (!$specific_error) {
                                echo '<p>' . __( 'Artist profile not found or you do not have permission to view it here.', 'extrachill-artist-platform' ) . '</p>';
                             }
                        }
                        ?>
                    </div><!-- .entry-content -->
                </div><!-- .inside-article -->
            </article><!-- #post-## -->

			<?php do_action( 'extra_chill_after_main_content' ); ?>
		</main><!-- #main -->
	</div><!-- #primary -->

<?php // Script for tab functionality removed, will be handled by shared-tabs.js ?>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    // Logic for Band Switcher Dropdown - KEEP THIS SEPARATE SCRIPT
    const bandSwitcher = document.getElementById('artist-switcher-select');
    if (bandSwitcher) {
        bandSwitcher.addEventListener('change', function() {
            const baseUrl = "<?php echo esc_url(get_permalink(get_the_ID())); ?>"; // Get current page's URL
            if (this.value) { // If a band ID is selected
                window.location.href = baseUrl + '?artist_id=' + this.value;
            } else { // If '-- Select a Band --' (empty value) is selected
                window.location.href = baseUrl;
            }
        });
    }
});
</script>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('bp-manage-artist-form');
    if (!form) return;

    const submitButton = form.querySelector('input[type="submit"].button-primary');
    const profileImageInput = document.getElementById('featured_image');
    const headerImageInput = document.getElementById('artist_header_image');
    
    let feedbackDiv = null; // To store the feedback message div

    form.addEventListener('submit', function(event) {
        let newProfileImageSelected = profileImageInput && profileImageInput.files && profileImageInput.files.length > 0;
        let newHeaderImageSelected = headerImageInput && headerImageInput.files && headerImageInput.files.length > 0;

        if (submitButton && (newProfileImageSelected || newHeaderImageSelected)) {
            if (!feedbackDiv) {
                feedbackDiv = document.createElement('div');
                feedbackDiv.className = 'bp-save-feedback'; // You can style this class
                feedbackDiv.style.marginTop = '10px';
                feedbackDiv.style.fontSize = '0.9em';
                feedbackDiv.style.color = '#555'; // Example style
                // Insert after the submit button, or its container
                if (submitButton.parentNode.classList.contains('submit-group')) {
                    submitButton.parentNode.appendChild(feedbackDiv);
                } else {
                    submitButton.insertAdjacentElement('afterend', feedbackDiv);
                }
            }
            feedbackDiv.textContent = 'Saving, please wait... Image processing may take a moment.';
            
            // Optionally disable the submit button to prevent multiple submissions
            // submitButton.disabled = true; 
            // submitButton.style.opacity = '0.7';
        }
    });
});
</script>

<?php 
do_action( 'extra_chill_after_primary_content_area' );
// Sidebar removed for standalone theme
get_footer(); 
?> 