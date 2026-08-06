<?php
/**
 * AI Adventure Block - Server-Side Render
 *
 * Renders the AI adventure container with data attributes for the frontend JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'extrachill-block-window' ) );

$encoded_attributes   = wp_json_encode( $attributes );
$encoded_inner_blocks = $attributes['innerBlocksJSON'] ?? '[]';
$post_id              = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
$adventure_reference  = extrachill_ai_adventure_create_reference( $post_id, $attributes );

printf(
	'<div %s data-attributes="%s" data-innerblocks="%s" data-adventure="%s"></div>',
	$wrapper_attributes,
	esc_attr( $encoded_attributes ),
	esc_attr( $encoded_inner_blocks ),
	esc_attr( $adventure_reference )
);
