<?php
/**
 * Meta Tags Output
 *
 * Handles title tag modification and meta description generation.
 * Uses WordPress native document_title_parts filter for title manipulation.
 *
 * Description pipeline:
 *   1. ec_seo_get_default_description() computes the default from WP context
 *   2. extrachill_seo_meta_description filter lets plugins override it
 *   3. Single output point renders the final <meta> tag
 *
 * Plugins should NOT output their own <meta name="description"> tags.
 * Instead, hook into the extrachill_seo_meta_description filter.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter document title separator
 */
add_filter(
	'document_title_separator',
	function () {
		return '-';
	},
	999
);

/**
 * Filter document title parts for site-specific patterns
 *
 * Modifies the site name suffix based on current blog context
 * and improves archive/pagination title context.
 */
add_filter(
	'document_title_parts',
	function ( $title_parts ) {
		if ( ! function_exists( 'ec_get_blog_slug_by_id' ) ) {
			return $title_parts;
		}

		$blog_id   = (int) get_current_blog_id();
		$site_slug = ec_get_blog_slug_by_id( $blog_id );

		// Build site suffixes from multisite single source of truth.
		$site_suffixes = array();
		if ( function_exists( 'ec_get_site_labels' ) ) {
			foreach ( ec_get_site_labels() as $slug => $label ) {
				if ( 'main' === $slug ) {
					$site_suffixes[ $slug ] = 'Extra Chill';
				} else {
					$site_suffixes[ $slug ] = 'Extra Chill ' . $label;
				}
			}
		}

		if ( $site_slug && isset( $site_suffixes[ $site_slug ] ) ) {
			$title_parts['site'] = $site_suffixes[ $site_slug ];
		}

		if ( is_front_page() && ! is_paged() ) {
			$title_parts['title'] = get_bloginfo( 'name' );

			$tagline = get_bloginfo( 'description' );
			if ( ! empty( $tagline ) ) {
				$title_parts['tagline'] = $tagline;
			} else {
				unset( $title_parts['tagline'] );
			}

			unset( $title_parts['site'] );
		}

		if ( is_home() && ! is_front_page() && ! is_paged() ) {
			$title_parts['title']   = get_bloginfo( 'name' );
			$title_parts['tagline'] = 'Blog';
			unset( $title_parts['site'] );
		}

		if ( is_home() && is_paged() ) {
			$page_num             = (int) get_query_var( 'paged' );
			$title_parts['title'] = sprintf( 'Blog - Page %d', $page_num );
		}

		if ( is_category() && is_paged() ) {
			$page_num             = (int) get_query_var( 'paged' );
			$cat_name             = single_cat_title( '', false );
			$title_parts['title'] = sprintf( '%s - Page %d', $cat_name, $page_num );
		}

		if ( is_tag() && is_paged() ) {
			$page_num             = (int) get_query_var( 'paged' );
			$tag_name             = single_tag_title( '', false );
			$title_parts['title'] = sprintf( '%s - Page %d', $tag_name, $page_num );
		}

		if ( is_author() && is_paged() ) {
			$page_num             = (int) get_query_var( 'paged' );
			$author_name          = get_the_author();
			$title_parts['title'] = sprintf( 'Posts by %s - Page %d', $author_name, $page_num );
		}

		// Event titles on events.extrachill.com
		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
		if ( $blog_id === $events_blog_id ) {

			// Single event: append venue, city, and date to disambiguate recurring events.
			// e.g. "Heybale at The Continental Club, Austin (Mar 26, 2028)"
			if ( is_singular( 'data_machine_events' ) ) {
				$event_id    = get_queried_object_id();
				$title_extra = array();

				// Venue name.
				$venues = wp_get_post_terms( $event_id, 'venue', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $venues ) && ! empty( $venues ) ) {
					$title_extra[] = 'at ' . $venues[0];
				}

				// City from location taxonomy.
				$locations = wp_get_post_terms( $event_id, 'location', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
					$title_extra[] = $locations[0];
				}

				// Event date.
				if ( function_exists( 'datamachine_get_event_dates' ) ) {
					$dates = datamachine_get_event_dates( $event_id );
					if ( $dates && ! empty( $dates->start_datetime ) ) {
						$title_extra[] = '(' . wp_date( 'M j, Y', strtotime( $dates->start_datetime ) ) . ')';
					}
				}

				if ( ! empty( $title_extra ) ) {
					$title_parts['title'] = $title_parts['title'] . ' ' . implode( ', ', $title_extra );
				}
			}

			// Archive: "Live Music Calendar"
			if ( is_post_type_archive( 'data_machine_events' ) ) {
				$title_parts['title'] = 'Live Music Calendar';
			}

			if ( is_tax() ) {
				$term     = get_queried_object();
				$taxonomy = $term->taxonomy ?? '';

				$event_taxonomies = array( 'location', 'festival', 'venue', 'promoter', 'artist' );
				if ( in_array( $taxonomy, $event_taxonomies, true ) ) {
					$term_name            = single_term_title( '', false );
					$title_parts['title'] = sprintf( '%s Live Music Calendar', $term_name );
				}
			}
		}

		return $title_parts;
	}
);

