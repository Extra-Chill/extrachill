<?php
/**
 * User Info - Biographical Profile Fields
 *
 * Handles user biographical information fields: custom title, bio, local city.
 * Template rendering functions used by bbPress profile edit template.
 *
 * @package ExtraChillCommunity
 */

/**
 * Save custom title field
 */
function save_ec_custom_title($user_id) {
    if (isset($_POST['ec_custom_title'])) {
        update_user_meta($user_id, 'ec_custom_title', sanitize_text_field(wp_unslash($_POST['ec_custom_title'])));
    }
}
add_action('personal_options_update', 'save_ec_custom_title');
add_action('edit_user_profile_update', 'save_ec_custom_title');

/**
 * Save local scene details (city/region)
 */
function save_bbp_user_local_scene_details($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    if (isset($_POST['local_city'])) {
        update_user_meta($user_id, 'local_city', sanitize_text_field(wp_unslash($_POST['local_city'])));
    }
}
add_action('personal_options_update', 'save_bbp_user_local_scene_details');
add_action('edit_user_profile_update', 'save_bbp_user_local_scene_details');

/**
 * Render custom title field
 */
function extrachill_render_custom_title_field() {
    $current_custom_title = get_user_meta(bbp_get_displayed_user_id(), 'ec_custom_title', true);
    $label_text = !empty($current_custom_title)
        ? sprintf(esc_html__('Custom Title (Current: %s)', 'bbpress'), $current_custom_title)
        : esc_html__('Custom Title', 'bbpress');
    ?>
    <label for="ec_custom_title"><?php echo esc_html($label_text); ?></label>
    <input type="text"
           name="ec_custom_title"
           id="ec_custom_title"
           value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'ec_custom_title', true)); ?>"
           class="regular-text"
           placeholder="Extra Chillian" />
    <p class="description"><?php esc_html_e('Enter a custom title, or leave blank for default.', 'bbpress'); ?></p>
    <?php
}

/**
 * Render about section fields (bio + local city)
 */
function extrachill_render_about_section_fields() {
    ?>
    <?php do_action('bbp_user_edit_before_about'); ?>

    <div class="form-group">
        <label for="description"><?php esc_html_e('Bio', 'bbpress'); ?></label>
        <textarea name="description" id="description" rows="5" cols="30"><?php bbp_displayed_user_field('description', 'edit'); ?></textarea>
    </div>

    <div class="form-group">
        <label for="local_city"><?php esc_html_e('Local Scene (City/Region)', 'extra-chill-community'); ?></label>
        <input type="text"
               name="local_city"
               id="local_city"
               value="<?php echo esc_attr(get_user_meta(bbp_get_displayed_user_id(), 'local_city', true)); ?>"
               class="regular-text"
               placeholder="<?php esc_attr_e('Your local city/region...', 'extra-chill-community'); ?>" />
    </div>

    <?php do_action('bbp_user_edit_after_about'); ?>
    <?php
}
