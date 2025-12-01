<?php
/**
 * Icon Helper
 *
 * Centralized SVG sprite icon rendering for the Extra Chill platform.
 *
 * @package ExtraChill
 */

/**
 * Render an SVG icon from the extrachill.svg sprite
 *
 * @param string $icon_id The symbol ID in extrachill.svg
 * @param string $class   Additional CSS classes (optional)
 * @return string SVG markup
 */
function ec_icon($icon_id, $class = '') {
    static $sprite_url = null;

    if ($sprite_url === null) {
        $sprite_path = get_template_directory() . '/assets/fonts/extrachill.svg';
        $version = file_exists($sprite_path) ? filemtime($sprite_path) : '';
        $sprite_url = get_template_directory_uri() . '/assets/fonts/extrachill.svg';
        if ($version) {
            $sprite_url .= '?v=' . $version;
        }
    }

    $classes = 'ec-icon' . ($class ? ' ' . esc_attr($class) : '');

    return sprintf(
        '<svg class="%s"><use href="%s#%s"></use></svg>',
        $classes,
        esc_url($sprite_url),
        esc_attr($icon_id)
    );
}
