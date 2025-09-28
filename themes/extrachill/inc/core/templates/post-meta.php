<?php
/**
 * Post Meta Display Component
 *
 * Handles author, date, and forum metadata display with conditional formatting.
 * Supports both regular posts and multisite forum posts from community.extrachill.com.
 * Integrates with Co-Authors Plus plugin when available.
 *
 * @package ExtraChill
 * @since 69.57
 */

if ( ! function_exists( 'extrachill_entry_meta' ) ) :
    /**
     * Display post metadata with conditional formatting
     *
     * Shows author, publication date, and update date for regular posts.
     * For forum posts, displays forum author links and forum context.
     *
     * @since 69.57
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