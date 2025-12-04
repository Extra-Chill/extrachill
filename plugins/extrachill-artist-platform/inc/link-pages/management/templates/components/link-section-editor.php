<?php
/**
 * Link Section Editor Template
 * 
 * Renders a complete editable link section for the management interface
 * Used for dynamic creation of new link sections via JavaScript/AJAX
 * 
 * @param array $args {
 *     @type int $sidx Section index
 *     @type array $section_data Section data with section_title and links
 *     @type bool $expiration_enabled Whether expiration feature is enabled
 * }
 */

// Default arguments
$args = wp_parse_args($args, array(
    'sidx' => 0,
    'section_data' => array(),
    'expiration_enabled' => false
));

// Extract variables
$sidx = (int) $args['sidx'];
$section_data = (array) $args['section_data'];
$expiration_enabled = (bool) $args['expiration_enabled'];

// Extract section data
$section_title = $section_data['section_title'] ?? '';
$links = $section_data['links'] ?? array();
?>

<div class="bp-link-section" data-sidx="<?php echo esc_attr($sidx); ?>">
    <div class="bp-link-section-header">
        <span class="bp-section-drag-handle drag-handle">
            <i class="fas fa-grip-vertical"></i>
        </span>
        
        <input type="text" 
               class="bp-link-section-title" 
               name="link_section_title[<?php echo esc_attr($sidx); ?>]" 
               placeholder="Section Title (optional)" 
               value="<?php echo esc_attr($section_title); ?>" 
               data-sidx="<?php echo esc_attr($sidx); ?>">
        
        <div class="bp-section-actions-group ml-auto">
            <button type="button" 
                    class="button-danger button-small bp-remove-link-section-btn" 
                    data-sidx="<?php echo esc_attr($sidx); ?>" 
                    title="Remove Section">
                <i class="fas fa-trash-can"></i>
            </button>
        </div>
    </div>
    
    <div class="bp-link-list">
        <?php foreach ($links as $lidx => $link): ?>
            <?php
            // Render each link item using the link item template
            echo ec_render_template('link-item-editor', array(
                'sidx' => $sidx,
                'lidx' => $lidx,
                'link_data' => $link,
                'expiration_enabled' => $expiration_enabled
            ));
            ?>
        <?php endforeach; ?>
    </div>
    
    <button type="button"
            class="button-2 button-medium bp-add-link-btn"
            data-sidx="<?php echo esc_attr($sidx); ?>">
        <i class="fas fa-plus"></i> Add Link
    </button>
</div>