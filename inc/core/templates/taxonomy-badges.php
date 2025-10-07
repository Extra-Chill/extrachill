<?php
/**
 * Centralized Taxonomy Badges Template
 *
 * Single source of truth for all taxonomy badge display logic across the theme.
 * Supports categories, locations, festivals, artists, and venues with consistent styling.
 *
 * @package ExtraChill
 * @since 69.57
 */

/**
 * Display taxonomy badges for a post
 *
 * Dynamically displays badges for ANY taxonomy assigned to the post.
 * Maintains CSS compatibility with existing badge-colors.css styles.
 *
 * @param int|null $post_id Post ID. Defaults to current post.
 * @param array $args Configuration arguments.
 */
function extrachill_display_taxonomy_badges( $post_id = null, $args = array() ) {
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }

    if ( ! $post_id ) {
        return;
    }

    // Default arguments
    $defaults = array(
        'wrapper_class' => 'taxonomy-badges',
        'show_wrapper' => true,
        'wrapper_style' => '',
    );

    $args = wp_parse_args( $args, $defaults );

    $badges_html = '';

    // Get ALL taxonomies for this post type
    $post_type = get_post_type( $post_id );
    $taxonomies = get_object_taxonomies( $post_type );

     // Process each taxonomy dynamically
     foreach ( $taxonomies as $taxonomy ) {
         // Exclude author taxonomy from badge system
         if ( $taxonomy === 'author' ) {
             continue;
         }

          // Use stored taxonomies if available (for search results), otherwise fetch normally
          if ( isset( $GLOBALS['post']->taxonomies ) && isset( $GLOBALS['post']->taxonomies[$taxonomy] ) ) {
              $term_ids = $GLOBALS['post']->taxonomies[$taxonomy];
              $terms = array();
              foreach ( $term_ids as $term_name => $term_id ) {
                  $term = get_term( $term_id );
                  if ( $term && ! is_wp_error( $term ) ) {
                      $terms[] = $term;
                  }
              }
          } else {
              $terms = get_the_terms( $post_id, $taxonomy );
          }

          // Skip if no terms assigned
          if ( ! $terms || is_wp_error( $terms ) ) {
              continue;
          }

        foreach ( $terms as $term ) {
            $term_slug = sanitize_html_class( $term->slug );
            $badge_class = $taxonomy . '-badge';

            // Add taxonomy-specific class modifier for categories (maintains CSS compatibility)
            if ( $taxonomy === 'category' ) {
                $badge_class .= ' category-' . $term_slug . '-badge';
            } else {
                $badge_class .= ' ' . $taxonomy . '-' . $term_slug;
            }

            $badges_html .= sprintf(
                '<a href="%s" class="taxonomy-badge %s">%s</a>',
                esc_url( get_term_link( $term ) ),
                esc_attr( $badge_class ),
                esc_html( $term->name )
            );
        }
    }

    // Output badges
    if ( $badges_html ) {
        if ( $args['show_wrapper'] ) {
            $wrapper_style = $args['wrapper_style'] ? ' style="' . esc_attr( $args['wrapper_style'] ) . '"' : '';
            echo '<div class="' . esc_attr( $args['wrapper_class'] ) . '"' . $wrapper_style . '>';
            echo $badges_html;
            echo '</div>';
        } else {
            echo $badges_html;
        }
    }
}