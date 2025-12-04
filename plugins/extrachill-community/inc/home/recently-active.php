<?php
/**
 * Recently Active Topics Component
 *
 * Template component loaded via extrachill_community_home_top action hook.
 * Displays three most recently active forum topics on community homepage.
 * Registered by inc/home/actions.php.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bbp_get_topic_post_type' ) || ! function_exists( 'bbpress' ) ) {
    return;
}

$query_args = array(
    'post_type'      => bbp_get_topic_post_type(),
    'posts_per_page' => 3,
    'post_status'    => 'publish',
    'orderby'        => 'meta_value',
    'meta_key'       => '_bbp_last_active_time',
    'meta_type'      => 'DATETIME',
    'order'          => 'DESC',
);

$query = new WP_Query( $query_args );
?>

<div class="front-page-section recently-active-topics">
    <h2>Recently Active Topics</h2>
    <ul class="recently-active-topic-row">
        <?php
        if ( $query->have_posts() ) :
            $primary_bbp = bbpress();
            $original_topic_id = isset( $primary_bbp->current_topic_id ) ? $primary_bbp->current_topic_id : 0;

            while ( $query->have_posts() ) :
                $query->the_post();
                $topic_id = get_the_ID();
                bbpress()->current_topic_id = $topic_id;
                ?>
                <li class="topic-card-row">
                    <div class="card-header">
                        <a class="bbp-forum-title" href="<?php bbp_topic_permalink( $topic_id ); ?>">
                            <?php bbp_topic_title( $topic_id ); ?>
                        </a>
                    </div>
                    <div class="card-meta">
                        <div class="bbp-forum-stats">
                            <div class="bbp-forum-views">
                                <?php echo number_format( ec_get_post_views( $topic_id ) ); ?> views
                            </div>
                            <div class="bbp-forum-topic-count">
                                <?php echo bbp_get_topic_voice_count( $topic_id ); ?> Voices
                            </div>
                            <div class="bbp-forum-reply-count">
                                <?php echo bbp_get_topic_reply_count( $topic_id ); ?> Replies
                            </div>
                        </div>
                        <?php bbp_topic_freshness_link( $topic_id ); ?>
                        by <?php bbp_author_link( array( 'post_id' => bbp_get_topic_last_active_id( $topic_id ), 'size' => 14 ) ); ?>
                        <br>
                        in <a href="<?php echo bbp_get_forum_permalink( bbp_get_topic_forum_id( $topic_id ) ); ?>">
                            <?php echo bbp_get_forum_title( bbp_get_topic_forum_id( $topic_id ) ); ?>
                        </a>
                    </div>
                </li>
                <?php
                bbpress()->current_topic_id = 0;
            endwhile;

            wp_reset_postdata();
            $primary_bbp->current_topic_id = $original_topic_id;
        else :
            echo '<li>No recently active topics found.</li>';
        endif;
        ?>
    </ul>
    <div class="view-all-users-link">
        <a href="<?php echo esc_url( home_url( '/recent' ) ); ?>" class="button-3 button-medium">View Recently Active</a>
    </div>
</div>
