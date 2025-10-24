<?php
/**
 * Plugin Name: Extra Chill Blocks
 * Plugin URI: https://extrachill.com
 * Description: Community engagement Gutenberg blocks: trivia, image voting with newsletter integration, music industry name generators, and AI-powered text adventures.
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
 * AI Adventure blocks require ExtraChill AI Client plugin with configured API credentials.
 * Image Voting block integrates with ExtraChill Newsletter plugin via bridge function.
 *
 * @package ExtraChillBlocks
 */

if (!defined('ABSPATH')) {
    exit;
}
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

/**
 * Register all blocks via automatic discovery from build/ directory
 */
function extrachill_blocks_register_all_blocks() {
    $block_json_files = glob(EXTRACHILL_BLOCKS_PATH . 'build/**/block.json');

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

        if ($block_name === 'extrachill-blocks/ai-adventure' && function_exists('extrachill_blocks_render_ai_adventure_block')) {
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
add_action('init', 'extrachill_blocks_register_all_blocks');

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

function extrachill_blocks_enqueue_block_assets() {
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

function extrachill_blocks_activate() {
    flush_rewrite_rules();
}

function extrachill_blocks_deactivate() {
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, 'extrachill_blocks_activate');
register_deactivation_hook(__FILE__, 'extrachill_blocks_deactivate');

/**
 * Register newsletter integration for image voting block.
 * Allows admin to configure Sendy list ID and enable/disable via ExtraChill Newsletter settings.
 * Actual subscription handled via extrachill_multisite_subscribe() bridge function in index.php.
 */
add_filter('newsletter_form_integrations', 'extrachill_blocks_register_newsletter_integration');
function extrachill_blocks_register_newsletter_integration($integrations) {
	$integrations['image_voting'] = array(
		'label' => __('Image Voting Block', 'extrachill-blocks'),
		'description' => __('Newsletter subscription when users vote on images', 'extrachill-blocks'),
		'list_id_key' => 'image_voting_list_id',
		'enable_key' => 'enable_image_voting',
		'plugin' => 'extrachill-blocks',
	);
	return $integrations;
}