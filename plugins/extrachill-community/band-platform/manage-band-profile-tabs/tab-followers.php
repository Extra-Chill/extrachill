<?php
/**
 * Content for the "Followers" tab in the Manage Band Profile page.
 */

defined( 'ABSPATH' ) || exit;

// Ensure $target_band_id is available, typically set in manage-band-profile.php
if ( ! isset( $target_band_id ) || empty( $target_band_id ) ) {
    echo '<p>' . esc_html__( 'Band ID not found. Cannot display followers.', 'generatepress_child' ) . '</p>';
    return;
}

// Check if the current user can manage this band's followers (same capability as managing members for now)
if ( ! current_user_can( 'manage_band_members', $target_band_id ) ) {
    echo '<p>' . esc_html__( 'You do not have permission to view this band\'s followers.', 'generatepress_child' ) . '</p>';
    return;
}

$band_post = get_post( $target_band_id );
if ( ! $band_post || $band_post->post_type !== 'band_profile' ) {
    echo '<p>' . esc_html__( 'Invalid band profile.', 'generatepress_child' ) . '</p>';
    return;
}

?>
<div class="band-profile-content-card followers-tab-content">
    <h3><?php esc_html_e( 'Band Followers', 'generatepress_child' ); ?></h3>
    <p><?php esc_html_e( 'This list shows users who follow your band. Email addresses are displayed only for users who have consented to share them with your band.', 'generatepress_child' ); ?></p>

    <?php
    $items_per_page = 20; // Define how many followers to show per page
    $paged = isset( $_GET['followers_page'] ) ? absint( $_GET['followers_page'] ) : 1;

    $followers_query_args = array(
        'number' => $items_per_page,
        'paged'  => $paged,
        // We can add orderby/order if needed later, e.g., by registration date or display name
        // 'orderby' => 'display_name',
        // 'order'   => 'ASC',
    );
    $followers_query = function_exists('bp_get_band_followers') ? bp_get_band_followers( $target_band_id, $followers_query_args ) : new WP_User_Query();
    $followers = $followers_query->get_results();
    $total_followers = $followers_query->get_total();

    if ( $total_followers > 0 ) :
    ?>
        <div class="bp-followers-list-actions">
            <?php // Placeholder for "Export All (CSV)" and "Export New (CSV)" buttons ?>
            <?php
            // CSV Export Form
            $csv_export_nonce = wp_create_nonce( 'export_band_followers_csv_' . $target_band_id );
            ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-left: 10px;">
                <input type="hidden" name="action" value="export_band_followers_csv">
                <input type="hidden" name="band_id" value="<?php echo esc_attr( $target_band_id ); ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $csv_export_nonce ); ?>">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Download Followers (CSV)', 'generatepress_child' ); ?></button>
            </form>
            <button type="button" class="button button-secondary" disabled><?php esc_html_e( 'Export All Followers (CSV) - Coming Soon', 'generatepress_child' ); ?></button>
        </div>

        <table class="bp-followers-list wp-list-table widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Username', 'generatepress_child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Display Name', 'generatepress_child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Email Contact Consent', 'generatepress_child' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $followers as $follower_user ) : ?>
                    <?php
                    $email_permissions = get_user_meta( $follower_user->ID, '_band_follow_email_permissions', true );
                    $has_consented_for_this_band = is_array( $email_permissions ) && isset( $email_permissions[ $target_band_id ] ) && $email_permissions[ $target_band_id ] === true;
                    $profile_url = bbp_get_user_profile_url( $follower_user->ID );
                    ?>
                    <tr>
                        <td>
                            <?php if ( $profile_url ) : ?>
                                <a href="<?php echo esc_url( $profile_url ); ?>" target="_blank">
                                    <?php echo esc_html( $follower_user->user_login ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $follower_user->user_login ); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $follower_user->display_name ); ?></td>
                        <td>
                            <?php echo $has_consented_for_this_band ? esc_html__( 'Yes', 'generatepress_child' ) : esc_html__( 'No', 'generatepress_child' ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php // Placeholder for pagination if 'number' is not -1 in bp_get_band_followers ?>
        <?php
        if ( $total_followers > $items_per_page ) {
            $pagination_base_url = esc_url( add_query_arg( 'band_id', $target_band_id, get_permalink() ) );
            // To ensure the current tab remains active, we might need to pass a tab query arg if the page uses them for navigation
            // For now, assuming a simple query param for followers_page is enough.
            // If your tab system relies on URL hashes (#tab-followers), paginate_links might not preserve it easily.
            // If it uses a query string like ?tab=followers, that needs to be included in the base.
            // Let's assume the manage page URL already correctly points to the band profile management page.
            // The key is that `paginate_links` needs a base URL to which it can append `/page/2/` or `&paged=2`.
            // Since this is not a standard archive, we'll use a custom query var 'followers_page'.

            $manage_profile_url = get_permalink(); // This should be the URL of page-templates/manage-band-profile.php
            $base_url_for_pagination = add_query_arg( array(
                'band_id' => $target_band_id,
                // If your tab switching is JS-based and doesn't change URL, this is fine.
                // If it uses a query like `&active_tab=followers`, add it here.
            ), $manage_profile_url );

            $pagination_args = array(
                'base'      => $base_url_for_pagination . '%_%#followers-tab-content', // %_% will be replaced by format, #followers-tab-content to scroll to the tab
                'format'    => '&followers_page=%#%', // %#% is the page number
                'current'   => $paged,
                'total'     => ceil( $total_followers / $items_per_page ),
                'prev_text' => __( '&laquo; Previous', 'generatepress_child' ),
                'next_text' => __( 'Next &raquo;', 'generatepress_child' ),
                'add_args'  => false, // We've already added band_id in the base
            );
            echo '<div class="pagination band-followers-pagination">' . paginate_links( $pagination_args ) . '</div>';
        }
        ?>

    <?php else : ?>
        <p><?php esc_html_e( 'This band does not have any followers yet.', 'generatepress_child' ); ?></p>
    <?php endif; ?>
</div> 