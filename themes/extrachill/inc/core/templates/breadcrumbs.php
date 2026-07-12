<?php
/**
 * Unified Breadcrumb System
 *
 * Technical Implementation:
 * - bbPress integration: Plugin-based breadcrumbs via extrachill_breadcrumbs_override_trail filter
 * - WooCommerce integration: Shop plugin provides breadcrumbs via extrachill_breadcrumbs_override_trail filter
 * - Hierarchical taxonomies: Full ancestor chain display for parent/child relationships
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'extrachill_breadcrumbs_allowed_html' ) ) {
	/**
	 * Allowed HTML for breadcrumb trail output.
	 *
	 * Extends the post allowlist with the sprite-reference SVG markup emitted
	 * by ec_icon() (e.g. the network dropdown chevron), which wp_kses_post()
	 * would otherwise strip.
	 *
	 * @return array Allowed HTML tags and attributes for wp_kses().
	 */
	function extrachill_breadcrumbs_allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['svg'] = array(
			'class'       => true,
			'aria-hidden' => true,
			'role'        => true,
			'focusable'   => true,
		);
		$allowed['use'] = array(
			'href'       => true,
			'xlink:href' => true,
		);

		return $allowed;
	}
}

if ( ! function_exists( 'extrachill_breadcrumbs' ) ) {
	function extrachill_breadcrumbs() {
		echo '<nav class="breadcrumbs" itemprop="breadcrumb">';

		// Allow plugins to override the root breadcrumb link.
		$root_link = apply_filters( 'extrachill_breadcrumbs_root', '<a href="' . home_url() . '">' . esc_html( extrachill_get_site_title() ) . '</a>' );
		echo wp_kses_post( $root_link ) . ' › ';

		// Allow plugins to override the default breadcrumb trail.
		$custom_trail = apply_filters( 'extrachill_breadcrumbs_override_trail', '' );
		if ( ! empty( $custom_trail ) ) {
			echo wp_kses( apply_filters( 'extrachill_breadcrumbs_trail_output', $custom_trail ), extrachill_breadcrumbs_allowed_html() );
		} else {
			// Original breadcrumb logic.
			if ( is_single() && is_singular( 'post' ) ) {
				global $post;

				$categories = get_the_category( $post->ID );
				$category   = ! empty( $categories ) ? reset( $categories ) : null;

				$tags    = get_the_tags( $post->ID );
				$top_tag = null;

				if ( $tags && is_array( $tags ) ) {
					usort(
						$tags,
						function ( $a, $b ) {
							return $b->count - $a->count;
						}
					);
					$top_tag = reset( $tags );
				}

				if ( $category ) {
					echo '<a href="' . esc_url( get_category_link( $category->term_id ) ) . '">' . esc_html( $category->name ) . '</a>';
				}

				if ( $top_tag ) {
					echo ' › <a href="' . esc_url( get_tag_link( $top_tag->term_id ) ) . '">' . esc_html( $top_tag->name ) . '</a>';
				}
			} elseif ( is_singular() && ! is_singular( array( 'post', 'page', 'product' ) ) ) {
				$post_type     = get_post_type();
				$post_type_obj = $post_type ? get_post_type_object( $post_type ) : null;
				$archive_link  = $post_type ? get_post_type_archive_link( $post_type ) : false;

				if ( $archive_link && $post_type_obj ) {
					echo '<a href="' . esc_url( $archive_link ) . '">' . esc_html( $post_type_obj->labels->name ) . '</a>';
				}
			} elseif ( is_page() ) {
				$current_id = get_the_ID();
				if ( false !== $current_id ) {
					$parent_id = wp_get_post_parent_id( $current_id );
					if ( $parent_id ) {
						$parent_title = get_the_title( $parent_id );
						$parent_url   = get_permalink( $parent_id );
						echo '<a href="' . esc_url( (string) $parent_url ) . '">' . esc_html( $parent_title ) . '</a> › ';
					}
				}
				echo '<span class="breadcrumb-title">' . esc_html( get_the_title() ) . '</span>';
			}

			// Trailing breadcrumb title. Pages are fully handled above and skip this chain.
			if ( ! is_page() ) {
				if ( is_singular() && ! is_front_page() ) {
					echo '<span class="breadcrumb-title"> › ' . esc_html( get_the_title() ) . '</span>';
				} elseif ( is_post_type_archive() ) {
					$post_type     = get_post_type();
					$post_type_obj = $post_type ? get_post_type_object( $post_type ) : null;

					if ( $post_type_obj ) {
						echo '<span>' . esc_html( $post_type_obj->labels->name ) . '</span>';
					}
				} elseif ( is_category() ) {
					$category = get_queried_object();
					if ( $category instanceof WP_Term && $category->parent ) {
						$parent_category = get_category( $category->parent );
						if ( $parent_category instanceof WP_Term ) {
							echo '<a href="' . esc_url( get_category_link( $parent_category->term_id ) ) . '">' . esc_html( $parent_category->name ) . '</a> › ';
						}
					}
					echo '<span>' . esc_html( single_cat_title( '', false ) ) . '</span>';
				} elseif ( is_tag() ) {
					echo '<span>' . esc_html( single_tag_title( '', false ) ) . '</span>';
				} elseif ( is_tax() ) {
					$term = get_queried_object();

					if ( $term instanceof WP_Term ) {
						$taxonomy = get_taxonomy( $term->taxonomy );

						if ( $taxonomy ) {
							if ( ! $taxonomy->hierarchical ) {
								echo '<span>' . esc_html( $taxonomy->labels->name ) . '</span> › ';
							}

							if ( $taxonomy->hierarchical && $term->parent ) {
								$parents = get_ancestors( $term->term_id, $term->taxonomy );
								if ( ! empty( $parents ) ) {
									$parents = array_reverse( $parents );
									foreach ( $parents as $parent_id ) {
										$parent_term = get_term( $parent_id, $term->taxonomy );
										if ( $parent_term instanceof WP_Term ) {
											$parent_term_link = get_term_link( $parent_term );
											if ( is_string( $parent_term_link ) ) {
												echo '<a href="' . esc_url( $parent_term_link ) . '">' . esc_html( $parent_term->name ) . '</a> › ';
											}
										}
									}
								}
							}

							echo '<span>' . esc_html( $term->name ) . '</span>';
						}
					}
				} elseif ( is_search() ) {
					echo '<span>Search Results</span>';
				} elseif ( is_author() ) {
					$author = get_queried_object();
					echo '<span>Author</span> › ';
					if ( $author instanceof WP_User ) {
						echo '<span>' . esc_html( $author->display_name ) . '</span>';
					}
				} elseif ( is_404() ) {
					echo '<span>Page Not Found</span>';
				} else {
					echo '<span>Archives</span>';
				}
			}
		}

		// Allow plugins to append custom breadcrumb items.
		do_action( 'extrachill_breadcrumbs_append' );

		echo '</nav>';
	}
}
