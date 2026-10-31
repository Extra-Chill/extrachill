<?php
/**
 * Google Tag Manager Integration
 *
 * Outputs GTM scripts via wp_head and wp_body_open hooks.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_GTM_CONTAINER_ID_OPTION', 'extrachill_gtm_container_id' );

/**
 * Get the configured GTM container ID.
 *
 * @return string
 */
function extrachill_analytics_get_gtm_container_id() {
	$gtm_container_id = get_site_option( EXTRACHILL_ANALYTICS_GTM_CONTAINER_ID_OPTION, '' );

	return (string) apply_filters( 'extrachill_gtm_container_id', $gtm_container_id );
}

/**
 * Outputs GTM JavaScript snippet in the <head> section.
 *
 * @return void
 */
function extrachill_analytics_output_gtm_head() {
	if ( apply_filters( 'extrachill_disable_gtm', false ) ) {
		return;
	}

	$gtm_id = extrachill_analytics_get_gtm_container_id();
	if ( '' === $gtm_id ) {
		return;
	}
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
add_action( 'wp_head', 'extrachill_analytics_output_gtm_head', 1 );

/**
 * Outputs GTM noscript fallback immediately after <body> tag.
 *
 * @return void
 */
function extrachill_analytics_output_gtm_body() {
	if ( apply_filters( 'extrachill_disable_gtm', false ) ) {
		return;
	}

	$gtm_id = extrachill_analytics_get_gtm_container_id();
	if ( '' === $gtm_id ) {
		return;
	}
	?>
	<!-- Google Tag Manager (noscript) -->
	<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( $gtm_id ); ?>"
	height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
	<!-- End Google Tag Manager (noscript) -->
	<?php
}
add_action( 'wp_body_open', 'extrachill_analytics_output_gtm_body', 1 );
