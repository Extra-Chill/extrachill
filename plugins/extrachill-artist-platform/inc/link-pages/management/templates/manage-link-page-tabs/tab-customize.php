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

// Ensure variables from parent scope are available
// (e.g., $background_type, $background_color, $background_image_url, $button_color, $text_color, $link_text_color, $hover_color, $link_page_id, etc.)
// As with tab-info.php, we assume accessibility from the parent scope for this refactor.
global $background_type, $background_color, $background_image_url, $button_color, $text_color, $link_text_color, $hover_color, $link_page_id;

// $extrch_link_page_fonts is provided by the font config system, loaded in bootstrap.
$extrch_link_page_fonts = get_query_var('extrch_link_page_fonts', array());

// Hydrate from canonical centralized data
$data = get_query_var('data', array());
$custom_vars = isset($data['css_vars']) && is_array($data['css_vars']) ? $data['css_vars'] : array();
$settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : array();

?>

<!-- Featured Link Settings Card -->
<?php
$is_featured_link_enabled = $settings['featured_link_enabled'] ?? false;
$featured_link_original_id_val = $settings['featured_link_original_id'] ?? '';
$show_featured_link_card = $is_featured_link_enabled && !empty($featured_link_original_id_val);

$featured_custom_description = $show_featured_link_card ? $settings['featured_link_custom_description'] ?? '' : '';
$featured_thumbnail_id = $show_featured_link_card ? $settings['featured_link_thumbnail_id'] ?? '' : '';
$fetched_og_image_url = $show_featured_link_card ? $settings['featured_link_fetched_thumbnail_url'] ?? '' : '';
$featured_thumbnail_url = $featured_thumbnail_id ? wp_get_attachment_image_url($featured_thumbnail_id, 'medium') : ($fetched_og_image_url ? esc_url($fetched_og_image_url) : '#');
$initial_thumbnail_display = ($featured_thumbnail_url && $featured_thumbnail_url !== '#') ? 'block' : 'none';
$has_any_thumbnail = ($featured_thumbnail_url && $featured_thumbnail_url !== '#');
?>
<div class="link-page-content-card" id="featured-link-settings-card" style="<?php echo $show_featured_link_card ? '' : 'display:none;'; ?>">
    <h4 class="customize-card-title"><?php esc_html_e('Featured Link Settings', 'extrachill-artist-platform'); ?></h4>
    
    <div class="customize-section featured-link-thumbnail-section">
        <label for="featured_link_thumbnail_upload"><strong><?php esc_html_e('Featured Link Thumbnail', 'extrachill-artist-platform'); ?></strong></label><br>
        <button type="button" class="button" id="featured-link-choose-file-btn" onclick="document.getElementById('featured_link_thumbnail_upload').click();" style="margin-bottom: 10px;">Choose File</button>
        <input type="file" id="featured_link_thumbnail_upload" name="featured_link_thumbnail_upload" accept="image/*" style="display:none;">
        <?php if ($has_any_thumbnail) : ?>
            <button type="button" class="button button-secondary" id="remove_featured_link_thumbnail_btn" style="margin-left: 8px; margin-bottom: 10px;">Remove Thumbnail</button>
        <?php endif; ?>
        <p class="description" style="font-size: 0.9em; margin-top: -5px; margin-bottom: 10px;">
            <?php esc_html_e('Upload an image (e.g., 1200x630px). If not provided, the system will attempt to fetch a preview image from the link source.', 'extrachill-artist-platform'); ?>
        </p>
    </div>

    <!-- The featured link title is always the link's title. No override input. -->

    <div class="customize-section featured-link-description-section" style="margin-top: 15px;">
        <label for="featured_link_custom_description"><strong><?php esc_html_e('Featured Link Description', 'extrachill-artist-platform'); ?></strong></label><br>
        <textarea id="featured_link_custom_description" name="featured_link_custom_description" rows="3" class="regular-text" style="width:100%; max-width:400px;"><?php echo esc_textarea($featured_custom_description); ?></textarea>
    </div>
</div>

