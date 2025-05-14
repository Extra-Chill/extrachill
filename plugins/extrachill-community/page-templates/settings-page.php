<?php
/* Template Name: Account Settings */

get_header();
?>
<div <?php generate_do_attr( 'page' ); ?>>
    <?php
    /**
     * generate_inside_site_container hook.
     */
    do_action( 'generate_inside_site_container' );
    ?>
    <div <?php generate_do_attr( 'site-content' ); ?>>
        <?php
        /**
         * generate_inside_container hook.
         */
        do_action( 'generate_inside_container' );
        ?>
        <?php extrachill_breadcrumbs(); ?>

<?php

// Ensure only logged-in users can access this page
if (!is_user_logged_in()) {
    auth_redirect();
}

$current_user = wp_get_current_user();

// Process form submission (example code provided later)

?>

<div class="account-settings">
    <h1>Settings</h1>

    <!-- Personal Information Form -->
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('update-personal-info', '_wpnonce_update_personal_info'); ?>

        <!-- Display errors for Personal Information Form -->
        <?php
        $personal_errors = get_transient('personal_settings_errors');
        delete_transient('personal_settings_errors'); // Clear the transient so errors don't persist on refresh

        if (!empty($personal_errors)) {
            echo '<div class="settings-errors">';
            foreach ($personal_errors as $error) {
                echo '<p class="error">' . esc_html($error) . '</p>';
            }
            echo '</div>';
        }
        ?>

        <!-- Personal Information Fields -->
        <fieldset class="name-settings">
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" value="<?php echo esc_attr($current_user->first_name); ?>">

            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" value="<?php echo esc_attr($current_user->last_name); ?>">

            <label for="nickname">Nickname</label>
            <input type="text" id="nickname" name="nickname" value="<?php echo esc_attr($current_user->nickname); ?>">

            <label for="display_name">Display Name</label>
            <select id="display_name" name="display_name">
                <?php
                $display_names = array_unique([
                    $current_user->first_name,
                    $current_user->last_name,
                    $current_user->first_name . ' ' . $current_user->last_name,
                    $current_user->nickname,
                ]);
                foreach ($display_names as $name) {
                    if (trim($name) === '') continue;
                    echo '<option value="' . esc_attr($name) . '"' . selected($current_user->display_name, $name, false) . '>' . esc_html($name) . '</option>';
                }
                ?>
            </select>
        </fieldset>

        <input type="submit" name="submit_personal_info" value="Save Personal Info">
    </form>

    <!-- Account Security Form -->
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('update-account-security', '_wpnonce_update_account_security'); ?>

        <!-- Display errors for Account Security Form -->
        <?php
        $security_errors = get_transient('security_settings_errors');
        delete_transient('security_settings_errors'); // Clear the transient so errors don't persist on refresh

        if (!empty($security_errors)) {
            echo '<div class="settings-errors">';
            foreach ($security_errors as $error) {
                echo '<p class="error">' . esc_html($error) . '</p>';
            }
            echo '</div>';
        }
        ?>

        <!-- Account Security Fields -->
        <fieldset class="account-settings">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?php echo esc_attr($current_user->user_email); ?>">

            <label for="pass1">New Password</label>
            <input type="password" id="pass1" name="pass1">
            <label for="pass2">Confirm New Password</label>
            <input type="password" id="pass2" name="pass2">
        </fieldset>

        <input type="submit" name="submit_account_security" value="Save Account Security">
    </form>
</div>

        </div><!-- .site-content -->
    </div><!-- .page -->
<?php
get_footer();
?>
