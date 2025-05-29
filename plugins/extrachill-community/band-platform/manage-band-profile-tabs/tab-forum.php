<?php
/**
 * Template Part: Forum Tab for Manage Band Profile
 *
 * Allows override of the forum section title and bio on the public band profile page.
 *
 * If these fields are set, they will override the default "About BandName" and bio section ONLY on the band profile page (not the Extrachill.link page).
 *
 * @package generatepress_child
 */

global $edit_mode, $target_band_id;

// Fetch current values if in edit mode
$forum_section_title_override = '';
$forum_section_bio_override = '';
if ( $edit_mode && $target_band_id > 0 ) {
    $forum_section_title_override = get_post_meta( $target_band_id, '_forum_section_title_override', true );
    $forum_section_bio_override = get_post_meta( $target_band_id, '_forum_section_bio_override', true );
}
?>
<div class="band-profile-content-card">
    <h2><?php esc_html_e( 'Forum Section Customization', 'generatepress_child' ); ?></h2>
    <p class="description"><strong><?php esc_html_e( 'These fields let you override the "About" section title and bio that appear above your band forum on your public band profile page.', 'generatepress_child' ); ?></strong><br>
        <?php esc_html_e( 'If you fill out either field below, it will ONLY change the forum section on your band profile page. It will NOT affect your Extrachill.link page or its bio.', 'generatepress_child' ); ?><br>
        <?php esc_html_e( 'Leave these blank to use your main band bio and the default "About BandName" title.', 'generatepress_child' ); ?>
    </p>
    <div class="form-group">
        <label for="forum_section_title_override"><?php esc_html_e( 'Forum Section Title (Optional)', 'generatepress_child' ); ?></label>
        <input type="text" id="forum_section_title_override" name="forum_section_title_override" value="<?php echo esc_attr( $forum_section_title_override ); ?>" placeholder="e.g., Tech Support, Community Q&A, About The Band">
        <p class="description"><?php esc_html_e( 'This will replace the default "About BandName" title above your forum. Leave blank to use the default.', 'generatepress_child' ); ?></p>
    </div>
    <div class="form-group">
        <label for="forum_section_bio_override"><?php esc_html_e( 'Forum Section Bio (Optional)', 'generatepress_child' ); ?></label>
        <textarea id="forum_section_bio_override" name="forum_section_bio_override" rows="6"><?php echo esc_textarea( $forum_section_bio_override ); ?></textarea>
        <p class="description"><?php esc_html_e( 'This will replace your main band bio ONLY in the forum section on your band profile page. Leave blank to use your main band bio.', 'generatepress_child' ); ?></p>
    </div>
</div> 