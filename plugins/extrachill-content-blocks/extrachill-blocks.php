<?php
/**
 * Plugin Name: ExtraChill Blocks
 * Plugin URI: https://extrachill.com
 * Description: A collection of custom Gutenberg blocks for community engagement including trivia, voting, and music industry generators.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Chris Huber
 * Author URI: https://extrachill.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-blocks
 * Domain Path: /languages
 * Network: false
 *
 * @package ExtraChillBlocks
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!defined('EXTRACHILL_BLOCKS_FILE')) {
    define('EXTRACHILL_BLOCKS_FILE', __FILE__);
}
if (!defined('EXTRACHILL_BLOCKS_PATH')) {
    define('EXTRACHILL_BLOCKS_PATH', plugin_dir_path(__FILE__));
}
if (!defined('EXTRACHILL_BLOCKS_URL')) {
    define('EXTRACHILL_BLOCKS_URL', plugin_dir_url(__FILE__));
}
if (!defined('EXTRACHILL_BLOCKS_VERSION')) {
    define('EXTRACHILL_BLOCKS_VERSION', '1.0.0');
}

// Include shared functionality
require_once EXTRACHILL_BLOCKS_PATH . 'includes/openai.php';
require_once EXTRACHILL_BLOCKS_PATH . 'includes/admin.php';

/**
 * Registers all blocks from the 'blocks' directory.
 *
 * This function scans for 'block.json' files in subdirectories of the 'blocks' folder
 * and registers them as WordPress blocks. For blocks that need server-side rendering,
 * a 'render.php' file should be included in the block's directory.
 */
function extrachill_blocks_register_all_blocks() {
    $block_json_files = glob(EXTRACHILL_BLOCKS_PATH . 'blocks/**/block.json');

    foreach ($block_json_files as $filename) {
        $block_folder = dirname($filename);

        // Skip utility/component folders
        if (strpos($block_folder, 'utils') !== false || strpos($block_folder, 'components') !== false) {
            continue;
        }

        // Load the block's index.php if it exists
        $index_file = $block_folder . '/index.php';
        if (file_exists($index_file)) {
            require_once $index_file;
        }

        // Prepare args for registration
        $args = [];
        $block_data = json_decode(file_get_contents($filename), true);

        // Only assign a render_callback if the block doesn't define a 'render' file in block.json
        // and a specific callback function exists for it.
        if (!isset($block_data['render'])) {
            if ($block_data['name'] === 'extrachill-blocks/ai-adventure' && function_exists('extrachill_blocks_render_ai_adventure_block')) {
                $args['render_callback'] = 'extrachill_blocks_render_ai_adventure_block';
            }
        }

        // Register the block
        register_block_type($block_folder, $args);
    }
}
add_action('init', 'extrachill_blocks_register_all_blocks');

/**
 * Enqueue shared styles for all blocks
 */
function extrachill_blocks_enqueue_shared_styles() {
    $shared_css_path = EXTRACHILL_BLOCKS_PATH . 'assets/css/shared.css';
    if (file_exists($shared_css_path)) {
        wp_enqueue_style(
            'extrachill-blocks-shared',
            EXTRACHILL_BLOCKS_URL . 'assets/css/shared.css',
            array(),
            filemtime($shared_css_path)
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_blocks_enqueue_shared_styles');

/**
 * Conditionally enqueue block-specific frontend assets only when blocks are present
 */
function extrachill_blocks_enqueue_block_assets() {
    // Check if any of our blocks are present
    $has_blocks = false;
    $block_checks = [
        'extrachill-blocks/trivia',
        'extrachill-blocks/image-voting',
        'extrachill-blocks/rapper-name-generator',
        'extrachill-blocks/band-name-generator',
        'extrachill-blocks/ai-adventure',
        'extrachill-blocks/ai-adventure-path',
        'extrachill-blocks/ai-adventure-step'
    ];

    foreach ($block_checks as $block_name) {
        if (has_block($block_name)) {
            $has_blocks = true;
            break;
        }
    }

    if (!$has_blocks) {
        return;
    }

    // Enqueue block-specific assets
    $blocks_to_check = [
        'trivia' => 'extrachill-blocks/trivia',
        'image-voting' => 'extrachill-blocks/image-voting',
        'rapper-name-generator' => 'extrachill-blocks/rapper-name-generator',
        'band-name-generator' => 'extrachill-blocks/band-name-generator',
        'ai-adventure' => 'extrachill-blocks/ai-adventure',
        'ai-adventure-path' => 'extrachill-blocks/ai-adventure-path',
        'ai-adventure-step' => 'extrachill-blocks/ai-adventure-step'
    ];

    foreach ($blocks_to_check as $block_slug => $block_name) {
        if (has_block($block_name)) {
            $style_path = EXTRACHILL_BLOCKS_PATH . "build/{$block_slug}/style-index.css";
            if (file_exists($style_path)) {
                wp_enqueue_style(
                    "extrachill-blocks-{$block_slug}",
                    EXTRACHILL_BLOCKS_URL . "build/{$block_slug}/style-index.css",
                    array(),
                    filemtime($style_path)
                );
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_blocks_enqueue_block_assets');

/**
 * Plugin activation
 */
function extrachill_blocks_activate() {
    // Create database tables
    extrachill_blocks_create_database_tables();

    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Plugin deactivation
 */
function extrachill_blocks_deactivate() {
    // Clean up if needed
    flush_rewrite_rules();
}

/**
 * Create database tables
 */
function extrachill_blocks_create_database_tables() {
    // No database tables needed - blocks use WordPress native post content storage
}

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'extrachill_blocks_activate');
register_deactivation_hook(__FILE__, 'extrachill_blocks_deactivate');