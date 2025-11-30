<?php
/**
 * Template Part: Info Tab for Manage Artist Profile
 *
 * Loaded from page-templates/manage-artist-profile.php
 */

defined( 'ABSPATH' ) || exit;

// Extract arguments passed from ec_render_template
$edit_mode = $edit_mode ?? false;
$target_artist_id = $target_artist_id ?? 0;
$display_artist_name = $display_artist_name ?? '';
$display_artist_bio = $display_artist_bio ?? '';
$display_profile_image_url = $display_profile_image_url ?? '';
$display_header_image_url = $display_header_image_url ?? '';
$current_profile_image_id = $current_profile_image_id ?? null;
$current_header_image_id = $current_header_image_id ?? null;

// The following variables are expected to be set in the parent scope (manage-artist-profile.php)
// $edit_mode (bool)
// $target_artist_id (int)
// $artist_post (WP_Post object or mock)
// $current_local_city (string)
// $current_genre (string)
// $artist_profile_social_links_data (array) - for social links

?>

<div class="artist-profile-content-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; border-bottom: 1px solid #555; padding-bottom: 0.75em;">
        <h2 style="margin-bottom:0; padding-bottom:0;border:none;"><?php esc_html_e( 'Artist Info', 'extrachill-artist-platform' ); ?></h2>
        <?php 
        // Link Page Management Button
        if ( $edit_mode && $target_artist_id > 0 ) : 
            $link_page_id = apply_filters('ec_get_link_page_id', $target_artist_id);
            $manage_url = add_query_arg( array( 'artist_id' => $target_artist_id ), site_url( '/manage-link-page/' ) );
            $label = ( ! empty( $link_page_id ) && get_post_status( $link_page_id ) ) ? __( 'Manage Link Page', 'extrachill-artist-platform' ) : __( 'Create Link Page', 'extrachill-artist-platform' );
        ?>
            <a href="<?php echo esc_url( $manage_url ); ?>" class="button-2 button-medium"><?php echo esc_html( $label ); ?></a>
        <?php else : // Create mode or conditions not met for edit mode button ?>
            <a href="#" class="button-2 button-medium disabled" style="pointer-events: none; opacity: 0.6;" title="<?php esc_attr_e( 'Save your artist profile first, then use this button to create your link page.', 'extrachill-artist-platform' ); ?>" onclick="return false;">
                <?php esc_html_e( 'Create Link Page', 'extrachill-artist-platform' ); ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Featured Image / Profile Picture -->
    <div class="form-group">
        <label><?php echo $edit_mode ? esc_html__( 'Profile Picture', 'extrachill-artist-platform' ) : esc_html__( 'Profile Picture (Optional)', 'extrachill-artist-platform' ); ?></label>
        
        <div id="featured-image-preview-container" class="current-image-preview featured-image-preview">
            <?php
            $current_featured_image_url = ''; // Initialize
            $featured_image_style = 'display: none;';

            if ( $edit_mode && has_post_thumbnail( $target_artist_id ) ) {
                $current_featured_image_url = get_the_post_thumbnail_url( $target_artist_id, 'medium' );
            } elseif ( !$edit_mode && !empty($prefill_user_avatar_thumbnail_url) ) {
                $current_featured_image_url = $prefill_user_avatar_thumbnail_url;
            }

            if ($current_featured_image_url) {
                $featured_image_style = 'display: block;';
            }
            ?>
            <img id="featured-image-preview-img" src="<?php echo esc_url($current_featured_image_url); ?>" alt="<?php esc_attr_e('Profile picture preview', 'extrachill-artist-platform'); ?>" style="<?php echo esc_attr($featured_image_style); ?>">
            
            <?php 
            $show_no_image_notice = false;
            if ($edit_mode && !has_post_thumbnail( $target_artist_id )) {
                $show_no_image_notice = true;
            } elseif (!$edit_mode && empty($prefill_user_avatar_thumbnail_url)) {
                $show_no_image_notice = true;
            }

            if ($show_no_image_notice): ?>
                 <p class="no-image-notice"><?php esc_html_e( 'No image available.', 'extrachill-artist-platform' ); ?></p>
            <?php endif; ?>
        </div>

        <input type="file" id="featured_image" name="featured_image" accept="image/*">
        <?php if ( !$edit_mode && !empty($prefill_user_avatar_id) ) : ?>
            <input type="hidden" name="prefill_user_avatar_id" value="<?php echo esc_attr( $prefill_user_avatar_id ); ?>">
        <?php endif; ?>
        <p class="description"><?php echo $edit_mode ? esc_html__( 'This picture is also used for your Extrachill.link page. ', 'extrachill-artist-platform' ) : esc_html__( 'Upload an image for your artist profile (e.g., logo, artist photo). Your user avatar will be used if no image is uploaded.', 'extrachill-artist-platform' ); ?></p>
    </div>

    <!-- Artist Header Image -->
    <div class="form-group">
        <label><?php esc_html_e( 'Artist Forum Header Image', 'extrachill-artist-platform' ); ?></label>
        
        <div id="artist-header-image-preview-container" class="current-image-preview artist-header-image-preview">
            <?php 
            $current_header_image_id = $edit_mode ? get_post_meta( $target_artist_id, '_artist_profile_header_image_id', true ) : null;
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
            <img id="artist-header-image-preview-img" src="<?php echo esc_url($preview_image_src); ?>" alt="<?php esc_attr_e('Header image preview', 'extrachill-artist-platform'); ?>" style="<?php echo esc_attr($preview_image_style); ?>">
            
            <?php if ($edit_mode && !$current_header_image_id): ?>
                 <p class="no-image-notice"><?php esc_html_e( 'No header image set.', 'extrachill-artist-platform' ); ?></p>
            <?php endif; ?>
        </div>

        <input type="file" id="artist_header_image" name="artist_header_image" accept="image/*">
        <p class="description"><?php esc_html_e( 'Recommended aspect ratio: 16:9. This image appears at the top of your artist\'s public profile page.', 'extrachill-artist-platform' ); ?></p>
    </div>

    <!-- Artist Name -->
    <div class="form-group">
        <label for="artist_title"><?php esc_html_e( 'Artist Name *', 'extrachill-artist-platform' ); ?></label>
        <input type="text" id="artist_title" name="artist_title" required value="<?php echo esc_attr( $display_artist_name ); ?>">
    </div>

    <!-- City / Region -->
    <div class="form-group">
        <label for="local_city"><?php esc_html_e( 'City / Region', 'extrachill-artist-platform' ); ?></label>
        <input type="text" id="local_city" name="local_city" value="<?php echo esc_attr( $current_local_city ); ?>" placeholder="e.g., Austin, TX">
    </div>

    <div class="form-group">
        <label for="genre"><?php esc_html_e( 'Genre', 'extrachill-artist-platform' ); ?></label>
        <input type="text" id="genre" name="genre" value="<?php echo esc_attr( $current_genre ); ?>" placeholder="e.g., Indie Rock, Electronic, Folk">
    </div>
    
    <div class="form-group">
        <label for="artist_bio"><?php esc_html_e( 'Artist Bio', 'extrachill-artist-platform' ); ?></label>
        <textarea id="artist_bio" name="artist_bio" rows="10"><?php echo esc_textarea( $display_artist_bio ); ?></textarea>
        <p class="description extrch-sync-info"><small><?php esc_html_e( 'This bio is also used for your Extrachill.link page.', 'extrachill-artist-platform' ); ?></small></p>
    </div>
