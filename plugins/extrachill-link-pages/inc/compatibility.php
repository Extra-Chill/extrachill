<?php
/**
 * Historical public helper aliases.
 *
 * These names intentionally retain their legacy domain wording. They are
 * declared late so a loaded companion fallback always wins without symbol
 * redeclaration during the rolling extraction.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Declare missing historical public helpers after companion plugins initialize. */
function ec_register_link_page_public_compatibility_aliases() {
	if ( ! function_exists( 'extrachill_add_rewrite_rules' ) ) {
		function extrachill_add_rewrite_rules() {
			ec_register_link_page_public_rewrites();
		}
	}
	if ( ! function_exists( 'extrachill_add_query_vars' ) ) {
		function extrachill_add_query_vars( $vars ) {
			return ec_add_link_page_public_query_vars( $vars );
		}
	}
	if ( ! function_exists( 'extrachill_prevent_canonical_redirect_for_link_domain' ) ) {
		function extrachill_prevent_canonical_redirect_for_link_domain( $redirect_url, $requested_url ) {
			return ec_prevent_link_page_public_canonical_redirect( $redirect_url, $requested_url );
		}
	}
	if ( ! function_exists( 'extrachill_resolve_link_domain_query' ) ) {
		function extrachill_resolve_link_domain_query() {
			ec_resolve_link_page_public_query();
		}
	}
	if ( ! function_exists( 'extrachill_handle_link_domain_routing' ) ) {
		function extrachill_handle_link_domain_routing( $template ) {
			return ec_link_page_public_template( $template );
		}
	}
	if ( ! function_exists( 'extrachill_redirect_artist_link_page_cpt_to_custom_domain' ) ) {
		function extrachill_redirect_artist_link_page_cpt_to_custom_domain() {
			ec_redirect_direct_link_page_request();
		}
	}
	if ( ! function_exists( 'extrachill_artist_link_page_sitemap_urls' ) ) {
		function extrachill_artist_link_page_sitemap_urls( $urls ) {
			return ec_link_page_sitemap_urls( $urls );
		}
	}
	if ( ! function_exists( 'extrachill_artist_enqueue_link_page_minimal_assets' ) ) {
		function extrachill_artist_enqueue_link_page_minimal_assets( $link_page_id, $owner = null ) {
			ec_enqueue_link_page_minimal_assets( $link_page_id, $owner );
		}
	}
	if ( ! function_exists( 'ec_get_link_page_public_urls' ) ) {
		function ec_get_link_page_public_urls( $link_page_id ) {
			return ec_link_page_public_urls( $link_page_id );
		}
	}
	if ( ! function_exists( 'ec_generate_css_variables_style_block' ) ) {
		function ec_generate_css_variables_style_block( $css_vars, $element_id = 'link-page-custom-vars' ) {
			return ec_link_page_css_variables_style_block( $css_vars, $element_id );
		}
	}
}

add_action( 'init', 'ec_register_link_page_public_compatibility_aliases', 99 );
