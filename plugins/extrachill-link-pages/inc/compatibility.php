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

/**
 * Declare missing historical public helpers after companion plugins initialize.
 *
 * The aliases must be named global functions declared late (on `init`) so a
 * companion plugin's own definition always wins. They therefore cannot be
 * top-level or anonymous functions, and each declaration carries a precise
 * PHPStan suppression for the unsupported inner-function pattern.
 */
function ec_register_link_page_public_compatibility_aliases() {
	if ( ! function_exists( 'extrachill_add_rewrite_rules' ) ) {
		/**
		 * Historical alias of ec_register_link_page_public_rewrites().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function extrachill_add_rewrite_rules() {
			ec_register_link_page_public_rewrites();
		}
	}
	if ( ! function_exists( 'extrachill_add_query_vars' ) ) {
		/**
		 * Historical alias of ec_add_link_page_public_query_vars().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function extrachill_add_query_vars( $vars ) {
			return ec_add_link_page_public_query_vars( $vars );
		}
	}
	if ( ! function_exists( 'extrachill_prevent_canonical_redirect_for_link_domain' ) ) {
		/**
		 * Historical alias of ec_prevent_link_page_public_canonical_redirect().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function extrachill_prevent_canonical_redirect_for_link_domain( $redirect_url, $requested_url ) {
			return ec_prevent_link_page_public_canonical_redirect( $redirect_url, $requested_url );
		}
	}
	if ( ! function_exists( 'extrachill_resolve_link_domain_query' ) ) {
		/**
		 * Historical alias of ec_resolve_link_page_public_query().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function extrachill_resolve_link_domain_query() {
			ec_resolve_link_page_public_query();
		}
	}
	if ( ! function_exists( 'extrachill_handle_link_domain_routing' ) ) {
		/**
		 * Historical alias of ec_link_page_public_template().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function extrachill_handle_link_domain_routing( $template ) {
			return ec_link_page_public_template( $template );
		}
	}
	if ( ! function_exists( 'extrachill_redirect_artist_link_page_cpt_to_custom_domain' ) ) {
		/**
		 * Historical alias of ec_redirect_direct_link_page_request().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function extrachill_redirect_artist_link_page_cpt_to_custom_domain() {
			ec_redirect_direct_link_page_request();
		}
	}
	if ( ! function_exists( 'extrachill_artist_link_page_sitemap_urls' ) ) {
		/**
		 * Historical alias of ec_link_page_sitemap_urls().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function extrachill_artist_link_page_sitemap_urls( $urls ) {
			return ec_link_page_sitemap_urls( $urls );
		}
	}
	if ( ! function_exists( 'extrachill_artist_enqueue_link_page_minimal_assets' ) ) {
		/**
		 * Historical alias of ec_enqueue_link_page_minimal_assets().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function extrachill_artist_enqueue_link_page_minimal_assets( $link_page_id, $owner = null ) {
			ec_enqueue_link_page_minimal_assets( $link_page_id, $owner );
		}
	}
	if ( ! function_exists( 'ec_get_link_page_public_urls' ) ) {
		/**
		 * Historical alias of ec_link_page_public_urls().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function ec_get_link_page_public_urls( $link_page_id ) {
			return ec_link_page_public_urls( $link_page_id );
		}
	}
	if ( ! function_exists( 'ec_generate_css_variables_style_block' ) ) {
		/**
		 * Historical alias of ec_link_page_css_variables_style_block().
		 *
		 * @phpstan-ignore function.inner (Declared late so a companion definition wins.)
		 */
		function ec_generate_css_variables_style_block( $css_vars, $element_id = 'link-page-custom-vars' ) {
			return ec_link_page_css_variables_style_block( $css_vars, $element_id );
		}
	}
}

add_action( 'init', 'ec_register_link_page_public_compatibility_aliases', 99 );
