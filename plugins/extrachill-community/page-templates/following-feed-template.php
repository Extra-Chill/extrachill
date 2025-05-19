<?php
/**
 * Template Name: Following Topics Feed
 * Description: A page template to show the most recently updated topics that the users the current user is following have participated in as either the topic starter or the most recent replier.
 */

get_header();
?>
    <?php
    /**
     * generate_inside_site_container hook.
     * This hook is inside the #page div element, handled by GeneratePress.
     */
    do_action( 'generate_inside_site_container' );
    ?>
    <?php // The site-content div wrapper previously here has been removed. ?>
        <?php
        /**
         * generate_inside_container hook.
         * This hook is inside the .container div element (itself inside #content), handled by GeneratePress.
         */
        do_action( 'generate_inside_container' );
        ?>
        <?php extrachill_breadcrumbs(); ?>

<?php

echo '<div id="chill-home">';
echo '<div id="chill-home-header"><span>';

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

    // Use the updated function to get the query arguments for topics from followed bands
    if ( function_exists('extrachill_get_following_posts_args') ) {
        
        $args_for_feed = extrachill_get_following_posts_args('topic');
        error_log('[DEBUG] page-template - $args_for_feed prepared: ' . print_r($args_for_feed, true)); // Log before bbp_has_topics

        // Pass arguments via a global $bbp property
        global $bbp;
        if ( !isset($bbp) || !is_object($bbp) ) { // Ensure $bbp is initialized
            $bbp = bbpress();
            error_log('[DEBUG] page-template - $bbp was not set, initialized via bbpress().');
        }
        $bbp->extrachill_passthrough_args = $args_for_feed;
        error_log('[DEBUG] page-template - $bbp->extrachill_passthrough_args SET: ' . print_r($bbp->extrachill_passthrough_args, true));

        // Pass $args_for_feed directly to the template part
        // Note: bbp_has_topics() here is mainly to check if there *could* be posts 
        // and to set up some initial bbPress globals if needed, but loop-topics.php will now primarily use the passed args.
        if ( bbp_has_topics( $args_for_feed ) ) : // This call helps set up $bbp->topic_query for some bbPress functions
            ?>
            <div id="bbpress-forums" class="bbpress-wrapper">
                <?php bbp_get_template_part('pagination', 'topics'); // This will use query from bbp_has_topics above for its counts ?>
                <?php 
                // loop-topics.php will now look for $bbp->extrachill_passthrough_args
                bbp_get_template_part('loop', 'topics' ); 
                ?>
                <?php bbp_get_template_part('pagination', 'topics'); // And this one too ?>
            </div>
            <?php
        else :
            if (isset($args_for_feed['post__in']) && $args_for_feed['post__in'] === array(0)) {
                echo '<div class="bbp-template-notice info"><p>' . __( 'You are not following any bands, or the bands you follow do not have forums.', 'generatepress_child' ) . '</p></div>';
            } else {
                echo '<div class="bbp-template-notice info"><p>' . __( 'No topics found from the bands you follow.', 'generatepress_child' ) . '</p></div>';
            }
        endif;
        
        // wp_reset_postdata(); // bbp_has_topics and its loop should handle this.
        
    } else {
         echo '<div class="bbp-template-notice error"><p>' . __( 'Error: Following feed function not available.', 'generatepress_child' ) . '</p></div>';
    }

} else {
    echo '<div class="bbp-template-notice info"><p>' . __( 'Please log in to see topics from bands you follow.', 'generatepress_child' ) . '</p></div>';
}

?>
    <?php // The closing divs for .site-content and .page have been removed. ?>
<?php
get_footer();
?>
