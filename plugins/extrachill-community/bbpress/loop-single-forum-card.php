<?php

/**
 * Forums Loop - Single Forum Card
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<div id="bbp-forum-card-<?php bbp_forum_id(); ?>" class="bbp-forum-card">
    <div class="bbp-forum-info">
        <?php do_action( 'bbp_theme_before_forum_title' ); ?>
        <a class="bbp-forum-title" href="<?php bbp_forum_permalink(); ?>"><?php bbp_forum_title(); ?></a>
        <?php do_action( 'bbp_theme_after_forum_title' ); ?>

        <?php do_action( 'bbp_theme_before_forum_description' ); ?>
        <div class="bbp-forum-content"><?php bbp_forum_content(); ?></div>
        <?php do_action( 'bbp_theme_after_forum_description' ); ?>

        <?php do_action( 'bbp_theme_before_forum_sub_forums' ); ?>
        <?php bbp_list_forums(); ?>
        <?php do_action( 'bbp_theme_after_forum_sub_forums' ); ?>
    </div>

    <div class="bbp-forum-stats">
        <div class="bbp-forum-topic-count">
            <?php bbp_forum_topic_count(); ?> Topics
        </div>
        <div class="bbp-forum-reply-count">
            <?php bbp_show_lead_topic() ? bbp_forum_reply_count() : bbp_forum_post_count(); ?> Replies
        </div>
    </div>

    <div class="bbp-forum-freshness">
        <?php do_action( 'bbp_theme_before_forum_freshness_link' ); ?>
        <?php bbp_forum_freshness_link(); ?>
        <?php do_action( 'bbp_theme_after_forum_freshness_link' ); ?>
        <div class="bbp-topic-meta">
            <?php do_action( 'bbp_theme_before_topic_author' ); ?>
            <span class="bbp-topic-freshness-author"><?php bbp_author_link( array( 'post_id' => bbp_get_forum_last_active_id(), 'size' => 24 ) ); ?></span>
            <?php do_action( 'bbp_theme_after_topic_author' ); ?>
        </div>
    </div>
</div><!-- #bbp-forum-card-<?php bbp_forum_id(); ?> -->