<?php
/* Template Name: Account Settings */

// Form processing logic is now in functions.php

get_header();
?>

        <?php extrachill_breadcrumbs(); ?>

<?php

// Ensure only logged-in users can access this page
if (!is_user_logged_in()) {
    auth_redirect();
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// Display success/error messages
$settings_errors = get_transient('user_settings_errors_' . $user_id);
if ($settings_errors) {
    delete_transient('user_settings_errors_' . $user_id);
    echo '<div class="settings-errors notice notice-error is-dismissible">';
    foreach ($settings_errors as $error) {
        echo '<p>' . esc_html($error) . '</p>';
    }
    echo '</div>';
}

$settings_success = get_transient('user_settings_success_' . $user_id);
if ($settings_success) {
    delete_transient('user_settings_success_' . $user_id);
    echo '<div class="settings-success notice notice-success is-dismissible">';
    foreach ($settings_success as $message) {
        echo '<p>' . esc_html($message) . '</p>';
    }
    echo '</div>';
}

// Check for email change specific messages
$email_change_error = get_transient('email_change_error_' . $user_id);
if ($email_change_error) {
    delete_transient('email_change_error_' . $user_id);
    echo '<div class="settings-errors notice notice-error is-dismissible">';
    echo '<p>' . esc_html($email_change_error) . '</p>';
    echo '</div>';
}

?>

<div class="account-settings-page"> <!-- Renamed class for page-level styling -->
    <h1><?php esc_html_e( 'Settings', 'extra-chill-community' ); ?></h1>

    <form method="post" enctype="multipart/form-data" id="user-settings-form">
        <?php wp_nonce_field('update-user-settings_' . $user_id, '_wpnonce_update_user_settings'); ?>
        <input type="hidden" name="current_tab_hash" id="current_tab_hash" value=""> <!-- Will be populated by JS -->

        <div class="shared-tabs-component"> <!-- Added shared-tabs-component wrapper -->
            <div class="shared-tabs-buttons-container"> <!-- Changed class -->
                <!-- Item 1: Account Details -->
                <div class="shared-tab-item"> <!-- Changed class -->
                    <button type="button" class="shared-tab-button active" data-tab="tab-account-details"> <!-- Changed class, added active -->
                        <?php esc_html_e( 'Account Details', 'extra-chill-community' ); ?>
                        <span class="shared-tab-arrow open"></span> <!-- Changed class, added open because button is active -->
                    </button>
                    <div id="tab-account-details" class="shared-tab-pane"> <!-- Changed class, removed style -->
                        <h2><?php esc_html_e( 'Account Details', 'extra-chill-community' ); ?></h2>
                        <?php // Removed individual form tag ?>
        <?php
                        // Display errors for Personal Information Form (transient based) - Now handled globally
                        /*
                        $personal_errors = get_transient('personal_settings_errors_' . $user_id);
                        if ($personal_errors) delete_transient('personal_settings_errors_' . $user_id);
        if (!empty($personal_errors)) {
            echo '<div class="settings-errors">';
            foreach ($personal_errors as $error) {
                echo '<p class="error">' . esc_html($error) . '</p>';
            }
            echo '</div>';
        }
                        */
        ?>
        <fieldset class="name-settings">
                        <p>
                            <label for="first_name"><?php esc_html_e( 'First Name', 'extra-chill-community' ); ?></label>
            <input type="text" id="first_name" name="first_name" value="<?php echo esc_attr($current_user->first_name); ?>">
                        </p>
                        <p>
                            <label for="last_name"><?php esc_html_e( 'Last Name', 'extra-chill-community' ); ?></label>
            <input type="text" id="last_name" name="last_name" value="<?php echo esc_attr($current_user->last_name); ?>">
                        </p>
                        <?php /* Nickname field removed as per user request */ ?>
                        <p>
                            <label for="display_name"><?php esc_html_e( 'Display Name', 'extra-chill-community' ); ?></label>
            <select id="display_name" name="display_name">
                <?php
                                // Generate display name options
                                $public_display = array();
                                $public_display['nickname'] = $current_user->nickname;
                                $public_display['username'] = $current_user->user_login;

                                if ( !empty($current_user->first_name) )
                                    $public_display['firstname'] = $current_user->first_name;

                                if ( !empty($current_user->last_name) )
                                    $public_display['lastname'] = $current_user->last_name;

                                if ( !empty($current_user->first_name) && !empty($current_user->last_name) ) {
                                    $public_display['firstlast'] = $current_user->first_name . ' ' . $current_user->last_name;
                                    $public_display['lastfirst'] = $current_user->last_name . ' ' . $current_user->first_name;
                                }

                                // Remove empty or duplicate values
                                $public_display = array_unique(array_filter(array_map('trim', $public_display)));

                                foreach ($public_display as $id => $item) {
                                ?>
                                    <option <?php selected( $current_user->display_name, $item ); ?>><?php echo esc_html( $item ); ?></option>
                                <?php
                }
                ?>
            </select>
                        </p>
        </fieldset>
                    </div>
                </div>

                <!-- Item 2: Security -->
                <div class="shared-tab-item"> <!-- Changed class -->
                    <button type="button" class="shared-tab-button" data-tab="tab-security"> <!-- Changed class -->
                        <?php esc_html_e( 'Security', 'extra-chill-community' ); ?>
                        <span class="shared-tab-arrow"></span> <!-- Changed class -->
                    </button>
                    <div id="tab-security" class="shared-tab-pane"> <!-- Changed class, removed style -->
                        <h2><?php esc_html_e( 'Security', 'extra-chill-community' ); ?></h2>
                        <?php // Removed individual form tag and nonce ?>
        <?php
                        // Display errors for Account Security Form (transient based) - Now handled globally
                        /*
                        $security_errors = get_transient('security_settings_errors_' . $user_id);
                        if ($security_errors) delete_transient('security_settings_errors_' . $user_id);
        if (!empty($security_errors)) {
            echo '<div class="settings-errors">';
            foreach ($security_errors as $error) {
                echo '<p class="error">' . esc_html($error) . '</p>';
            }
            echo '</div>';
        }
                        */
        ?>
        <fieldset class="account-settings">
                        <?php
                        // Check for pending email change
                        $pending_change = extrachill_get_user_pending_email_change( $user_id );
                        $can_change_email = extrachill_can_user_change_email( $user_id );
                        ?>
                        
                        <p>
                            <label for="current_email"><?php esc_html_e( 'Current Email Address', 'extra-chill-community' ); ?></label>
                            <input type="email" id="current_email" value="<?php echo esc_attr($current_user->user_email); ?>" disabled autocomplete="email">
                            <?php if ( $pending_change ) : ?>
                                <span class="email-status pending">
                                    <?php
                                    printf(
                                        /* translators: %s: new email address */
                                        __( 'Email change pending - verification sent to %s', 'extra-chill-community' ),
                                        '<strong>' . esc_html( $pending_change['new_email'] ) . '</strong>'
                                    );
                                    ?>
                                    <br><small><?php 
                                    $time_ago = human_time_diff( $pending_change['timestamp'], current_time( 'timestamp' ) );
                                    printf( 
                                        /* translators: %s: time ago */
                                        __( 'Sent %s ago. Check your inbox and click the verification link.', 'extra-chill-community' ), 
                                        $time_ago 
                                    ); 
                                    ?></small>
                                </span>
                            <?php endif; ?>
                        </p>

                        <?php if ( $can_change_email ) : ?>
                        <p>
                            <label for="new_email"><?php esc_html_e( 'New Email Address', 'extra-chill-community' ); ?></label>
                            <input type="email" id="new_email" name="new_email" value="" placeholder="<?php esc_attr_e( 'Enter new email address', 'extra-chill-community' ); ?>" autocomplete="new-email">
                            <span class="description">
                                <?php esc_html_e( 'A verification email will be sent to your new address. Your current email will remain active until verification is complete.', 'extra-chill-community' ); ?>
                            </span>
                        </p>
                        <?php elseif ( ! $pending_change ) : ?>
                        <p>
                            <span class="email-status rate-limited">
                                <?php esc_html_e( 'Email can only be changed once per 24 hours. Please try again later.', 'extra-chill-community' ); ?>
                            </span>
                        </p>
                        <?php endif; ?>
                        <p>
                            <label for="pass1"><?php esc_html_e( 'New Password', 'extra-chill-community' ); ?></label>
                            <input type="password" id="pass1" name="pass1" autocomplete="new-password">
                        </p>
                        <p>
                            <label for="pass2"><?php esc_html_e( 'Confirm New Password', 'extra-chill-community' ); ?></label>
                            <input type="password" id="pass2" name="pass2" autocomplete="new-password">
                        </p>
        </fieldset>
                    </div>
                </div>

                <!-- Item 3: Subscriptions -->
                <div class="shared-tab-item"> <!-- Changed class -->
                    <button type="button" class="shared-tab-button" data-tab="tab-subscriptions"> <!-- Changed class -->
                        <?php esc_html_e( 'Subscriptions', 'extra-chill-community' ); ?>
                        <span class="shared-tab-arrow"></span> <!-- Changed class -->
                    </button>
                    <div id="tab-subscriptions" class="shared-tab-pane"> <!-- Changed class, removed style -->
                        <h2><?php esc_html_e( 'Subscriptions & Email Preferences', 'extra-chill-community' ); ?></h2>
                        <p><?php esc_html_e( 'Manage email consent for bands you follow. Unchecking will prevent a band from seeing your email or including it in their exports.', 'extra-chill-community' ); ?></p>
                        
                        <?php // Removed individual form tag and nonce ?>
                        <div id="followed-bands-list">
            <?php
                            global $wpdb;
                            $table_name = $wpdb->prefix . 'artist_subscribers';
                            $current_user_id = get_current_user_id();

                            // Get all artist_profile_ids for which the current user has a 'platform_follow_consent'
                            $consented_artist_ids_results = $wpdb->get_results( $wpdb->prepare(
                                "SELECT artist_profile_id FROM {$table_name} WHERE user_id = %d AND source = 'platform_follow_consent'",
                                $current_user_id
                            ), ARRAY_A );
                            $consented_artist_ids = !empty($consented_artist_ids_results) ? wp_list_pluck( $consented_artist_ids_results, 'artist_profile_id' ) : array();

                            $followed_artists_posts = function_exists('bp_get_user_followed_bands') ? bp_get_user_followed_bands( $current_user_id, array('posts_per_page' => -1) ) : array();
                            
                            if ( ! empty( $followed_artists_posts ) ) :
                            ?>
                                <ul class="followed-bands-settings">
                                    <?php foreach ( $followed_artists_posts as $artist_post ) :
                                        $artist_id = $artist_post->ID;
                                        $artist_name = get_the_title( $artist_id );
                                        $artist_url = get_permalink( $artist_id );
                                        // Determine if user has consented for this specific band via platform follow
                                        $has_platform_consent = in_array( $artist_id, $consented_artist_ids );
                                    ?>
                                    <li>
                                        <input type="checkbox"
                                               id="artist_consent_<?php echo esc_attr( $artist_id ); ?>"
                                               name="artists_consented[]" // Ensure this is the correct name for the AJAX handler
                                               value="<?php echo esc_attr( $artist_id ); ?>"
                                               <?php checked( $has_platform_consent, true ); ?>>
                                        <label for="artist_consent_<?php echo esc_attr( $artist_id ); ?>">
                                            <?php
                                            printf(
                                                esc_html__( 'Share my email with %s', 'extra-chill-community' ),
                                                '<a href="' . esc_url( $artist_url ) . '" target="_blank">' . esc_html( $artist_name ) . '</a>'
                                            );
                                            ?>
                                        </label>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p><?php esc_html_e( 'You are not currently following any bands.', 'extra-chill-community' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div> <!-- End shared-tabs-buttons-container -->

            <div class="shared-desktop-tab-content-area" style="display: none;"></div> <!-- Added desktop content area -->

        </div> <!-- End shared-tabs-component -->

        <div class="user-settings-save-button-container" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
            <input type="submit" name="submit_user_settings" class="button button-primary" value="<?php esc_attr_e( 'Save All Settings', 'extra-chill-community' ); ?>">
        </div>

    </form> <!-- End user-settings-form -->
</div>

<?php
get_footer();
?>
