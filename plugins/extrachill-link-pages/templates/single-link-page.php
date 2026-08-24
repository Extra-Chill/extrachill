<?php
/**
 * Standalone public Link Page shell.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

global $wp_query;
$link_page = $wp_query->get_queried_object();
if ( ! $link_page || empty( $link_page->ID ) || EC_LINK_PAGE_POST_TYPE !== $link_page->post_type ) {
	status_header( 404 );
	return;
}
$link_page_id = (int) $link_page->ID;
$data         = ec_read_link_page_persistence( $link_page_id );
$projection   = ec_get_link_page_public_projection(
	$link_page_id,
	array(
		'method' => sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ),
		'host'   => sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ),
		'uri'    => sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
	)
);
if ( is_wp_error( $data ) || is_wp_error( $projection ) ) {
	$runtime_error = is_wp_error( $projection ) ? $projection : $data;
	$error_data    = $runtime_error->get_error_data();
	$not_found     = is_array( $error_data ) && 404 === (int) ( $error_data['status'] ?? 0 );
	status_header( $not_found ? 404 : 500 );
	return;
}
$projection = ec_prepare_link_page_public_render( $projection, $data );
if ( is_wp_error( $projection ) ) {
	status_header( 500 );
	return;
}
$data['css_vars']     = array_merge( $data['css_vars'], $projection['css_vars'] );
$background_type      = $data['css_vars']['--link-page-background-type'] ?? 'color';
$background_image_url = $data['background_image_url'];
if ( 'image' === $background_type && $background_image_url ) {
	$body_style = 'background-image:url(' . esc_url( $background_image_url ) . ');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed;';
} elseif ( 'gradient' === $background_type ) {
	$body_style = 'background:linear-gradient(' . esc_attr( $data['css_vars']['--link-page-background-gradient-direction'] ) . ', ' . esc_attr( $data['css_vars']['--link-page-background-gradient-start'] ) . ', ' . esc_attr( $data['css_vars']['--link-page-background-gradient-end'] ) . ');background-attachment:fixed;';
} else {
	$body_style = 'background-color:' . esc_attr( $data['css_vars']['--link-page-background-color'] ) . ';';
}
$body_style      = $projection['body_style'] ? $projection['body_style'] : $body_style . 'min-height:100vh;';
$body_attributes = array_merge(
	array(
		'data-extrch-link-page-id'       => (string) $link_page_id,
		'data-extrch-tracking-click-url' => $projection['tracking_url'],
	),
	$projection['body_attributes']
);
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head><?php ec_render_link_page_public_head( $link_page_id, $data, $projection ); ?></head>
<body class="extrch-link-page" style="<?php echo esc_attr( $body_style ); ?>"
<?php
foreach ( $body_attributes as $attribute => $value ) :
	?>
	<?php echo esc_attr( $attribute ); ?>="<?php echo esc_attr( (string) $value ); ?>"<?php endforeach; ?>>
<?php echo $projection['_rendered_components']['body_start']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output. ?>
<?php require EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'templates/link-page.php'; ?>
<?php wp_print_footer_scripts(); ?>
</body>
</html>
