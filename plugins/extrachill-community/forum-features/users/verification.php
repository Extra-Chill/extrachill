<?php
function extrachill_add_user_role_fields($user) {
    $is_admin = current_user_can('administrator');
    $artist = get_user_meta($user->ID, 'user_is_artist', true) == '1';
    $professional = get_user_meta($user->ID, 'user_is_professional', true) == '1';

    ?>
    <div class="hideme">
        <h3><?php _e("Extra User Information", "extra-chill-community"); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="user_is_artist"><?php _e("Artist Status"); ?></label></th>
                <td>
                    <input type="checkbox" name="user_is_artist" id="user_is_artist" value="1" <?php checked($artist, true); ?>>
                </td>
            </tr>
            <tr>
                <th><label for="user_is_professional"><?php _e("Industry Professional Status"); ?></label></th>
                <td>
                    <input type="checkbox" name="user_is_professional" id="user_is_professional" value="1" <?php checked($professional, true); ?>>
                </td>
            </tr>
        </table>
    </div>
    <?php
}

add_action('show_user_profile', 'extrachill_add_user_role_fields');
add_action('edit_user_profile', 'extrachill_add_user_role_fields');

function extrachill_save_user_meta($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Direct save for Artist status
    update_user_meta($user_id, 'user_is_artist', isset($_POST['user_is_artist']) ? '1' : '0');

    // Direct save for Professional status
    update_user_meta($user_id, 'user_is_professional', isset($_POST['user_is_professional']) ? '1' : '0');
}

add_action('personal_options_update', 'extrachill_save_user_meta');
add_action('edit_user_profile_update', 'extrachill_save_user_meta');