/**
 * Output meta description tag.
 *
 * Single output point. Computes default description, applies filter,
 * and renders. Plugins should use the extrachill_seo_meta_description
 * filter instead of outputting their own <meta> tag.
 */
add_action(
	'wp_head',
	function () {
		$description = ec_seo_get_meta_description();

		if ( ! empty( $description ) ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( $description )
			);
		}
	},
	5
);

/**
 * Get the final meta description for the current page.
 *
 * Computes a default from WordPress context, then applies the
 * extrachill_seo_meta_description filter so plugins can override.
 *
 * @return string Meta description (max 160 chars).
 */
function ec_seo_get_meta_description() {
	$description = ec_seo_get_default_description();

	/**
	 * Filter the meta description before output.
	 *
	 * Plugins should return a non-empty string to override the default
	 * description. Return empty string to suppress output entirely.
	 *
	 * @param string $description Default meta description.
	 */
	$description = apply_filters( 'extrachill_seo_meta_description', $description );

	return $description;
}

/**
 * Compute default meta description from WordPress context.
 *
 * Priority for singular content:
 *   1. Manual per-post description meta (`_ec_seo_meta_description`) — lets
 *      crafted landing pages (e.g. /power) carry an intentional snippet.
 *   2. Post excerpt.
 *   3. Raw post_content (classic posts — the #39 path).
 *   4. Rendered content via the_content (block / template / the_content-filter
 *      pages whose raw post_content is empty — e.g. /power, /contact).
 *
 * Non-singular priority: Auth pages > term/author description > site tagline (homepage).
 *
 * @return string Meta description (max 160 chars).
 */
function ec_seo_get_default_description() {
	// Auth page descriptions (login exists on all sites, reset-password on community only).
	if ( is_page( 'login' ) ) {
		return 'Sign in to Extra Chill to access your profile, join community discussions, and connect with independent music fans.';
	}

	if ( is_page( 'reset-password' ) ) {
		return 'Reset your Extra Chill account password to regain access to your profile and community features.';
	}

	if ( is_singular() ) {
		$post = get_queried_object();

		// is_singular() can be true on virtual/plugin-driven singular contexts
		// where get_queried_object() returns null. Guard the null deref.
		if ( $post instanceof \WP_Post ) {
			// 1. Manual per-post description (crafted landing pages like /power).
			$manual = ec_seo_get_manual_post_description( $post );
			if ( ! empty( $manual ) ) {
				return ec_seo_truncate_description( $manual );
			}

			// 2. Use excerpt if available.
			if ( ! empty( $post->post_excerpt ) ) {
				return ec_seo_truncate_description( $post->post_excerpt );
			}

			// 3. Generate from raw post_content (classic posts — the #39 path).
			$content = wp_strip_all_tags( $post->post_content );
			$content = str_replace( array( "\n", "\r", "\t" ), ' ', $content );
			$content = preg_replace( '/\s+/', ' ', $content );
			$content = trim( $content );

			if ( '' !== $content ) {
				return ec_seo_truncate_description( $content );
			}

			// 4. Raw post_content was empty — derive from RENDERED content.
			// Block-themed pages and the_content-filter pages (e.g. /power,
			// /contact) store almost nothing in post_content; the text lives in
			// blocks / templates / the_content filters. Render it (cheaply,
			// cached per request) so those pages still get a snippet.
			$rendered = ec_seo_get_rendered_description( $post );
			if ( ! empty( $rendered ) ) {
				return ec_seo_truncate_description( $rendered );
			}
		}
	}

	if ( is_front_page() || is_home() ) {
		return ec_seo_truncate_description( get_bloginfo( 'description' ) );
	}

	if ( is_category() || is_tag() ) {
		$term = get_queried_object();
		if ( ! empty( $term->description ) ) {
			return ec_seo_truncate_description( $term->description );
		}
	}

	if ( is_author() ) {
		$author = get_queried_object();
		$bio    = get_the_author_meta( 'description', $author->ID );
		if ( ! empty( $bio ) ) {
			return ec_seo_truncate_description( $bio );
		}
	}

	return '';
}