</div>

<?php /* Remove Social Icons card - Managed on Link Page now
<div class="artist-profile-content-card">
    <!-- Social Icons Section -->
    <div id="bp-social-icons-section">
        <h2 style="margin-bottom: 0.5em;"><?php esc_html_e( 'Social Icons', 'extrachill-artist-platform' ); ?></h2>
        <p class="description extrch-sync-info" style="margin-top: -0.5em; margin-bottom: 1em;"><small><?php esc_html_e( 'These icons are also used for your Extrachill.link page and are managed there.', 'extrachill-artist-platform' ); ?></small></p>
        <?php 
        // This was already in manage-artist-profile.php, ensuring it's here.
        // $artist_profile_social_links_data is expected to be set in parent scope.
        // if ( ! is_array( $artist_profile_social_links_data ) ) {
        //     $artist_profile_social_links_data = array();
        // }
        ?>
        // <input type="hidden" name="artist_profile_social_links_json" id="artist_profile_social_links_json" value="<?php echo esc_attr(json_encode($artist_profile_social_links_data)); ?>">
        
        <div id="bp-social-icons-list">
            <!-- JS will render the list of social icons here -->
        </div>
        <button type="button" id="bp-add-social-icon-btn" class="button-2 button-medium bp-add-social-icon-btn">
            <i class="fas fa-plus"></i> <?php esc_html_e('Add Social Icon', 'extrachill-artist-platform'); ?>
        </button>
    </div>
</div>
*/ ?>

<?php /* Original commented out section for Extrachill.link management button - already moved and handled.
// ... existing code ...
*/ ?> 