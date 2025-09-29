<?php
/**
 * AI Adventure Block
 *
 * Main entry point for the AI Adventure block module.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Include the API handler, which now self-registers its routes.
require_once __DIR__ . '/includes/api-handler.php';

/**
 * Renders the AI Adventure block on the front-end, passing data to the script.
 *
 * @param array    $attributes The block attributes.
 * @param string   $content    The block content.
 * @param WP_Block $block      The block object.
 * @return string The HTML of the block.
 */
if ( ! function_exists( 'extrachill_blocks_render_ai_adventure_block' ) ) {
    function extrachill_blocks_render_ai_adventure_block( $attributes, $content, $block ) {
        $wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'extrachill-block-window' ) );

        // Use the JSON string from attributes as the reliable source for inner blocks.
        $encoded_attributes = wp_json_encode( $attributes );
        $encoded_inner_blocks = $attributes['innerBlocksJSON'] ?? '[]';

        // The data-* attributes will be picked up by the view.js script.
        return sprintf(
            '<div %s data-attributes="%s" data-innerblocks="%s"></div>',
            $wrapper_attributes,
            esc_attr( $encoded_attributes ),
            esc_attr( $encoded_inner_blocks )
        );
    }
} 