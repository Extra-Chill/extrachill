<?php
/**
 * The template for displaying Search Results pages for BBPress topics and replies.
 *
 * This template mimics the BBPress native structure like the recent topics template.
 *
 * @package Extra Chill Community
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
                $search_term = get_search_query();
                $results_count = 0;

                if ( is_search() ) {
                    $paged = ( get_query_var('paged') ) ? get_query_var('paged') : 1;
                    $args = array(
                        'post_type'      => array( 'forum', 'topic', 'reply' ),
                        'posts_per_page' => bbp_get_replies_per_page(),
                        'paged'          => $paged,
                        's'              => $search_term,
                        'post_status'    => 'publish',
                        'meta_key'       => '_bbp_last_active_time',
                        'orderby'        => 'meta_value',
                        'order'          => 'DESC',
                    );

                    $search_query = new WP_Query( $args );
                    $results_count = $search_query->found_posts;
                }

                echo '<h1>' . esc_html( $results_count ) . ' Search Results for "' . esc_html( $search_term ) . '"</h1>';
                ?>
            </span>
        </div>
    </div>

    <?php
    if ( is_search() && isset($search_query) && $search_query->have_posts() ) {
        if ( bbp_has_topics( $args ) ) {
    ?>

        <div id="bbpress-forums" class="bbpress-wrapper">
			
            <?php bbp_get_template_part( 'loop', 'topics' ); // Topics loop ?>
        </div>

        <?php
        $pagination_args = array(
            'total'   => $search_query->max_num_pages,
            'current' => $paged,
            'mid_size' => 2,
            'prev_text' => __('« Previous'),
            'next_text' => __('Next »'),
        );
        echo paginate_links($pagination_args);

        wp_reset_postdata();
        }
    } else {
        echo '<div class="bbp-template-notice"><p>No topics or replies found matching your search.</p></div>';
    }

    ?>

</div> <!-- End of bbpress-forums -->

<?php get_footer(); ?>
