<?php

/**
 * Topics Loop - Single
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<ul id="bbp-topic-<?php bbp_topic_id(); ?>" <?php bbp_topic_class(); ?>>
    <li class="bbp-topic-title">

        <?php if ( bbp_is_user_home() ) : ?>

            <?php if ( bbp_is_favorites() ) : ?>

                <span class="bbp-row-actions">

                    <?php do_action( 'bbp_theme_before_topic_favorites_action' ); ?>

                    <?php bbp_topic_favorite_link( array( 'before' => '', 'favorite' => '+', 'favorited' => '&times;' ) ); ?>

                    <?php do_action( 'bbp_theme_after_topic_favorites_action' ); ?>

                </span>

            <?php elseif ( bbp_is_subscriptions() ) : ?>

                <span class="bbp-row-actions">

                    <?php do_action( 'bbp_theme_before_topic_subscription_action' ); ?>

                    <?php bbp_topic_subscription_link( array( 'before' => '', 'subscribe' => '+', 'unsubscribe' => '&times;' ) ); ?>

                    <?php do_action( 'bbp_theme_after_topic_subscription_action' ); ?>

                </span>

            <?php endif; ?>

        <?php endif; ?>

        <?php do_action( 'bbp_theme_before_topic_title' ); ?>
        <?php
$user_id = get_current_user_id();
$topic_id = bbp_get_topic_id();
$forum_id = bbp_get_topic_forum_id($topic_id);
$icon_class = 'fa-regular'; // Default to "fa-regular" for all users initially

// Logic to determine upvote icon status based on local user meta
if ($user_id) {
    $upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
    $icon_class = is_array($upvoted_posts) && in_array($topic_id, $upvoted_posts) ? 'fa-solid' : 'fa-regular';
}

// Fetch the upvote counts for this post ID
// The logic for fetching upvote counts is applied to all forums, but special handling is done for forum 1494
$upvote_count = get_upvote_count($topic_id); // Default logic for upvote count

if ($forum_id == 1494) {
    // Assuming you have the main site post ID stored in a variable $main_site_post_id
    $main_site_post_id = get_post_meta($topic_id, 'main_site_post_id', true);
    
    if ($main_site_post_id) {
        $upvote_data = fetch_upvote_counts_from_extrachill([$main_site_post_id]);
        
        // Only update the upvote count based on the external data
        $upvote_info = isset($upvote_data[$main_site_post_id]) ? $upvote_data[$main_site_post_id] : ['count' => 0];
        $upvote_count = $upvote_info['count'];
    }
}

// Add 1 to the upvote count for display purposes
$display_upvote_count = $upvote_count + 1;
?>

<div class="upvote">
    <span class="upvote-icon" 
          data-post-id="<?php echo esc_attr($topic_id); ?>" 
          data-main-site-post-id="<?php echo isset($main_site_post_id) ? esc_attr($main_site_post_id) : ''; ?>" 
          data-forum-id="<?php echo esc_attr($forum_id); ?>" 
          data-type="topic" 
          data-nonce="<?php echo esc_attr(wp_create_nonce('upvote_nonce')); ?>" 
          role="button" 
          aria-label="Upvote this topic">
        <i class="<?php echo esc_attr($icon_class); ?> fa-circle-up"></i>
    </span>
    <span class="upvote-count"><?php echo esc_html($display_upvote_count); ?></span> |
</div>

<a class="bbp-topic-permalink" href="<?php bbp_topic_permalink(); ?>"><?php bbp_topic_title(); ?></a>

        <?php do_action( 'bbp_theme_after_topic_title' ); ?>

        <?php bbp_topic_pagination(); ?>

        <?php do_action( 'bbp_theme_before_topic_meta' ); ?>

        <p class="bbp-topic-meta">

            <?php do_action( 'bbp_theme_before_topic_started_by' ); ?>

            <span class="bbp-topic-started-by"><?php printf( esc_html__( 'Started by: %1$s', 'bbpress' ), bbp_get_topic_author_link( array( 'size' => '14' ) ) ); ?></span>

            <?php do_action( 'bbp_theme_after_topic_started_by' ); ?>

            <?php if ( ! bbp_is_single_forum() || ( bbp_get_topic_forum_id() !== bbp_get_forum_id() ) ) : ?>

                <?php do_action( 'bbp_theme_before_topic_started_in' ); ?>

                <span class="bbp-topic-started-in"><?php printf( esc_html__( 'in: %1$s', 'bbpress' ), '<a href="' . bbp_get_forum_permalink( bbp_get_topic_forum_id() ) . '">' . bbp_get_forum_title( bbp_get_topic_forum_id() ) . '</a>' ); ?></span>
                <?php do_action( 'bbp_theme_after_topic_started_in' ); ?>

            <?php endif; ?>

        </p>

        <?php do_action( 'bbp_theme_after_topic_meta' ); ?>

        <?php bbp_topic_row_actions(); ?>

    </li>

    <li class="bbp-topic-voice-count"><?php bbp_topic_voice_count(); ?></li>

    <li class="bbp-topic-reply-count"><?php bbp_show_lead_topic() ? bbp_topic_reply_count() : bbp_topic_post_count(); ?></li>

    <li class="bbp-topic-freshness">

        <?php do_action( 'bbp_theme_before_topic_freshness_link' ); ?>

        <?php bbp_topic_freshness_link(); ?>

        <?php do_action( 'bbp_theme_after_topic_freshness_link' ); ?>

        <p class="bbp-topic-meta">

            <?php do_action( 'bbp_theme_before_topic_freshness_author' ); ?>

            <span class="bbp-topic-freshness-author"><?php bbp_author_link( array( 'post_id' => bbp_get_topic_last_active_id(), 'size' => 14 ) ); ?></span>

            <?php do_action( 'bbp_theme_after_topic_freshness_author' ); ?>

        </p>
    </li>
</ul><!-- #bbp-topic-<?php bbp_topic_id(); ?> -->
