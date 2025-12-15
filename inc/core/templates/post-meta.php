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
	/**
	 * Outputs post meta for the current post.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function extrachill_entry_meta() {
		global $post;

		$is_forum_post = isset( $post->_is_forum_post ) && $post->_is_forum_post;
		$forum_class   = $is_forum_post ? ' forum-meta' : '';

		ob_start();

		echo '<div class="below-entry-meta ' . esc_attr( $forum_class ) . '">';

		echo '<div class="below-entry-meta-left">';

		if ( $is_forum_post ) {
			$author = isset( $post->_author ) ? esc_html( $post->_author ) : 'Unknown';
			$date   = isset( $post->post_date ) ? gmdate( 'F j, Y', strtotime( $post->post_date ) ) : 'Unknown';

			$author_id  = isset( $post->post_author ) ? $post->post_author : 0;
			$author_url = function_exists( 'ec_get_user_profile_url' )
				? ec_get_user_profile_url( $author_id )
				: ec_get_site_url( 'community' ) . '/u/' . sanitize_title( $author );

			$forum_title = isset( $post->_forum['title'] ) ? esc_html( $post->_forum['title'] ) : 'Unknown Forum';
			$forum_link  = isset( $post->_forum['link'] ) ? esc_url( $post->_forum['link'] ) : '#';

			echo '<div class="meta-top-row">';
			printf(
				// translators: 1: post date, 2: author URL, 3: author name, 4: forum URL, 5: forum title.
				wp_kses_post( __( '<time class="entry-date published">%1$s</time> by <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a> in <a href="%4$s" target="_blank" rel="noopener noreferrer">%5$s</a>', 'extrachill' ) ),
				esc_html( $date ),
				esc_url( $author_url ),
				esc_html( $author ),
				esc_url( $forum_link ),
				esc_html( $forum_title )
			);
				echo '</div>';
		} else {
			$post_id   = get_the_ID();
			$post_type = get_post_type( $post_id );

			$parts = apply_filters( 'extrachill_post_meta_parts', array( 'published', 'author', 'updated' ), $post_id, $post_type );

			$show_published = in_array( 'published', $parts, true );
			$show_author    = in_array( 'author', $parts, true );
			$show_updated   = in_array( 'updated', $parts, true );

			$published_time    = esc_attr( get_the_date( 'c' ) );
			$modified_time     = esc_attr( get_the_modified_date( 'c' ) );
			$published_display = esc_html( get_the_date() );
			$modified_display  = esc_html( get_the_modified_date() );

			$published_datetime = new DateTime( get_the_date( 'Y-m-d' ) );
			$modified_datetime  = new DateTime( get_the_modified_date( 'Y-m-d' ) );
			$date_diff          = $published_datetime->diff( $modified_datetime );
			$is_updated         = get_the_time( 'U' ) !== get_the_modified_time( 'U' ) && $date_diff->days >= 1;

			$published_time_string = sprintf(
				'<time class="entry-date published" datetime="%s">%s</time>',
				$published_time,
				$published_display
			);

			$updated_time_string = ( $show_updated && $is_updated ) ? sprintf(
				'<time class="entry-date updated" datetime="%s"><b>Last Updated:</b> %s</time>',
				$modified_time,
				$modified_display
			) : '';

			echo '<div class="meta-top-row">';

			$published_prefix = apply_filters( 'extrachill_post_meta_published_prefix', '', $post_id, $post_type );

			if ( $show_published ) {
				echo esc_html( $published_prefix );
				echo wp_kses_post( $published_time_string );
			}

			if ( $show_author ) {
				echo ' ' . esc_html__( 'by', 'extrachill' ) . ' ';
				if ( is_plugin_active( 'co-authors-plus/co-authors-plus.php' ) ) {
					coauthors_posts_links();
				} elseif ( function_exists( 'ec_get_user_author_archive_url' ) ) {
					$author_id  = isset( $post->post_author ) ? $post->post_author : get_the_author_meta( 'ID' );
					$author_url = ec_get_user_author_archive_url( $author_id );
					if ( $author_url ) {
						echo '<a href="' . esc_url( $author_url ) . '">' . esc_html( get_the_author() ) . '</a>';
					} else {
						the_author_posts_link();
					}
				} else {
					the_author_posts_link();
				}
			}

			echo '</div>';

			if ( $updated_time_string ) {
				echo '<div class="meta-bottom-row">';
				echo wp_kses_post( $updated_time_string );
				echo '</div>';
			}
		}

		echo '</div>';

		echo '</div>';

		$default_meta = ob_get_clean();

		echo wp_kses_post( apply_filters( 'extrachill_post_meta', $default_meta, get_the_ID(), get_post_type() ) );
	}
endif;
