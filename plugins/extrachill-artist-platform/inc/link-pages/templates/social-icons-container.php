<?php
/**
 * Social Icons Container Template
 * 
 * Renders a container with multiple social media icons
 * Used by both live pages and preview system
 * 
 * @param array $args {
 *     @type array $social_links Array of social link data
 *     @type string $position Position class - 'above' or 'below' (default: 'above')
 *     @type object $social_manager Instance of social links manager (optional)
 * }
 */

// Default arguments
$args = wp_parse_args($args, array(
    'social_links' => array(),
    'position' => 'above',
    'social_manager' => null
));

// Extract variables
$social_links = $args['social_links'];
$position = $args['position'];
$social_manager = $args['social_manager'];

// Don't render if no social links
if (empty($social_links) || !is_array($social_links)) {
    return;
}

// Get social manager if not provided
if (!$social_manager && function_exists('extrachill_artist_platform_social_links')) {
    $social_manager = extrachill_artist_platform_social_links();
}

if (!$social_manager) {
    return;
}

// Determine container classes
$container_classes = 'extrch-link-page-socials';
if ($position === 'below') {
    $container_classes .= ' extrch-socials-below';
}

// Filter out empty social links
$valid_social_links = array_filter($social_links, function($link) {
    return !empty($link['url']) && !empty($link['type']);
});

// Don't render if no valid links
if (empty($valid_social_links)) {
    return;
}
?>

<div class="<?php echo esc_attr($container_classes); ?>">
    <?php foreach ($valid_social_links as $social_link):
        
        // Render single social icon using unified template system
        $icon_args = array(
            'social_data' => $social_link,
            'social_manager' => $social_manager
        );
        
        echo ec_render_template('social-icon', $icon_args);
        
    endforeach; ?>
</div>