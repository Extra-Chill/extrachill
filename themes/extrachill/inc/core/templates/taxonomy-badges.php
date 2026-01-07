<?php
/**
 * Centralized Taxonomy Badges Template
 *
 * Single source of truth for all taxonomy badge display logic across the theme.
 * Supports categories, locations, festivals, artists, and venues with consistent styling.
 *
 * @package ExtraChill
 * @since 1.0.0
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

    // Check if this is a cross-site search result
    $origin_site_id = isset( $GLOBALS['post']->_origin_site_id ) ? $GLOBALS['post']->_origin_site_id : null;
    $current_site_id = get_current_blog_id();
    $is_cross_site = $origin_site_id && $origin_site_id !== $current_site_id;

    // For cross-site results, switch to origin site FIRST to get correct taxonomies
    if ( $origin_site_id ) {
        switch_to_blog( $origin_site_id );
    }

    // Get ALL taxonomies for this post type (from origin site if cross-site)
    $post_type = get_post_type( $post_id );
    $taxonomies = get_object_taxonomies( $post_type );

    // Define display order for taxonomy badges
    $taxonomy_order = array(
        'category' => 1,
        'location' => 2,
        'festival' => 3,
        'venue'    => 4,
        'artist'   => 5,
        'post_tag' => 99,
    );

    // Sort taxonomies by defined order (unlisted = 50, before post_tag)
    usort( $taxonomies, function( $a, $b ) use ( $taxonomy_order ) {
        $order_a = isset( $taxonomy_order[ $a ] ) ? $taxonomy_order[ $a ] : 50;
        $order_b = isset( $taxonomy_order[ $b ] ) ? $taxonomy_order[ $b ] : 50;
        return $order_a - $order_b;
    } );

     // Exclude internal/system taxonomies from badge system
     $excluded_taxonomies = array(
         'author',
         'product_type',           // WooCommerce: simple, variable, grouped, external
         'product_visibility',     // WooCommerce: featured, rated-1, rated-2, etc.
         'product_shipping_class', // WooCommerce: shipping classes
     );

     // Process each taxonomy dynamically
     foreach ( $taxonomies as $taxonomy ) {
         if ( in_array( $taxonomy, $excluded_taxonomies, true ) ) {
             continue;
         }

          // Use stored taxonomies if available (for search results), otherwise fetch normally
          if ( isset( $GLOBALS['post']->taxonomies ) && isset( $GLOBALS['post']->taxonomies[$taxonomy] ) ) {
              $term_ids = $GLOBALS['post']->taxonomies[$taxonomy];
              $terms = array();

              foreach ( $term_ids as $term_name => $term_id ) {
                  $term = get_term( $term_id );
                  if ( $term && ! is_wp_error( $term ) ) {
                      // For cross-site, manually construct the archive URL
                      if ( $is_cross_site ) {
                          $origin_site_url = get_site_url( $origin_site_id );
                          $term_slug = $term->slug;

                          // Get taxonomy object to determine archive path
                          $tax_obj = get_taxonomy( $taxonomy );
                          $rewrite_slug = $tax_obj && isset( $tax_obj->rewrite['slug'] ) ? $tax_obj->rewrite['slug'] : $taxonomy;

                          // Manually construct archive URL: https://site.com/taxonomy-slug/term-slug/
                          $term->cross_site_link = trailingslashit( $origin_site_url ) . trailingslashit( $rewrite_slug ) . $term_slug . '/';
                      }

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

         // Limit to maximum 3 terms per taxonomy with smart selection
         if ( count( $terms ) > 3 ) {
             // Sort by post count (descending), then alphabetically (ascending)
             usort( $terms, function( $a, $b ) {
                 // Primary sort: higher post count first
                 if ( $a->count !== $b->count ) {
                     return $b->count - $a->count;
                 }
                 // Secondary sort: alphabetical by name
                 return strcmp( $a->name, $b->name );
             } );

             // Take only the top 3 terms
             $terms = array_slice( $terms, 0, 3 );
         }

         foreach ( $terms as $term ) {
             $skip_term = apply_filters('extrachill_taxonomy_badges_skip_term', false, $term, $taxonomy, $post_id);
             if ($skip_term) {
                 continue;
             }

             $term_slug = sanitize_html_class( $term->slug );
             $badge_class = $taxonomy . '-badge';

             // Add taxonomy-specific class modifier for categories (maintains CSS compatibility)
             if ( $taxonomy === 'category' ) {
                 $badge_class .= ' category-' . $term_slug . '-badge';
             } else {
                 $badge_class .= ' ' . $taxonomy . '-' . $term_slug;
             }

             // Use manually constructed cross-site link if available, otherwise use get_term_link()
             $term_link = isset( $term->cross_site_link ) ? $term->cross_site_link : get_term_link( $term );

             $badges_html .= sprintf(
                 '<a href="%s" class="taxonomy-badge %s">%s</a>',
                 esc_url( $term_link ),
                 esc_attr( $badge_class ),
                 esc_html( $term->name )
             );
         }
    }

    // Restore current blog if we switched
    if ( $origin_site_id ) {
        restore_current_blog();
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