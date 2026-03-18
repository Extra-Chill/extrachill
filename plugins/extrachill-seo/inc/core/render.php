<?php
/**
 * Standalone SEO Head Renderer
 *
 * Provides ec_seo_render_head() for templates that bypass wp_head().
 * Uses the same data functions and filters as the wp_head hooks so
 * plugins can override values via the standard filter API.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render SEO meta tags for standalone templates.
 *
 * Outputs: canonical, meta description, Open Graph, Twitter Cards,
 * and Schema.org JSON-LD. Safe to call from any template that does
 * NOT use wp_head() (which would double-output these tags).
 *
 * @param array $context Optional override context. Keys:
 *   - title       (string) Page title for OG/Twitter.
 *   - description (string) Meta description.
 *   - canonical   (string) Canonical URL.
 *   - image       (string) OG image URL.
 *   - image_alt   (string) OG image alt text.
 *   - og_type     (string) OG type (default: 'profile').
 */
function ec_seo_render_head( $context = array() ) {
	// --- Canonical ---
	$canonical = ! empty( $context['canonical'] )
		? $context['canonical']
		: ec_seo_get_final_canonical_url();

	if ( ! empty( $canonical ) ) {
		printf(
			"\n<!-- SEO: Canonical -->\n" . '<link rel="canonical" href="%s" />' . "\n",
			esc_url( $canonical )
		);
	}

	// --- Meta Description ---
	$description = ! empty( $context['description'] )
		? $context['description']
		: ec_seo_get_meta_description();

	// Apply the standard filter so plugins can still override.
	$description = apply_filters( 'extrachill_seo_standalone_meta_description', $description, $context );

	if ( ! empty( $description ) ) {
		printf(
			'<meta name="description" content="%s" />' . "\n",
			esc_attr( $description )
		);
	}

	// --- Open Graph ---
	$og_data = array(
		'og:locale'    => 'en_US',
		'og:site_name' => 'Extra Chill',
		'og:type'      => ! empty( $context['og_type'] ) ? $context['og_type'] : 'website',
		'og:title'     => ! empty( $context['title'] ) ? $context['title'] : wp_get_document_title(),
		'og:url'       => $canonical,
	);

	if ( ! empty( $description ) ) {
		$og_data['og:description'] = $description;
	}

	if ( ! empty( $context['image'] ) ) {
		$og_data['og:image'] = $context['image'];
		if ( ! empty( $context['image_alt'] ) ) {
			$og_data['og:image:alt'] = $context['image_alt'];
		}
	} else {
		$default_image = ec_seo_get_default_image();
		if ( ! empty( $default_image ) ) {
			$og_data['og:image'] = $default_image;
		}
	}

	/** This filter is documented in inc/core/open-graph.php. */
	$og_data = apply_filters( 'extrachill_seo_open_graph_data', $og_data );

	echo "\n<!-- Open Graph -->\n";
	foreach ( $og_data as $property => $content ) {
		if ( ! empty( $content ) ) {
			printf(
				'<meta property="%s" content="%s" />' . "\n",
				esc_attr( $property ),
				esc_attr( $content )
			);
		}
	}

	// --- Twitter Cards ---
	echo "\n<!-- Twitter Card -->\n";
	echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
	echo '<meta name="twitter:site" content="@extra_chill" />' . "\n";

	if ( ! empty( $og_data['og:title'] ) ) {
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $og_data['og:title'] ) );
	}
	if ( ! empty( $og_data['og:description'] ) ) {
		printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $og_data['og:description'] ) );
	}
	if ( ! empty( $og_data['og:image'] ) ) {
		printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_attr( $og_data['og:image'] ) );
		if ( ! empty( $og_data['og:image:alt'] ) ) {
			printf( '<meta name="twitter:image:alt" content="%s" />' . "\n", esc_attr( $og_data['og:image:alt'] ) );
		}
	}

	// --- Schema.org JSON-LD ---
	$graph = array();

	/** This filter is documented in inc/schema/schema-output.php. */
	$graph = apply_filters( 'extrachill_seo_schema_graph', $graph );

	if ( ! empty( $graph ) ) {
		$schema = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		echo "\n<!-- Schema.org JSON-LD -->\n";
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}
