<?php

/**
 * Topics Loop - Single Topic Card
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<div id="bbp-topic-card-<?php bbp_topic_id(); ?>" class="bbp-topic-card<?php echo ( bbp_is_topic_sticky( bbp_get_topic_id() ) ? ' bbp-topic-card-sticky' : '' ); ?>">
    <div class="bbp-topic-info">
        <?php do_action( 'bbp_theme_before_topic_title' ); ?>
        <div class="topic-card-header-area">
            <div class="bbp-topic-header-inner">
                <div class="bbp-meta-upvote">
                    <?php
                    $user_id = get_current_user_id();
                    $topic_id = bbp_get_topic_id();
                    // Determine icon class based on user's upvote status
                    $upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
                    $icon_class = is_array($upvoted_posts) && in_array($topic_id, $upvoted_posts) ? 'fa-solid' : 'fa-regular';

                    // Fetch the upvote count.
                    $upvote_count = get_upvote_count($topic_id); // Ensure this function is adapted to your setup

                    // Add 1 to the upvote count for display purposes
                    $display_upvote_count = $upvote_count + 1;
                    ?>

                    <div class="upvote-date">
                        <div class="upvote">
                            <span class="upvote-icon" 
                                  data-post-id="<?php echo esc_attr($topic_id); ?>" 
                                  data-type="topic">
                                <i class="<?php echo esc_attr($icon_class); ?> fa-circle-up"></i>
                            </span>
                            <span class="upvote-count"><?php echo esc_html($display_upvote_count); ?></span>
                        </div> 
                    </div> 
                </div><!-- .bbp-meta-upvote -->
                <a class="bbp-topic-title" href="<?php bbp_topic_permalink(); ?>"><?php bbp_topic_title(); ?></a>
                <?php
                // Forum name display REMOVED from here
                ?>
            </div><!-- bbp-topic-header-inner -->
        </div><!-- topic-card-header-area -->
        <?php do_action( 'bbp_theme_after_topic_title' ); ?>
    </div>

    <div class="bbp-topic-stats">
        <span class="topic-views"><?php echo number_format(ec_get_post_views()); ?> views</span>
        <div class="bbp-topic-voice-count">
            <?php bbp_topic_voice_count(); ?> Voices
        </div>
        <div class="bbp-topic-reply-count">
            <?php bbp_show_lead_topic() ? bbp_topic_reply_count() : bbp_topic_post_count(); ?> Replies
        </div>
    </div>

    <div class="bbp-topic-freshness">
        <?php do_action( 'bbp_theme_before_topic_freshness_link' ); ?>
        <?php bbp_topic_freshness_link(); ?>
        <?php do_action( 'bbp_theme_after_topic_freshness_link' ); ?>
        <div class="bbp-topic-meta">
            <?php do_action( 'bbp_theme_before_topic_author' ); ?>
            <span class="bbp-topic-freshness-author"><?php bbp_author_link( array( 'post_id' => bbp_get_topic_last_active_id(), 'size' => 24 ) ); ?></span>
            <?php do_action( 'bbp_theme_after_topic_author' ); ?>
        </div>
        <?php
        // Display forum name if topic has one AND it's a relevant page context
        $topic_id_for_forum = bbp_get_topic_id(); 
        $forum_id_for_topic = bbp_get_topic_forum_id($topic_id_for_forum);

        $show_forum_name_on_card = false;
        if ( is_page_template('page-templates/recent-feed-template.php') ||
             is_search() ||
             bbp_is_search() ) {
            $show_forum_name_on_card = true;
        }

        if ( $show_forum_name_on_card && !empty($forum_id_for_topic) ) :
            ?>
            <div class="bbp-topic-forum-origin"> 
                <span class="bbp-topic-started-in">
                    <?php printf(
                        esc_html__( 'in %1$s', 'bbpress' ), 
                        '<a href="' . esc_url( bbp_get_forum_permalink( $forum_id_for_topic ) ) . '">' . esc_html( bbp_get_forum_title( $forum_id_for_topic ) ) . '</a>' 
                    ); ?>
                </span>
            </div>
        <?php
        endif;
        ?>
    </div>
</div><!-- #bbp-topic-card-<?php bbp_topic_id(); ?> -->