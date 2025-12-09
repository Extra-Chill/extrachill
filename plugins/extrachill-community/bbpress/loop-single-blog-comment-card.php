<?php
/**
 * Blog Comment Card - Main Site Comments Display
 *
 * Displays comments from extrachill.com main site in card format.
 * Reuses .bbp-reply-card structure to inherit base styling.
 *
 * @package ExtraChillCommunity
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Get comment data from query var
$comment_data = get_query_var( 'comment_data' );
if ( empty( $comment_data ) ) {
    return;
}

$comment_id      = $comment_data['comment_ID'];
$post_title      = $comment_data['post_title'];
$post_permalink  = $comment_data['post_permalink'];
$comment_date    = $comment_data['comment_date_gmt'];
$comment_content = $comment_data['comment_content'];
$author_id       = $comment_data['author_id'];
$author_name     = $comment_data['author_name'];
$author_avatar   = $comment_data['author_avatar'];
$upvote_count    = isset( $comment_data['upvote_count'] ) ? $comment_data['upvote_count'] : 0;

// Format comment permalink
$comment_permalink = esc_url( $post_permalink . '#comment-' . $comment_id );

// Get user info from community site
$user_info = get_userdata( $author_id );
$author_role = $user_info ? bbp_get_user_display_role( $author_id ) : '';
?>

<div id="blog-comment-<?php echo esc_attr( $comment_id ); ?>"
     class="bbp-reply-card blog-comment-card"
     data-comment-id="<?php echo esc_attr( $comment_id ); ?>">

    <div class="bbp-reply">

        <div class="bbp-reply-header">
            <div class="bbp-reply-header-content">

                <div class="upvote-date">
                    <span class="bbp-reply-post-date">
                        <?php echo esc_html( date( 'F j, Y, g:i a', strtotime( $comment_date ) ) ); ?>
                    </span>
                </div>

                <div class="author-header-column">
                    <div class="author-details-header">
                        <div class="bbp-author-avatar">
                            <a href="<?php echo esc_url( bbp_get_user_profile_url( $author_id ) ); ?>" title="View profile">
                                <img src="<?php echo esc_url( $author_avatar ); ?>"
                                     alt="<?php echo esc_attr( $author_name ); ?>"
                                     class="avatar"
                                     width="80"
                                     height="80">
                            </a>
                        </div>

                        <div class="author-name-badges">
                            <a href="<?php echo esc_url( bbp_get_user_profile_url( $author_id ) ); ?>" class="bbp-author-name">
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
                        <?php
                        if ( function_exists( 'extrachill_add_rank_and_points_to_reply' ) ) {
                            extrachill_add_rank_and_points_to_reply();
                        }
                        ?>
                    </div>
                </div><!-- .author-header-column -->

                <div class="blog-comment-post-title">
                    <b>Commented on:</b> <a href="<?php echo esc_url( $post_permalink ); ?>"><?php echo esc_html( $post_title ); ?></a>
                </div>

            </div><!-- .bbp-reply-header-content -->
        </div><!-- .bbp-reply-header -->

        <div class="bbp-reply-content-area">
            <div class="bbp-reply-content" data-comment-id="<?php echo esc_attr( $comment_id ); ?>">
                <?php
                $content_length = strlen( strip_tags( $comment_content ) );
                $truncate_length = 500;

                if ( $content_length > $truncate_length ) {
                    echo '<div class="reply-content-truncated" id="content-' . esc_attr( $comment_id ) . '">';

                    $truncated_content = extrachill_truncate_html_content( $comment_content, $truncate_length );
                    echo '<div class="content-preview">' . wp_kses_post( $truncated_content ) . '</div>';

                    echo '<div class="content-full collapsed">' . wp_kses_post( $comment_content ) . '</div>';
                    echo '<button class="read-more-toggle" data-reply-id="' . esc_attr( $comment_id ) . '">';
                    echo '<span class="read-more-text">Show More</span>';
                    echo '<span class="read-less-text">Show Less</span>';
                    echo '</button>';
                    echo '</div>';
                } else {
                    echo wp_kses_post( $comment_content );
                }
                ?>
            </div><!-- .bbp-reply-content -->

            <div class="bbp-reply-meta-right">
                <a href="<?php echo esc_url( $comment_permalink ); ?>" class="blog-comment-main-site-link" target="_blank">
                    View on Main Site &rarr;
                </a>
            </div>
        </div><!-- .bbp-reply-content-area -->

    </div><!-- .bbp-reply -->

</div><!-- #blog-comment-<?php echo esc_attr( $comment_id ); ?> -->
