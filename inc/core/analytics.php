<?php
/**
 * Google Tag Manager Integration
 *
 * Outputs GTM scripts via wp_head and wp_body_open hooks.
 * Can be disabled via the 'extrachill_disable_gtm' filter.
 *
 * @package ExtraChill
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_GTM_CONTAINER_ID', 'GTM-NXKDLFD' );

/**
 * Outputs GTM JavaScript snippet in the <head> section.
 */
function extrachill_output_gtm_head() {
	if ( apply_filters( 'extrachill_disable_gtm', false ) ) {
		return;
	}

	$gtm_id = EXTRACHILL_GTM_CONTAINER_ID;
	?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js( $gtm_id ); ?>');</script>
<!-- End Google Tag Manager -->
	<?php
}
add_action( 'wp_head', 'extrachill_output_gtm_head', 1 );

/**
 * Outputs GTM noscript fallback immediately after <body> tag.
 */
function extrachill_output_gtm_body() {
	if ( apply_filters( 'extrachill_disable_gtm', false ) ) {
		return;
	}

	$gtm_id = EXTRACHILL_GTM_CONTAINER_ID;
	?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( $gtm_id ); ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
	<?php
}
add_action( 'wp_body_open', 'extrachill_output_gtm_body', 1 );
