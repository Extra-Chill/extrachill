<?php
/**
 * Template Part: Info Tab for Manage Band Profile
 *
 * Loaded from page-templates/manage-band-profile.php
 */

defined( 'ABSPATH' ) || exit;

// Ensure variables from parent scope are available (e.g., $edit_mode, $target_band_id, $band_post, etc.)
global $edit_mode, $target_band_id, $band_post, 
       $current_genre, $current_local_city, $current_website_url, // Though website is not explicitly listed, it might be part of social or general info. For now, focusing on listed items.
       $current_spotify_url, $current_apple_music_url, $current_bandcamp_url, // These are social links.
       $band_profile_social_links_data; // This will hold all social links.

// Make title and content available from the parent scope
global $band_post_title, $band_post_content;

// These are now expected to be set in manage-band-profile.php and made available
// global $band_post_title, $band_post_content;
global $prefill_user_avatar_id, $prefill_user_avatar_thumbnail_url;

// The following variables are expected to be set in the parent scope (manage-band-profile.php)
// $edit_mode (bool)
// $target_band_id (int)
// $band_post (WP_Post object or mock)
// $current_local_city (string)
// $current_genre (string)
// $band_profile_social_links_data (array) - for social links

?>

<div class="band-profile-content-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; border-bottom: 1px solid #555; padding-bottom: 0.75em;">
        <h2 style="margin-bottom:0; padding-bottom:0;border:none;"><?php esc_html_e( 'Band Info', 'generatepress_child' ); ?></h2>
        <?php 
        // Link Page Management Button
        if ( $edit_mode && $target_band_id > 0 ) : 
            $link_page_id = get_post_meta( $target_band_id, '_extrch_link_page_id', true );
            $manage_url = add_query_arg( array( 'band_id' => $target_band_id ), site_url( '/manage-link-page/' ) );
            $label = ( ! empty( $link_page_id ) && get_post_status( $link_page_id ) ) ? __( 'Manage Link Page', 'generatepress_child' ) : __( 'Create Link Page', 'generatepress_child' );
        ?>
            <a href="<?php echo esc_url( $manage_url ); ?>" class="button button-secondary"><?php echo esc_html( $label ); ?></a>
        <?php else : // Create mode or conditions not met for edit mode button ?>
            <a href="#" class="button button-secondary disabled" style="pointer-events: none; opacity: 0.6;" title="<?php esc_attr_e( 'Save your band profile first. A link page will be created automatically.', 'generatepress_child' ); ?>" onclick="return false;">
                <?php esc_html_e( 'Create Link Page', 'generatepress_child' ); ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Featured Image / Profile Picture -->
    <div class="form-group">
        <label><?php echo $edit_mode ? esc_html__( 'Profile Picture', 'generatepress_child' ) : esc_html__( 'Profile Picture (Featured Image)', 'generatepress_child' ); ?></label>
        
        <div id="featured-image-preview-container" class="current-image-preview featured-image-preview">
            <?php
            $current_featured_image_url = ''; // Initialize
            $featured_image_style = 'display: none;';

            if ( $edit_mode && has_post_thumbnail( $target_band_id ) ) {
                $current_featured_image_url = get_the_post_thumbnail_url( $target_band_id, 'thumbnail' );
            } elseif ( !$edit_mode && !empty($prefill_user_avatar_thumbnail_url) ) {
                $current_featured_image_url = $prefill_user_avatar_thumbnail_url;
            }

            if ($current_featured_image_url) {
                $featured_image_style = 'display: block;';
            }
            ?>
            <img id="featured-image-preview-img" src="<?php echo esc_url($current_featured_image_url); ?>" alt="<?php esc_attr_e('Profile picture preview', 'generatepress_child'); ?>" style="<?php echo esc_attr($featured_image_style); ?>">
            
            <?php 
            $show_no_image_notice = false;
            if ($edit_mode && !has_post_thumbnail( $target_band_id )) {
                $show_no_image_notice = true;
            } elseif (!$edit_mode && empty($prefill_user_avatar_thumbnail_url)) {
                $show_no_image_notice = true;
            }

            if ($show_no_image_notice): ?>
                 <p class="no-image-notice"><?php esc_html_e( 'No image available.', 'generatepress_child' ); ?></p>
            <?php endif; ?>
        </div>

        <input type="file" id="featured_image" name="featured_image" accept="image/*">
        <?php if ( !$edit_mode && !empty($prefill_user_avatar_id) ) : ?>
            <input type="hidden" name="prefill_user_avatar_id" value="<?php echo esc_attr( $prefill_user_avatar_id ); ?>">
        <?php endif; ?>
        <p class="description"><?php echo $edit_mode ? esc_html__( 'This picture is also used for your Extrachill.link page. ', 'generatepress_child' ) : esc_html__( 'Upload an image for your band profile (e.g., logo, band photo). Your user avatar will be used if no image is uploaded.', 'generatepress_child' ); ?></p>
    </div>

    <!-- Band Header Image -->
    <div class="form-group">
        <label><?php esc_html_e( 'Band Forum Header Image', 'generatepress_child' ); ?></label>
        
        <div id="band-header-image-preview-container" class="current-image-preview band-header-image-preview">
            <?php 
            $current_header_image_id = $edit_mode ? get_post_meta( $target_band_id, '_band_profile_header_image_id', true ) : null;
            $preview_image_src = '';
            $preview_image_style = 'display: none;'; // Initially hide if no image

            if ( $edit_mode && $current_header_image_id ) {
                $img_src_array = wp_get_attachment_image_src( $current_header_image_id, 'large' );
                if ($img_src_array) {
                    $preview_image_src = $img_src_array[0];
                    $preview_image_style = 'display: block;'; // Show if there is a current image
                }
            } elseif ($edit_mode) {
                // No current image, but in edit mode - placeholder text handled after the img tag
            }
            ?>
            <img id="band-header-image-preview-img" src="<?php echo esc_url($preview_image_src); ?>" alt="<?php esc_attr_e('Header image preview', 'generatepress_child'); ?>" style="<?php echo esc_attr($preview_image_style); ?>">
            
            <?php if ($edit_mode && !$current_header_image_id): ?>
                 <p class="no-image-notice"><?php esc_html_e( 'No header image set.', 'generatepress_child' ); ?></p>
            <?php endif; ?>
        </div>

        <input type="file" id="band_header_image" name="band_header_image" accept="image/*">
        <p class="description"><?php esc_html_e( 'Recommended aspect ratio: 16:9. This image appears at the top of your band\'s public profile page.', 'generatepress_child' ); ?></p>
    </div>

    <!-- Band Name -->
    <div class="form-group">
        <label for="band_title"><?php esc_html_e( 'Band Name *', 'generatepress_child' ); ?></label>
        <input type="text" id="band_title" name="band_title" required value="<?php echo esc_attr( $band_post_title ); ?>">
    </div>

    <!-- City / Region -->
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
        <textarea id="band_bio" name="band_bio" rows="10"><?php echo esc_textarea( $band_post_content ); ?></textarea>
        <p class="description extrch-sync-info"><small><?php esc_html_e( 'This bio is also used for your Extrachill.link page.', 'generatepress_child' ); ?></small></p>
    </div>
