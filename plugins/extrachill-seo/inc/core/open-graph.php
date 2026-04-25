<?php
/**
 * Open Graph Meta Tags
 *
 * Outputs OG tags for social sharing on Facebook, LinkedIn, etc.
 * Uses the filtered description and canonical from meta-tags.php
 * and canonical.php so plugins only need to hook in one place.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output Open Graph meta tags
 */
add_action(
	'wp_head',
	function () {
		$og_data = ec_seo_get_open_graph_data();

		/**
		 * Filter Open Graph data before output.
		 *
		 * Allows plugins to override individual OG properties (e.g., og:url,
		 * og:description) for specialized page types like discovery pages.
		 *
		 * @param array $og_data Associative array of OG property => value.
		 */
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
	},
	5
);

/**
 * Get Open Graph data for current page.
 *
 * Uses the filtered description and canonical URL so OG tags
 * automatically reflect any plugin overrides.
 *
 * @return array OG properties and values
 */
function ec_seo_get_open_graph_data() {
	$data = array(
		'og:locale'    => 'en_US',
		'og:site_name' => 'Extra Chill',
		'og:type'      => 'website',
		'og:title'     => wp_get_document_title(),
		'og:url'       => ec_seo_get_final_canonical_url(),
	);

	// Use the filtered description (same as <meta name="description">).
	$description = ec_seo_get_meta_description();
	if ( ! empty( $description ) ) {
		$data['og:description'] = $description;
	}

	// Singular content
	if ( is_singular() ) {
		$post = get_queried_object();

		// Article type for posts
		if ( 'post' === $post->post_type ) {
			$data['og:type']                = 'article';
			$data['article:published_time'] = get_the_date( 'c', $post );
			$data['article:modified_time']  = get_the_modified_date( 'c', $post );

			// Article author
			$author = get_the_author_meta( 'display_name', $post->post_author );
			if ( $author ) {
				$data['article:author'] = $author;
			}
		}

		// Featured image
		if ( has_post_thumbnail( $post->ID ) ) {
			$image_id = get_post_thumbnail_id( $post->ID );
			$image    = wp_get_attachment_image_src( $image_id, 'large' );

			if ( $image ) {
				$data['og:image']        = $image[0];
				$data['og:image:width']  = $image[1];
				$data['og:image:height'] = $image[2];

				$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
				if ( $alt ) {
					$data['og:image:alt'] = $alt;
				}
			}
		}

		// Plugin-provided fallback for posts without featured images.
		// Allows plugins (e.g. data-machine-events) to supply a generated
		// per-post OG image — typically rendered from a Data Machine template.
		if ( empty( $data['og:image'] ) ) {
			/**
			 * Filter the OG image URL for a singular post.
			 *
			 * Fires only when the post has no featured image. Plugins should
			 * return a fully qualified image URL (1200x630 recommended) or an
			 * empty string to defer to the network default.
			 *
			 * @param string   $image_url Empty string by default.
			 * @param \WP_Post $post      The queried post object.
			 */
			$singular_image = apply_filters( 'extrachill_seo_singular_og_image_url', '', $post );

			if ( ! empty( $singular_image ) ) {
				$data['og:image']        = $singular_image;
				$data['og:image:width']  = 1200;
				$data['og:image:height'] = 630;

				/**
				 * Filter the alt text for a plugin-provided singular OG image.
				 *
				 * @param string   $alt_text Empty string by default.
				 * @param \WP_Post $post     The queried post object.
				 */
				$singular_alt = apply_filters( 'extrachill_seo_singular_og_image_alt', '', $post );
				if ( ! empty( $singular_alt ) ) {
					$data['og:image:alt'] = $singular_alt;
				}
			}
		}
	}

	// Taxonomy archives — allow plugins to provide a term-specific image.
	if ( empty( $data['og:image'] ) && ( is_category() || is_tag() || is_tax() ) ) {
		$term = get_queried_object();

		if ( $term instanceof \WP_Term ) {
			/**
			 * Filter the OG image URL for a taxonomy archive page.
			 *
			 * Allows plugins to supply a term-specific image (e.g. a city
			 * photo for a location term on the events site).
			 *
			 * @param string   $image_url Empty string by default.
			 * @param \WP_Term $term      The queried term object.
			 */
			$term_image = apply_filters( 'extrachill_seo_term_og_image_url', '', $term );

			if ( ! empty( $term_image ) ) {
				$data['og:image'] = $term_image;
			}
		}
	}

	// Default image fallback for pages without featured image
	if ( empty( $data['og:image'] ) ) {
		$data['og:image'] = ec_seo_get_default_image();
	}

	return $data;
}

