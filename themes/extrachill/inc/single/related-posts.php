<?php
/**
 * Related Posts
 *
 * Displays related posts from artist or venue taxonomy with 1-hour caching.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

/**
 * Display related posts from taxonomy term
 *
 * @param string $taxonomy artist or venue
 * @param int    $post_id Current post to exclude
 */
function extrachill_display_related_posts( $taxonomy, $post_id ) {
	static $displayed_posts = array();

	$allowed_taxonomies = apply_filters( 'extrachill_related_posts_allowed_taxonomies', array( 'artist', 'venue' ), get_post_type( $post_id ) );

	if ( ! in_array( $taxonomy, $allowed_taxonomies, true ) ) {
		return;
	}

	if ( apply_filters( 'extrachill_override_related_posts_display', false, $taxonomy, $post_id ) ) {
		do_action( 'extrachill_custom_related_posts_display', $taxonomy, $post_id );
		return;
	}

        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! $terms || is_wp_error( $terms ) ) {
                return;
        }

        $term      = $terms[0];
        $term_id   = $term->term_id;
        $term_link = get_term_link( $term );
        $term_name = esc_html( $term->name );

	$cache_key          = $taxonomy . '_posts_' . $term_id . '_' . $post_id;
	$related_posts_data = get_transient( $cache_key );

	if ( false === $related_posts_data ) {
		$tax_query = array(
			array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		);

		$tax_query = apply_filters( 'extrachill_related_posts_tax_query', $tax_query, $taxonomy, $term_id, $post_id, get_post_type( $post_id ) );

		$query_args = array(
			'post_type'      => 'post',
			'posts_per_page' => 3,
			'post_status'    => 'publish',
			'tax_query'      => $tax_query,
			'post__not_in'   => array( $post_id ),
		);

		$query_args = apply_filters( 'extrachill_related_posts_query_args', $query_args, $taxonomy, $post_id, get_post_type( $post_id ) );

		$related_posts = new WP_Query( $query_args );

                $related_posts_data = $related_posts->posts;
                set_transient( $cache_key, $related_posts_data, 3600 );
        } else {
                $related_posts             = new WP_Query();
                $related_posts->posts      = $related_posts_data;
                $related_posts->post_count = count( $related_posts_data );

                $related_posts->query_vars = array_merge(
                        array(
                                'fields'                 => '',
                                'update_post_term_cache' => true,
                                'update_post_meta_cache' => true,
                                'lazy_load_term_meta'    => false,
                                'ignore_sticky_posts'    => false,
                        ),
                        $related_posts->query_vars ?? array()
                );

                $related_posts->current_post = -1;
        }

        // Filter out already displayed posts to prevent duplicates
        $filtered_posts = array_filter( $related_posts->posts, function( $post ) use ( &$displayed_posts ) {
                if ( in_array( $post->ID, $displayed_posts ) ) {
                        return false;
                }
                $displayed_posts[] = $post->ID;
                return true;
        } );

        // Update the query object with filtered posts
        $related_posts->posts      = array_slice( $filtered_posts, 0, 3 );
        $related_posts->post_count = count( $related_posts->posts );

        if ( $related_posts->have_posts() ) : ?>
                <div class="related-tax-section">
                        <h3 class="related-tax-header">More from <a href="<?php echo esc_url( $term_link ); ?>" class="sidebar-tax-link"><?php echo $term_name; ?></a></h3>
                        <div class="related-tax-grid">
                                <?php
                                while ( $related_posts->have_posts() ) :
                                        $related_posts->the_post();
                                        ?>
                                        <a href="<?php the_permalink(); ?>" class="related-tax-card">
                                                <?php if ( has_post_thumbnail() ) : ?>
                                                        <span class="related-tax-thumb"><?php the_post_thumbnail( 'medium' ); ?></span>
                                                <?php endif; ?>
                                                <span class="related-tax-title"><?php the_title(); ?></span>
                                                <span class="related-tax-meta"><?php echo get_the_date(); ?></span>
                                        </a>
                                        <?php
                                endwhile;
                                wp_reset_postdata();
                                ?>
                        </div>
                </div>
                <?php
        endif;
}