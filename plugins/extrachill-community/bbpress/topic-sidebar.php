<?php
/**
 * Topic Sidebar
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// First, get the Recently Active topics IDs (used for exclusion in Most Active)
$recent_topic_ids = array();
$recent_topics_query_for_exclusion = new WP_Query( array(
    'post_type'      => 'topic',
    'posts_per_page' => 3, // Keep this low, just need IDs for exclusion
    'orderby'        => 'modified',
    'order'          => 'DESC',
    'post__not_in'   => array( get_the_ID() ),
    'fields'         => 'ids', // Only fetch IDs
) );

if ( $recent_topics_query_for_exclusion->have_posts() ) {
    $recent_topic_ids = $recent_topics_query_for_exclusion->posts;
}
// No need to reset postdata as we only fetched IDs

?>

<aside class="topic-sidebar">

    <!-- Recently Active Section -->
    <div class="sidebar-section recently-active-topics">
        <h2>Recently Active</h2>
        <ul>
            <?php
            $recent_topics_query = new WP_Query( array(
                'post_type'      => 'topic',
                'posts_per_page' => 6, // Fetch more to filter
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'post__not_in'   => array( get_the_ID() ),
                // No 'fields' => 'ids' here, need full post objects
            ) );

            if ( $recent_topics_query->have_posts() ) { // Use curly braces
                $displayed_count = 0;

                while ( $recent_topics_query->have_posts() ) {
                    $recent_topics_query->the_post();
                    $topic_id = get_the_ID();


                    // Stop if we have already displayed 3 topics
                    if ( $displayed_count >= 3 ) {
                        break; // Exit loop once we have enough topics
                    }

                    // Set bbPress global context
                    bbpress()->current_topic_id = $topic_id;
                    ?>
                    <li class="topic-card-sidebar">
                            <a class="post-title" href="<?php bbp_topic_permalink($topic_id); ?>">
                                <?php bbp_topic_title($topic_id); ?>
                            </a>
                        <br>
                        <!-- Stats container: Voices and Replies -->
                        <div class="bbp-forum-stats">
                        <div class="bbp-forum-views">
                                <?php echo number_format(ec_get_post_views($topic_id)); ?> views
                                </div>
                            <div class="bbp-forum-topic-count">
                                <?php echo bbp_get_topic_voice_count($topic_id); ?> Voices
                            </div>
                            <div class="bbp-forum-reply-count">
                                <?php echo bbp_get_topic_reply_count($topic_id); ?> Replies
                            </div>
                        </div>
                        <?php bbp_topic_freshness_link($topic_id); ?>
                        by <?php bbp_author_link( array( 'post_id' => bbp_get_topic_last_active_id($topic_id), 'size' => 14 ) ); ?>
                        <br>
                        in <a href="<?php echo bbp_get_forum_permalink( bbp_get_topic_forum_id($topic_id) ); ?>">
                            <?php echo bbp_get_forum_title( bbp_get_topic_forum_id($topic_id) ); ?>
                        </a>
                    </li>
                    <?php
                    // Reset bbPress context
                    bbpress()->current_topic_id = 0;
                    $displayed_count++; // Increment counter only after displaying a topic
                } // End while loop
                wp_reset_postdata();

                // Add check if no topics were displayed after filtering
                if ( $displayed_count === 0 ) {
                    echo '<li>No recent topics found.</li>';
                }

            } else { // Use curly braces for outer if
                echo '<li>No recent topics found.</li>';
            } // End if have_posts()
            ?>
        </ul>
    </div>

</aside><!-- .topic-sidebar -->
