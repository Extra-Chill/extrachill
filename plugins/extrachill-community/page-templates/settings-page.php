<?php
/* Template Name: Account Settings */

// Form processing logic is now in functions.php

get_header();
?>
<?php // The <div id="page"> and <div id="content"> are typically opened in header.php by GeneratePress. We remove them from here. ?>
<?php // The generate_inside_site_container and generate_inside_container hooks are also part of the main structure. ?>

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

?>

<div class="account-settings-page"> <!-- Renamed class for page-level styling -->
    <h1><?php esc_html_e( 'Settings', 'generatepress_child' ); ?></h1>

    <form method="post" enctype="multipart/form-data" id="user-settings-form">
        <?php wp_nonce_field('update-user-settings_' . $user_id, '_wpnonce_update_user_settings'); ?>
        <input type="hidden" name="current_tab_hash" id="current_tab_hash" value=""> <!-- Will be populated by JS -->

        <div class="shared-tabs-component"> <!-- Added shared-tabs-component wrapper -->
            <div class="shared-tabs-buttons-container"> <!-- Changed class -->
                <!-- Item 1: Account Details -->
                <div class="shared-tab-item"> <!-- Changed class -->
                    <button type="button" class="shared-tab-button active" data-tab="tab-account-details"> <!-- Changed class, added active -->
                        <?php esc_html_e( 'Account Details', 'generatepress_child' ); ?>
                        <span class="shared-tab-arrow open"></span> <!-- Changed class, added open because button is active -->
                    </button>
                    <div id="tab-account-details" class="shared-tab-pane"> <!-- Changed class, removed style -->
                        <h2><?php esc_html_e( 'Account Details', 'generatepress_child' ); ?></h2>
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
                            <label for="first_name"><?php esc_html_e( 'First Name', 'generatepress_child' ); ?></label>
            <input type="text" id="first_name" name="first_name" value="<?php echo esc_attr($current_user->first_name); ?>">
                        </p>
                        <p>
                            <label for="last_name"><?php esc_html_e( 'Last Name', 'generatepress_child' ); ?></label>
            <input type="text" id="last_name" name="last_name" value="<?php echo esc_attr($current_user->last_name); ?>">
                        </p>
                        <?php /* Nickname field removed as per user request */ ?>
                        <p>
                            <label for="display_name"><?php esc_html_e( 'Display Name', 'generatepress_child' ); ?></label>
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
                        <?php esc_html_e( 'Security', 'generatepress_child' ); ?>
                        <span class="shared-tab-arrow"></span> <!-- Changed class -->
                    </button>
                    <div id="tab-security" class="shared-tab-pane"> <!-- Changed class, removed style -->
                        <h2><?php esc_html_e( 'Security', 'generatepress_child' ); ?></h2>
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
                        <p>
                            <label for="email"><?php esc_html_e( 'Email Address (cannot be changed here)', 'generatepress_child' ); ?></label>
                            <input type="email" id="email" name="email" value="<?php echo esc_attr($current_user->user_email); ?>" disabled autocomplete="email">
                             <span class="description"><?php esc_html_e('Email changes require confirmation and are handled via your profile edit page.', 'generatepress_child'); ?></span>
                        </p>
                        <p>
                            <label for="pass1"><?php esc_html_e( 'New Password', 'generatepress_child' ); ?></label>
                            <input type="password" id="pass1" name="pass1" autocomplete="new-password">
                        </p>
                        <p>
                            <label for="pass2"><?php esc_html_e( 'Confirm New Password', 'generatepress_child' ); ?></label>
                            <input type="password" id="pass2" name="pass2" autocomplete="new-password">
                        </p>
        </fieldset>
                    </div>
                </div>

                <!-- Item 3: Subscriptions -->
                <div class="shared-tab-item"> <!-- Changed class -->
                    <button type="button" class="shared-tab-button" data-tab="tab-subscriptions"> <!-- Changed class -->
                        <?php esc_html_e( 'Subscriptions', 'generatepress_child' ); ?>
                        <span class="shared-tab-arrow"></span> <!-- Changed class -->
                    </button>
                    <div id="tab-subscriptions" class="shared-tab-pane"> <!-- Changed class, removed style -->
                        <h2><?php esc_html_e( 'Subscriptions & Email Preferences', 'generatepress_child' ); ?></h2>
                        <p><?php esc_html_e( 'Manage email consent for bands you follow. Unchecking will prevent a band from seeing your email or including it in their exports.', 'generatepress_child' ); ?></p>
                        
                        <?php // Removed individual form tag and nonce ?>
                        <div id="followed-bands-list">
            <?php
                            $followed_bands = function_exists('bp_get_user_followed_bands') ? bp_get_user_followed_bands( $user_id, array('posts_per_page' => -1) ) : array();
                            $email_permissions = get_user_meta( $user_id, '_band_follow_email_permissions', true );
                            if ( ! is_array( $email_permissions ) ) {
                                $email_permissions = array();
                            }

                            if ( ! empty( $followed_bands ) ) :
                            ?>
                                <ul class="followed-bands-settings">
                                    <?php foreach ( $followed_bands as $band_post ) :
                                        $band_id = $band_post->ID;
                                        // Add a hidden field for each band ID displayed on the page
                                        echo '<input type="hidden" name="followed_bands_on_page[]" value="' . esc_attr( $band_id ) . '">';
                                        $band_name = get_the_title( $band_id );
                                        $band_url = get_permalink( $band_id );
                                        $can_share_email = isset( $email_permissions[ $band_id ] ) ? (bool) $email_permissions[ $band_id ] : false; // Default to false if not set for safety
                                    ?>
                                    <li>
                                        <input type="checkbox"
                                               id="band_consent_<?php echo esc_attr( $band_id ); ?>"
                                               name="band_email_consent[<?php echo esc_attr( $band_id ); ?>]"
                                               value="1"
                                               <?php checked( $can_share_email, true ); ?>>
                                        <label for="band_consent_<?php echo esc_attr( $band_id ); ?>">
                                            <?php
                                            printf(
                                                esc_html__( 'Share my email with %s', 'generatepress_child' ),
                                                '<a href="' . esc_url( get_permalink( $band_id ) ) . '" target="_blank">' . esc_html( get_the_title( $band_id ) ) . '</a>'
                                            );
                                            ?>
                                        </label>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p><?php esc_html_e( 'You are not currently following any bands.', 'generatepress_child' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div> <!-- End shared-tabs-buttons-container -->

            <div class="shared-desktop-tab-content-area" style="display: none;"></div> <!-- Added desktop content area -->

        </div> <!-- End shared-tabs-component -->

        <div class="user-settings-save-button-container" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
            <input type="submit" name="submit_user_settings" class="button button-primary" value="<?php esc_attr_e( 'Save All Settings', 'generatepress_child' ); ?>">
        </div>

    </form> <!-- End user-settings-form -->
</div>
<?php // Script removed from here and will be handled by shared-tabs.js ?>
        </div><!-- .site-content -->
    </div><!-- .page -->
<?php // The generate_after_primary_content_area and generate_after_content hooks should also be part of the main structure from footer.php ?>
<?php // The closing #content and #page divs are handled by get_footer() in GeneratePress. ?>
<?php
get_footer();
?>
