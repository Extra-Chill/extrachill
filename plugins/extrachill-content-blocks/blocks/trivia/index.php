<?php
/**
 * Trivia Block initialization
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the main plugin class
require_once __DIR__ . '/includes/class-trivia-block-plugin.php';

// Initialize the trivia block
$trivia_block = Trivia_Block_Plugin::get_instance();
$trivia_block->init();

// Update the class to work with the new namespace
class Trivia_Block_Plugin {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function enqueue_frontend_assets() {
        if (!has_block('extrachill-blocks/trivia')) {
            return;
        }

        $js_file = EXTRACHILL_BLOCKS_URL . 'blocks/trivia/assets/js/trivia-block-frontend.js';
        $css_file = EXTRACHILL_BLOCKS_URL . 'blocks/trivia/assets/css/trivia-block-frontend.css';

        // Enqueue frontend JavaScript
        if (file_exists(EXTRACHILL_BLOCKS_PATH . 'blocks/trivia/assets/js/trivia-block-frontend.js')) {
            wp_enqueue_script(
                'extrachill-blocks-trivia-frontend',
                $js_file,
                array(),
                filemtime(EXTRACHILL_BLOCKS_PATH . 'blocks/trivia/assets/js/trivia-block-frontend.js'),
                true
            );

            // Localize script with AJAX URL and nonce
            wp_localize_script(
                'extrachill-blocks-trivia-frontend',
                'triviaBlockAjax',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'restUrl' => rest_url('extrachill-blocks/v1/'),
                    'nonce'   => wp_create_nonce('extrachill_blocks_trivia_nonce'),
                )
            );
        }

        // Enqueue frontend CSS
        if (file_exists(EXTRACHILL_BLOCKS_PATH . 'blocks/trivia/assets/css/trivia-block-frontend.css')) {
            wp_enqueue_style(
                'extrachill-blocks-trivia-frontend',
                $css_file,
                array(),
                filemtime(EXTRACHILL_BLOCKS_PATH . 'blocks/trivia/assets/css/trivia-block-frontend.css')
            );
        }
    }

    public function register_rest_routes() {
        register_rest_route(
            'extrachill-blocks/v1',
            '/trivia/log-attempt',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'log_attempt'),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'block_id' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'selected_option' => array(
                        'required'          => true,
                        'sanitize_callback' => 'intval',
                    ),
                    'is_correct' => array(
                        'required'          => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ),
                ),
            )
        );
    }

    public function log_attempt($request) {
        // Verify nonce
        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'extrachill_blocks_trivia_nonce')) {
            return new WP_Error(
                'invalid_nonce',
                'Invalid security token',
                array('status' => 403)
            );
        }

        $block_id = $request->get_param('block_id');
        $selected_option = $request->get_param('selected_option');
        $is_correct = $request->get_param('is_correct');

        // Log the attempt (for now, just return success)
        // In the future, we can store this in a custom table for analytics
        
        return rest_ensure_response(
            array(
                'success' => true,
                'message' => 'Attempt logged successfully',
                'data'    => array(
                    'block_id'        => $block_id,
                    'selected_option' => $selected_option,
                    'is_correct'      => $is_correct,
                    'timestamp'       => current_time('mysql'),
                ),
            )
        );
    }
}