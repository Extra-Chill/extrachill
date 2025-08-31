<?php
/**
 * Template Part: Customize Tab for Manage Link Page
 *
 * Hydrates all custom vars exclusively from canonical $data['css_vars'] (set by ec_get_link_page_data filter).
 * Do not hydrate from post meta here. This enforces a single source of truth.
 *
 * Loaded from manage-link-page.php
 */

// All customize tab data should be hydrated from $data provided by ec_get_link_page_data filter.

defined( 'ABSPATH' ) || exit;

// Extract arguments passed from ec_render_template
$data = $data ?? array();

// Extract variables from $data
$background_type = $data['background_type'] ?? 'color';
$background_color = $data['background_color'] ?? '#1a1a1a';
$background_gradient_start = $data['background_gradient_start'] ?? '#0b5394';
$background_gradient_end = $data['background_gradient_end'] ?? '#53940b';
$background_gradient_direction = $data['background_gradient_direction'] ?? 'to right';
$background_image_id = $data['background_image_id'] ?? '';
$background_image_url = $data['background_image_url'] ?? '';

// CSS variable related values
$button_color = $data['css_vars']['--link-page-button-color'] ?? '#0b5394';
$text_color = $data['css_vars']['--link-page-text-color'] ?? '#e5e5e5';
$link_text_color = $data['css_vars']['--link-page-link-text-color'] ?? '#ffffff';
$hover_color = $data['css_vars']['--link-page-hover-color'] ?? '#083b6c';

// $extrch_link_page_fonts is provided by the font config system
$extrch_link_page_fonts = apply_filters('ec_artist_platform_fonts', array());
$custom_vars = isset($data['css_vars']) && is_array($data['css_vars']) ? $data['css_vars'] : array();
$settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : array();

?>


<!-- Fonts Card -->
<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Fonts', 'extrachill-artist-platform'); ?></h4>
    <div class="customize-section customize-title-section">
        <label for="link_page_title_font_family"><strong><?php esc_html_e('Title Font', 'extrachill-artist-platform'); ?></strong></label><br>
        <select id="link_page_title_font_family" name="link_page_title_font_family" style="max-width:200px;">
            <?php
            // Use raw font values for dropdown selection (single source of truth)
            $current_font_family = isset($data['raw_font_values']['title_font']) ? $data['raw_font_values']['title_font'] : '';
            foreach ($extrch_link_page_fonts as $font) {
                echo '<option value="' . esc_attr($font['value']) . '" data-googlefontparam="' . esc_attr($font['google_font_param']) . '"' . ($current_font_family === $font['value'] ? ' selected' : '') . '>' . esc_html($font['label']) . '</option>';
            }
            ?>
        </select>
        <div class="customize-subsection" style="margin-top: 15px;">
            <label for="link_page_title_font_size"><strong><?php esc_html_e('Title Size', 'extrachill-artist-platform'); ?></strong></label><br>
            <?php 
            // Calculate slider value from stored em value using original conversion
            $font_size_min_em = 0.8;
            $font_size_max_em = 3.5;
            $current_em_string = $custom_vars['--link-page-title-font-size'] ?? '2.1em';
            $current_em_value = (float) str_replace('em', '', $current_em_string);
            $percentage = ($current_em_value - $font_size_min_em) / ($font_size_max_em - $font_size_min_em);
            $slider_value = max(1, min(100, round($percentage * 100)));
            ?>
            <input type="range" id="link_page_title_font_size" name="link_page_title_font_size" min="1" max="100" value="<?php echo esc_attr($slider_value); ?>" step="1" style="width: 180px; vertical-align: middle;">
            <output for="link_page_title_font_size" id="title_font_size_output" style="margin-left: 10px; vertical-align: middle;"><?php echo esc_html($slider_value . '%'); ?></output>
        </div>
        <div class="customize-section customize-body-font-section" style="margin-top: 20px;">
            <label for="link_page_body_font_family"><strong><?php esc_html_e('Body Font', 'extrachill-artist-platform'); ?></strong></label><br>
            <select id="link_page_body_font_family" name="link_page_body_font_family" style="max-width:200px;">
                <?php
                // Use raw font values for dropdown selection (single source of truth)
                $current_body_font_value = isset($data['raw_font_values']['body_font']) ? $data['raw_font_values']['body_font'] : '';
                foreach ($extrch_link_page_fonts as $font) {
                    echo '<option value="' . esc_attr($font['value']) . '" data-googlefontparam="' . esc_attr($font['google_font_param']) . '"' . selected($current_body_font_value, $font['value'], false) . '>' . esc_html($font['label']) . '</option>';
                }
                ?>
            </select>
            <!-- Removed body font size control - uses theme default font size -->
        </div>
    </div>
