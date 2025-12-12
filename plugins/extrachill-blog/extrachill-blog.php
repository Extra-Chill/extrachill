<?php
/**
 * Plugin Name: Extra Chill Blog
 * Plugin URI: https://extrachill.com
 * Description: Blog-specific functionality for extrachill.com (Blog ID 1). Provides secondary header navigation, homepage customizations, and custom Gutenberg blocks for community engagement.
 * Version: 0.3.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-blog
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_BLOG_VERSION', '0.3.0' );
define( 'EXTRACHILL_BLOG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_BLOG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/nav.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/homepage-hooks.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/blog-archive-routing.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/breadcrumbs.php';

/**
 * Register Gutenberg blocks from build directory.
 */
function extrachill_blog_register_blocks() {
    $block_json_files = glob(EXTRACHILL_BLOG_PLUGIN_DIR . 'build/**/block.json');

    foreach ($block_json_files as $filename) {
        $block_folder = dirname($filename);

        if (strpos($block_folder, 'utils') !== false || strpos($block_folder, 'components') !== false) {
            continue;
        }

        $index_file = $block_folder . '/index.php';
        if (file_exists($index_file)) {
            require_once $index_file;
        }

        $block_data = json_decode(file_get_contents($filename), true);
        $block_name = $block_data['name'];

        $block_args = array();

        if ($block_name === 'extrachill/ai-adventure' && function_exists('extrachill_blocks_render_ai_adventure_block')) {
            $block_args['render_callback'] = 'extrachill_blocks_render_ai_adventure_block';
        } else {
            $render_file = $block_folder . '/render.php';
            if (file_exists($render_file)) {
                $block_args['render_callback'] = function($attributes, $content, $block) use ($render_file) {
                    return include $render_file;
                };
            }
        }

        register_block_type($block_folder, $block_args);
    }
}
add_action('init', 'extrachill_blog_register_blocks');

/**
 * Attach block styles via wp_add_inline_style() to WordPress core handles.
 * WordPress 5.8+ recommended pattern - styles only load when blocks render.
 */
function extrachill_blog_enqueue_block_assets() {
    if (is_admin()) {
        return;
    }

    $blocks_to_check = [
        'trivia' => 'extrachill/trivia',
        'image-voting' => 'extrachill/image-voting',
        'rapper-name-generator' => 'extrachill/rapper-name-generator',
        'band-name-generator' => 'extrachill/band-name-generator',
        'ai-adventure' => 'extrachill/ai-adventure',
        'ai-adventure-path' => 'extrachill/ai-adventure-path',
        'ai-adventure-step' => 'extrachill/ai-adventure-step'
    ];

    foreach ($blocks_to_check as $block_slug => $block_name) {
        if (has_block($block_name)) {
            $style_path = EXTRACHILL_BLOG_PLUGIN_DIR . "build/{$block_slug}/style-index.css";
            if (file_exists($style_path)) {
                $style_content = file_get_contents($style_path);
                wp_add_inline_style('wp-block-library', $style_content);
            }

            // Enqueue frontend scripts if they exist
            $js_path = EXTRACHILL_BLOG_PLUGIN_DIR . "build/{$block_slug}/index.js";
            if (file_exists($js_path)) {
                wp_enqueue_script(
                    "extrachill-blog-{$block_slug}-frontend",
                    EXTRACHILL_BLOG_PLUGIN_URL . "build/{$block_slug}/index.js",
                    array(),
                    filemtime($js_path),
                    true
                );
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_blog_enqueue_block_assets');
