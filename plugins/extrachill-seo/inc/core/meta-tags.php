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
 * Priority: Auth pages > Post excerpt > Auto-generated from content > Site tagline (homepage)
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

		// Use excerpt if available
		if ( ! empty( $post->post_excerpt ) ) {
			return ec_seo_truncate_description( $post->post_excerpt );
		}

		// Generate from content
		$content = wp_strip_all_tags( $post->post_content );
		$content = str_replace( array( "\n", "\r", "\t" ), ' ', $content );
		$content = preg_replace( '/\s+/', ' ', $content );

		return ec_seo_truncate_description( $content );
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
