<?php
/**
 * Comment System Template
 *
 * Custom comment template for single posts with community integration.
 * Links to community.extrachill.com profiles for newer comments.
 *
 * @package ExtraChill
 * @since 1.0
 */

if ( ! function_exists( 'extrachill_comment' ) ) :

/**
 * Template for comments and pingbacks
 * Callback for wp_list_comments() with community integration
 */
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

                        $comment_date = strtotime( $comment->comment_date );
                        $cutoff_date = strtotime( '2024-02-09 00:00:00' );

                        if ( $comment_date > $cutoff_date ) {
                            $user_nicename = get_comment_meta( $comment->comment_ID, 'user_nicename', true );
                            $profile_url = "https://community.extrachill.com/u/" . $user_nicename;

                            $author_link = '<a href="' . esc_url($profile_url) . '">' . get_comment_author() . '</a>';
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