<input type="hidden" name="featured_link_thumbnail_id_action" id="featured_link_thumbnail_id_action" value="<?php echo ($show_featured_link_card && $featured_thumbnail_id && $featured_link_original_id_val) ? 'remove' : ''; ?>">

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
            <input type="range" id="link_page_title_font_size" name="link_page_title_font_size" min="1" max="100" value="50" step="1" style="width: 180px; vertical-align: middle;">
            <output for="link_page_title_font_size" id="title_font_size_output" style="margin-left: 10px; vertical-align: middle;">50%</output>
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
            <div class="customize-subsection" style="margin-top: 15px;">
                <label for="link_page_body_font_size"><strong><?php esc_html_e('Body Font Size', 'extrachill-artist-platform'); ?></strong></label><br>
                <input type="range" id="link_page_body_font_size" name="link_page_body_font_size" min="10" max="30" value="<?php 
                    // Convert em to pixel approximation for slider (1em ≈ 16px)
                    $body_font_size = $custom_vars['--link-page-body-font-size'] ?? '1em';
                    $size_value = str_replace('em', '', $body_font_size);
                    echo esc_attr(round($size_value * 16));
                ?>" step="1" style="width: 180px; vertical-align: middle;">
                <output for="link_page_body_font_size" id="body_font_size_output" style="margin-left: 10px; vertical-align: middle;"><?php 
                    $body_font_size = $custom_vars['--link-page-body-font-size'] ?? '1em';
                    echo esc_html($body_font_size);
                ?></output>
            </div>
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
    <div class="customize-section">
        <label for="link_page_profile_img_border_radius"><strong><?php esc_html_e('Profile Image Border Radius', 'extrachill-artist-platform'); ?></strong></label><br>
        <input type="range" id="link_page_profile_img_border_radius" name="link_page_profile_img_border_radius" min="0" max="50" value="<?php 
            $border_radius = $custom_vars['--link-page-profile-img-border-radius'] ?? '50%';
            $radius_value = str_replace('%', '', $border_radius);
            echo esc_attr($radius_value);
        ?>" step="1" style="width: 180px; vertical-align: middle;">
        <output for="link_page_profile_img_border_radius" id="profile_img_border_radius_output" style="margin-left: 10px; vertical-align: middle;"><?php 
            echo esc_html($custom_vars['--link-page-profile-img-border-radius'] ?? '50%');
        ?></output>
        <p class="description" style="margin-top: 0.5em; font-size: 0.97em;">
            <?php esc_html_e('Adjust the profile image border radius from square (0%) to circle (50%).', 'extrachill-artist-platform'); ?>
        </p>
    </div>
    <div class="customize-section">
        <label for="link_page_profile_img_aspect_ratio"><strong><?php esc_html_e('Profile Image Aspect Ratio', 'extrachill-artist-platform'); ?></strong></label><br>
        <select id="link_page_profile_img_aspect_ratio" name="link_page_profile_img_aspect_ratio" style="max-width:200px;">
            <option value="1/1"<?php selected($custom_vars['--link-page-profile-img-aspect-ratio'] ?? '1/1', '1/1'); ?>><?php esc_html_e('Square (1:1)', 'extrachill-artist-platform'); ?></option>
            <option value="4/3"<?php selected($custom_vars['--link-page-profile-img-aspect-ratio'] ?? '1/1', '4/3'); ?>><?php esc_html_e('Standard (4:3)', 'extrachill-artist-platform'); ?></option>
            <option value="16/9"<?php selected($custom_vars['--link-page-profile-img-aspect-ratio'] ?? '1/1', '16/9'); ?>><?php esc_html_e('Widescreen (16:9)', 'extrachill-artist-platform'); ?></option>
            <option value="3/2"<?php selected($custom_vars['--link-page-profile-img-aspect-ratio'] ?? '1/1', '3/2'); ?>><?php esc_html_e('Photo (3:2)', 'extrachill-artist-platform'); ?></option>
        </select>
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

<input type="hidden" name="featured_link_og_image_removed" id="featured_link_og_image_removed" value="<?php echo ($settings['featured_link_og_image_removed'] ?? false) ? '1' : ''; ?>">