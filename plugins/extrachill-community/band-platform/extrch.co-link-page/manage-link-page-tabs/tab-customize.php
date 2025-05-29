<?php
/**
 * Template Part: Customize Tab for Manage Link Page
 *
 * Hydrates all custom vars exclusively from canonical $data['css_vars'] (set by LivePreviewManager).
 * Do not hydrate from post meta here. This enforces a single source of truth.
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

// Hydrate from canonical $data['css_vars'] only
$data = get_query_var('data', array());
$custom_vars = isset($data['css_vars']) && is_array($data['css_vars']) ? $data['css_vars'] : array();

?>

<!-- Fonts Card -->
<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Fonts', 'generatepress_child'); ?></h4>
    <div class="customize-section customize-title-section">
        <label for="link_page_title_font_family"><strong><?php esc_html_e('Title Font', 'generatepress_child'); ?></strong></label><br>
        <select id="link_page_title_font_family" name="link_page_title_font_family" style="max-width:200px;">
            <?php
            $current_font_family = isset($custom_vars['--link-page-title-font-family']) ? $custom_vars['--link-page-title-font-family'] : 'WilcoLoftSans';
            if (strpos($current_font_family, ',') !== false) {
                $parts = explode(',', $current_font_family);
                $current_font_family = trim($parts[0], " '");
            }
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
        <div class="customize-section customize-body-font-section" style="margin-top: 20px;">
            <label for="link_page_body_font_family"><strong><?php esc_html_e('Body Font', 'generatepress_child'); ?></strong></label><br>
            <select id="link_page_body_font_family" name="link_page_body_font_family" style="max-width:200px;">
                <?php
                $current_body_font_value = isset($custom_vars['--link-page-body-font-family']) 
                                            ? $custom_vars['--link-page-body-font-family'] 
                                            : 'Helvetica';
                if (strpos($current_body_font_value, ',') !== false) {
                    $parts = explode(',', $current_body_font_value);
                    $current_body_font_value = trim($parts[0], " '");
                }
                foreach ($extrch_link_page_fonts as $font) {
                    echo '<option value="' . esc_attr($font['value']) . '" data-googlefontparam="' . esc_attr($font['google_font_param']) . '"' . selected($current_body_font_value, $font['value'], false) . '>' . esc_html($font['label']) . '</option>';
                }
                ?>
            </select>
            <!-- Placeholder for Body Font Size/Color if needed later -->
        </div>
    </div>
</div>

<!-- Profile Image Card -->
<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Profile Image', 'generatepress_child'); ?></h4>
    <div class="customize-section">
        <label for="link_page_profile_img_shape"><strong><?php esc_html_e('Profile Image Shape', 'generatepress_child'); ?></strong></label><br>
        <?php
        $current_shape = get_post_meta($link_page_id, '_link_page_profile_img_shape', true);
        if (empty($current_shape)) $current_shape = 'circle';
        ?>
        <label>
            <input type="radio" name="link_page_profile_img_shape_radio" id="profile-img-shape-circle" value="circle" <?php checked($current_shape, 'circle'); ?>>
            <?php esc_html_e('Circle', 'generatepress_child'); ?>
        </label>
        <label style="margin-left: 1em;">
            <input type="radio" name="link_page_profile_img_shape_radio" id="profile-img-shape-square" value="square" <?php checked($current_shape, 'square'); ?>>
            <?php esc_html_e('Square', 'generatepress_child'); ?>
        </label>
        <label style="margin-left: 1em;">
            <input type="radio" name="link_page_profile_img_shape_radio" id="profile-img-shape-rectangle" value="rectangle" <?php checked($current_shape, 'rectangle'); ?>>
            <?php esc_html_e('Rectangle', 'generatepress_child'); ?>
        </label>
        <input type="hidden" name="link_page_profile_img_shape" id="link_page_profile_img_shape_hidden" value="<?php echo esc_attr($current_shape); ?>">
    </div>
    <div class="customize-section" style="margin-top: 18px;">
        <label for="link_page_profile_img_size"><strong><?php esc_html_e('Profile Image Size', 'generatepress_child'); ?></strong></label><br>
        <input type="range" id="link_page_profile_img_size" name="link_page_profile_img_size" min="1" max="100" value="30" step="1" style="width: 180px; vertical-align: middle;">
        <output for="link_page_profile_img_size" id="profile_img_size_output" style="margin-left: 10px; vertical-align: middle;">30%</output>
        <p class="description" style="margin-top: 0.5em; font-size: 0.97em;">
            <?php esc_html_e('Adjust the profile image size (relative to the card width).', 'generatepress_child'); ?>
        </p>
    </div>
</div>

<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Background', 'generatepress_child'); ?></h4>
    <div class="customize-section customize-background-section">
        <label for="link_page_background_type"><strong><?php esc_html_e('Background Type', 'generatepress_child'); ?></strong></label><br>
        <?php
        $background_type = isset($custom_vars['--link-page-background-type']) ? $custom_vars['--link-page-background-type'] : 'color';
        $background_color = isset($custom_vars['--link-page-background-color']) ? $custom_vars['--link-page-background-color'] : '#1a1a1a';
        $background_gradient_start = isset($custom_vars['--link-page-background-gradient-start']) ? $custom_vars['--link-page-background-gradient-start'] : '#0b5394';
        $background_gradient_end = isset($custom_vars['--link-page-background-gradient-end']) ? $custom_vars['--link-page-background-gradient-end'] : '#53940b';
        $background_gradient_direction = isset($custom_vars['--link-page-background-gradient-direction']) ? $custom_vars['--link-page-background-gradient-direction'] : 'to right';
        ?>
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
            <input type="color" id="link_page_background_gradient_start" name="link_page_background_gradient_start" value="<?php echo esc_attr($background_gradient_start); ?>">
            <input type="color" id="link_page_background_gradient_end" name="link_page_background_gradient_end" value="<?php echo esc_attr($background_gradient_end); ?>">
            <select id="link_page_background_gradient_direction" name="link_page_background_gradient_direction" style="margin-left:10px;">
                <option value="to right"<?php selected($background_gradient_direction, 'to right'); ?>>→ <?php esc_html_e('Left to Right', 'generatepress_child'); ?></option>
                <option value="to bottom"<?php selected($background_gradient_direction, 'to bottom'); ?>>↓ <?php esc_html_e('Top to Bottom', 'generatepress_child'); ?></option>
                <option value="135deg"<?php selected($background_gradient_direction, '135deg'); ?>>↘ <?php esc_html_e('Diagonal', 'generatepress_child'); ?></option>
            </select>
        </div>
        <div id="background-image-controls" class="background-type-controls" style="display:none;">
            <label for="link_page_background_image_upload"><strong><?php esc_html_e('Background Image', 'generatepress_child'); ?></strong></label><br>
            <input type="file" id="link_page_background_image_upload" name="link_page_background_image_upload" accept="image/*">
            <p class="description" style="margin-top: 0.5em; font-size: 0.9em;">
                <?php esc_html_e('Maximum file size: 5MB.', 'generatepress_child'); ?>
            </p>
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

<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Colors', 'generatepress_child'); ?></h4>
    <div class="customize-section">
        <label for="link_page_button_color"><strong><?php esc_html_e('Button Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_button_color" name="link_page_button_color" value="<?php echo esc_attr($custom_vars['--link-page-button-bg-color'] ?? '#0b5394'); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_text_color"><strong><?php esc_html_e('Text Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_text_color" name="link_page_text_color" value="<?php echo esc_attr($custom_vars['--link-page-text-color'] ?? '#e5e5e5'); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_link_text_color"><strong><?php esc_html_e('Link Text Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_link_text_color" name="link_page_link_text_color" value="<?php echo esc_attr($custom_vars['--link-page-link-text-color'] ?? '#ffffff'); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_hover_color"><strong><?php esc_html_e('Hover Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_hover_color" name="link_page_hover_color" value="<?php echo esc_attr($custom_vars['--link-page-button-hover-bg-color'] ?? '#53940b'); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_button_border_color"><strong><?php esc_html_e('Button Border Color', 'generatepress_child'); ?></strong></label><br>
        <input type="color" id="link_page_button_border_color" name="link_page_button_border_color" value="<?php echo esc_attr($custom_vars['--link-page-button-border-color'] ?? '#0b5394'); ?>">
    </div>
</div>

<!-- Buttons Card -->
<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Buttons', 'generatepress_child'); ?></h4>
    <div class="customize-section customize-button-shape-section">
        <label for="link_page_button_radius"><strong><?php esc_html_e('Button Radius', 'generatepress_child'); ?></strong></label><br>
        <input type="range" id="link_page_button_radius" name="link_page_button_radius" min="0" max="50" value="8" step="1" style="width: 180px; vertical-align: middle;">
        <output for="link_page_button_radius" id="button_radius_output" style="margin-left: 10px; vertical-align: middle;">8%</output>
        <p class="description" style="margin-top: 0.5em; font-size: 0.97em;">
            <?php esc_html_e('Adjust the button border radius from square (0px) to pill (50px).', 'generatepress_child'); ?>
        </p>
    </div>
</div>

<!-- Add the hidden input for custom CSS vars JSON -->
<input type="hidden" id="link_page_custom_css_vars_json" name="link_page_custom_css_vars_json" value="<?php echo esc_attr(json_encode($custom_vars)); ?>">