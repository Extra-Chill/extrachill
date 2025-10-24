<?php
/**
 * Trivia Block initialization
 * Manual asset enqueuing required due to assets/ subdirectory structure
 * REST endpoint: /wp-json/extrachill-blocks/v1/trivia/log-attempt
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', 'extrachill_blocks_trivia_enqueue_frontend_assets');

function extrachill_blocks_trivia_enqueue_frontend_assets() {
    if (!has_block('extrachill-blocks/trivia')) {
        return;
    }

    $js_file = EXTRACHILL_BLOCKS_URL . 'build/trivia/assets/js/trivia-block-frontend.js';
    $css_file = EXTRACHILL_BLOCKS_URL . 'build/trivia/assets/css/trivia-block-frontend.css';

    if (file_exists(EXTRACHILL_BLOCKS_PATH . 'build/trivia/assets/js/trivia-block-frontend.js')) {
        wp_enqueue_script(
            'extrachill-blocks-trivia-frontend',
            $js_file,
            array(),
            filemtime(EXTRACHILL_BLOCKS_PATH . 'build/trivia/assets/js/trivia-block-frontend.js'),
            true
        );

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

    if (file_exists(EXTRACHILL_BLOCKS_PATH . 'build/trivia/assets/css/trivia-block-frontend.css')) {
        wp_enqueue_style(
            'extrachill-blocks-trivia-frontend',
            $css_file,
            array(),
            filemtime(EXTRACHILL_BLOCKS_PATH . 'build/trivia/assets/css/trivia-block-frontend.css')
        );
    }
}

add_action('rest_api_init', 'extrachill_blocks_trivia_register_rest_routes');

function extrachill_blocks_trivia_register_rest_routes() {
    register_rest_route(
        'extrachill-blocks/v1',
        '/trivia/log-attempt',
        array(
            'methods'             => 'POST',
            'callback'            => 'extrachill_blocks_trivia_log_attempt',
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

function extrachill_blocks_trivia_log_attempt($request) {
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
