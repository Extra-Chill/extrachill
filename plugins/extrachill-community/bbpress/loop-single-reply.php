<div id="post-<?php bbp_reply_id(); ?>" class="bbp-reply-header">
    <div class="bbp-meta">
        <div class="upvote-date">
<?php
$user_id = get_current_user_id();
$reply_id = bbp_get_reply_id();
$upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
$icon_class = is_array($upvoted_posts) && in_array($reply_id, $upvoted_posts) ? 'fa-solid' : 'fa-regular';
?>
<div class="upvote">
<span class="upvote-icon" data-post-id="<?php echo $reply_id; ?>" data-type="reply" data-nonce="<?php echo wp_create_nonce('upvote_nonce'); ?>">
    <i class="<?php echo $icon_class; ?> fa-circle-up"></i>
</span>
<span class="upvote-count"><?php echo get_upvote_count($reply_id); ?></span></div>

            <span class="bbp-reply-post-date"> | <?php bbp_reply_post_date(); ?></span>
        </div>

        <?php if ( bbp_is_single_user_replies() ) : ?>

            <span class="bbp-header">
                <?php esc_html_e( 'in reply to: ', 'bbpress' ); ?>
                <a class="bbp-topic-permalink" href="<?php bbp_topic_permalink( bbp_get_reply_topic_id() ); ?>"><?php bbp_topic_title( bbp_get_reply_topic_id() ); ?></a>
            </span>

        <?php endif; ?>

        <a href="<?php bbp_reply_url(); ?>" class="bbp-reply-permalink">#<?php bbp_reply_id(); ?></a>

        <?php do_action( 'bbp_theme_before_reply_admin_links' ); ?>
        <?php bbp_reply_admin_links(); ?> 
        <?php do_action( 'bbp_theme_after_reply_admin_links' ); ?>

    </div><!-- .bbp-meta -->
</div><!-- #post-<?php bbp_reply_id(); ?> -->

<div <?php bbp_reply_class(); ?>>
    <div class="bbp-reply-author">
        <?php do_action( 'bbp_theme_before_reply_author_details' ); ?>
<div class="followdetails">
        <div class="author-details">
            <?php bbp_reply_author_link( array( 'show_role' => true ) ); ?>
        </div>

        <div class="author-follow-button">
            <?php 
            if (function_exists('extrachill_follow_button')) {
                extrachill_follow_button(bbp_get_reply_author_id());
            } 
            ?>
        </div>
</div>
        <?php if ( current_user_can( 'moderate', bbp_get_reply_id() ) ) : ?>

            <?php do_action( 'bbp_theme_before_reply_author_admin_details' ); ?>

            <?php do_action( 'bbp_theme_after_reply_author_admin_details' ); ?>

        <?php endif; ?>
<div class="forum-badges">
        <?php do_action( 'bbp_theme_after_reply_author_details' ); ?>
</div>
    </div><!-- .bbp-reply-author -->

    <div class="bbp-reply-content" data-reply-id="<?php bbp_reply_id(); ?>">

        <?php do_action( 'bbp_theme_before_reply_content' ); ?>

        <?php bbp_reply_content(); ?>

        <?php do_action( 'bbp_theme_after_reply_content' ); ?>
    <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <div class="bbp-reply-ip"><?php bbp_author_ip( bbp_get_reply_id() ); ?></div>
    <?php endif; ?>
    </div><!-- .bbp-reply-content -->
</div><!-- .reply -->
