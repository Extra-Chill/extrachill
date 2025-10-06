<?php
/**
 * Replies Loop - Single Reply Card
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;
?>

<div id="post-<?php bbp_reply_id(); ?>" 
     class="bbp-reply-card <?php if ( bbp_get_reply_author_id() == bbp_get_topic_author_id( bbp_get_topic_id() ) ) echo 'is-topic-author'; ?>"
     data-reply-id="<?php bbp_reply_id(); ?>">

    <?php do_action( 'bbp_template_before_reply_content' ); ?>

    <div <?php bbp_reply_class(); ?>>

        <div class="bbp-reply-header">
            <div class="bbp-reply-header-content">
                <?php
                $user_id       = get_current_user_id();
                $reply_id      = bbp_get_reply_id();
                $upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
                $icon_class    = ( is_array($upvoted_posts) && in_array($reply_id, $upvoted_posts ) ) ? 'fa-solid' : 'fa-regular';

                $upvote_count = get_upvote_count($reply_id);
                $display_upvote_count = $upvote_count + 1;
                ?>

                <div class="upvote-date">
                    <div class="upvote">
                        <span class="upvote-icon"
                              data-post-id="<?php echo esc_attr($reply_id); ?>"
                              data-type="reply"
                              <?php if (!empty($main_site_post_id)) echo 'data-main-site-post-id="' . esc_attr($main_site_post_id) . '"'; ?>>
                            <i class="<?php echo esc_attr($icon_class); ?> fa-circle-up"></i>
                        </span>
                        <span class="upvote-count"><?php echo esc_html($display_upvote_count); ?></span>
                    </div>
                    <a href="<?php bbp_reply_url(); ?>" class="bbp-reply-post-date" id="bbp-reply-permalink">
    <?php bbp_reply_post_date(); ?>
</a>

                    <?php
$reply_id  = bbp_get_reply_id();
$topic_id  = bbp_get_topic_id();
$current_post_id = get_the_ID();
$current_post_type = get_post_type($current_post_id);

$is_lead_topic = ( $reply_id === $topic_id ) || ( $current_post_type === bbp_get_topic_post_type() );

if ( $is_lead_topic ) {
    if ( ! bbp_is_topic_closed( $topic_id ) ) {
        $reply_link = bbp_get_topic_reply_link( array(
            'id'         => $topic_id,
            'reply_text' => __( 'Reply', 'bbpress' )
        ) );
    }

    if ( current_user_can( 'edit_topic', $topic_id ) ) {
        $edit_link = bbp_get_topic_edit_link( array(
            'id'        => $topic_id,
            'edit_text' => __( 'Edit', 'bbpress' )
        ) );
    }

} else {
    if ( $current_post_type === bbp_get_reply_post_type() ) {
        $reply_topic_id = bbp_get_reply_topic_id( $reply_id );
        if ( $reply_topic_id && ! bbp_is_topic_closed( $reply_topic_id ) ) {
            $reply_link = bbp_get_reply_to_link( array(
                'id'         => $reply_id,
                'reply_text' => __( 'Reply', 'bbpress' )
            ) );
        }

        if ( current_user_can( 'edit_reply', $reply_id ) ) {
            $edit_link = bbp_get_reply_edit_link( array(
                'id'        => $reply_id,
                'edit_text' => __( 'Edit', 'bbpress' )
            ) );
        }
    }
}
?>
<div class="bbp-reply-meta-top">
    <?php if ( ! empty( $reply_link ) ) : ?>
        <?php echo $reply_link; ?>
    <?php endif; ?>

    <?php if ( ! empty( $edit_link ) ) : ?>
        <?php echo $edit_link; ?>
    <?php endif; ?>
</div>




                </div>

                <?php
                $author_id     = bbp_get_reply_author_id( $reply_id );
                $author_name   = bbp_get_reply_author_display_name( $reply_id );
                $author_avatar = bbp_get_reply_author_avatar( $reply_id, 80 );
                $author_url    = bbp_get_reply_author_url( $reply_id );
                $author_role = bbp_get_user_display_role( $author_id );
                ?>

                <div class="author-header-column">
                    <div class="author-details-header">
                        <div class="bbp-author-avatar">
                            <a href="<?php echo esc_url( $author_url ); ?>" title="View profile">
                                <?php echo $author_avatar; ?>
                            </a>
                        </div>

                        <div class="author-name-badges">
                            <a href="<?php echo esc_url( $author_url ); ?>" class="bbp-author-name">
                                <?php echo esc_html( $author_name ); ?>
                            </a>

                            <div class="forum-badges">
                                <?php
                                do_action( 'bbp_theme_after_reply_author_details' );
                                ?>
                            </div>

                        </div>
                    </div><!-- .author-details-header -->

                    <?php if ( ! empty( $author_role ) ) : ?>
                        <div class="bbp-author-role">
                            <?php echo esc_html( $author_role ); ?>
                        </div>
                    <?php endif; ?>

                    <div class="header-rankpoints">
                        <?php extrachill_add_rank_and_points_to_reply(); ?>
                    </div>
                </div><!-- .author-header-column -->

                <?php if ( bbp_is_single_user_replies() || is_page_template('page-templates/recent-feed-template.php') ) : ?>
                    <span class="bbp-header">
                        <?php if ( $is_lead_topic || $current_post_type === bbp_get_topic_post_type() ) : ?>
                            <?php esc_html_e( 'in forum: ', 'bbpress' ); ?>
                            <a class="bbp-forum-permalink" href="<?php bbp_forum_permalink( bbp_get_topic_forum_id() ); ?>">
                                <?php echo esc_html( bbp_get_forum_title( bbp_get_topic_forum_id() ) ); ?>
                            </a>
                        <?php else : ?>
                            <?php esc_html_e( 'in reply to: ', 'bbpress' ); ?>
                            <a class="bbp-topic-permalink" href="<?php bbp_topic_permalink( bbp_get_reply_topic_id() ); ?>">
                                <?php bbp_topic_title( bbp_get_reply_topic_id() ); ?>
                            </a>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>        

            </div><!-- .bbp-reply-header-content -->
        </div><!-- .bbp-reply-header -->

        <div class="bbp-reply-content-area">
            <div class="bbp-reply-content" data-reply-id="<?php bbp_reply_id(); ?>">
                <?php do_action( 'bbp_theme_before_reply_content' ); ?>
                <?php
                if ( is_page_template('page-templates/recent-feed-template.php') ) {
                    $content = bbp_get_reply_content();
                    $content_length = strlen( strip_tags( $content ) );
                    $truncate_length = 500;
                    
                    if ( $content_length > $truncate_length ) {
                        $reply_id = bbp_get_reply_id();
                        echo '<div class="reply-content-truncated" id="content-' . $reply_id . '">';

                        $truncated_content = extrachill_truncate_html_content( $content, $truncate_length );
                        echo '<div class="content-preview">' . $truncated_content . '</div>';
                        
                        echo '<div class="content-full collapsed" style="height: 0; overflow: hidden;">' . $content . '</div>';
                        echo '<button class="read-more-toggle" onclick="toggleContentExpansion(' . $reply_id . ', this)">';
                        echo '<span class="read-more-text">Show More</span>';
                        echo '<span class="read-less-text" style="display: none;">Show Less</span>';
                        echo '</button>';
                        echo '</div>';
                    } else {
                        bbp_reply_content();
                    }
                } else {
                    bbp_reply_content();
                }
                ?>
                <?php do_action( 'bbp_theme_after_reply_content' ); ?>

            </div><!-- .bbp-reply-content -->
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <div class="bbp-reply-meta-right">
                    <div class="bbp-reply-ip"><?php bbp_author_ip( bbp_get_reply_id() ); ?></div>
                    <?php do_action( 'bbp_theme_before_reply_admin_links' ); ?>
                    <?php bbp_reply_admin_links(); ?>
                    <?php do_action( 'bbp_theme_after_reply_admin_links' ); ?>
                </div>
                <?php endif; ?>
        </div>

    </div><!-- .bbp-reply-content-area -->

    <?php do_action( 'bbp_template_after_reply_content' ); ?>
</div><!-- #bbp-reply-card-<?php bbp_reply_id(); ?> -->

<?php do_action( 'bbp_template_after_single_card' ); ?>
