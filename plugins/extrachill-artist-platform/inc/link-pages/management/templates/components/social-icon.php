<?php
/**
 * Social Icon Template
 * 
 * Renders a single social media icon link
 * Used by both live pages and preview system
 * 
 * @param array $args {
 *     @type array $social_data Social link data with 'url' and 'type' keys
 *     @type object $social_manager Instance of social links manager (optional, will create if not provided)
 * }
 */

// Default arguments
$args = wp_parse_args($args, array(
    'social_data' => array(),
    'social_manager' => null
));

// Extract variables
$social_data = $args['social_data'];
$social_manager = $args['social_manager'];

// Don't render if missing required data
if (empty($social_data['url']) || empty($social_data['type'])) {
    return;
}

// Get social manager if not provided
if (!$social_manager && function_exists('extrachill_artist_platform_social_links')) {
    $social_manager = extrachill_artist_platform_social_links();
}

if (!$social_manager) {
    return;
}

// Get icon class and label from social manager
$icon_class = $social_manager->get_icon_class($social_data['type'], $social_data);
$label = $social_manager->get_link_label($social_data);

// Don't render if no icon class
if (empty($icon_class)) {
    return;
}
?>

<a href="<?php echo esc_url($social_data['url']); ?>" 
   class="extrch-social-icon" 
   target="_blank" 
   rel="noopener noreferrer" 
   title="<?php echo esc_attr($label); ?>" 
   aria-label="<?php echo esc_attr($label); ?>">
    <i class="<?php echo esc_attr($icon_class); ?>"></i>
</a>