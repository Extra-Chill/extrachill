<?php
/**
 * Template Part: Customize Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

defined( 'ABSPATH' ) || exit;

// Ensure variables from parent scope are available
// (e.g., $background_type, $background_color, $background_image_url, $button_color, $text_color, $link_text_color, $hover_color, $link_page_id, etc.)
// As with tab-info.php, we assume accessibility from the parent scope for this refactor.
global $background_type, $background_color, $background_image_url, $button_color, $text_color, $link_text_color, $hover_color, $link_page_id;

// $extrch_link_page_fonts is provided by link-page-includes.php, which should be included by the parent template.
$extrch_link_page_fonts = get_query_var('extrch_link_page_fonts', array());

?>
<h2><?php esc_html_e('Customize', 'generatepress_child'); ?></h2>

<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Background', 'generatepress_child'); ?></h4>
    <div class="customize-section customize-background-section">
        <label for="link_page_background_type"><strong><?php esc_html_e('Background Type', 'generatepress_child'); ?></strong></label><br>
        <select id="link_page_background_type" name="link_page_background_type" style="max-width:200px;">
            <option value="color"<?php selected($background_type, 'color'); ?>><?php esc_html_e('Solid Color', 'generatepress_child'); ?></option>
            <option value="gradient"<?php selected($background_type, 'gradient'); ?>><?php esc_html_e('Gradient', 'generatepress_child'); ?></option>
            <option value="image"<?php selected($background_type, 'image'); ?>><?php esc_html_e('Image', 'generatepress_child'); ?></option>
        </select>
        <div id="background-color-controls" class="background-type-controls">
            <label for="link_page_background_color"><strong><?php esc_html_e('Background Color', 'generatepress_child'); ?></strong></label><br>
            <input type="color" id="link_page_background_color" name="link_page_background_color" value="<?php echo esc_attr($background_color); ?>">
        </div>
        <div id="background-gradient-controls" class="background-type-controls" style="display:none;">
            <label><strong><?php esc_html_e('Gradient Colors', 'generatepress_child'); ?></strong></label><br>
            <input type="color" id="link_page_background_gradient_start" name="link_page_background_gradient_start" value="#0b5394">
            <input type="color" id="link_page_background_gradient_end" name="link_page_background_gradient_end" value="#53940b">
            <select id="link_page_background_gradient_direction" name="link_page_background_gradient_direction" style="margin-left:10px;">
                <option value="to right">→ <?php esc_html_e('Left to Right', 'generatepress_child'); ?></option>
                <option value="to bottom">↓ <?php esc_html_e('Top to Bottom', 'generatepress_child'); ?></option>
                <option value="135deg">↘ <?php esc_html_e('Diagonal', 'generatepress_child'); ?></option>
            </select>
        </div>
        <div id="background-image-controls" class="background-type-controls" style="display:none;">
            <label for="link_page_background_image_upload"><strong><?php esc_html_e('Background Image', 'generatepress_child'); ?></strong></label><br>
            <input type="file" id="link_page_background_image_upload" name="link_page_background_image_upload" accept="image/*">
            <div id="background-image-preview" style="margin-top:10px;"></div>
        </div>
        <div class="customize-section">
            <label>
                <input type="checkbox" id="link_page_overlay_toggle" name="link_page_overlay_toggle" value="1" <?php checked(get_post_meta($link_page_id, '_link_page_overlay_toggle', true), '1'); ?>>
                <?php esc_html_e('Overlay (Card Background & Shadow)', 'generatepress_child'); ?>
            </label>
            <input type="hidden" name="link_page_overlay_toggle_present" value="1">
        </div>
    </div>
</div>

<!-- Fonts Card -->
<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Fonts', 'generatepress_child'); ?></h4>
    <div class="customize-section customize-title-section">
        <label for="link_page_title_font_family"><strong><?php esc_html_e('Title Font', 'generatepress_child'); ?></strong></label><br>
        <select id="link_page_title_font_family" name="link_page_title_font_family" style="max-width:200px;">
            <?php
            $current_font_family = isset($custom_css_vars['--link-page-title-font-family']) ? $custom_css_vars['--link-page-title-font-family'] : 'WilcoLoftSans';
            foreach ($extrch_link_page_fonts as $font) {
                echo '<option value="' . esc_attr($font['value']) . '" data-googlefontparam="' . esc_attr($font['google_font_param']) . '"' . ($current_font_family === $font['value'] ? ' selected' : '') . '>' . esc_html($font['label']) . '</option>';
            }
            ?>
        </select>
        <div class="customize-subsection" style="margin-top: 15px;">
            <label for="link_page_title_font_size"><strong><?php esc_html_e('Title Size', 'generatepress_child'); ?></strong></label><br>
            <input type="range" id="link_page_title_font_size" name="link_page_title_font_size" min="1" max="100" value="50" step="1" style="width: 180px; vertical-align: middle;">
            <output for="link_page_title_font_size" id="title_font_size_output" style="margin-left: 10px; vertical-align: middle;">50%</output>
        </div>
        <!-- Placeholder for Title Color Picker (if different from general text color) -->
    </div>
</div>

<!-- Profile Image Card -->
<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Profile Image', 'generatepress_child'); ?></h4>
    <div class="customize-section">
        <label for="link_page_profile_img_shape"><strong><?php esc_html_e('Profile Image Shape', 'generatepress_child'); ?></strong></label><br>
        <?php
        $current_shape = get_post_meta($link_page_id, '_link_page_profile_img_shape', true);
        if (empty($current_shape) || $current_shape === 'rectangle') {
            $current_shape = 'square';
        }
        $is_circle_selected = ($current_shape === 'circle');
        ?>
        <label class="switch">
            <input type="checkbox" id="link_page_profile_img_shape_toggle" name="link_page_profile_img_shape_toggle" value="circle" <?php checked($is_circle_selected); ?> onchange="document.getElementById('profile-img-shape-value').textContent = this.checked ? 'Circle' : 'Square'; document.getElementById('link_page_profile_img_shape_hidden').value = this.checked ? 'circle' : 'square';">
            <span class="slider round"></span>
        </label>
        <span id="profile-img-shape-value"><?php echo $is_circle_selected ? 'Circle' : 'Square'; ?></span>
        <input type="hidden" name="link_page_profile_img_shape" id="link_page_profile_img_shape_hidden" value="<?php echo esc_attr($current_shape); ?>">
    </div>
    <div class="customize-section" style="margin-top: 18px;">
        <label for="link_page_profile_img_size"><strong><?php esc_html_e('Profile Image Size', 'generatepress_child'); ?></strong></label><br>
        <input type="range" id="link_page_profile_img_size" name="link_page_profile_img_size" min="1" max="100" value="30" step="1" style="width: 180px; vertical-align: middle;">
        <output for="link_page_profile_img_size" id="profile_img_size_output" style="margin-left: 10px; vertical-align: middle;">30%</output>
        <p class="description" style="margin-top: 0.5em; color: #888; font-size: 0.97em;">
            <?php esc_html_e('Adjust the profile image size (relative to the card width).', 'generatepress_child'); ?>
        </p>
    </div>
</div>

<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Colors', 'generatepress_child'); ?></h4>
    <div class="customize-section">
        <label for="link_page_button_color"><strong><?php esc_html_e('Button Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_button_color" name="link_page_button_color" value="<?php echo esc_attr($button_color); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_text_color"><strong><?php esc_html_e('Text Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_text_color" name="link_page_text_color" value="<?php echo esc_attr($text_color); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_link_text_color"><strong><?php esc_html_e('Link Text Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_link_text_color" name="link_page_link_text_color" value="<?php echo esc_attr($link_text_color); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_hover_color"><strong><?php esc_html_e('Hover Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_hover_color" name="link_page_hover_color" value="<?php echo esc_attr($hover_color); ?>">
    </div>
</div>

<!-- Moved switch CSS to main stylesheet -->
<input type="hidden" name="link_page_custom_css_vars_json" id="link_page_custom_css_vars_json" value="<?php echo esc_attr(get_post_meta($link_page_id, '_link_page_custom_css_vars', true) ?: ''); ?>"> 