</div>

<!-- Profile Image Card -->
<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Profile Image', 'extrachill-artist-platform'); ?></h4>
    <div class="customize-section">
        <label for="link_page_profile_img_shape"><strong><?php esc_html_e('Profile Image Shape', 'extrachill-artist-platform'); ?></strong></label><br>
        <?php
        $current_shape = isset($data['profile_img_shape']) ? $data['profile_img_shape'] : 'circle';
        ?>
        <label>
            <input type="radio" name="link_page_profile_img_shape_radio" id="profile-img-shape-circle" value="circle" <?php checked($current_shape, 'circle'); ?>>
            <?php esc_html_e('Circle', 'extrachill-artist-platform'); ?>
        </label>
        <label style="margin-left: 1em;">
            <input type="radio" name="link_page_profile_img_shape_radio" id="profile-img-shape-square" value="square" <?php checked($current_shape, 'square'); ?>>
            <?php esc_html_e('Square', 'extrachill-artist-platform'); ?>
        </label>
        <label style="margin-left: 1em;">
            <input type="radio" name="link_page_profile_img_shape_radio" id="profile-img-shape-rectangle" value="rectangle" <?php checked($current_shape, 'rectangle'); ?>>
            <?php esc_html_e('Rectangle', 'extrachill-artist-platform'); ?>
        </label>
        <input type="hidden" name="link_page_profile_img_shape" id="link_page_profile_img_shape_hidden" value="<?php echo esc_attr($current_shape); ?>">
    </div>
    <div class="customize-section" style="margin-top: 18px;">
        <label for="link_page_profile_img_size"><strong><?php esc_html_e('Profile Image Size', 'extrachill-artist-platform'); ?></strong></label><br>
        <input type="range" id="link_page_profile_img_size" name="link_page_profile_img_size" min="1" max="100" value="30" step="1" style="width: 180px; vertical-align: middle;">
        <output for="link_page_profile_img_size" id="profile_img_size_output" style="margin-left: 10px; vertical-align: middle;">30%</output>
        <p class="description" style="margin-top: 0.5em; font-size: 0.97em;">
            <?php esc_html_e('Adjust the profile image size (relative to the card width).', 'extrachill-artist-platform'); ?>
        </p>
    </div>
</div>