/**
 * Meta key for a manual, per-post SEO description.
 *
 * Stored as post meta so crafted landing pages (e.g. /power) can carry an
 * intentional, conversion-oriented snippet instead of an auto-derived one.
 * Registered with show_in_rest so it can be set via the REST API / WP-CLI.
 */
const EC_SEO_DESCRIPTION_META_KEY = '_ec_seo_meta_description';

/**
 * Register the manual SEO description post meta.
 *
 * Registered for every public post type so any singular content can opt into a
 * hand-written description. Exposed in REST (auth required to write) so editors
 * and tooling can set it without a bespoke meta box.
 */
add_action(
	'init',
	function () {
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			register_post_meta(
				$post_type,
				EC_SEO_DESCRIPTION_META_KEY,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	},
	20
);

/**
 * Resolve a manual (hand-written) description for a post.
 *
 * Reads the `_ec_seo_meta_description` post meta, then exposes a filter so
 * plugins can supply a programmatic per-post description (e.g. the blog plugin
 * for its server-rendered /power manifesto) without needing the meta field set.
 *
 * @param \WP_Post $post The queried post.
 * @return string Manual description, or empty string when none is set.
 */
function ec_seo_get_manual_post_description( $post ) {
	$manual = get_post_meta( $post->ID, EC_SEO_DESCRIPTION_META_KEY, true );

	if ( ! is_string( $manual ) ) {
		$manual = '';
	}

	/**
	 * Filter the manual per-post meta description.
	 *
	 * Lets plugins supply an intentional description for pages whose content is
	 * rendered server-side (block templates, the_content filters) rather than
	 * stored in post_content. Return a non-empty string to use it.
	 *
	 * @param string   $manual Description from post meta (may be empty).
	 * @param \WP_Post $post   The queried post object.
	 */
	$manual = apply_filters( 'extrachill_seo_post_meta_description', $manual, $post );

	return is_string( $manual ) ? trim( $manual ) : '';
}

/**
 * Derive a description from a post's RENDERED content.
 *
 * Block-themed pages and pages that inject their body through a `the_content`
 * filter (e.g. the /power manifesto) store almost nothing in raw post_content,
 * so the post_content fallback produces nothing. Running the content through
 * `the_content` captures block output (do_blocks) AND the_content-filter
 * injections, giving those pages a real snippet.
 *
 * Cost guard: this only runs when post_content has no usable raw text (so
 * classic posts never pay for it), runs at most once per post per request
 * (static cache), and is re-entrancy guarded so a the_content filter that
 * itself calls into SEO can't recurse.
 *
 * @param \WP_Post $post The queried post.
 * @return string Rendered description text, or empty string.
 */
function ec_seo_get_rendered_description( $post ) {
	static $cache    = array();
	static $rendering = false;

	if ( isset( $cache[ $post->ID ] ) ) {
		return $cache[ $post->ID ];
	}

	// Re-entrancy guard: never render the_content while already rendering it.
	if ( $rendering ) {
		return '';
	}

	$rendering = true;

	// Run the same pipeline core uses for the_content so block output and
	// the_content-filter injections are both captured.
	$rendered = apply_filters( 'the_content', $post->post_content );

	$rendering = false;

	$rendered = wp_strip_all_tags( $rendered );
	$rendered = str_replace( array( "\n", "\r", "\t" ), ' ', $rendered );
	$rendered = preg_replace( '/\s+/', ' ', $rendered );
	$rendered = trim( (string) $rendered );

	$cache[ $post->ID ] = $rendered;

	return $rendered;
}

/**
 * Truncate description to 160 characters at word boundary
 *
 * @param string $text Text to truncate
 * @return string Truncated text
 */
function ec_seo_truncate_description( $text ) {
	$text = trim( wp_strip_all_tags( $text ) );

	if ( strlen( $text ) <= 160 ) {
		return $text;
	}

	$text       = substr( $text, 0, 157 );
	$last_space = strrpos( $text, ' ' );

	if ( false !== $last_space ) {
		$text = substr( $text, 0, $last_space );
	}

	return $text . '...';
}
