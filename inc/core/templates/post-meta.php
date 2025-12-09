<?php
/**
 * Post Meta Display
 *
 * Displays publication date, author, and last updated information.
 * Supports forum posts, Co-Authors Plus, and extrachill-users plugin integration.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! function_exists( 'extrachill_entry_meta' ) ) :
    function extrachill_entry_meta() {
        global $post;

        $is_forum_post = isset( $post->_is_forum_post ) && $post->_is_forum_post;
        $forum_class = $is_forum_post ? ' forum-meta' : '';

        ob_start();

        echo '<div class="below-entry-meta ' . esc_attr( $forum_class ) . '">';

        echo '<div class="below-entry-meta-left">';

        if ( $is_forum_post ) {
                $author = isset( $post->_author ) ? esc_html( $post->_author ) : 'Unknown';
                $date = isset( $post->post_date ) ? date( 'F j, Y', strtotime( $post->post_date ) ) : 'Unknown';

                $author_id = isset( $post->post_author ) ? $post->post_author : 0;
                $author_url = function_exists( 'ec_get_user_profile_url' )
                    ? ec_get_user_profile_url( $author_id )
                    : ec_get_site_url( 'community' ) . '/u/' . sanitize_title( $author );

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
                    if ( function_exists( 'ec_get_user_profile_url' ) ) {
                        $author_id = isset( $post->post_author ) ? $post->post_author : get_the_author_meta( 'ID' );
                        $author_url = ec_get_user_profile_url( $author_id );
                        echo '<a href="' . esc_url( $author_url ) . '">' . esc_html( get_the_author() ) . '</a>';
                    } else {
                        the_author_posts_link();
                    }
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

        $default_meta = ob_get_clean();

        echo apply_filters('extrachill_post_meta', $default_meta, get_the_ID(), get_post_type());
    }
endif;