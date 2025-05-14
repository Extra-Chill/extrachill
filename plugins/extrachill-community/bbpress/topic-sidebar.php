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

                    // Skip if topic should be excluded for the current user
                    if ( function_exists('wp_surgeon_is_private_topic_excluded') && wp_surgeon_is_private_topic_excluded( $topic_id ) ) {
                        continue;
                    }

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
                                <?php echo get_post_meta( $topic_id, 'bbp_topic_views', true ); ?> Views
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

    <!-- Most Active Section -->
    <div class="sidebar-section most-active-topics">
        <h2>Most Active</h2>
        <ul>
            <?php
            global $wpdb;

            $target_count = 3;
            $timeframes = [14, 30, 45]; // Timeframes in days
            $most_active_topic_ids = [];
            $exclude_ids_base = array_merge( array(get_the_ID()), $recent_topic_ids ); // IDs to always exclude (current topic + recent topics)

            foreach ($timeframes as $days) {
                // Construct the date limit for the current timeframe
                $date_limit = date('Y-m-d H:i:s', strtotime("-$days days"));

                // Combine base exclusions with IDs found in previous iterations for this loop
                $current_exclude_ids = array_unique(array_merge($exclude_ids_base, $most_active_topic_ids));

                // Prepare the NOT IN clause for the SQL query
                $not_in_clause = !empty($current_exclude_ids) ? " AND p.post_parent NOT IN (" . implode(',', array_map('intval', $current_exclude_ids)) . ")" : "";

                $sql = $wpdb->prepare(
                    "SELECT p.post_parent, COUNT(p.ID) AS reply_count
                     FROM {$wpdb->posts} AS p
                     INNER JOIN {$wpdb->posts} AS t ON p.post_parent = t.ID
                     WHERE p.post_type = %s
                       AND t.post_type = %s
                       AND p.post_date >= %s" .
                       $not_in_clause . // Dynamically add the combined exclusion clause
                     " GROUP BY p.post_parent
                     ORDER BY reply_count DESC
                     LIMIT 20", // Fetch more than needed initially
                    bbp_get_reply_post_type(),
                    bbp_get_topic_post_type(),
                    $date_limit
                );

                $results = $wpdb->get_results($sql, ARRAY_A);

                if (!empty($results)) {
                    foreach ($results as $result) {
                        $topic_id = (int) $result['post_parent'];

                        // Double-check exclusion (should be handled by SQL, but safeguard)
                        if (in_array($topic_id, $current_exclude_ids)) {
                             continue;
                        }

                        // Skip if topic should be excluded for the current user (privacy)
                        if (function_exists('wp_surgeon_is_private_topic_excluded') && wp_surgeon_is_private_topic_excluded($topic_id)) {
                            continue;
                        }

                        // Add to list if passed checks
                        $most_active_topic_ids[] = $topic_id;

                        // If we have enough topics, break out of both loops
                        if (count($most_active_topic_ids) >= $target_count) {
                            break 2; // Break out of the inner foreach and the outer foreach
                        }
                    }
                }

                // If we have enough topics after checking this timeframe, break the outer loop
                if (count($most_active_topic_ids) >= $target_count) {
                    break;
                }
            }

            if ( !empty($most_active_topic_ids) ) { // Use curly braces
                foreach ( $most_active_topic_ids as $topic_id ) { // Use curly braces

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
                                <?php echo get_post_meta( $topic_id, 'bbp_topic_views', true ); ?> Views
                                </div>
                            <div class="bbp-forum-topic-count">
                                <?php echo bbp_get_topic_voice_count($topic_id); ?> Voices
                            </div>
                            <div class="bbp-forum-reply-count">
                                <?php echo bbp_get_topic_reply_count($topic_id); ?> Replies
                            </div>
                        </div>
                        <br>
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
                } // End foreach loop
            } else { // Use curly braces
                // This message now reflects the state *after* filtering
                echo '<li>No most active topics found.</li>';
            } // End if !empty
            ?>
        </ul>
    </div>

</aside><!-- .topic-sidebar -->
