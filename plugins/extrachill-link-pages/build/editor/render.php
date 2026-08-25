<?php
/** Portable Link Page editor block mount. */
defined( 'ABSPATH' ) || exit;

$configuration = apply_filters( 'ec_link_page_editor_configuration', null, $attributes, $block );
if ( ! is_array( $configuration ) || empty( $configuration['adapter'] ) || empty( $configuration['identities'] ) ) {
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Link Page management is unavailable.', 'extrachill-link-pages' ) . '</p></div>';
	return;
}

$mount_id = wp_unique_id( 'ec-link-page-editor-root-' );
echo '<div ' . get_block_wrapper_attributes( array( 'class' => 'ec-link-page-editor' ) ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes block attributes.
echo '<div id="' . esc_attr( $mount_id ) . '" class="ec-link-page-editor-root"></div>';
echo '<script type="application/json" data-link-page-editor-config="' . esc_attr( $mount_id ) . '">' . wp_json_encode( $configuration, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is hex escaped in an inert script element.
echo '</div>';
