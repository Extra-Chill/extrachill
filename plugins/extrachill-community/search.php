<?php
/**
 * The template for displaying Search Results pages for BBPress topics and replies.
 *
 * This template mimics the BBPress native structure like the recent topics template.
 *
 * @package GeneratePress Child Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

get_header(); ?>

<div id="bbpress-forums" class="bbpress-wrapper">

    <div id="chill-home">
        <div id="chill-home-header">
            <span>
                <?php
                // Get the search term
                $search_term = get_search_query();  // Search query term

                // Initialize count for display
                $results_count = 0;

                // Only proceed if this IS a search page
                if ( is_search() ) {
                    // Set up the query arguments for searching BBPress topics and replies
                    $paged = ( get_query_var('paged') ) ? get_query_var('paged') : 1;  // Correct paged handling
                    $args = array(
                        'post_type'      => array( 'forum', 'topic', 'reply' ),
                        'posts_per_page' => bbp_get_replies_per_page(),
                        'paged'          => $paged,  // Use BBPress's native pagination function
                        's'              => $search_term,     // Search query term
                        'post_status'    => 'publish',
                        'meta_key'       => '_bbp_last_active_time',
                        'orderby'        => 'meta_value',
                        'order'          => 'DESC',
                    );

                    $search_query = new WP_Query( $args );
                    $results_count = $search_query->found_posts;  // Get the number of results
                } // End if ( is_search() )

                // Display the number of search results for the search term
                echo '<h1>' . esc_html( $results_count ) . ' Search Results for "' . esc_html( $search_term ) . '"</h1>';
                ?>
            </span>
        </div>
    </div>

    <?php
    // Check if this is a search and if we have the search query object
    if ( is_search() && isset($search_query) && $search_query->have_posts() ) {
        // Now use bbp_has_topics, but potentially re-using args or ensuring context is right?
        // Note: Passing $args directly to bbp_has_topics might be redundant if it re-runs the query.
        // It might be better to loop through $search_query->posts directly here.
        // However, sticking to the original structure for minimal change:
        if ( bbp_has_topics( $args ) ) { 
    ?>

        <div id="bbpress-forums" class="bbpress-wrapper">
			
            <?php bbp_get_template_part( 'loop', 'topics' ); // Topics loop ?>
        </div>

        <?php
        // Custom pagination links for the search query
        $pagination_args = array(
            'total'   => $search_query->max_num_pages,
            'current' => $paged,
            'mid_size' => 2,
            'prev_text' => __('« Previous'),
            'next_text' => __('Next »'),
        );
        echo paginate_links($pagination_args);

        // Reset post data after the custom query
        wp_reset_postdata();
        }
    } else {
        // No results found (or not a search page)
        echo '<div class="bbp-template-notice"><p>No topics or replies found matching your search.</p></div>';
    }

    ?>

</div> <!-- End of bbpress-forums -->

<?php get_footer(); ?>
