<?php
/**
 * Template Override System
 *
 * Centralizes template loading for cleaner theme root directory
 * by redirecting template requests to organized subdirectories.
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Hook into template hierarchy to intercept and redirect templates
 */
add_filter('template_include', 'extrachill_template_overrides');

/**
 * Template override handler
 *
 * Redirects specific template files from theme root to organized subdirectories
 * to maintain a clean theme structure while preserving WordPress template hierarchy.
 *
 * @param string $template The path of the template to include
 * @return string The modified template path or original if no override exists
 */
function extrachill_template_overrides($template) {
    // Define template overrides map - maps template filename to new location
    $overrides = array(
        'front-page.php' => EXTRACHILL_INCLUDES_DIR . '/home/templates/front-page.php',
        'archive.php' => EXTRACHILL_INCLUDES_DIR . '/archives/archive.php'
    );

    // Get the template filename from the full path
    $template_name = basename($template);

    // Check if we have an override for this template and if the override file exists
    if (isset($overrides[$template_name]) && file_exists($overrides[$template_name])) {
        return $overrides[$template_name];
    }

    // No override found, return original template
    return $template;
}