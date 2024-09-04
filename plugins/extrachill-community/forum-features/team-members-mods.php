<?php
function extrachill_add_team_meta($user) {
    // Whether the current user is an administrator
    $is_admin = current_user_can('administrator');
    // Fetching the current meta value
    $is_team_member = get_the_author_meta('extrachill_team', $user->ID);

    ?>
    <div class="hideme">
    <h3>Extra Chill Team Member</h3>
    <table class="form-table">
        <tr>
            <th><label for="extrachill_team"><?php _e('Extra Chill Team Member', 'text-domain'); ?></label></th>
            <td>
                <?php if ($is_admin): ?>
                    <input type="checkbox" name="extrachill_team" id="extrachill_team" <?php checked($is_team_member, 1); ?> value="1" />
                <?php else: ?>
                    <input type="checkbox" disabled="disabled" <?php checked($is_team_member, 1); ?> value="1" />
                    <input type="hidden" name="extrachill_team" value="<?php echo esc_attr($is_team_member); ?>">
                <?php endif; ?>
            </td>
        </tr>
    </table></div>
    <?php
}


add_action('show_user_profile', 'extrachill_add_team_meta');
add_action('edit_user_profile', 'extrachill_add_team_meta');

function extrachill_save_team_meta($user_id) {
    // Check if the current user is an administrator
    if (current_user_can('administrator')) {
        // Only update the 'extrachill_team' meta if the current user is an administrator
        update_user_meta($user_id, 'extrachill_team', isset($_POST['extrachill_team']) ? 1 : 0);
    }
    // If the user is not an administrator, do not update the 'extrachill_team' meta
}
add_action('personal_options_update', 'extrachill_save_team_meta');
add_action('edit_user_profile_update', 'extrachill_save_team_meta');

function extrachill_add_team_class($classes, $user_id) {
    // Check if the user is an Extra Chill Team member
    if (is_extrachill_team_member($user_id)) {
        $classes[] = 'extrachill-team-member'; // Add your custom class
    }

    return $classes;
}

