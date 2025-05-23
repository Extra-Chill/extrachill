<?php
/**
 * Template Part: Info Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

defined( 'ABSPATH' ) || exit;

// Retrieve data passed from manage-link-page.php
$current_band_id = get_query_var('tab_info_band_id');
$current_bio_text = get_query_var('tab_info_bio_text', '');

?>
<div class="link-page-content-card">
    <div id="bp-band-name-section" class="form-group">
        <label for="band_profile_title"><strong><?php esc_html_e('Band Name', 'generatepress_child'); ?></strong></label>
        <?php $band_profile_title = $current_band_id ? get_the_title($current_band_id) : ''; ?>
        <input type="text" id="band_profile_title" name="band_profile_title" value="<?php echo esc_attr($band_profile_title); ?>" maxlength="120" style="width:100%;max-width:400px;">
    </div>
    <div id="bp-profile-image-section" class="form-group">
        <label for="link_page_profile_image_upload"><strong><?php esc_html_e('Profile Image', 'generatepress_child'); ?></strong></label><br>
        <button type="button" class="button" onclick="document.getElementById('link_page_profile_image_upload').click();">Change Profile Picture</button>
        <input type="file" id="link_page_profile_image_upload" name="link_page_profile_image_upload" accept="image/*" style="display:none;">
        <button type="button" id="bp-remove-profile-image-btn" class="button button-secondary" style="margin-left: 5px;"><?php esc_html_e('Remove Image', 'generatepress_child'); ?></button>
        <input type="hidden" name="remove_link_page_profile_image" id="remove_link_page_profile_image_hidden" value="0">
    </div>
    <div id="bp-bio-section" class="form-group">
        <label for="link_page_bio_text"><strong><?php esc_html_e('Bio', 'generatepress_child'); ?></strong></label>
        <textarea id="link_page_bio_text" name="link_page_bio_text" rows="4" class="bp-link-page-bio-text" placeholder="Enter a short bio for your link page."><?php echo esc_textarea($current_bio_text); ?></textarea>
        <p class="description bp-link-page-bio-desc">
            <?php
            if ($current_band_id) {
                $edit_profile_url = site_url('/manage-band-profile/?band_id=' . $current_band_id);
                printf(
                    /* translators: 1: opening <a> tag, 2: closing </a> tag */
                    esc_html__('The band name, bio, and profile picture are synced between this link page and the %1$sband profile%2$s.', 'generatepress_child'),
                    '<a href="' . esc_url($edit_profile_url) . '" target="_blank" rel="noopener">',
                    '</a>'
                );
            } else {
                esc_html_e('The band name, bio, and profile picture are synced between this link page and the band profile.', 'generatepress_child');
            }
            ?>
        </p>
    </div> 
</div> 