<?php

/**
 * Forums Loop - Single Forum Card
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

$is_band_directory_card = ( bbp_get_forum_id() == 5432 );

?>

<div id="bbp-forum-card-<?php bbp_forum_id(); ?>" class="bbp-forum-card<?php echo $is_band_directory_card ? ' bbp-forum-band-directory' : ''; ?>">
    <div class="bbp-forum-info">
        <?php do_action( 'bbp_theme_before_forum_title' ); ?>
        <a class="bbp-forum-title" href="<?php bbp_forum_permalink(); ?>"><?php bbp_forum_title(); ?></a>
        <?php do_action( 'bbp_theme_after_forum_title' ); ?>

        <?php do_action( 'bbp_theme_before_forum_description' ); ?>
        <div class="bbp-forum-content"><?php bbp_forum_content(); ?></div>
        <?php do_action( 'bbp_theme_after_forum_description' ); ?>

        <?php if ( ! $is_band_directory_card ) : ?>
            <?php do_action( 'bbp_theme_before_forum_sub_forums' ); ?>
            <?php bbp_list_forums(); ?>
            <?php do_action( 'bbp_theme_after_forum_sub_forums' ); ?>
        <?php endif; ?>
    </div>

    <?php if ( $is_band_directory_card ) : ?>
        <?php
        // Custom Stats & Freshness for Band Directory (Forum 5432)

        // 1. Number of Bands
        $band_count_query = new WP_Query(array(
            'post_type' => 'band_profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));
        $number_of_bands = $band_count_query->post_count;

        // 2. Get all associated band forum IDs
        $all_band_profiles_query = new WP_Query(array(
            'post_type' => 'band_profile',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));
        $band_forum_ids = array();
        if ($all_band_profiles_query->have_posts()) {
            foreach ($all_band_profiles_query->posts as $band_profile_cpt_id) {
                $forum_id = get_post_meta($band_profile_cpt_id, '_band_forum_id', true);
                if (!empty($forum_id) && is_numeric($forum_id)) {
                    $band_forum_ids[] = absint($forum_id);
                }
            }
        }
        $band_forum_ids = array_unique(array_filter($band_forum_ids));
        wp_reset_postdata(); // Reset after $all_band_profiles_query

        $total_topics_in_bands = 0;
        $total_replies_in_bands = 0;
        $latest_band_activity_post = null;

        if (!empty($band_forum_ids)) {
            // 3. Total Topics in Band Forums
            $topic_query_args = array(
                'post_type' => bbp_get_topic_post_type(),
                'post_status' => array('publish', 'closed'),
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query' => array(
                    array(
                        'key' => '_bbp_forum_id',
                        'value' => $band_forum_ids,
                        'compare' => 'IN',
                    ),
                ),
            );
            $topics_in_band_forums_query = new WP_Query($topic_query_args);
            $total_topics_in_bands = $topics_in_band_forums_query->post_count;
            wp_reset_postdata();

            // 4. Total Replies in Band Forums
            $reply_query_args = array(
                'post_type' => bbp_get_reply_post_type(),
                'post_status' => 'publish', // Replies are typically just 'publish'
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query' => array(
                    array(
                        'key' => '_bbp_forum_id',
                        'value' => $band_forum_ids,
                        'compare' => 'IN',
                    ),
                ),
            );
            $replies_in_band_forums_query = new WP_Query($reply_query_args);
            $total_replies_in_bands = $replies_in_band_forums_query->post_count;
            wp_reset_postdata();

            // 5. Latest Activity Post from Band Forums
            $latest_post_query_args = array(
                'post_type' => array(bbp_get_topic_post_type(), bbp_get_reply_post_type()),
                'post_status' => array('publish', 'closed'),
                'posts_per_page' => 1,
                'orderby' => 'date', // Orders by post_date_gmt
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_bbp_forum_id',
                        'value' => $band_forum_ids,
                        'compare' => 'IN',
                    ),
                ),
            );
            $latest_post_query = new WP_Query($latest_post_query_args);
            if ($latest_post_query->have_posts()) {
                $latest_band_activity_post = $latest_post_query->posts[0];
            }
            wp_reset_postdata();
        }
        ?>
        <div class="bbp-forum-stats bbp-band-directory-stats">
            <div class="bbp-forum-band-count">
                <?php echo esc_html( number_format_i18n( $number_of_bands ) ); ?> <?php echo esc_html( _n( 'Band', 'Bands', $number_of_bands, 'generatepress_child' ) ); ?>
            </div>
            <div class="bbp-forum-topic-count">
                <?php echo esc_html( number_format_i18n( $total_topics_in_bands ) ); ?> <?php echo esc_html( _n( 'Total Topic', 'Topics', $total_topics_in_bands, 'generatepress_child' ) ); ?>
            </div>
            <div class="bbp-forum-reply-count">
                <?php echo esc_html( number_format_i18n( $total_replies_in_bands ) ); ?> <?php echo esc_html( _n( 'Total Reply', 'Replies', $total_replies_in_bands, 'generatepress_child' ) ); ?>
            </div>
        </div>

        <div class="bbp-forum-freshness bbp-band-directory-freshness">
            <?php if ( $latest_band_activity_post ) : ?>
                <?php
                $activity_post_id = $latest_band_activity_post->ID;
                $activity_post_type = get_post_type( $activity_post_id );
                $activity_link_url = '';
                $activity_link_title_text = '';

                if ( $activity_post_type === bbp_get_reply_post_type() ) {
                    $activity_link_url = bbp_get_reply_url( $activity_post_id );
                    $topic_id = bbp_get_reply_topic_id( $activity_post_id );
                    $activity_link_title_text = bbp_get_topic_title( $topic_id );
                } else { // It's a topic
                    $activity_link_url = bbp_get_topic_permalink( $activity_post_id );
                    $activity_link_title_text = bbp_get_topic_title( $activity_post_id );
                }
                
                // Time since last active (as a link)
                echo '<p class="bbp-forum-last-active-time">';
                echo '<a href="' . esc_url( $activity_link_url ) . '" title="' . esc_attr( sprintf( __( 'View %s', 'generatepress_child' ), $activity_link_title_text ) ) . '">' . esc_html( bbp_get_time_since( strtotime( $latest_band_activity_post->post_date_gmt ) ) ) . '</a>';
                echo '</p>';
                ?>
                <p class="bbp-topic-meta">
                    <?php echo bbp_get_author_link( array( 'post_id' => $activity_post_id, 'type' => 'both', 'size' => 14 ) ); // Display avatar and name ?>
                </p>
            <?php else : ?>
                <p><?php _e( 'No activity in any band forum yet.', 'generatepress_child' ); ?></p>
            <?php endif; ?>
        </div>

    <?php else : // Standard forums (not 5432) ?>
        <div class="bbp-forum-stats">
            <div class="bbp-forum-topic-count">
                <?php bbp_forum_topic_count(); ?> Topics
            </div>
            <div class="bbp-forum-reply-count">
                <?php bbp_show_lead_topic() ? bbp_forum_reply_count() : bbp_forum_post_count(); ?> Replies
            </div>
        </div>

        <div class="bbp-forum-freshness">
            <?php 
            // bbp_forum_freshness_link() provides the time since as a link to the latest post.
            // It generates: <a href="..." title="Topic Title">Time Since</a>
            // We want to wrap this in a <p class="bbp-forum-last-active-time">
            $freshness_link = bbp_get_forum_freshness_link(); // Get the link HTML
            if ( $freshness_link ) {
                echo '<p class="bbp-forum-last-active-time">'. $freshness_link .'</p>';
            }
            ?>
            <p class="bbp-topic-meta">
                <?php 
                $standard_forum_last_active_id = bbp_get_forum_last_active_id();
                if ( $standard_forum_last_active_id ) {
                    // Display avatar and name of the author of the last post
                    echo bbp_get_author_link( array( 'post_id' => $standard_forum_last_active_id, 'type' => 'both', 'size' => 14 ) ); 
                }
                ?>
            </p>
        </div>
    <?php endif; ?>
</div><!-- #bbp-forum-card-<?php bbp_forum_id(); ?> -->