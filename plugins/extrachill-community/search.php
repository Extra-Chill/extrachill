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
                // Set up the query to search for BBPress topics and replies
                $args = array(
                    'post_type'      => array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ),
                    'posts_per_page' => 15,
                    'paged'          => bbp_get_paged(),  // Paginate results
                    's'              => get_search_query(),  // Search query term
                    'post_status'    => array( 'publish', 'closed', 'private', 'hidden' ),
                    'meta_key'       => '_bbp_last_active_time',
                    'orderby'        => 'meta_value',
                    'order'          => 'DESC',
                );

                $search_query = new WP_Query($args);
                $search_term = get_search_query();  // Get the search term
                $results_count = $search_query->found_posts;  // Get the number of results

                // Display the H1 with the number of results and the search term
                echo '<h1>' . esc_html( $results_count ) . ' Search Results for "' . esc_html( $search_term ) . '"</h1>';
                ?>
            </span>
        </div>
    </div>

    <?php
    // Check if there are search results matching topics or replies
    if ( bbp_has_topics( $args ) ) {
        ?>
        <div id="bbpress-forums" class="bbpress-wrapper">
            <?php bbp_get_template_part( 'pagination', 'topics' ); // Pagination above the topics ?>

            <?php bbp_get_template_part( 'loop', 'topics' ); // The loop that displays topics ?>

            <?php bbp_get_template_part( 'pagination', 'topics' ); // Pagination below the topics ?>
        </div>
    <?php
    } else {
        // If no topics or replies are found
        echo '<div class="bbp-template-notice"><p>No topics or replies found matching your search.</p></div>';
    }

    ?>

</div> <!-- End of bbpress-forums -->

<?php get_footer(); ?>
