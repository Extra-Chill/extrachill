<?php
/**
 * Complete Comment System Template
 *
 * Consolidated comment template with multisite integration and custom comment callback.
 * Replaces root comments.php with action hook architecture for extensibility.
 *
 * @package ExtraChill
 * @since 1.0
 */

/*
 * If the current post is protected by a password and
 * the visitor has not yet entered the password we will
 * return early without loading the comments.
 */
if ( post_password_required() ) {
    return;
}

/**
 * Custom comment callback function with community integration
 */
if ( ! function_exists( 'extrachill_comment' ) ) :

function extrachill_comment( $comment, $args, $depth ) {
    $GLOBALS['comment'] = $comment;
    switch ( $comment->comment_type ) :
        case 'trackback' :
            ?>
            <li <?php comment_class(); ?> id="comment-<?php comment_ID(); ?>">
            <?php
            break;
        default :
            global $post;
            ?>
            <li <?php comment_class(); ?> id="li-comment-<?php comment_ID(); ?>">
                <article id="comment-<?php comment_ID(); ?>" class="comment">
                    <header class="comment-meta comment-author vcard">
                        <?php
                        echo get_avatar( $comment, 74 );

                        if ( ec_should_use_multisite_comment_links( $comment ) ) {
                            $author_link = ec_get_comment_author_link_multisite( $comment );
                        } else {
                            $author_link = get_comment_author_link();
                        }

                        printf( '<div class="comment-author-link">%s</div>', $author_link );

                        if ( $comment->user_id === $post->post_author ) {
                            echo '<span>' . __( 'Post author', 'extrachill' ) . '</span>';
                        }

                        printf( '<div class="comment-date-time">%1$s</div>', sprintf( __( '%1$s at %2$s', 'extrachill' ), get_comment_date(), get_comment_time() ) );
                        edit_comment_link();
                        ?>
                    </header>

                    <?php if ( '0' == $comment->comment_approved ) : ?>
                        <p class="comment-awaiting-moderation"><?php _e( 'Your comment is awaiting moderation.', 'extrachill' ); ?></p>
                    <?php endif; ?>

                    <section class="comment-content comment">
                        <?php comment_text(); ?>
                        <?php comment_reply_link( array_merge( $args, array(
                            'reply_text' => __( 'Reply', 'extrachill' ),
                            'after'      => '',
                            'depth'      => $depth,
                            'max_depth'  => $args['max_depth'],
                        ) ) ); ?>
                    </section>

                </article>
            <?php
            break;
    endswitch;
}

endif;
?>

<div id="comments" class="comments-area">

    <?php if ( have_comments() ) : ?>
        <h2 class="comments-title">
            <?php
                printf( _nx( 'One comment on &ldquo;%2$s&rdquo;', '%1$s comments on <strong>%2$s</strong>', get_comments_number(), 'comments title', 'extrachill' ),
                    number_format_i18n( get_comments_number() ), '<span>' . get_the_title() . '</span>' );
            ?>
        </h2>

        <?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : ?>
        <nav id="comment-nav-above" class="comment-navigation clearfix" role="navigation">
            <h4 class="screen-reader-text"><?php _e( 'Comment navigation', 'extrachill' ); ?></h4>
            <div class="nav-previous"><?php previous_comments_link( __( '&larr; Older Comments', 'extrachill' ) ); ?></div>
            <div class="nav-next"><?php next_comments_link( __( 'Newer Comments &rarr;', 'extrachill' ) ); ?></div>
        </nav><!-- #comment-nav-above -->
        <?php endif; ?>

        <ul class="comment-list">
            <?php
                wp_list_comments( array(
                    'callback'    => 'extrachill_comment',
                    'short_ping'  => true
                ) );
            ?>
        </ul><!-- .comment-list -->

        <?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : ?>
        <nav id="comment-nav-below" class="comment-navigation clearfix" role="navigation">
            <h4 class="screen-reader-text"><?php _e( 'Comment navigation', 'extrachill' ); ?></h4>
            <div class="nav-previous"><?php previous_comments_link( __( '&larr; Older Comments', 'extrachill' ) ); ?></div>
            <div class="nav-next"><?php next_comments_link( __( 'Newer Comments &rarr;', 'extrachill' ) ); ?></div>
        </nav><!-- #comment-nav-below -->
        <?php endif; ?>

    <?php endif; ?>

    <?php
        // If comments are closed and there are comments, show notice
        if ( ! comments_open() && '0' != get_comments_number() && post_type_supports( get_post_type(), 'comments' ) ) :
    ?>
        <p class="no-comments"><?php _e( 'Comments are closed.', 'extrachill' ); ?></p>
    <?php endif; ?>
    <?php
    // Native WordPress multisite authentication
    if (is_user_logged_in()) {
        // User is logged in across the multisite - show comment form
        comment_form(array(
            'title_reply' => __('Leave a Comment', 'extrachill'),
            'comment_notes_before' => '',
            'comment_notes_after' => '',
        ));
    } else {
        // User not logged in - show native WordPress login form
        echo '<h3>' . __('Login to Comment', 'extrachill') . '</h3>';
        echo '<p>You must be logged in to comment. <a href="' . wp_registration_url() . '">Register here</a> if you don\'t have an account.</p>';
        wp_login_form(array(
            'redirect' => get_permalink(),
            'form_id' => 'community-loginform',
            'label_username' => __('Username or Email', 'extrachill'),
            'label_password' => __('Password', 'extrachill'),
            'label_remember' => __('Remember Me', 'extrachill'),
            'label_log_in' => __('Log In', 'extrachill'),
        ));
    }
    ?>
</div><!-- #comments -->