</div>

<?php /* Remove Social Icons card - Managed on Link Page now
<div class="band-profile-content-card">
    <!-- Social Icons Section -->
    <div id="bp-social-icons-section">
        <h2 style="margin-bottom: 0.5em;"><?php esc_html_e( 'Social Icons', 'generatepress_child' ); ?></h2>
        <p class="description extrch-sync-info" style="margin-top: -0.5em; margin-bottom: 1em;"><small><?php esc_html_e( 'These icons are also used for your Extrachill.link page and are managed there.', 'generatepress_child' ); ?></small></p>
        <?php 
        // This was already in manage-band-profile.php, ensuring it's here.
        // $band_profile_social_links_data is expected to be set in parent scope.
        // if ( ! is_array( $band_profile_social_links_data ) ) {
        //     $band_profile_social_links_data = array();
        // }
        ?>
        // <input type="hidden" name="band_profile_social_links_json" id="band_profile_social_links_json" value="<?php echo esc_attr(json_encode($band_profile_social_links_data)); ?>">
        
        <div id="bp-social-icons-list">
            <!-- JS will render the list of social icons here -->
        </div>
        <button type="button" id="bp-add-social-icon-btn" class="button button-secondary bp-add-social-icon-btn">
            <i class="fas fa-plus"></i> <?php esc_html_e('Add Social Icon', 'generatepress_child'); ?>
        </button>
    </div>
</div>
*/ ?>

<?php /* Original commented out section for Extrachill.link management button - already moved and handled.
// ... existing code ...
*/ ?> 