<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Background', 'extrachill-artist-platform'); ?></h4>
    <div class="customize-section customize-background-section">
        <label for="link_page_background_type"><strong><?php esc_html_e('Background Type', 'extrachill-artist-platform'); ?></strong></label><br>
        <?php
        $background_type = isset($custom_vars['--link-page-background-type']) ? $custom_vars['--link-page-background-type'] : 'color';
        $background_color = isset($custom_vars['--link-page-background-color']) ? $custom_vars['--link-page-background-color'] : '#1a1a1a';
        $background_gradient_start = isset($custom_vars['--link-page-background-gradient-start']) ? $custom_vars['--link-page-background-gradient-start'] : '#0b5394';
        $background_gradient_end = isset($custom_vars['--link-page-background-gradient-end']) ? $custom_vars['--link-page-background-gradient-end'] : '#53940b';
        $background_gradient_direction = isset($custom_vars['--link-page-background-gradient-direction']) ? $custom_vars['--link-page-background-gradient-direction'] : 'to right';
        ?>
        <select id="link_page_background_type" name="link_page_background_type" style="max-width:200px;">
            <option value="color"<?php selected($background_type, 'color'); ?>><?php esc_html_e('Solid Color', 'extrachill-artist-platform'); ?></option>
            <option value="gradient"<?php selected($background_type, 'gradient'); ?>><?php esc_html_e('Gradient', 'extrachill-artist-platform'); ?></option>
            <option value="image"<?php selected($background_type, 'image'); ?>><?php esc_html_e('Image', 'extrachill-artist-platform'); ?></option>
        </select>
        <div id="background-color-controls" class="background-type-controls" style="<?php echo ($background_type === 'color') ? '' : 'display:none;'; ?>">
            <label for="link_page_background_color"><strong><?php esc_html_e('Background Color', 'extrachill-artist-platform'); ?></strong></label><br>
            <input type="color" id="link_page_background_color" name="link_page_background_color" value="<?php echo esc_attr($background_color); ?>">
        </div>
        <div id="background-gradient-controls" class="background-type-controls" style="<?php echo ($background_type === 'gradient') ? '' : 'display:none;'; ?>">
            <label><strong><?php esc_html_e('Gradient Colors', 'extrachill-artist-platform'); ?></strong></label><br>
            <input type="color" id="link_page_background_gradient_start" name="link_page_background_gradient_start" value="<?php echo esc_attr($background_gradient_start); ?>">
            <input type="color" id="link_page_background_gradient_end" name="link_page_background_gradient_end" value="<?php echo esc_attr($background_gradient_end); ?>">
            <select id="link_page_background_gradient_direction" name="link_page_background_gradient_direction" style="margin-left:10px;">
                <option value="to right"<?php selected($background_gradient_direction, 'to right'); ?>>→ <?php esc_html_e('Left to Right', 'extrachill-artist-platform'); ?></option>
                <option value="to bottom"<?php selected($background_gradient_direction, 'to bottom'); ?>>↓ <?php esc_html_e('Top to Bottom', 'extrachill-artist-platform'); ?></option>
                <option value="135deg"<?php selected($background_gradient_direction, '135deg'); ?>>↘ <?php esc_html_e('Diagonal', 'extrachill-artist-platform'); ?></option>
            </select>
        </div>
        <div id="background-image-controls" class="background-type-controls" style="<?php echo ($background_type === 'image') ? '' : 'display:none;'; ?>">
            <label for="link_page_background_image_upload"><strong><?php esc_html_e('Background Image', 'extrachill-artist-platform'); ?></strong></label><br>
            <input type="file" id="link_page_background_image_upload" name="link_page_background_image_upload" accept="image/*">
            <p class="description" style="margin-top: 0.5em; font-size: 0.9em;">
                <?php esc_html_e('Maximum file size: 5MB.', 'extrachill-artist-platform'); ?>
            </p>
            <div id="background-image-preview" style="margin-top:10px;"></div>
            
            <!-- Additional Background Image Controls -->
            <div style="margin-top: 15px;">
                <label for="link_page_background_image_size"><strong><?php esc_html_e('Background Size', 'extrachill-artist-platform'); ?></strong></label><br>
                <select id="link_page_background_image_size" name="link_page_background_image_size" style="max-width:200px;">
                    <option value="cover"<?php selected($custom_vars['--link-page-image-size'] ?? 'cover', 'cover'); ?>><?php esc_html_e('Cover (Fill Area)', 'extrachill-artist-platform'); ?></option>
                    <option value="contain"<?php selected($custom_vars['--link-page-image-size'] ?? 'cover', 'contain'); ?>><?php esc_html_e('Contain (Fit Area)', 'extrachill-artist-platform'); ?></option>
                    <option value="auto"<?php selected($custom_vars['--link-page-image-size'] ?? 'cover', 'auto'); ?>><?php esc_html_e('Original Size', 'extrachill-artist-platform'); ?></option>
                </select>
            </div>
            
            <div style="margin-top: 10px;">
                <label for="link_page_background_image_position"><strong><?php esc_html_e('Background Position', 'extrachill-artist-platform'); ?></strong></label><br>
                <select id="link_page_background_image_position" name="link_page_background_image_position" style="max-width:200px;">
                    <option value="center center"<?php selected($custom_vars['--link-page-image-position'] ?? 'center center', 'center center'); ?>><?php esc_html_e('Center', 'extrachill-artist-platform'); ?></option>
                    <option value="top center"<?php selected($custom_vars['--link-page-image-position'] ?? 'center center', 'top center'); ?>><?php esc_html_e('Top Center', 'extrachill-artist-platform'); ?></option>
                    <option value="bottom center"<?php selected($custom_vars['--link-page-image-position'] ?? 'center center', 'bottom center'); ?>><?php esc_html_e('Bottom Center', 'extrachill-artist-platform'); ?></option>
                    <option value="left center"<?php selected($custom_vars['--link-page-image-position'] ?? 'center center', 'left center'); ?>><?php esc_html_e('Left Center', 'extrachill-artist-platform'); ?></option>
                    <option value="right center"<?php selected($custom_vars['--link-page-image-position'] ?? 'center center', 'right center'); ?>><?php esc_html_e('Right Center', 'extrachill-artist-platform'); ?></option>
                </select>
            </div>
            
            <div style="margin-top: 10px;">
                <label for="link_page_background_image_repeat"><strong><?php esc_html_e('Background Repeat', 'extrachill-artist-platform'); ?></strong></label><br>
                <select id="link_page_background_image_repeat" name="link_page_background_image_repeat" style="max-width:200px;">
                    <option value="no-repeat"<?php selected($custom_vars['--link-page-image-repeat'] ?? 'no-repeat', 'no-repeat'); ?>><?php esc_html_e('No Repeat', 'extrachill-artist-platform'); ?></option>
                    <option value="repeat"<?php selected($custom_vars['--link-page-image-repeat'] ?? 'no-repeat', 'repeat'); ?>><?php esc_html_e('Repeat', 'extrachill-artist-platform'); ?></option>
                    <option value="repeat-x"<?php selected($custom_vars['--link-page-image-repeat'] ?? 'no-repeat', 'repeat-x'); ?>><?php esc_html_e('Repeat Horizontally', 'extrachill-artist-platform'); ?></option>
                    <option value="repeat-y"<?php selected($custom_vars['--link-page-image-repeat'] ?? 'no-repeat', 'repeat-y'); ?>><?php esc_html_e('Repeat Vertically', 'extrachill-artist-platform'); ?></option>
                </select>
            </div>
        </div>
        <div class="customize-section">
            <label>
                <input type="checkbox" id="link_page_overlay_toggle" name="link_page_overlay_toggle" value="1" <?php checked(isset($custom_vars['overlay']) ? $custom_vars['overlay'] : '1', '1'); ?>>
                <?php esc_html_e('Overlay (Card Background & Shadow)', 'extrachill-artist-platform'); ?>
            </label>
            <input type="hidden" name="link_page_overlay_toggle_present" value="1">
        </div>
    </div>
