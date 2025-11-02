<?php
/**
 * Recently Active Topics Component
 *
 * Template component loaded via extrachill_community_home_top action hook (not via extrachill_community_init).
 * Displays three most recently active forum topics on community homepage.
 * Registered by inc/home/actions.php.
 *
 * @package ExtraChillCommunity
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bbp_get_topic_post_type' ) || ! function_exists( 'bbpress' ) ) {
    return;
}

$community_blog_id = 2; // community.extrachill.com
$artist_blog_id    = 4; // artist.extrachill.com
$topic_candidates  = array();
$per_blog_limit    = 10;

foreach ( array( $community_blog_id, $artist_blog_id ) as $blog_id ) {
    $switched = false;

    if ( $blog_id !== get_current_blog_id() ) {
        switch_to_blog( $blog_id );
        $switched = true;
    }

    $query_args = array(
        'post_type'      => bbp_get_topic_post_type(),
        'posts_per_page' => $per_blog_limit,
        'post_status'    => 'publish',
        'orderby'        => 'meta_value',
        'meta_key'       => '_bbp_last_active_time',
        'meta_type'      => 'DATETIME',
        'order'          => 'DESC',
        'fields'         => 'ids',
    );

    $query = new WP_Query( $query_args );

    if ( ! empty( $query->posts ) ) {
        foreach ( $query->posts as $topic_id ) {
            $last_active = get_post_meta( $topic_id, '_bbp_last_active_time', true );

            if ( empty( $last_active ) ) {
                $last_active = get_post_modified_time( 'Y-m-d H:i:s', true, $topic_id );
            }

            if ( empty( $last_active ) ) {
                $last_active = get_post_time( 'Y-m-d H:i:s', true, $topic_id );
            }

            $topic_candidates[] = array(
                'topic_id'    => $topic_id,
                'blog_id'     => $blog_id,
                'last_active' => $last_active,
            );
        }
    }

    wp_reset_postdata();

    if ( $switched ) {
        restore_current_blog();
    }
}

usort(
    $topic_candidates,
    function ( $a, $b ) {
        $a_time = strtotime( $a['last_active'] );
        $b_time = strtotime( $b['last_active'] );

        if ( false === $a_time ) {
            $a_time = 0;
        }

        if ( false === $b_time ) {
            $b_time = 0;
        }

        return $b_time <=> $a_time;
    }
);

$recently_active_topics = array_slice( $topic_candidates, 0, 3 );
?>

<div class="front-page-section recently-active-topics">
    <h2>Recently Active Topics</h2>
    <ul class="recently-active-topic-row">
        <?php
        if ( ! empty( $recently_active_topics ) ) :
            $primary_bbp = bbpress();
            $original_topic_id = isset( $primary_bbp->current_topic_id ) ? $primary_bbp->current_topic_id : 0;

            foreach ( $recently_active_topics as $topic_data ) :
                $topic_id  = $topic_data['topic_id'];
                $blog_id   = $topic_data['blog_id'];
                $switched  = false;

                if ( $blog_id !== get_current_blog_id() ) {
                    switch_to_blog( $blog_id );
                    $switched = true;
                }

                // Set bbPress global context so timestamps, author links, etc. are correct.
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
                                <?php echo number_format(ec_get_post_views($topic_id)); ?> views
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

                if ( $switched ) {
                    restore_current_blog();
                }
            endforeach;

            $primary_bbp->current_topic_id = $original_topic_id;
        else :
            echo '<li>No recently active topics found.</li>';
        endif;
        ?>
    </ul>
    <div class="view-all-users-link">
    <a href="<?php echo esc_url(home_url('/recent')); ?>" class="button-3 button-medium">View Recently Active</a>
</div>
</div>
