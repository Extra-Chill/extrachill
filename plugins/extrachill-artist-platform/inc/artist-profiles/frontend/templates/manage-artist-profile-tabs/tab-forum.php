<?php
/**
 * Template Part: Forum Tab for Manage Artist Profile
 *
 * Allows override of the forum section title and bio on the public artist profile page.
 *
 * If these fields are set, they will override the default "About ArtistName" and bio section ONLY on the artist profile page (not the Extrachill.link page).
 *
 * @package extra-chill-community
 */

// Extract arguments passed from ec_render_template
$target_artist_id = $target_artist_id ?? 0;

// Fetch current values
$forum_section_title_override = '';
$forum_section_bio_override = '';
if ( $target_artist_id > 0 ) {
    $forum_section_title_override = get_post_meta( $target_artist_id, '_forum_section_title_override', true );
    $forum_section_bio_override = get_post_meta( $target_artist_id, '_forum_section_bio_override', true );
}
?>
<div class="artist-profile-content-card">
    <h2><?php esc_html_e( 'Forum Section Customization', 'extrachill-artist-platform' ); ?></h2>
    <p class="description"><strong><?php esc_html_e( 'These fields let you override the "About" section title and bio that appear above your artist forum on your public artist profile page.', 'extrachill-artist-platform' ); ?></strong><br>
        <?php esc_html_e( 'If you fill out either field below, it will ONLY change the forum section on your artist profile page. It will NOT affect your Extrachill.link page or its bio.', 'extrachill-artist-platform' ); ?><br>
        <?php esc_html_e( 'Leave these blank to use your main artist bio and the default "About ArtistName" title.', 'extrachill-artist-platform' ); ?>
    </p>
    <div class="form-group">
        <label for="forum_section_title_override"><?php esc_html_e( 'Forum Section Title (Optional)', 'extrachill-artist-platform' ); ?></label>
        <input type="text" id="forum_section_title_override" name="forum_section_title_override" value="<?php echo esc_attr( $forum_section_title_override ); ?>" placeholder="e.g., Tech Support, Community Q&A, About The Artist">
        <p class="description"><?php esc_html_e( 'This will replace the default "About ArtistName" title above your forum. Leave blank to use the default.', 'extrachill-artist-platform' ); ?></p>
    </div>
    <div class="form-group">
        <label for="forum_section_bio_override"><?php esc_html_e( 'Forum Section Bio (Optional)', 'extrachill-artist-platform' ); ?></label>
        <textarea id="forum_section_bio_override" name="forum_section_bio_override" rows="6"><?php echo esc_textarea( $forum_section_bio_override ); ?></textarea>
        <p class="description"><?php esc_html_e( 'This will replace your main artist bio ONLY in the forum section on your artist profile page. Leave blank to use your main artist bio.', 'extrachill-artist-platform' ); ?></p>
    </div>
</div> 