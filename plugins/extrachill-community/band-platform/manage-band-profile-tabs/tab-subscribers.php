<?php
/**
 * Content for the "Followers" tab in the Manage Band Profile page.
 */

defined( 'ABSPATH' ) || exit;

// Ensure $target_band_id is available, typically set in manage-band-profile.php
if ( ! isset( $target_band_id ) || empty( $target_band_id ) ) {
    echo '<p>' . esc_html__( 'Band ID not found. Cannot display followers.', 'extra-chill-community' ) . '</p>';
    return;
}

// Check if the current user can manage this band's followers (same capability as managing members for now)
if ( ! current_user_can( 'manage_band_members', $target_band_id ) ) {
    echo '<p>' . esc_html__( 'You do not have permission to view this band\'s followers.', 'extra-chill-community' ) . '</p>';
    return;
}

$band_post = get_post( $target_band_id );
if ( ! $band_post || $band_post->post_type !== 'band_profile' ) {
    echo '<p>' . esc_html__( 'Invalid band profile.', 'extra-chill-community' ) . '</p>';
    return;
}

// Add this after $target_band_id is set and validated
$subscribers_csv_export_nonce = wp_create_nonce( 'export_band_subscribers_csv_' . $target_band_id );

?>
<div class="band-profile-content-card subscribers-tab-content" data-fetch-subscribers-nonce="<?php echo esc_attr( wp_create_nonce('extrch_fetch_subscribers_nonce') ); ?>">
    <h3><?php esc_html_e( 'Band Subscribers', 'extra-chill-community' ); ?></h3>
    <p><?php esc_html_e( 'This section lists the email subscribers for your band. Including those who subscribed on your link page and those who followed your band and opted-in to share their email address.', 'extra-chill-community' ); ?></p>

    <div class="bp-subscribers-list-actions" style="display: flex; flex-wrap: wrap; gap: 1em; align-items: center;">
        <?php
        // CSV Export Controls
        // Generate the base export URL
        $export_url = add_query_arg( array(
            'action'    => 'extrch_export_subscribers_csv',
            'band_id'   => esc_attr( $target_band_id ),
            '_wpnonce'  => esc_attr( $subscribers_csv_export_nonce ),
        ), admin_url( 'admin-post.php' ) );
        ?>
        <label style="display: flex; align-items: center; gap: 0.5em; margin: 0;">
            <input type="checkbox" id="include-exported-subscribers" value="1">
            <?php esc_html_e('Include already exported subscribers', 'extra-chill-community'); ?>
        </label>
        <?php // Changed from button to anchor tag for direct link download ?>
        <a href="<?php echo esc_url( $export_url ); ?>" id="export-subscribers-link" class="button button-primary" style="min-width: 120px; flex: 1 1 200px; max-width: 220px; text-align: center;">
            <?php esc_html_e( 'Export', 'extra-chill-community' ); ?>
        </a>
         <?php // Optional: Add a button/form for exporting ALL subscribers if needed later ?>
         <!-- 
         <button type="button" class="button button-secondary" disabled><?php esc_html_e( 'Download All Subscribers (CSV) - Coming Soon', 'extra-chill-community' ); ?></button>
          -->
    </div>

    <div class="bp-subscribers-list">
        <?php // Subscriber list will be loaded here via AJAX ?>
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce('extrch_fetch_subscribers_nonce') ); ?>">
        <p class="loading-message"><?php esc_html_e( 'Loading subscribers...', 'extra-chill-community' ); ?></p>
        <table class="wp-list-table widefat striped hidden">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Email', 'extra-chill-community' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Username', 'extra-chill-community' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Subscribed At', 'extra-chill-community' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Exported', 'extra-chill-community' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php // Subscriber data will be inserted here by JavaScript ?>
            </tbody>
        </table>
         <p class="no-subscribers-message hidden"><?php esc_html_e( 'This band does not have any email subscribers yet.', 'extra-chill-community' ); ?></p>
         <p class="error-message hidden" style="color: red;"><?php esc_html_e( 'Could not load subscribers.', 'extra-chill-community' ); ?></p>
    </div>

    <?php // Placeholder for pagination if implemented later ?>


</div> 