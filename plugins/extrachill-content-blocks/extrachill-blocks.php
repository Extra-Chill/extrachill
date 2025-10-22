<?php
/**
 * Plugin Name: Extra Chill Blocks
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
 * AI Integration Dependency: The AI Adventure blocks require the ExtraChill AI Client plugin
 * for AI-powered storytelling functionality. The AI Client must be network-activated with
 * valid API credentials configured in Network Admin → Settings → AI Client.
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
 * Register blocks via glob() pattern matching for automatic discovery.
 * Skips utility and component directories. Loads index.php for server-side rendering when present.
 */
function extrachill_blocks_register_all_blocks() {
    $block_json_files = glob(EXTRACHILL_BLOCKS_PATH . 'blocks/**/block.json');

    foreach ($block_json_files as $filename) {
        $block_folder = dirname($filename);

        if (strpos($block_folder, 'utils') !== false || strpos($block_folder, 'components') !== false) {
            continue;
        }

        $index_file = $block_folder . '/index.php';
        if (file_exists($index_file)) {
            require_once $index_file;
        }

        $args = [];
        $block_data = json_decode(file_get_contents($filename), true);
        if (!isset($block_data['render'])) {
            if ($block_data['name'] === 'extrachill-blocks/ai-adventure' && function_exists('extrachill_blocks_render_ai_adventure_block')) {
                $args['render_callback'] = 'extrachill_blocks_render_ai_adventure_block';
            }
        }

        register_block_type($block_folder, $args);
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
 * Register image voting newsletter integration context.
 * Configuration managed via extrachill-newsletter admin settings.
 * Subscription handled via extrachill-multisite bridge function.
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