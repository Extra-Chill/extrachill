<?php
/**
 * Social Item Editor Template
 * 
 * Renders a single editable social media item for the management interface
 * Used for dynamic creation of new social items via JavaScript/AJAX
 * 
 * @param array $args {
 *     @type int $index Social item index
 *     @type array $social_data Social data with type and url
 *     @type array $available_options Available social type options 
 *     @type array $current_socials Currently selected social types (to prevent duplicates)
 * }
 */

// Default arguments
$args = wp_parse_args($args, array(
    'index' => 0,
    'social_data' => array(),
    'available_options' => array(),
    'current_socials' => array()
));

// Extract variables
$index = (int) $args['index'];
$social_data = (array) $args['social_data'];
$available_options = (array) $args['available_options'];
$current_socials = (array) $args['current_socials'];

// Extract social data
$social_type = $social_data['type'] ?? '';
$social_url = $social_data['url'] ?? '';

// Build options HTML - only show options that aren't used or are currently selected
$options_html = '';
foreach ($available_options as $option) {
    $is_currently_selected = ($option['value'] === $social_type);
    $is_used_by_another = (
        $option['value'] !== 'website' && 
        $option['value'] !== 'email' && 
        in_array($option['value'], array_column($current_socials, 'type'), true) &&
        !$is_currently_selected
    );
    
    if ($is_currently_selected || !$is_used_by_another) {
        $selected_attr = $is_currently_selected ? ' selected' : '';
        $options_html .= sprintf(
            '<option value="%s"%s>%s</option>',
            esc_attr($option['value']),
            $selected_attr,
            esc_html($option['label'])
        );
    }
}
?>

<div class="bp-social-row">
    <span class="bp-social-drag-handle drag-handle">
        <i class="fas fa-grip-vertical"></i>
    </span>
    
    <select class="bp-social-type-select" name="social_type[<?php echo esc_attr($index); ?>]">
        <?php echo $options_html; // Already escaped above ?>
    </select>
    
    <input type="url" 
           class="bp-social-url-input" 
           name="social_url[<?php echo esc_attr($index); ?>]" 
           placeholder="Profile URL" 
           value="<?php echo esc_attr($social_url); ?>">
    
    <button type="button" 
            class="button-danger button-small bp-remove-social-btn ml-auto" 
            title="Remove Social Icon">
        <i class="fas fa-trash-can"></i>
    </button>
</div>