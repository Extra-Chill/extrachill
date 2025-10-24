<?php
/**
 * AI Adventure Block initialization
 * REST endpoint: /wp-json/extrachill-blocks/v1/adventure
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/api-handler.php';

if ( ! function_exists( 'extrachill_blocks_render_ai_adventure_block' ) ) {
    function extrachill_blocks_render_ai_adventure_block( $attributes, $content, $block ) {
        $wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'extrachill-block-window' ) );

        $encoded_attributes = wp_json_encode( $attributes );
        $encoded_inner_blocks = $attributes['innerBlocksJSON'] ?? '[]';

        return sprintf(
            '<div %s data-attributes="%s" data-innerblocks="%s"></div>',
            $wrapper_attributes,
            esc_attr( $encoded_attributes ),
            esc_attr( $encoded_inner_blocks )
        );
    }
} 