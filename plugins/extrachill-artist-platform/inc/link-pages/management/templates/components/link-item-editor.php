<?php
/**
 * Link Item Editor Template
 * 
 * Renders a single editable link item for the management interface
 * Used for dynamic creation of new link items via JavaScript/AJAX
 * 
 * @param array $args {
 *     @type int $sidx Section index
 *     @type int $lidx Link index  
 *     @type array $link_data Link data with text, url, expires_at, id keys
 *     @type bool $expiration_enabled Whether expiration feature is enabled
 * }
 */

// Default arguments
$args = wp_parse_args($args, array(
    'sidx' => 0,
    'lidx' => 0,
    'link_data' => array(),
    'expiration_enabled' => false
));

// Extract variables
$sidx = (int) $args['sidx'];
$lidx = (int) $args['lidx'];
$link_data = (array) $args['link_data'];
$expiration_enabled = (bool) $args['expiration_enabled'];

// Extract link data with defaults
$link_text = $link_data['link_text'] ?? '';
$link_url = $link_data['link_url'] ?? '';
$expires_at = $link_data['expires_at'] ?? '';
$link_id = $link_data['id'] ?? ('link_' . time() . '_' . wp_generate_password(9, false));

// Determine item classes
$item_classes = 'bp-link-item';
?>

<div class="<?php echo esc_attr($item_classes); ?>" 
     data-sidx="<?php echo esc_attr($sidx); ?>" 
     data-lidx="<?php echo esc_attr($lidx); ?>" 
     data-expires-at="<?php echo esc_attr($expires_at); ?>" 
     data-link-id="<?php echo esc_attr($link_id); ?>">
    
    <span class="bp-link-drag-handle drag-handle">
        <i class="fas fa-grip-vertical"></i>
    </span>
    
    <input type="text" 
           class="bp-link-text-input" 
           name="link_text[<?php echo esc_attr($sidx); ?>][<?php echo esc_attr($lidx); ?>]" 
           placeholder="Link Text" 
           value="<?php echo esc_attr($link_text); ?>">
    
    <input type="url" 
           class="bp-link-url-input" 
           name="link_url[<?php echo esc_attr($sidx); ?>][<?php echo esc_attr($lidx); ?>]" 
           placeholder="URL" 
           value="<?php echo esc_attr($link_url); ?>">
    
    <input type="hidden" 
           name="link_id[<?php echo esc_attr($sidx); ?>][<?php echo esc_attr($lidx); ?>]" 
           value="<?php echo esc_attr($link_id); ?>">
    
    <input type="hidden" 
           name="link_expires_at[<?php echo esc_attr($sidx); ?>][<?php echo esc_attr($lidx); ?>]" 
           value="<?php echo esc_attr($expires_at); ?>">
    
    <!-- Expiration icon will be added dynamically via JavaScript when enabled -->
    
    <button type="button" 
            class="button-danger button-small bp-remove-link-btn ml-auto" 
            title="Remove Link">
        <i class="fas fa-trash-can"></i>
    </button>
</div>