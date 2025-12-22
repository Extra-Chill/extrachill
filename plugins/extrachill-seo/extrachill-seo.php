<?php
/**
 * Extra Chill SEO Plugin
 *
 * Replaces Yoast SEO with a lean, code-first SEO system tailored to the Extra Chill Platform.
 * Manages meta tags, structured data, robots directives, and social sharing across the multisite network.
 *
 * @package ExtraChill\SEO
 * @version 0.2.0
 */

/**
 * Plugin Name: Extra Chill SEO
 * Plugin URI: https://extrachill.com
 * Description: Lean SEO plugin replacing Yoast with code-first meta tags, structured data, and robots directives
 * Version: 0.2.0
 * Author: Extra Chill
 * Author URI: https://extrachill.com
 * Network: true
 * Text Domain: extrachill-seo
 * Domain Path: /languages
 * Requires: 6.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace ExtraChill\SEO;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('EXTRACHILL_SEO_VERSION', '0.2.0');
define('EXTRACHILL_SEO_PATH', plugin_dir_path(__FILE__));
define('EXTRACHILL_SEO_URL', plugin_dir_url(__FILE__));

/**
 * Activation hook - verify multisite requirement
 */
register_activation_hook(__FILE__, function () {
    if (!is_multisite()) {
        wp_die(
            'Extra Chill SEO requires WordPress Multisite to be enabled.',
            'Extra Chill SEO Activation Error',
            ['response' => 403]
        );
    }
});

/**
 * Initialize plugin on wp_loaded
 *
 * Load core files that register hooks and filters
 */
add_action('wp_loaded', function () {
    // Disable Yoast frontend output if active.
    // This allows side-by-side testing before Yoast is deactivated.
    add_action('template_redirect', function () {
        $yoast_frontend_class = \Yoast\WP\SEO\Integrations\Front_End_Integration::class;

        if (!class_exists($yoast_frontend_class)) {
            return;
        }

        global $wp_filter;

        // Remove Yoast wp_head output.
        if (isset($wp_filter['wp_head']) && $wp_filter['wp_head'] instanceof \WP_Hook) {
            foreach ($wp_filter['wp_head']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $cb) {
                    if (!isset($cb['function']) || !is_array($cb['function'])) {
                        continue;
                    }

                    $fn_object = $cb['function'][0] ?? null;
                    $fn_method = $cb['function'][1] ?? null;

                    if (is_object($fn_object) && $fn_object instanceof $yoast_frontend_class && $fn_method === 'call_wpseo_head') {
                        remove_action('wp_head', [$fn_object, $fn_method], $priority);
                    }
                }
            }
        }

        // Remove Yoast title filtering.
        if (isset($wp_filter['pre_get_document_title']) && $wp_filter['pre_get_document_title'] instanceof \WP_Hook) {
            foreach ($wp_filter['pre_get_document_title']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $cb) {
                    if (!isset($cb['function']) || !is_array($cb['function'])) {
                        continue;
                    }

                    $fn_object = $cb['function'][0] ?? null;
                    $fn_method = $cb['function'][1] ?? null;

                    if (is_object($fn_object) && $fn_object instanceof $yoast_frontend_class && $fn_method === 'filter_title') {
                        remove_filter('pre_get_document_title', [$fn_object, $fn_method], $priority);
                    }
                }
            }
        }

        // Yoast removes core title/canonical output. Restore them.
        if (!has_action('wp_head', 'rel_canonical')) {
            add_action('wp_head', 'rel_canonical');
        }

        if (!has_action('wp_head', '_wp_render_title_tag')) {
            add_action('wp_head', '_wp_render_title_tag', 1);
        }
    }, 1);

    // Load core SEO components
    require_once EXTRACHILL_SEO_PATH . 'inc/core/meta-tags.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/core/canonical.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/core/robots.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/core/open-graph.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/core/twitter-cards.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-output.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-website.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-organization.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-article.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-breadcrumb.php';
    require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-webpage.php';
});

/**
 * Load text domain for translations
 */
add_action('init', function () {
    load_plugin_textdomain(
        'extrachill-seo',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});
