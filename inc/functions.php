<?php
/**
 * ExtraChill Core Helper Functions
 *
 * Meta display, comment system, and core WordPress functionality.
 *
 * @package ExtraChill
 * @since 1.0
 */

include_once(ABSPATH . 'wp-admin/includes/plugin.php');


/**
 * Remove default WordPress gallery styles
 */
add_filter( 'use_default_gallery_style', '__return_false' );


if ( ! function_exists( 'extrachill_entry_meta' ) ) :
    /**
     * Display post meta information including date, author, and forum integration
     * Handles both regular posts and forum posts from community multisite
     */
    function extrachill_entry_meta() {
        global $post;

        if ( 'post' === get_post_type() || ( isset( $post->_is_forum_post ) && $post->_is_forum_post ) ) {

            $is_forum_post = isset( $post->_is_forum_post ) && $post->_is_forum_post;

            $forum_class = $is_forum_post ? ' forum-meta' : '';

            echo '<div class="below-entry-meta ' . esc_attr( $forum_class ) . '">';

            echo '<div class="below-entry-meta-left">';

            if ( $is_forum_post ) {
                $author = isset( $post->_author ) ? esc_html( $post->_author ) : 'Unknown';
                $date = isset( $post->post_date ) ? date( 'F j, Y', strtotime( $post->post_date ) ) : 'Unknown';
                $author_url = 'https://community.extrachill.com/u/' . sanitize_title( $author );

                $forum_title = isset( $post->_forum['title'] ) ? esc_html( $post->_forum['title'] ) : 'Unknown Forum';
                $forum_link = isset( $post->_forum['link'] ) ? esc_url( $post->_forum['link'] ) : '#';

                echo '<div class="meta-top-row">';
                printf(
                    __( '<time class="entry-date published">%s</time> by <a href="%s" target="_blank" rel="noopener noreferrer">%s</a> in <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', 'extrachill' ),
                    esc_html( $date ),
                    esc_url( $author_url ),
                    $author,
                    $forum_link,
                    $forum_title
                );
                echo '</div>';
            } else {
                $published_time = esc_attr( get_the_date( 'c' ) );
                $modified_time = esc_attr( get_the_modified_date( 'c' ) );
                $published_display = esc_html( get_the_date() );
                $modified_display = esc_html( get_the_modified_date() );

                $published_datetime = new DateTime( get_the_date( 'Y-m-d' ) );
                $modified_datetime = new DateTime( get_the_modified_date( 'Y-m-d' ) );
                $date_diff = $published_datetime->diff( $modified_datetime );
                $is_updated = get_the_time( 'U' ) !== get_the_modified_time( 'U' ) && $date_diff->days >= 1;

                $published_time_string = sprintf(
                    '<time class="entry-date published" datetime="%s">%s</time>',
                    $published_time,
                    $published_display
                );

                $updated_time_string = $is_updated ? sprintf(
                    '<time class="entry-date updated" datetime="%s"><b>Last Updated:</b> %s</time>',
                    $modified_time,
                    $modified_display
                ) : '';

                echo '<div class="meta-top-row">';
                printf(
                    __( '%s by ', 'extrachill' ),
                    $published_time_string
                );
                if ( is_plugin_active('co-authors-plus/co-authors-plus.php') ) {
                    coauthors_posts_links();
                } else {
                    the_author_posts_link();
                }
                echo '</div>';

                if ( $is_updated ) {
                    echo '<div class="meta-bottom-row">';
                    echo $updated_time_string;
                    echo '</div>';
                }
            }

            echo '</div>';


            echo '</div>';
        }
    }
endif;


	








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


	