/**
 * Get canonical URL for current page
 *
 * @deprecated Use ec_seo_get_final_canonical_url() or ec_seo_get_default_canonical_url() instead.
 * @return string Canonical URL
 */
function ec_seo_get_canonical_url() {
	// bbPress user subpages use main profile URL for OG.
	if ( function_exists( 'ec_seo_get_bbp_user_subpage_canonical' ) ) {
		$bbp_canonical = ec_seo_get_bbp_user_subpage_canonical();
		if ( $bbp_canonical ) {
			return $bbp_canonical;
		}
	}

	if ( is_singular() ) {
		return get_permalink();
	}

	if ( is_front_page() ) {
		return home_url( '/' );
	}

	if ( is_home() ) {
		$page_for_posts = get_option( 'page_for_posts' );
		if ( $page_for_posts ) {
			$url = get_permalink( $page_for_posts );
		} else {
			$url = home_url( '/' );
		}

		// Add pagination
		$paged = get_query_var( 'paged' );
		if ( $paged > 1 ) {
			$url = trailingslashit( $url ) . 'page/' . $paged . '/';
		}

		return $url;
	}

	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		// Check for cross-site canonical authority on custom taxonomies.
		if ( is_tax() && function_exists( 'ec_get_canonical_authority_url' ) ) {
			$authority_url = ec_get_canonical_authority_url( $term, $term->taxonomy );
			if ( $authority_url ) {
				// Cross-site canonical doesn't use pagination from current site.
				return $authority_url;
			}
		}

		$url = get_term_link( $term );

		// Add pagination
		$paged = get_query_var( 'paged' );
		if ( $paged > 1 ) {
			$url = trailingslashit( $url ) . 'page/' . $paged . '/';
		}

		return $url;
	}

	if ( is_author() ) {
		$author = get_queried_object();
		$url    = get_author_posts_url( $author->ID );

		// Add pagination
		$paged = get_query_var( 'paged' );
		if ( $paged > 1 ) {
			$url = trailingslashit( $url ) . 'page/' . $paged . '/';
		}

		return $url;
	}

	if ( is_search() ) {
		return home_url( '?s=' . rawurlencode( get_search_query() ) );
	}

	// Fallback to current URL
	global $wp;
	return home_url( $wp->request );
}

/**
 * Get default OG image
 *
 * The default image is a network option pointing to an attachment on the
 * main site (blog ID 1).  On subsites `wp_get_attachment_image_url()`
 * cannot resolve it without switching context first.
 *
 * @return string Default image URL
 */
function ec_seo_get_default_image() {
	$attachment_id = ec_seo_get_default_og_image_id();
	if ( ! $attachment_id ) {
		return '';
	}

	// Try current site first (works on the main site).
	$url = wp_get_attachment_image_url( $attachment_id, 'large' );

	// On subsites the attachment lives on the main site — switch context.
	if ( ! $url && function_exists( 'ec_get_blog_id' ) ) {
		$main_blog_id = ec_get_blog_id( 'main' );

		if ( $main_blog_id && (int) get_current_blog_id() !== $main_blog_id ) {
			try {
				switch_to_blog( $main_blog_id );
				$url = wp_get_attachment_image_url( $attachment_id, 'large' );
			} finally {
				restore_current_blog();
			}
		}
	}

	return $url ? $url : '';
}
