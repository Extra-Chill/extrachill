<?php
/**
 * Content for the "Followers" tab in the Manage Artist Profile page.
 */

defined( 'ABSPATH' ) || exit;

// Extract arguments passed from ec_render_template
$target_artist_id = $target_artist_id ?? 0;

if ( ! $target_artist_id ) {
    echo '<p>' . esc_html__( 'Artist ID not found. Cannot display followers.', 'extrachill-artist-platform' ) . '</p>';
    return;
}

// Check if the current user can manage this artist's followers (same capability as managing members for now)
if ( ! ec_can_manage_artist( get_current_user_id(), $target_artist_id ) ) {
    echo '<p>' . esc_html__( 'You do not have permission to view this artist\'s followers.', 'extrachill-artist-platform' ) . '</p>';
    return;
}

$artist_post = get_post( $target_artist_id );
if ( ! $artist_post || $artist_post->post_type !== 'artist_profile' ) {
    echo '<p>' . esc_html__( 'Invalid artist profile.', 'extrachill-artist-platform' ) . '</p>';
    return;
}

$rest_url = rest_url( 'extrachill/v1' );
?>
<div class="artist-profile-content-card subscribers-tab-content" data-artist-id="<?php echo esc_attr( $target_artist_id ); ?>" data-rest-url="<?php echo esc_url( $rest_url ); ?>">
    <h3><?php esc_html_e( 'Artist Subscribers', 'extrachill-artist-platform' ); ?></h3>
    <p><?php esc_html_e( 'This section lists the email subscribers for your artist. Including those who subscribed on your link page and those who followed your artist and opted-in to share their email address.', 'extrachill-artist-platform' ); ?></p>

    <div class="bp-subscribers-list-actions" style="display: flex; flex-wrap: wrap; gap: 1em; align-items: center;">
        <label style="display: flex; align-items: center; gap: 0.5em; margin: 0;">
            <input type="checkbox" id="include-exported-subscribers" value="1">
            <?php esc_html_e('Include already exported subscribers', 'extrachill-artist-platform'); ?>
        </label>
        <a href="#" id="export-subscribers-link" class="button-2 button-medium" style="min-width: 120px; flex: 1 1 200px; max-width: 220px; text-align: center;">
            <?php esc_html_e( 'Export', 'extrachill-artist-platform' ); ?>
        </a>
    </div>

    <div class="bp-subscribers-list">
        <p class="loading-message"><?php esc_html_e( 'Loading subscribers...', 'extrachill-artist-platform' ); ?></p>
        <table class="wp-list-table widefat striped hidden">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Email', 'extrachill-artist-platform' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Username', 'extrachill-artist-platform' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Subscribed At', 'extrachill-artist-platform' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Exported', 'extrachill-artist-platform' ); ?></th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
         <p class="no-subscribers-message hidden"><?php esc_html_e( 'This artist does not have any email subscribers yet.', 'extrachill-artist-platform' ); ?></p>
         <p class="error-message hidden" style="color: red;"><?php esc_html_e( 'Could not load subscribers.', 'extrachill-artist-platform' ); ?></p>
    </div>
</div> 