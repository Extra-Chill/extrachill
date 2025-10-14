<?php
/**
 * Settings Page Content Renderer
 *
 * Hook-based settings page system uses extrachill_after_page_content
 * to inject complete settings interface without page template file.
 * Provides account details, security, and subscription management tabs.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

function extrachill_community_render_settings_content() {
    if (!is_page('settings')) {
        return;
    }

    if (!is_user_logged_in()) {
        auth_redirect();
        return;
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Display error messages
    $settings_errors = get_transient('user_settings_errors_' . $user_id);
    if ($settings_errors) {
        delete_transient('user_settings_errors_' . $user_id);
        echo '<div class="settings-errors notice notice-error is-dismissible">';
        foreach ($settings_errors as $error) {
            echo '<p>' . esc_html($error) . '</p>';
        }
        echo '</div>';
    }

    // Display success messages
    $settings_success = get_transient('user_settings_success_' . $user_id);
    if ($settings_success) {
        delete_transient('user_settings_success_' . $user_id);
        echo '<div class="settings-success notice notice-success is-dismissible">';
        foreach ($settings_success as $message) {
            echo '<p>' . esc_html($message) . '</p>';
        }
        echo '</div>';
    }

    // Check for pending email change (WordPress native)
    $new_email_data = get_user_meta($user_id, '_new_user_email', true);
    ?>

    <div class="account-settings-page">
        <h1><?php esc_html_e('Settings', 'extra-chill-community'); ?></h1>

        <form method="post" enctype="multipart/form-data" id="user-settings-form">
            <?php wp_nonce_field('update-user-settings_' . $user_id, '_wpnonce_update_user_settings'); ?>
            <input type="hidden" name="user_id" value="<?php echo esc_attr($user_id); ?>">
            <input type="hidden" name="current_tab_hash" id="current_tab_hash" value="">

            <div class="shared-tabs-component">
                <div class="shared-tabs-buttons-container">

                    <!-- Account Details Tab -->
                    <div class="shared-tab-item">
                        <button type="button" class="shared-tab-button active" data-tab="tab-account-details">
                            <?php esc_html_e('Account Details', 'extra-chill-community'); ?>
                            <span class="shared-tab-arrow open"></span>
                        </button>
                        <div id="tab-account-details" class="shared-tab-pane">
                            <h2><?php esc_html_e('Account Details', 'extra-chill-community'); ?></h2>
                            <fieldset class="name-settings">
                                <p>
                                    <label for="first_name"><?php esc_html_e('First Name', 'extra-chill-community'); ?></label>
                                    <input type="text" id="first_name" name="first_name" value="<?php echo esc_attr($current_user->first_name); ?>">
                                </p>
                                <p>
                                    <label for="last_name"><?php esc_html_e('Last Name', 'extra-chill-community'); ?></label>
                                    <input type="text" id="last_name" name="last_name" value="<?php echo esc_attr($current_user->last_name); ?>">
                                </p>
                                <p>
                                    <label for="display_name"><?php esc_html_e('Display Name', 'extra-chill-community'); ?></label>
                                    <select id="display_name" name="display_name">
                                        <?php
                                        $public_display = array();
                                        $public_display['nickname'] = $current_user->nickname;
                                        $public_display['username'] = $current_user->user_login;

                                        if (!empty($current_user->first_name))
                                            $public_display['firstname'] = $current_user->first_name;

                                        if (!empty($current_user->last_name))
                                            $public_display['lastname'] = $current_user->last_name;

                                        if (!empty($current_user->first_name) && !empty($current_user->last_name)) {
                                            $public_display['firstlast'] = $current_user->first_name . ' ' . $current_user->last_name;
                                            $public_display['lastfirst'] = $current_user->last_name . ' ' . $current_user->first_name;
                                        }

                                        $public_display = array_unique(array_filter(array_map('trim', $public_display)));

                                        foreach ($public_display as $id => $item) {
                                            ?>
                                            <option <?php selected($current_user->display_name, $item); ?>><?php echo esc_html($item); ?></option>
                                            <?php
                                        }
                                        ?>
                                    </select>
                                </p>
                            </fieldset>
                        </div>
                    </div>

                    <!-- Security Tab -->
                    <div class="shared-tab-item">
                        <button type="button" class="shared-tab-button" data-tab="tab-security">
                            <?php esc_html_e('Security', 'extra-chill-community'); ?>
                            <span class="shared-tab-arrow"></span>
                        </button>
                        <div id="tab-security" class="shared-tab-pane">
                            <h2><?php esc_html_e('Security', 'extra-chill-community'); ?></h2>
                            <fieldset class="account-settings">

                                <!-- Current Email Display -->
                                <p>
                                    <label for="current_email"><?php esc_html_e('Current Email Address', 'extra-chill-community'); ?></label>
                                    <input type="email" id="current_email" value="<?php echo esc_attr($current_user->user_email); ?>" disabled autocomplete="email">

                                    <?php if ($new_email_data && isset($new_email_data['newemail'])) : ?>
                                        <span class="email-status pending">
                                            <?php
                                            printf(
                                                /* translators: %s: new email address */
                                                __('Email change pending - verification sent to %s', 'extra-chill-community'),
                                                '<strong>' . esc_html($new_email_data['newemail']) . '</strong>'
                                            );
                                            ?>
                                            <br><small><?php esc_html_e('Check your inbox and click the verification link.', 'extra-chill-community'); ?></small>
                                        </span>
                                    <?php endif; ?>
                                </p>

                                <!-- New Email Field (WordPress native) -->
                                <p>
                                    <label for="email"><?php esc_html_e('New Email Address', 'extra-chill-community'); ?></label>
                                    <input type="email" id="email" name="email" value="" placeholder="<?php esc_attr_e('Enter new email address', 'extra-chill-community'); ?>" autocomplete="new-email">
                                    <span class="description">
                                        <?php esc_html_e('A verification email will be sent to your new address. Your current email will remain active until verification is complete.', 'extra-chill-community'); ?>
                                    </span>
                                </p>

                                <!-- Password Change Fields -->
                                <p>
                                    <label for="current_pass"><?php esc_html_e('Current Password', 'extra-chill-community'); ?> <span class="required">*</span></label>
                                    <input type="password" id="current_pass" name="current_pass" autocomplete="current-password">
                                </p>
                                <p>
                                    <label for="pass1"><?php esc_html_e('New Password', 'extra-chill-community'); ?></label>
                                    <input type="password" id="pass1" name="pass1" autocomplete="new-password">
                                </p>
                                <p>
                                    <label for="pass2"><?php esc_html_e('Confirm New Password', 'extra-chill-community'); ?></label>
                                    <input type="password" id="pass2" name="pass2" autocomplete="new-password">
                                </p>
                            </fieldset>
                        </div>
                    </div>

                    <!-- Subscriptions Tab -->
                    <div class="shared-tab-item">
                        <button type="button" class="shared-tab-button" data-tab="tab-subscriptions">
                            <?php esc_html_e('Subscriptions', 'extra-chill-community'); ?>
                            <span class="shared-tab-arrow"></span>
                        </button>
                        <div id="tab-subscriptions" class="shared-tab-pane">
                            <h2><?php esc_html_e('Subscriptions & Email Preferences', 'extra-chill-community'); ?></h2>
                            <p><?php esc_html_e('Manage email consent for bands you follow. Unchecking will prevent a band from seeing your email or including it in their exports.', 'extra-chill-community'); ?></p>

                            <div id="followed-bands-list">
                                <?php
                                global $wpdb;
                                $table_name = $wpdb->prefix . 'artist_subscribers';
                                $current_user_id = get_current_user_id();

                                $consented_artist_ids_results = $wpdb->get_results($wpdb->prepare(
                                    "SELECT artist_profile_id FROM {$table_name} WHERE user_id = %d AND source = 'platform_follow_consent'",
                                    $current_user_id
                                ), ARRAY_A);
                                $consented_artist_ids = !empty($consented_artist_ids_results) ? wp_list_pluck($consented_artist_ids_results, 'artist_profile_id') : array();

                                $followed_artists_posts = function_exists('bp_get_user_followed_bands') ? bp_get_user_followed_bands($current_user_id, array('posts_per_page' => -1)) : array();

                                if (!empty($followed_artists_posts)) :
                                ?>
                                    <ul class="followed-bands-settings">
                                        <?php foreach ($followed_artists_posts as $artist_post) :
                                            $artist_id = $artist_post->ID;
                                            $artist_name = get_the_title($artist_id);
                                            $artist_url = get_permalink($artist_id);
                                            $has_platform_consent = in_array($artist_id, $consented_artist_ids);
                                        ?>
                                        <li>
                                            <input type="checkbox"
                                                   id="artist_consent_<?php echo esc_attr($artist_id); ?>"
                                                   name="artists_consented[]"
                                                   value="<?php echo esc_attr($artist_id); ?>"
                                                   <?php checked($has_platform_consent, true); ?>>
                                            <label for="artist_consent_<?php echo esc_attr($artist_id); ?>">
                                                <?php
                                                printf(
                                                    esc_html__('Share my email with %s', 'extra-chill-community'),
                                                    '<a href="' . esc_url($artist_url) . '" target="_blank">' . esc_html($artist_name) . '</a>'
                                                );
                                                ?>
                                            </label>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else : ?>
                                    <p><?php esc_html_e('You are not currently following any bands.', 'extra-chill-community'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="shared-desktop-tab-content-area" style="display: none;"></div>
            </div>

            <div class="user-settings-save-button-container" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                <input type="submit" name="submit_user_settings" class="button" value="<?php esc_attr_e('Save All Settings', 'extra-chill-community'); ?>">
            </div>
        </form>
    </div>

    <?php
}
add_action('extrachill_after_page_content', 'extrachill_community_render_settings_content', 5);