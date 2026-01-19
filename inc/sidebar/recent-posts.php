<?php
/**
 * Sidebar Recent Posts
 *
 * Clean, hook-based recent posts display for sidebar.
 * Shows contextual posts based on current page type.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! function_exists( 'extrachill_sidebar_recent_posts' ) ) :
	/**
	 * Display contextual recent posts in sidebar
	 *
	 * Shows different content based on current page:
	 * - Single posts: Related posts from same category
	 * - Festival Wire posts: Latest Festival Wire posts
	 * - Other pages: General recent posts
	 */
	function extrachill_sidebar_recent_posts() {
		$post_id           = get_the_ID();
		$current_post_type = get_post_type( $post_id );
		$args              = array();
		$title             = 'Recent Posts';

		if ( is_single() ) {
			if ( 'post' === $current_post_type ) {
				$categories = get_the_category( $post_id );
				if ( $categories ) {
					$category = $categories[0];
					$args     = array(
						'post_type'      => 'post',
						'posts_per_page' => 3,
						'orderby'        => 'date',
						'order'          => 'DESC',
						'post__not_in'   => array( $post_id ),
						'category__in'   => array( $category->term_id ),
					);
					$title    = sprintf(
						'More from <a href="%s" class="sidebar-tax-link" title="View all posts in %s" aria-label="View all posts in %s">%s</a>',
						esc_url( get_category_link( $category->term_id ) ),
						esc_html( $category->name ),
						esc_html( $category->name ),
						esc_html( $category->name )
					);
				} else {
					$args = array(
						'post_type'      => 'post',
						'posts_per_page' => 3,
						'orderby'        => 'date',
						'order'          => 'DESC',
						'post__not_in'   => array( $post_id ),
					);
				}
			} elseif ( 'festival_wire' === $current_post_type ) {
				$args  = array(
					'post_type'      => 'festival_wire',
					'posts_per_page' => 3,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'post__not_in'   => array( $post_id ),
				);
				$title = 'Latest Festival Wire';
			} else {
				$args = array(
					'post_type'      => 'post',
					'posts_per_page' => 3,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'post__not_in'   => array( $post_id ),
				);
			}
		} else {
			$args = array(
				'post_type'      => 'post',
				'posts_per_page' => 3,
				'orderby'        => 'date',
				'order'          => 'DESC',
			);
		}

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) :
			echo '<div class="sidebar-card">';
			echo '<div class="widget my-recent-posts-widget">';
			echo '<div class="my-recent-posts">';
			echo '<h3 class="widget-title sidebar-recent-title-margin"><span>' . $title . '</span></h3>';

			$counter = 0;
			while ( $query->have_posts() ) :
				$query->the_post();
				++$counter;
				echo '<div class="post mini-card">';
				if ( has_post_thumbnail() ) {
					echo '<a id="post-thumbnail-link-' . $counter . '" href="' . get_permalink() . '" aria-label="Read more about ' . esc_attr( get_the_title() ) . ', an image is attached"><div class="post-thumbnail">' . get_the_post_thumbnail( get_the_ID(), 'medium_large' ) . '</div></a>';
				}
				echo '<h2 class="recent-title"><a id="post-title-link-' . $counter . '" href="' . get_permalink() . '" aria-label="Read more about ' . esc_attr( get_the_title() ) . '">' . get_the_title() . '</a></h2>';
				echo '</div>';
			endwhile;

			echo '</div>';
			echo '</div>';
			echo '</div>';

			wp_reset_postdata();
		endif;
	}
endif;

// Hook into sidebar top
add_action( 'extrachill_sidebar_top', 'extrachill_sidebar_recent_posts', 10 );
