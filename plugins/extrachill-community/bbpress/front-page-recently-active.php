<?php
/**
 * Front Page - Recently Active Topics
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;
?>

<div class="front-page-section recently-active-topics">
    <h2 class="forum-front-ec">Recently Active Topics</h2>
    <ul class="recently-active-topic-row">
        <?php
        $target_count = 3;
        $recently_active_topic_ids = array();

        $args = array(
            'post_type'      => bbp_get_topic_post_type(),
            'posts_per_page' => 10, // Fetch more to allow for exclusions
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => '_bbp_last_active_time',
            'meta_type'      => 'DATETIME',
            'order'          => 'DESC',
            'fields'         => 'ids',
        );
        $query = new WP_Query($args);

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $topic_id ) {
                // Skip if topic should be excluded for the current user
                if (function_exists('wp_surgeon_is_private_topic_excluded') && wp_surgeon_is_private_topic_excluded($topic_id)) {
                    continue;
                }
                $recently_active_topic_ids[] = $topic_id;
                if (count($recently_active_topic_ids) >= $target_count) {
                    break;
                }
            }
        }
        wp_reset_postdata();

        // Display the topics
        if ( ! empty( $recently_active_topic_ids ) ) :
            foreach ( $recently_active_topic_ids as $topic_id ) :
                // Set bbPress global context so timestamps, author links, etc. are correct
                bbpress()->current_topic_id = $topic_id;
                ?>
                <li class="topic-card-row">
                    <!-- Card Header -->
                    <div class="card-header">
                            <a class="bbp-forum-title" href="<?php bbp_topic_permalink( $topic_id ); ?>">
                                <?php bbp_topic_title( $topic_id ); ?>
                            </a>
                    </div>
                    <!-- Card Meta/Content -->
                    <div class="card-meta">
                    <div class="bbp-forum-stats">
                            <div class="bbp-forum-views">
                                <?php echo get_post_meta( $topic_id, 'bbp_topic_views', true ); ?> Views
                                </div>
                            <div class="bbp-forum-topic-count">
                                <?php echo bbp_get_topic_voice_count( $topic_id ); ?> Voices
                            </div>
                            <div class="bbp-forum-reply-count">
                                <?php echo bbp_get_topic_reply_count( $topic_id ); ?> Replies
                            </div>
                        </div>
    <?php // freshness link already includes <a> tags ?>
    <?php bbp_topic_freshness_link( $topic_id ); ?>
    by <?php bbp_author_link( array( 'post_id' => bbp_get_topic_last_active_id( $topic_id ), 'size' => 14 ) ); ?>
    <br>
    in <a href="<?php echo bbp_get_forum_permalink( bbp_get_topic_forum_id( $topic_id ) ); ?>">
        <?php echo bbp_get_forum_title( bbp_get_topic_forum_id( $topic_id ) ); ?>
    </a>
    <br>
</div>

                </li>
                <?php
                // Reset bbPress context
                bbpress()->current_topic_id = 0;
            endforeach;
        else :
            echo '<li>No recently active topics found.</li>';
        endif;
        ?>
    </ul>
    <div class="view-all-users-link">
    <a href="<?php echo esc_url(home_url('/recent')); ?>">View Recently Active</a>
</div>
</div>
