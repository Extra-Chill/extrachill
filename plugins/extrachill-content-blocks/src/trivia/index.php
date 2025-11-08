<?php
/**
 * Trivia Block initialization
 * Manual asset enqueuing for assets/ subdirectory (non-standard structure)
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