</div>

<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Colors', 'extrachill-artist-platform'); ?></h4>
    <div class="customize-section">
        <label for="link_page_button_color"><strong><?php esc_html_e('Button Color', 'extrachill-artist-platform'); ?></strong></label><br>
        <input type="color" id="link_page_button_color" name="link_page_button_color" value="<?php echo esc_attr($custom_vars['--link-page-button-bg-color'] ?? '#0b5394'); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_text_color"><strong><?php esc_html_e('Text Color', 'extrachill-artist-platform'); ?></strong></label><br>
        <input type="color" id="link_page_text_color" name="link_page_text_color" value="<?php echo esc_attr($custom_vars['--link-page-text-color'] ?? '#e5e5e5'); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_link_text_color"><strong><?php esc_html_e('Link Text Color', 'extrachill-artist-platform'); ?></strong></label><br>
        <input type="color" id="link_page_link_text_color" name="link_page_link_text_color" value="<?php echo esc_attr($custom_vars['--link-page-link-text-color'] ?? '#ffffff'); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_hover_color"><strong><?php esc_html_e('Hover Color', 'extrachill-artist-platform'); ?></strong></label><br>
        <input type="color" id="link_page_hover_color" name="link_page_hover_color" value="<?php echo esc_attr($custom_vars['--link-page-button-hover-bg-color'] ?? '#53940b'); ?>">
    </div>
    <div class="customize-section">
        <label for="link_page_button_border_color"><strong><?php esc_html_e('Button Border Color', 'extrachill-artist-platform'); ?></strong></label><br>
        <input type="color" id="link_page_button_border_color" name="link_page_button_border_color" value="<?php echo esc_attr($custom_vars['--link-page-button-border-color'] ?? '#0b5394'); ?>">
    </div>
    <!-- Removed inappropriate color controls - these should be derived automatically:
         - Button Hover Text Color (automatically contrasts with button hover color)
         - Card Background Color (derived from main background with opacity)
         - Muted Text Color (derived from main text color with reduced opacity)
         - Overlay Color (derived from background with opacity)
         - Input Background Color (derived from background color)
         - Accent Colors (derived from button/hover colors) -->
</div>

<!-- Buttons Card -->
<div class="link-page-content-card">
    <h4 class="customize-card-title"><?php esc_html_e('Buttons', 'extrachill-artist-platform'); ?></h4>
    <div class="customize-section customize-button-shape-section">
        <label for="link_page_button_radius"><strong><?php esc_html_e('Button Radius', 'extrachill-artist-platform'); ?></strong></label><br>
        <input type="range" id="link_page_button_radius" name="link_page_button_radius" min="0" max="50" value="8" step="1" style="width: 180px; vertical-align: middle;">
        <output for="link_page_button_radius" id="button_radius_output" style="margin-left: 10px; vertical-align: middle;">8%</output>
        <p class="description" style="margin-top: 0.5em; font-size: 0.97em;">
            <?php esc_html_e('Adjust the button border radius from square (0px) to pill (50px).', 'extrachill-artist-platform'); ?>
        </p>
    </div>
    <!-- Removed button border width control - inappropriate level of styling detail -->
</div>

<!-- Add the hidden input for custom CSS vars JSON -->
<!-- CSS variables now handled directly via form inputs - no JSON intermediary needed -->

