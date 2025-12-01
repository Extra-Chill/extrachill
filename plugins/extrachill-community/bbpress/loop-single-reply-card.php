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
                $is_upvoted    = is_array($upvoted_posts) && in_array($reply_id, $upvoted_posts);
                $icon_id       = $is_upvoted ? 'circle-up' : 'circle-up-outline';

                $upvote_count = get_upvote_count($reply_id);
                $display_upvote_count = $upvote_count + 1;
                ?>

                <div class="upvote-date">
                    <div class="upvote">
                        <span class="upvote-icon"
                              data-post-id="<?php echo esc_attr($reply_id); ?>"
                              data-type="reply"
                              data-upvoted="<?php echo $is_upvoted ? 'true' : 'false'; ?>"
                              <?php if (!empty($main_site_post_id)) echo 'data-main-site-post-id="' . esc_attr($main_site_post_id) . '"'; ?>>
                            <?php echo ec_icon($icon_id); ?>
                        </span>
                        <span class="upvote-count"><?php echo esc_html($display_upvote_count); ?></span>
                    </div>
                    <a href="<?php bbp_reply_url(); ?>" class="bbp-reply-post-date" id="bbp-reply-permalink">
    <?php bbp_reply_post_date(); ?>
</a>

                    <?php
$bbp = bbpress();
$reply_id  = bbp_get_reply_id();
$topic_id  = ! empty( $bbp->current_topic_id ) ? $bbp->current_topic_id : bbp_get_topic_id();
$current_post_id = get_the_ID();
$current_post_type = get_post_type( $current_post_id );
$is_lead_topic = ( $reply_id === $topic_id ) || ( $current_post_type === bbp_get_topic_post_type() );
?>

                </div>

                <?php
                // Check for pre-fetched author data (multisite recent feed context)
                $prefetch_author_id         = get_query_var('prefetch_author_id');
                $prefetch_author_name       = get_query_var('prefetch_author_name');
                $prefetch_author_avatar_url = get_query_var('prefetch_author_avatar_url');

                if ($prefetch_author_id && $prefetch_author_avatar_url) {
                    // Use pre-fetched data
                    $author_id     = $prefetch_author_id;
                    $author_name   = $prefetch_author_name;
                    $author_avatar = '<img src="' . esc_url($prefetch_author_avatar_url) . '" alt="' . esc_attr($author_name) . '" class="avatar" width="80" height="80">';
                    $author_url    = bbp_get_reply_author_url( $reply_id );
                    $author_role   = bbp_get_user_display_role( $author_id );
                } else {
                    // Use standard bbPress functions
                    $author_id     = bbp_get_reply_author_id( $reply_id );
                    $author_name   = bbp_get_reply_author_display_name( $reply_id );
                    $author_avatar = bbp_get_reply_author_avatar( $reply_id, 80 );
                    $author_url    = bbp_get_reply_author_url( $reply_id );
                    $author_role   = bbp_get_user_display_role( $author_id );
                }
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

                <?php if ( bbp_is_single_user_replies() || is_page_template('page-templates/recent-feed-template.php') ) :
                    // Check for pre-fetched topic/forum data (multisite context)
                    $prefetch_topic_url   = get_query_var('prefetch_topic_url');
                    $prefetch_topic_title = get_query_var('prefetch_topic_title');
                    $prefetch_forum_url   = get_query_var('prefetch_forum_url');
                    $prefetch_forum_title = get_query_var('prefetch_forum_title');
                ?>
                    <span class="bbp-header">
                        <?php if ( $is_lead_topic || $current_post_type === bbp_get_topic_post_type() ) : ?>
                            <?php esc_html_e( 'in forum: ', 'bbpress' ); ?>
                            <?php if ( $prefetch_forum_url && $prefetch_forum_title ) : ?>
                                <a class="bbp-forum-permalink" href="<?php echo esc_url( $prefetch_forum_url ); ?>">
                                    <?php echo esc_html( $prefetch_forum_title ); ?>
                                </a>
                            <?php else : ?>
                                <a class="bbp-forum-permalink" href="<?php bbp_forum_permalink( bbp_get_topic_forum_id() ); ?>">
                                    <?php echo esc_html( bbp_get_forum_title( bbp_get_topic_forum_id() ) ); ?>
                                </a>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php esc_html_e( 'in reply to: ', 'bbpress' ); ?>
                            <?php if ( $prefetch_topic_url && $prefetch_topic_title ) : ?>
                                <a class="bbp-topic-permalink" href="<?php echo esc_url( $prefetch_topic_url ); ?>">
                                    <?php echo esc_html( $prefetch_topic_title ); ?>
                                </a>
                            <?php else : ?>
                                <a class="bbp-topic-permalink" href="<?php bbp_topic_permalink( bbp_get_reply_topic_id() ); ?>">
                                    <?php bbp_topic_title( bbp_get_reply_topic_id() ); ?>
                                </a>
                            <?php endif; ?>
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
        </div>

    </div><!-- .bbp-reply-content-area -->

    <?php if ( is_user_logged_in() ) : ?>
    <div class="bbp-reply-footer">
        <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <div class="bbp-reply-admin-actions">
                <?php bbp_reply_admin_links(); ?>
            </div>
        <?php endif; ?>

        <div class="bbp-reply-user-actions">
            <?php
            if ( $is_lead_topic ) {
                if ( $topic_id && current_user_can( 'edit_topic', $topic_id ) ) {
                    $edit_url = bbp_get_topic_edit_url( $topic_id );
                    echo '<a href="' . esc_url( $edit_url ) . '" class="button-3 button-small">' . esc_html__( 'Edit', 'bbpress' ) . '</a>';
                }
            } else {
                $reply = bbp_get_reply( $reply_id );
                if ( $reply && current_user_can( 'edit_reply', $reply_id ) && ! bbp_past_edit_lock( $reply->post_date_gmt ) ) {
                    $edit_url = bbp_get_reply_edit_url( $reply_id );
                    echo '<a href="' . esc_url( $edit_url ) . '" class="button-3 button-small">' . esc_html__( 'Edit', 'bbpress' ) . '</a>';
                }
            }

            if ( $is_lead_topic ) {
                if ( $topic_id && ! bbp_is_topic_closed( $topic_id ) && bbp_current_user_can_access_create_reply_form() ) {
                    $reply_url = remove_query_arg( array( 'bbp_reply_to', '_wpnonce' ) ) . '#new-post';
                    echo '<a href="' . esc_url( $reply_url ) . '" class="bbp-reply-to-link button-3 button-small">' . esc_html__( 'Reply', 'bbpress' ) . '</a>';
                }
            } else {
                $reply_topic_id = bbp_get_reply_topic_id( $reply_id );
                if ( $reply_topic_id && ! bbp_is_topic_closed( $reply_topic_id ) && bbp_current_user_can_access_create_reply_form() ) {
                    $reply_url = bbp_get_reply_url( $reply_id ) . '#new-post';
                    echo '<a href="' . esc_url( $reply_url ) . '" class="bbp-reply-to-link button-3 button-small" data-reply-id="' . esc_attr( $reply_id ) . '">' . esc_html__( 'Reply', 'bbpress' ) . '</a>';
                }
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

    <?php do_action( 'bbp_template_after_reply_content' ); ?>
</div><!-- #bbp-reply-card-<?php bbp_reply_id(); ?> -->

<?php do_action( 'bbp_template_after_single_card' ); ?>
