<?php
/**
 * Template Part: Info Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

defined( 'ABSPATH' ) || exit;

// Extract arguments passed from ec_render_template
$current_artist_id = $artist_id ?? 0;
$data = $data ?? array();
$current_bio_text = $data['bio'] ?? '';

?>
<div class="link-page-content-card">
    <div id="bp-artist-name-section" class="form-group">
        <label for="artist_profile_title"><strong><?php esc_html_e('Artist Name', 'extrachill-artist-platform'); ?></strong></label>
        <?php $artist_profile_title = $current_artist_id ? get_the_title($current_artist_id) : ''; ?>
        <input type="text" id="artist_profile_title" name="artist_profile_title" value="<?php echo esc_attr($artist_profile_title); ?>" maxlength="120" class="extrch-form-input-wide">
    </div>
    <div id="bp-profile-image-section" class="form-group">
        <label for="link_page_profile_image_upload"><strong><?php esc_html_e('Profile Image', 'extrachill-artist-platform'); ?></strong></label><br>
        <button type="button" class="button-2 button-medium" onclick="document.getElementById('link_page_profile_image_upload').click();">Change Profile Picture</button>
        <input type="file" id="link_page_profile_image_upload" name="link_page_profile_image_upload" accept="image/*" class="extrch-form-input-hidden">
        <button type="button" id="bp-remove-profile-image-btn" class="button-2 button-medium extrch-form-button-spaced"><?php esc_html_e('Remove Image', 'extrachill-artist-platform'); ?></button>
        <input type="hidden" name="remove_link_page_profile_image" id="remove_link_page_profile_image_hidden" value="0">
    </div>
    <div id="bp-bio-section" class="form-group">
        <label for="link_page_bio_text"><strong><?php esc_html_e('Bio', 'extrachill-artist-platform'); ?></strong></label>
        <textarea id="link_page_bio_text" name="link_page_bio_text" rows="4" class="bp-link-page-bio-text" placeholder="Enter a short bio for your link page."><?php echo esc_textarea($current_bio_text); ?></textarea>
        <p class="description bp-link-page-bio-desc">
            <?php
            if ($current_artist_id) {
                $edit_profile_url = site_url('/manage-artist-profile/?artist_id=' . $current_artist_id);
                printf(
                    /* translators: 1: opening <a> tag, 2: closing </a> tag */
                    esc_html__('The artist name, bio, and profile picture are synced between this link page and the %1$sartist profile%2$s.', 'extrachill-artist-platform'),
                    '<a href="' . esc_url($edit_profile_url) . '" target="_blank" rel="noopener">',
                    '</a>'
                );
            } else {
                esc_html_e('The artist name, bio, and profile picture are synced between this link page and the artist profile.', 'extrachill-artist-platform');
            }
            ?>
        </p>
    </div> 
</div> 