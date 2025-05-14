<?php
/**
 * Template Name: Following Topics Feed
 * Description: A page template to show the most recently updated topics that the users the current user is following have participated in as either the topic starter or the most recent replier.
 */

get_header();
?>
<div <?php generate_do_attr( 'page' ); ?>>
    <?php
    /**
     * generate_inside_site_container hook.
     */
    do_action( 'generate_inside_site_container' );
    ?>
    <div <?php generate_do_attr( 'site-content' ); ?>>
        <?php
        /**
         * generate_inside_container hook.
         */
        do_action( 'generate_inside_container' );
        ?>
        <?php extrachill_breadcrumbs(); ?>

<?php

echo '<div id="chill-home">';
echo '<div id="chill-home-header"><span>';

// Check if we are on a user profile page
// -- REMOVED User Profile Check & Follow Button --
/*
$isUserProfile = bbp_is_single_user();
if ($isUserProfile) {
    $title = '@' . bbp_get_displayed_user_field('user_nicename');
    echo '<h1 class="profile-title-inline">' . $title . '</h1>';

    // Display the follow button only on user profile pages
    if (function_exists('extrachill_follow_button')) {
        extrachill_follow_button(bbp_get_displayed_user_id());
    }
} else {
    echo '<h1>Following Topics</h1>';
}
*/

echo '<h1>' . __( 'Followed Band Topics', 'generatepress_child' ) . '</h1>'; // Updated Title

echo '</span>';

echo '</div>'; // End of chill-home-header

// Output the standard WordPress content within the div (e.g., introductory text from the page editor)
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

echo '</div>'; // End of chill-home

if ( is_user_logged_in() ) {

    // Use the updated function to get the query for topics from followed bands
    if ( function_exists('extrachill_get_following_posts') ) {
        
        // --- bbPress Topic Loop Setup --- 
        // Use the query returned by our function
        $args = extrachill_get_following_posts( 'topic' )->query_vars; // Get query vars from the returned WP_Query
        
        // We need to manually set the global $wp_query for bbp_has_topics pagination to work correctly
        // Store the original query
        $original_query = $GLOBALS['wp_query'];
        // Create a new query based on our function's results
        $GLOBALS['wp_query'] = new WP_Query($args); 

        if ( bbp_has_topics( $args ) ) :
            ?>
            <div id="bbpress-forums" class="bbpress-wrapper">
                <?php bbp_get_template_part('pagination', 'topics'); // Pagination above the topics ?>
                <?php bbp_get_template_part('loop', 'topics'); // The loop that displays topics ?>
                <?php bbp_get_template_part('pagination', 'topics'); // Pagination below the topics ?>
            </div>
            <?php
        else :
            echo '<div class="bbp-template-notice info"><p>' . __( 'You are not following any bands yet, or the bands you follow haven\'t posted.', 'generatepress_child' ) . '</p></div>';
        endif;
        
        // Restore the original global query
        $GLOBALS['wp_query'] = $original_query;
        wp_reset_postdata(); // Important after custom queries affecting the main loop
        
    } else {
         echo '<div class="bbp-template-notice error"><p>' . __( 'Error: Following feed function not available.', 'generatepress_child' ) . '</p></div>';
    }

} else {
    echo '<div class="bbp-template-notice info"><p>' . __( 'Please log in to see topics from bands you follow.', 'generatepress_child' ) . '</p></div>';
}

?>
        </div><!-- .site-content -->
    </div><!-- .page -->
<?php
get_footer();
?>
