<?php
/**
 * ColorMag functions and definitions
 *
 * This file contains all the functions and it's defination that particularly can't be
 * in other files.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */
/* * ************************************************************************************* */

/**
 * Removing the default style of wordpress gallery
 */
add_filter( 'use_default_gallery_style', '__return_false' );

/* * ************************************************************************************* */

if ( ! function_exists( 'extrachill_entry_meta' ) ) :
    /**
     * Shows meta information of post.
     */
    function extrachill_entry_meta() {
        global $post;

        if ( 'post' === get_post_type() || ( isset( $post->_is_forum_post ) && $post->_is_forum_post ) ) {

            // Determine if this is a forum post
            $is_forum_post = isset( $post->_is_forum_post ) && $post->_is_forum_post;

            // Add an additional class for forum posts
            $forum_class = $is_forum_post ? ' forum-meta' : '';

            echo '<div class="below-entry-meta ' . esc_attr( $forum_class ) . '">';

            // Left-side meta content
            echo '<div class="below-entry-meta-left">';

            if ( $is_forum_post ) {
                // Forum-specific metadata
                $author = isset( $post->_author ) ? esc_html( $post->_author ) : 'Unknown';
                $date = isset( $post->post_date ) ? date( 'F j, Y', strtotime( $post->post_date ) ) : 'Unknown';
                $author_url = 'https://community.extrachill.com/u/' . sanitize_title( $author );

                // Forum-specific forum title and link
                $forum_title = isset( $post->_forum['title'] ) ? esc_html( $post->_forum['title'] ) : 'Unknown Forum';
                $forum_link = isset( $post->_forum['link'] ) ? esc_url( $post->_forum['link'] ) : '#';

                echo '<div class="meta-top-row">';
                printf(
                    __( '<time class="entry-date published">%s</time> by <a href="%s" target="_blank" rel="noopener noreferrer">%s</a> in <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', 'colormag-pro' ),
                    esc_html( $date ),
                    esc_url( $author_url ),
                    $author,
                    $forum_link,
                    $forum_title
                );
                echo '</div>';
            } else {
                // Regular post metadata
                $published_time = esc_attr( get_the_date( 'c' ) );
                $modified_time = esc_attr( get_the_modified_date( 'c' ) );
                $published_display = esc_html( get_the_date() );
                $modified_display = esc_html( get_the_modified_date() );

                // Check if the post has been updated
                $published_datetime = new DateTime( get_the_date( 'Y-m-d' ) );
                $modified_datetime = new DateTime( get_the_modified_date( 'Y-m-d' ) );
                $date_diff = $published_datetime->diff( $modified_datetime );
                $is_updated = get_the_time( 'U' ) !== get_the_modified_time( 'U' ) && $date_diff->days >= 1;

                // Format the time strings
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

                // Display date and author on the same line
                echo '<div class="meta-top-row">';
                printf(
                    __( '%s by ', 'colormag-pro' ),
                    $published_time_string
                );
                coauthors_posts_links();
                echo '</div>';

                // Display the updated time on a new line if it exists
                if ( $is_updated ) {
                    echo '<div class="meta-bottom-row">';
                    echo $updated_time_string;
                    echo '</div>';
                }
            }

            echo '</div>'; // Close below-entry-meta-left div


            echo '</div>'; // Close below-entry-meta div
        }
    }
endif;


	

/*	 * *********************************************************************************** */

/*
 * Video post format functionality removed - not used in current theme
 */


/*	 * *********************************************************************************** */

/*	 * *********************************************************************************** */

/*
 * User Social Links functionality has been removed as it was unused.
 * The theme now uses community integration for social features.
 */


/*	 * *********************************************************************************** */

if ( ! function_exists( 'colormag_footer_copyright' ) ) :
	/**
	 * Footer Copyright
	 */
	function colormag_footer_copyright() {
		// We are now using a dedicated function in extrachill-customizer.php
		// This function is being kept for now to avoid breaking anything that might still be calling it.
		// It will be removed in a future cleanup.
		if ( function_exists( 'extrachill_footer_copyright' ) ) {
			extrachill_footer_copyright();
		}
	}
endif;


/*
 * Duplicate post system has been removed as it was unused.
 * The theme now uses modern query optimization techniques.
 */

/*	 * *********************************************************************************** */

// colormag_the_custom_logo function removed - not used in current theme



/* * *********************************************************************************** */

if ( ! function_exists( 'colormag_comment' ) ) :

/**
 * Template for comments and pingbacks.
 *
 * Used as a callback by wp_list_comments() for displaying the comments.
 */
function colormag_comment( $comment, $args, $depth ) {
    $GLOBALS['comment'] = $comment;
    switch ( $comment->comment_type ) :
        case 'trackback' :
            // Display trackbacks differently than normal comments.
            ?>
            <li <?php comment_class(); ?> id="comment-<?php comment_ID(); ?>">
            <?php
            break;
        default :
            // Proceed with normal comments.
            global $post;
            ?>
            <li <?php comment_class(); ?> id="li-comment-<?php comment_ID(); ?>">
                <article id="comment-<?php comment_ID(); ?>" class="comment">
                    <header class="comment-meta comment-author vcard">
                        <?php
                        echo get_avatar( $comment, 74 );

                        $comment_date = strtotime( $comment->comment_date );
                        $cutoff_date = strtotime( '2024-02-09 00:00:00' );

                        // Custom logic for comments made after February 9, 2024
                        if ( $comment_date > $cutoff_date ) {
                            // Assuming user_nicename is stored in comment meta
                            $user_nicename = get_comment_meta( $comment->comment_ID, 'user_nicename', true );
                            $profile_url = "https://community.extrachill.com/u/" . $user_nicename;

                            // Construct the custom author link with BBPress profile URL
                            $author_link = '<a href="' . esc_url($profile_url) . '">' . get_comment_author() . '</a>';
                        } else {
                            // Fallback to default behavior for older comments
                            $author_link = get_comment_author_link();
                        }

                        printf( '<div class="comment-author-link">%s</div>', $author_link );

                        // If current post author is also comment author, make it known visually.
                        if ( $comment->user_id === $post->post_author ) {
                            echo '<span>' . __( 'Post author', 'colormag-pro' ) . '</span>';
                        }

                        printf( '<div class="comment-date-time">%1$s</div>', sprintf( __( '%1$s at %2$s', 'colormag-pro' ), get_comment_date(), get_comment_time() ) );
                        edit_comment_link();
                        ?>
                    </header><!-- .comment-meta -->

                    <?php if ( '0' == $comment->comment_approved ) : ?>
                        <p class="comment-awaiting-moderation"><?php _e( 'Your comment is awaiting moderation.', 'colormag-pro' ); ?></p>
                    <?php endif; ?>

                    <section class="comment-content comment">
                        <?php comment_text(); ?>
                        <?php comment_reply_link( array_merge( $args, array(
                            'reply_text' => __( 'Reply', 'colormag-pro' ),
                            'after'      => '',
                            'depth'      => $depth,
                            'max_depth'  => $args['max_depth'],
                        ) ) ); ?>
                    </section><!-- .comment-content -->

                </article><!-- #comment-## -->
            <?php
            break;
    endswitch; // end comment_type check
}

	endif;

	
/*	 * *********************************************************************************** */

/*
 * Post format video functionality has been removed as it was unused.
 * The theme now uses modern embed handling for video content.
 */

/*	 * *********************************************************************************** */
	