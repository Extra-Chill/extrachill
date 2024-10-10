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
add_action( 'wp_enqueue_scripts', 'colormag_scripts_styles_method' );

/**
 * Register jquery scripts
 */
function colormag_scripts_styles_method() {

	$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

	/**
	 * Loads our main stylesheet.
	 */
wp_enqueue_style('colormag_style', get_stylesheet_uri(), array(), filemtime(get_stylesheet_directory() . '/style.css'));

}

add_action( 'admin_enqueue_scripts', 'colormag_image_uploader' );

function colormag_image_uploader() {
	wp_enqueue_media();
	wp_enqueue_script( 'colormag-widget-image-upload', COLORMAG_JS_URL . '/image-uploader.js', false, '20150309', true );
}

/* * ************************************************************************************* */

add_filter( 'excerpt_length', 'colormag_excerpt_length' );

/**
 * Sets the post excerpt length to 40 words.
 *
 * function tied to the excerpt_length filter hook.
 *
 * @uses filter excerpt_length
 */
function colormag_excerpt_length( $length ) {
	return 20;
}

add_filter( 'excerpt_more', 'colormag_continue_reading' );

/**
 * Returns a "Continue Reading" link for excerpts
 */
function colormag_continue_reading() {
	return '';
}

/* * ************************************************************************************* */

/**
 * Removing the default style of wordpress gallery
 */
add_filter( 'use_default_gallery_style', '__return_false' );

/**
 * Filtering the size to be full from thumbnail to be used in WordPress gallery as a default size
 */
function colormag_gallery_atts( $out, $pairs, $atts ) {
	$atts = shortcode_atts( array(
		'size' => 'colormag-featured-image',
	), $atts );

	$out['size'] = $atts['size'];

	return $out;
}

add_filter( 'shortcode_atts_gallery', 'colormag_gallery_atts', 10, 3 );

/* * ************************************************************************************* */

add_filter( 'body_class', 'colormag_body_class' );

/**
 * Filter the body_class
 *
 * Throwing different body class for the different layouts in the body tag
 */
function colormag_body_class( $classes ) {
	global $post;

	if ( $post ) {
		$layout_meta = get_post_meta( $post->ID, 'colormag_page_layout', true );
	}

	if ( is_home() ) {
		$queried_id  = get_option( 'page_for_posts' );
		$layout_meta = get_post_meta( $queried_id, 'colormag_page_layout', true );
	}
	if ( empty( $layout_meta ) || is_archive() || is_search() ) {
		$layout_meta = 'default_layout';
	}
	$colormag_default_layout = get_theme_mod( 'colormag_default_layout', 'right_sidebar' );

	$colormag_default_page_layout = get_theme_mod( 'colormag_default_page_layout', 'right_sidebar' );
	$colormag_default_post_layout = get_theme_mod( 'colormag_default_single_posts_layout', 'right_sidebar' );

	$woocommerce_widgets_enabled = get_theme_mod( 'colormag_woocommerce_sidebar_register_setting', 0 );

	// Proceed only if WooCommerce extra widget option is not enabled as well as
	// Proceed only if WooCommerce is enabled and not in WooCommerce pages.
	if ( ( $woocommerce_widgets_enabled == 0 ) || ( ( $woocommerce_widgets_enabled == 1 ) && ( function_exists( 'is_woocommerce' ) && ( ! is_woocommerce() ) ) ) ) :
		if ( $layout_meta == 'default_layout' ) {
			if ( is_page() ) {
				if ( $colormag_default_page_layout == 'right_sidebar' ) {
					$classes[] = '';
				} elseif ( $colormag_default_page_layout == 'left_sidebar' ) {
					$classes[] = 'left-sidebar';
				} elseif ( $colormag_default_page_layout == 'no_sidebar_full_width' ) {
					$classes[] = 'no-sidebar-full-width';
				} elseif ( $colormag_default_page_layout == 'no_sidebar_content_centered' ) {
					$classes[] = 'no-sidebar';
				}
			} elseif ( is_single() ) {
				if ( $colormag_default_post_layout == 'right_sidebar' ) {
					$classes[] = '';
				} elseif ( $colormag_default_post_layout == 'left_sidebar' ) {
					$classes[] = 'left-sidebar';
				} elseif ( $colormag_default_post_layout == 'no_sidebar_full_width' ) {
					$classes[] = 'no-sidebar-full-width';
				} elseif ( $colormag_default_post_layout == 'no_sidebar_content_centered' ) {
					$classes[] = 'no-sidebar';
				}
			} elseif ( $colormag_default_layout == 'right_sidebar' ) {
				$classes[] = '';
			} elseif ( $colormag_default_layout == 'left_sidebar' ) {
				$classes[] = 'left-sidebar';
			} elseif ( $colormag_default_layout == 'no_sidebar_full_width' ) {
				$classes[] = 'no-sidebar-full-width';
			} elseif ( $colormag_default_layout == 'no_sidebar_content_centered' ) {
				$classes[] = 'no-sidebar';
			}
		} elseif ( $layout_meta == 'right_sidebar' ) {
			$classes[] = '';
		} elseif ( $layout_meta == 'left_sidebar' ) {
			$classes[] = 'left-sidebar';
		} elseif ( $layout_meta == 'no_sidebar_full_width' ) {
			$classes[] = 'no-sidebar-full-width';
		} elseif ( $layout_meta == 'no_sidebar_content_centered' ) {
			$classes[] = 'no-sidebar';
		}
	endif;

	if ( get_theme_mod( 'colormag_site_layout', 'wide_layout' ) == 'wide_layout' ) {
		$classes[] = 'wide';
	} elseif ( get_theme_mod( 'colormag_site_layout', 'wide_layout' ) == 'boxed_layout' ) {
		$classes[] = '';
	}

	// Add body class for header display type
	if ( get_theme_mod( 'colormag_header_display_type', 'type_one' ) == 'type_two' ) {
		$classes[] = 'header_display_type_one';
	}
	if ( get_theme_mod( 'colormag_header_display_type', 'type_one' ) == 'type_three' ) {
		$classes[] = 'header_display_type_two';
	}

	// Add body class for body skin type
	if ( get_theme_mod( 'colormag_color_skin_setting', 'white' ) == 'dark' ) {
		$classes[] = 'dark-skin';
	}

	// For background image clickable.
	$background_image_url_link = get_theme_mod( 'colormag_background_image_link' );
	if ( $background_image_url_link ) {
		$classes[] = 'clickable-background-image';
	}

	return $classes;
}

/* * ************************************************************************************* */

if ( ! function_exists( 'colormag_sidebar_select' ) ) :

	/**
	 * Function to select the sidebar
	 */
	function colormag_sidebar_select() {
		global $post;

		if ( $post ) {
			$layout_meta = get_post_meta( $post->ID, 'colormag_page_layout', true );
		}

		if ( is_home() ) {
			$queried_id  = get_option( 'page_for_posts' );
			$layout_meta = get_post_meta( $queried_id, 'colormag_page_layout', true );
		}

		if ( empty( $layout_meta ) || is_archive() || is_search() ) {
			$layout_meta = 'default_layout';
		}
		$colormag_default_layout = get_theme_mod( 'colormag_default_layout', 'right_sidebar' );

		$colormag_default_page_layout = get_theme_mod( 'colormag_default_page_layout', 'right_sidebar' );
		$colormag_default_post_layout = get_theme_mod( 'colormag_default_single_posts_layout', 'right_sidebar' );

		if ( $layout_meta == 'default_layout' ) {
			if ( is_page() ) {
				if ( $colormag_default_page_layout == 'right_sidebar' ) {
					get_sidebar();
				} elseif ( $colormag_default_page_layout == 'left_sidebar' ) {
					get_sidebar( 'left' );
				}
			}
			if ( is_single() ) {
				if ( $colormag_default_post_layout == 'right_sidebar' ) {
					get_sidebar();
				} elseif ( $colormag_default_post_layout == 'left_sidebar' ) {
					get_sidebar( 'left' );
				}
			} elseif ( $colormag_default_layout == 'right_sidebar' ) {
				get_sidebar();
			} elseif ( $colormag_default_layout == 'left_sidebar' ) {
				get_sidebar( 'left' );
			}
		} elseif ( $layout_meta == 'right_sidebar' ) {
			get_sidebar();
		} elseif ( $layout_meta == 'left_sidebar' ) {
			get_sidebar( 'left' );
		}
	}

endif;

/* * ************************************************************************************* */
if ( ! function_exists( 'colormag_entry_meta' ) ) :
    /**
     * Shows meta information of post.
     */
	function colormag_entry_meta() {
		if ( 'post' === get_post_type() ) {
			// Define human readable time class if needed
			$human_diff_time = get_theme_mod( 'colormag_post_meta_date_setting', 'post_date' ) === 'post_human_readable_date' ? 'human-diff-time' : '';
			echo '<div class="below-entry-meta ' . esc_attr( $human_diff_time ) . '">';
	
			// Left-side meta content
			echo '<div class="below-entry-meta-left">';
			
			// Get publish and modified times
			$published_time = esc_attr( get_the_date( 'c' ) );
			$modified_time = esc_attr( get_the_modified_date( 'c' ) );
			$published_display = esc_html( get_the_date() );
			$modified_display = esc_html( get_the_modified_date() );
	
			// Check if the post has been updated and if the update is at least a day after the original publish date
			$published_datetime = new DateTime( get_the_date( 'Y-m-d' ) );
			$modified_datetime = new DateTime( get_the_modified_date( 'Y-m-d' ) );
			$date_diff = $published_datetime->diff( $modified_datetime );
			$is_updated = get_the_time( 'U' ) !== get_the_modified_time( 'U' ) && $date_diff->days >= 1;
	
			// Format the published time string
			$published_time_string = sprintf(
				'<time class="entry-date published" datetime="%s">%s</time>',
				$published_time,
				$published_display
			);
	
// Format the updated time string
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
	
			echo '</div>'; // Close below-entry-meta-left div
	
			// Right-side location link
			if ( is_singular( 'post' ) ) {
				$locations = get_the_terms( get_the_ID(), 'location' );
				if ( $locations && !is_wp_error( $locations ) ) {
					echo '<div class="post-location-meta">';
					foreach ( $locations as $location ) {
						echo '<a href="' . esc_url( get_term_link( $location ) ) . '" class="location-link">' . esc_html( $location->name ) . '</a>';
					}
					echo '</div>';
				}
			}
	
			echo '</div>'; // Close below-entry-meta div
		}
	}
	
endif;	
	




/* * ********************************	************************************************** */
// add_action( 'admin_head', 'colormag_favicon' );
// add_action( 'wp_head', 'colormag_favicon' );

/**
 * Favicon for the site
 */
// function colormag_favicon() {
//     if ( get_theme_mod( 'colormag_favicon_show', '0' ) == '1' ) {
//         $colormag_favicon        = get_theme_mod( 'colormag_favicon_upload', '' );
//         $colormag_favicon_output = '';
//         if ( ! function_exists( 'has_site_icon' ) || ( ! empty( $colormag_favicon ) && ! has_site_icon() ) ) {
//             $colormag_favicon_output .= '<link rel="shortcut icon" href="' . esc_url( $colormag_favicon ) . '" type="image/x-icon" />';
//         }
//         echo $colormag_favicon_output;
//     }
// }

/* * ************************************************************************************* */



/* * *********************************************************************************** */

/**
 * Category ID on Menu
 *
 * @param array  $classes
 * @param object $item
 *
 * @return array $classes
 */
function colormag_category_id_on_menu( $classes, $item ) {
	if ( $item->object !== 'category' ) {
		return $classes;
	}

	$classes[] = 'menu-item-category-' . $item->object_id;

	return $classes;
}

add_filter( 'nav_menu_css_class', 'colormag_category_id_on_menu', 10, 2 );

add_filter( 'the_content_more_link', 'colormag_remove_more_jump_link' );

/**
 * Removing the more link jumping to middle of content
 */
function colormag_remove_more_jump_link( $link ) {
	$offset = strpos( $link, '#more-' );
	if ( $offset ) {
		$end = strpos( $link, '"', $offset );
	}
	if ( $end ) {
		$link = substr_replace( $link, '', $offset, $end - $offset );
	}

	return $link;
}

/* * *********************************************************************************** */

if ( ! function_exists( 'colormag_content_nav' ) ) :

	/**
	 * Display navigation to next/previous pages when applicable
	 */
	function colormag_content_nav( $nav_id ) {
		global $wp_query, $post;

		// Don't print empty markup on single pages if there's nowhere to navigate.
		if ( is_single() ) {
			$previous = ( is_attachment() ) ? get_post( $post->post_parent ) : get_adjacent_post( false, '', true );
			$next     = get_adjacent_post( false, '', false );

			if ( ! $next && ! $previous ) {
				return;
			}
		}

		// Don't print empty markup in archives if there's only one page.
		if ( $wp_query->max_num_pages < 2 && ( is_home() || is_archive() || is_search() ) ) {
			return;
		}

		$nav_class = ( is_single() ) ? 'post-navigation' : 'paging-navigation';
		?>
		<nav role="navigation" id="<?php echo esc_attr( $nav_id ); ?>" class="<?php echo $nav_class; ?>">
			<h1 class="screen-reader-text"><?php _e( 'Post navigation', 'colormag-pro' ); ?></h1>

			<?php if ( is_single() ) : // navigation links for single posts ?>

				<?php previous_post_link( '<div class="nav-previous">%link</div>', '<span class="meta-nav">' . _x( '&larr;', 'Previous post link', 'colormag-pro' ) . '</span> %title' ); ?>
				<?php next_post_link( '<div class="nav-next">%link</div>', '%title <span class="meta-nav">' . _x( '&rarr;', 'Next post link', 'colormag-pro' ) . '</span>' ); ?>

			<?php elseif ( $wp_query->max_num_pages > 1 && ( is_home() || is_archive() || is_search() ) ) : // navigation links for home, archive, and search pages  ?>

				<?php if ( get_next_posts_link() ) : ?>
					<div class="nav-previous"><?php next_posts_link( __( '<span class="meta-nav">&larr;</span> Older posts', 'colormag-pro' ) ); ?></div>
				<?php endif; ?>

				<?php if ( get_previous_posts_link() ) : ?>
					<div class="nav-next"><?php previous_posts_link( __( 'Newer posts <span class="meta-nav">&rarr;</span>', 'colormag-pro' ) ); ?></div>
				<?php endif; ?>

			<?php endif; ?>

		</nav><!-- #<?php echo esc_html( $nav_id ); ?> -->
		<?php
	}

endif; // colormag_content_nav

/*	 * *********************************************************************************** */

/*
 * Category Color Options
 */
if ( ! function_exists( 'colormag_category_color' ) ) :

	function colormag_category_color( $wp_category_id ) {
		$args     = array(
			'orderby'    => 'id',
			'hide_empty' => 0,
		);
		$category = get_categories( $args );
		foreach ( $category as $category_list ) {
			$color = get_theme_mod( 'colormag_category_color_' . $wp_category_id );

			return $color;
		}
	}

endif;

/*	 * *********************************************************************************** */

/*
 * Display the date in the header
 */
if ( ! function_exists( 'colormag_date_display' ) ) :

	function colormag_date_display() {
		if ( get_theme_mod( 'colormag_date_display', 0 ) == 0 ) {
			return;
		}
		?>

		<div class="date-in-header">
			<?php
			if ( get_theme_mod( 'colormag_date_display_type', 'theme_default' ) == 'theme_default' ) {
				echo date_i18n( 'l, F j, Y' );
			} elseif ( get_theme_mod( 'colormag_date_display_type', 'theme_default' ) == 'wordpress_date_setting' ) {
				echo date_i18n( get_option( 'date_format' ) );
			}
			?>
		</div>

		<?php
	}

endif;

/*	 * *********************************************************************************** */

/*
 * Random Post in header
 */
if ( ! function_exists( 'colormag_random_post' ) ) :

	function colormag_random_post() {
		// Bail out if random post in menu is not activated
		if ( get_theme_mod( 'colormag_random_post_in_menu', 0 ) == 0 ) {
			return;
		}

		$get_random_post = new WP_Query( array(
			'posts_per_page'         => 1,
			'post_type'              => 'post',
			'ignore_sticky_posts'    => true,
			'orderby'                => 'rand',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		?>

		<div class="random-post">
			<?php while ( $get_random_post->have_posts() ):$get_random_post->the_post(); ?>
				<a href="<?php the_permalink(); ?>" title="<?php esc_attr_e( 'View a random post', 'colormag-pro' ); ?>"><i class="fa fa-random"></i></a>
			<?php endwhile; ?>
		</div>

		<?php
		// Reset Post Data
		wp_reset_query();
	}

endif;

/*	 * *********************************************************************************** */

/*
 * Display the related posts
 */
if ( ! function_exists( 'colormag_related_posts_function' ) ) {

	function colormag_related_posts_function( $related_type = 'categories' ) {
		wp_reset_postdata();
		global $post;

		// Define shared post arguments
		$args = array(
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => 1,
			'orderby'                => 'rand',
			'post__not_in'           => array( $post->ID ),
			'posts_per_page'         => 3,  // Set this as per your preference
		);

		if ( $related_type == 'categories' ) {
			$cats = get_post_meta( $post->ID, 'related-posts', true );
			if ( ! $cats ) {
				$cats                 = wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) );
				$args['category__in'] = $cats;
			} else {
				$args['cat'] = $cats;
			}
		}

		if ( $related_type == 'tags' ) {
			$tags = get_post_meta( $post->ID, 'related-posts', true );
			if ( ! $tags ) {
				$tags            = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
				$args['tag__in'] = $tags;
			} else {
				$args['tag_slug__in'] = explode( ',', $tags );
			}
		}

		$query = new WP_Query( $args );

		return $query;
	}

}

if ( ! function_exists( 'colormag_flyout_related_post_query' ) ) {

	/**
	 * Flyout related posts query.
	 */
	function colormag_flyout_related_post_query() {
		wp_reset_postdata();
		global $post;

		// Define shared post arguments.
		$args = array(
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => 1,
			'orderby'                => 'rand',
			'post__not_in'           => array( $post->ID ),
			'posts_per_page'         => 2,
		);

		// Related by categories.
		if ( get_theme_mod( 'colormag_related_posts_flyout_type', 'categories' ) == 'categories' ) {
			$cats                 = wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) );
			$args['category__in'] = $cats;
		}

		// Related by tags.
		if ( get_theme_mod( 'colormag_related_posts_flyout_type', 'categories' ) == 'tags' ) {
			$tags            = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
			$args['tag__in'] = $tags;

			// If no tags added, return.
			if ( ! $tags ) {
				$break = true;
			}
		}

		$query = ! isset( $break ) ? new WP_Query( $args ) : new WP_Query;

		return $query;

	}

}

/*	 * *********************************************************************************** */

/*	 * *********************************************************************************** */


/*	 * *********************************************************************************** */

/*	 * *********************************************************************************** */

/*
 * Adding the custom meta box for supporting the post formats
 */

function colormag_post_format_meta_box() {
	add_meta_box( 'post-video-url', __( 'Video URL', 'colormag-pro' ), 'colormag_post_format_video_url', 'post', 'side', 'high' );
}

add_action( 'add_meta_boxes', 'colormag_post_format_meta_box' );

function colormag_post_format_video_url( $post ) {
	$video_post_id  = get_post_custom( $post->ID );
	$video_post_url = isset( $video_post_id['video_url'] ) ? esc_attr( $video_post_id['video_url'][0] ) : '';
	wp_nonce_field( 'my_meta_box_nonce', 'meta_box_nonce' );
	?>
	<p>
		<input type="text" class="widefat" name="video_url" id="video_url" value="<?php echo $video_post_url; ?>" />
	</p>
	<?php
}

function colormag_post_meta_save( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// checking if the nonce isn't there, or we can't verify it, then we should return
	if ( ! isset( $_POST['meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['meta_box_nonce'], 'my_meta_box_nonce' ) ) {
		return;
	}

	// checking if the current user can't edit this post, then we should return
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	// saving the data in meta box
	// saving the video url in the meta box
	if ( isset( $_POST['video_url'] ) ) {
		update_post_meta( $post_id, 'video_url', esc_url_raw( $_POST['video_url'] ) );
	}
}

add_action( 'save_post', 'colormag_post_meta_save' );

function colormag_meta_box_display_toggle() {
	$custom_script
		= '
<script type="text/javascript">
jQuery(document).ready(function() {
// hide the div by default
jQuery( "#post-video-url" ).hide();

// if post format is selected, then, display the respective div
if(jQuery( "#post-format-video" ).is( ":checked" ))
jQuery( "#post-video-url" ).show();

// hiding the default post format type input box by default
jQuery( "input[name=\"post_format\"]" ).change(function() {
	jQuery( "#post-video-url" ).hide();
});

// if post format is selected, then, display the respective input div
jQuery( "input#post-format-video" ).change( function() {
	jQuery( "#post-video-url" ).show();
});
});
</script>
';

	return print $custom_script;
}

add_action( 'admin_footer', 'colormag_meta_box_display_toggle' );

/*	 * *********************************************************************************** */

/*	 * *********************************************************************************** */

/*
 * Adding the Custom Generated User Field
 */
add_action( 'show_user_profile', 'colormag_extra_user_field' );
add_action( 'edit_user_profile', 'colormag_extra_user_field' );

function colormag_extra_user_field( $user ) {
	?>
	<h3><?php _e( 'User Social Links', 'colormag-pro' ); ?></h3>
	<table class="form-table">
		<tr>
			<th><label for="colormag_twitter"><?php _e( 'Twitter', 'colormag-pro' ); ?></label></th>
			<td>
				<input type="text" name="colormag_twitter" id="colormag_twitter" value="<?php echo esc_attr( get_the_author_meta( 'colormag_twitter', $user->ID ) ); ?>" class="regular-text" />
			</td>
		</tr>
		<tr>
			<th><label for="colormag_facebook"><?php _e( 'Facebook', 'colormag-pro' ); ?></label></th>
			<td>
				<input type="text" name="colormag_facebook" id="colormag_facebook" value="<?php echo esc_attr( get_the_author_meta( 'colormag_facebook', $user->ID ) ); ?>" class="regular-text" />
			</td>
		<tr>
			<th><label for="colormag_instagram"><?php _e( 'Instagram', 'colormag-pro' ); ?></label></th>
			<td>
				<input type="text" name="colormag_instagram" id="colormag_instagram" value="<?php echo esc_attr( get_the_author_meta( 'colormag_instagram', $user->ID ) ); ?>" class="regular-text" />
			</td>
		</tr>
		<tr>
			<th><label for="colormag_youtube"><?php _e( 'Youtube', 'colormag-pro' ); ?></label></th>
			<td>
				<input type="text" name="colormag_youtube" id="colormag_youtube" value="<?php echo esc_attr( get_the_author_meta( 'colormag_youtube', $user->ID ) ); ?>" class="regular-text" />
			</td>
	</table>
	<?php
}

// Saving the user field used above
add_action( 'personal_options_update', 'colormag_extra_user_field_save_option' );
add_action( 'edit_user_profile_update', 'colormag_extra_user_field_save_option' );

function colormag_extra_user_field_save_option( $user_id ) {

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return false;
	}

	update_user_meta( $user_id, 'colormag_twitter', wp_filter_nohtml_kses( $_POST['colormag_twitter'] ) );
	update_user_meta( $user_id, 'colormag_facebook', wp_filter_nohtml_kses( $_POST['colormag_facebook'] ) );
	update_user_meta( $user_id, 'colormag_instagram', wp_filter_nohtml_kses( $_POST['colormag_instagram'] ) );
	update_user_meta( $user_id, 'colormag_youtube', wp_filter_nohtml_kses( $_POST['colormag_youtube'] ) );
}

function colormag_author_social_link() {
    ?>
    <ul class="author-social-sites">
    <?php if ( get_the_author_meta( 'colormag_twitter' ) ) { ?>
        <li class="twitter-link">
            <a href="https://twitter.com/<?php the_author_meta( 'colormag_twitter' ); ?>"><svg>
    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#x-twitter"></use>
</svg>
</a>
        </li>
    <?php } // End check for twitter ?>
    <?php if ( get_the_author_meta( 'colormag_facebook' ) ) { ?>
        <li class="facebook-link">
            <a href="https://facebook.com/<?php the_author_meta( 'colormag_facebook' ); ?>"><svg>
    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#facebook-f"></use>
</svg>
</a>
        </li>
    <?php } // End check for facebook ?>
    <?php if ( get_the_author_meta( 'colormag_instagram' ) ) { ?>
        <li class="instagram-link">
            <a href="https://instagram.com/<?php the_author_meta( 'colormag_instagram' ); ?>"><i class="fa fa-instagram"></i></a>
        </li>
    <?php } // End check for instagram ?>
    <?php if ( get_the_author_meta( 'colormag_youtube' ) ) { ?>
        <li class="youtube-link">
            <a href="https://youtube.com/<?php the_author_meta( 'colormag_youtube' ); ?>"><i class="fa fa-youtube"></i></a>
        </li>
    <?php } // End check for youtube ?>
    <!-- Igloo Icon Link -->
    <li class="igloo-link">
        <a href="https://community.extrachill.com"><i class="fa-solid fa-igloo"></i></a>
    </li>
    </ul><?php
}


/*	 * *********************************************************************************** */

if ( ! function_exists( 'colormag_footer_copyright' ) ) :

	function colormag_footer_copyright() {
		$default_footer_value      = get_theme_mod( 'colormag_footer_editor', __( 'Copyright &copy; ', 'colormag-pro' ) . '[the-year] [site-link]. All rights reserved. ' . '<br>' . __( 'Theme: ColorMag Pro by ', 'colormag-pro' ) . '[tg-link]. ' . __( 'Powered by ', 'colormag-pro' ) . '[wp-link].' );
		$colormag_footer_copyright = '<div class="copyright">' . $default_footer_value . '</div>';

		echo do_shortcode( $colormag_footer_copyright );
	}

endif;

/*	 * ************************************************************************************* */

if ( ! function_exists( 'colormag_font_size_range_generator' ) ) :

	/**
	 * Function to generate font size range for font size options.
	 */
	function colormag_font_size_range_generator( $start_range, $end_range ) {
		$range_string = array();
		for ( $i = $start_range; $i <= $end_range; $i ++ ) {
			$range_string[ $i ] = $i;
		}

		return $range_string;
	}

endif;

/*
 * Unique Post Display function
 *
 * The following sets of fucntions help in removing the duplicate posts from being shown
 * in a page.
 *
 * colormag_exclude_duplicate_posts() - Excluding the Duplicate posts in featured posts widget
 * colormag_append_excluded_duplicate_posts() - Appending the duplicate posts
 */

function colormag_exclude_duplicate_posts() {
	global $colormag_duplicate_posts;

	if ( get_theme_mod( 'colormag_unique_post_system', 0 ) == 1 ) {
		$post__not_in = $colormag_duplicate_posts;
	} else {
		$post__not_in = array();
	}

	return $post__not_in;
}

function colormag_append_excluded_duplicate_posts( $featured_posts ) {
    global $colormag_duplicate_posts;

    // Initialize the global variable if not already set
    if (!isset($colormag_duplicate_posts) || !is_array($colormag_duplicate_posts)) {
        $colormag_duplicate_posts = array();
    }

    // Check if unique post system is enabled and $featured_posts is a valid WP_Query object
    if (get_theme_mod('colormag_unique_post_system', 0) == 1 && $featured_posts instanceof WP_Query && !empty($featured_posts->posts)) {
        $post_ids = wp_list_pluck($featured_posts->posts, 'ID');
        if (is_array($post_ids)) {
            $colormag_duplicate_posts = array_unique(array_merge($colormag_duplicate_posts, $post_ids));
        }
    }

    // Return the modified array
    return $colormag_duplicate_posts;
}

/*	 * *********************************************************************************** */

if ( ! function_exists( 'colormag_woocommerce_body_class' ) ) {

	/**
	 * Filter body class for WooCommerce pages.
	 *
	 * @return array classes for WooCommerce pages.
	 *
	 * @since 2.2.8
	 */
	function colormag_woocommerce_body_class( $classes ) {
		$classes[] = '';

		// Filter body class if WooCommerce plugin is activated.
		if ( class_exists( 'WooCommerce' ) ) {
			$classes[] = 'woocommerce-active';

			$woocommerce_shop_page_layout           = get_theme_mod( 'colormag_woocmmerce_shop_page_layout', 'right_sidebar' );
			$woocommerce_archive_page_layout        = get_theme_mod( 'colormag_woocmmerce_archive_page_layout', 'right_sidebar' );
			$woocommerce_single_product_page_layout = get_theme_mod( 'colormag_woocmmerce_single_product_page_layout', 'right_sidebar' );

			$woocommerce_widgets_enabled = get_theme_mod( 'colormag_woocommerce_sidebar_register_setting', 0 );

			if ( ( $woocommerce_widgets_enabled == 1 ) ) :
				if ( is_shop() ) {
					if ( $woocommerce_shop_page_layout == 'right_sidebar' ) {
						$classes[] = '';
					} elseif ( $woocommerce_shop_page_layout == 'left_sidebar' ) {
						$classes[] = 'left-sidebar';
					} elseif ( $woocommerce_shop_page_layout == 'no_sidebar_full_width' ) {
						$classes[] = 'no-sidebar-full-width';
					} elseif ( $woocommerce_shop_page_layout == 'no_sidebar_content_centered' ) {
						$classes[] = 'no-sidebar';
					}
				} elseif ( is_product_category() || is_product_tag() ) {
					if ( $woocommerce_archive_page_layout == 'right_sidebar' ) {
						$classes[] = '';
					} elseif ( $woocommerce_archive_page_layout == 'left_sidebar' ) {
						$classes[] = 'left-sidebar';
					} elseif ( $woocommerce_archive_page_layout == 'no_sidebar_full_width' ) {
						$classes[] = 'no-sidebar-full-width';
					} elseif ( $woocommerce_archive_page_layout == 'no_sidebar_content_centered' ) {
						$classes[] = 'no-sidebar';
					}
				} elseif ( is_product() ) {
					if ( $woocommerce_single_product_page_layout == 'right_sidebar' ) {
						$classes[] = '';
					} elseif ( $woocommerce_single_product_page_layout == 'left_sidebar' ) {
						$classes[] = 'left-sidebar';
					} elseif ( $woocommerce_single_product_page_layout == 'no_sidebar_full_width' ) {
						$classes[] = 'no-sidebar-full-width';
					} elseif ( $woocommerce_single_product_page_layout == 'no_sidebar_content_centered' ) {
						$classes[] = 'no-sidebar';
					}
				}
			endif;
		}

		return $classes;
	}
}

add_filter( 'body_class', 'colormag_woocommerce_body_class' );

if ( ! function_exists( 'colormag_woocommerce_sidebar_select' ) ) {

	/**
	 * Select different sidebars for WooCommerce pages as set by the user
	 * when extra WooCommerce widgets is enabled.
	 *
	 * @since 2.2.8
	 */
	function colormag_woocommerce_sidebar_select() {
		// Bail out if extra sidebar area for WooCommerce page is not activated.
		if ( get_theme_mod( 'colormag_woocommerce_sidebar_register_setting', 0 ) == 0 ) {
			return;
		}

		// Proceed only if WooCommerce plugin is activated.
		if ( class_exists( 'WooCommerce' ) ) {
			$woocommerce_shop_page_layout           = get_theme_mod( 'colormag_woocmmerce_shop_page_layout', 'right_sidebar' );
			$woocommerce_archive_page_layout        = get_theme_mod( 'colormag_woocmmerce_archive_page_layout', 'right_sidebar' );
			$woocommerce_single_product_page_layout = get_theme_mod( 'colormag_woocmmerce_single_product_page_layout', 'right_sidebar' );

			if ( is_shop() ) { // For Shop page.
				if ( $woocommerce_shop_page_layout == 'right_sidebar' ) {
					get_sidebar( 'woocommerce-right' );
				} elseif ( $woocommerce_shop_page_layout == 'left_sidebar' ) {
					get_sidebar( 'woocommerce-left' );
				}
			} elseif ( is_product_category() || is_product_tag() ) { // For Archive page
				if ( $woocommerce_archive_page_layout == 'right_sidebar' ) {
					get_sidebar( 'woocommerce-right' );
				} elseif ( $woocommerce_archive_page_layout == 'left_sidebar' ) {
					get_sidebar( 'woocommerce-left' );
				}
			} elseif ( is_product() ) { // For Single product page
				if ( $woocommerce_single_product_page_layout == 'right_sidebar' ) {
					get_sidebar( 'woocommerce-right' );
				} elseif ( $woocommerce_single_product_page_layout == 'left_sidebar' ) {
					get_sidebar( 'woocommerce-left' );
				}
			}
		}
	}
}

/**
 * Making the theme Woocommrece compatible
 */
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20, 0 );
// Remove WooCommerce default sidebar
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

add_filter( 'woocommerce_show_page_title', '__return_false' );

add_action( 'woocommerce_before_main_content', 'colormag_wrapper_start', 10 );
add_action( 'woocommerce_after_main_content', 'colormag_wrapper_end', 10 );

function colormag_wrapper_start() {
	echo '<section id="primary">';
}

function colormag_wrapper_end() {
	echo '</section>';

	if ( get_theme_mod( 'colormag_woocommerce_sidebar_register_setting', 0 ) == 1 ) {
		colormag_woocommerce_sidebar_select();
	} else {
		colormag_sidebar_select();
	}
}

// Displays the site logo
if ( ! function_exists( 'colormag_the_custom_logo' ) ) {

	/**
	 * Displays the optional custom logo.
	 */
	function colormag_the_custom_logo() {
		if ( function_exists( 'the_custom_logo' ) && ( get_theme_mod( 'colormag_logo', '' ) == '' ) ) {
			the_custom_logo();
		}
	}

}

/**
 * Migrate any existing theme CSS codes added in Customize Options to the core option added in WordPress 4.7
 */
function colormag_custom_css_migrate() {
	if ( function_exists( 'wp_update_custom_css_post' ) ) {
		$custom_css = get_theme_mod( 'colormag_custom_css' );
		if ( $custom_css ) {
			$core_css = wp_get_custom_css(); // Preserve any CSS already added to the core option.
			$return   = wp_update_custom_css_post( $core_css . $custom_css );
			if ( ! is_wp_error( $return ) ) {
				// Remove the old theme_mod, so that the CSS is stored in only one place moving forward.
				remove_theme_mod( 'colormag_custom_css' );
			}
		}
	}
}

add_action( 'after_setup_theme', 'colormag_custom_css_migrate' );

/**
 * Function to transfer the Header Logo added in Customizer Options of theme to Site Logo in Site Identity section
 */
function colormag_site_logo_migrate() {
	if ( function_exists( 'the_custom_logo' ) && ! has_custom_logo( $blog_id = 0 ) ) {
		$logo_url = get_theme_mod( 'colormag_logo' );

		if ( $logo_url ) {
			$customizer_site_logo_id = attachment_url_to_postid( $logo_url );
			set_theme_mod( 'custom_logo', $customizer_site_logo_id );

			// Delete the old Site Logo theme_mod option.
			remove_theme_mod( 'colormag_logo' );
		}
	}
}

add_action( 'after_setup_theme', 'colormag_site_logo_migrate' );

/**
 * List of allowed social protocols in HTML attributes.
 *
 * @param  array $protocols Array of allowed protocols.
 *
 * @return array
 */
function colormag_allowed_social_protocols( $protocols ) {
	$social_protocols = array(
		'skype',
	);

	return array_merge( $protocols, $social_protocols );
}

add_filter( 'kses_allowed_protocols', 'colormag_allowed_social_protocols' );

/**
 * Get Weather Icon
 *
 * @param  $code weather code
 *
 * @return string weather icon class
 */
function colormag_get_weather_icon( $weather_code ) {
	if ( $weather_code <= 210 || ( $weather_code >= 230 && $weather_code <= 232 ) ) {
		return "wi-storm-showers";
	}
}

/**
 * Get Weather Color
 *
 * @param  $code weather code
 *
 * @return string HEX Color Code
 */
function colormag_get_weather_color( $weather_code ) {
	if ( substr( $weather_code, 0, 1 ) == "2" || substr( $weather_code, 0, 1 ) == "2" ) {
		return "#1B364F";
	} elseif ( substr( $weather_code, 0, 1 ) == "5" ) {
		return "#7F89A2";
	} elseif ( substr( $weather_code, 0, 1 ) == "6" OR $weather_code == 903 ) {
		return "#7E9EF3";
	} elseif ( $weather_code == 781 || $weather_code == 900 ) {
		return "#666C7A";
	} elseif ( $weather_code == 800 || $weather_code == 904 ) {
		return "#628EFB";
	} elseif ( substr( $weather_code, 0, 1 ) == "7" ) {
		return "#628EFB";
	} elseif ( substr( $weather_code, 0, 1 ) == "8" ) {
		return "#AAB4CD";
	} elseif ( $weather_code == 901 || $weather_code == 902 OR $weather_code == 962 ) {
		return "#666C7A";
	} elseif ( $weather_code == 905 ) {
		return "#81A4FE";
	} elseif ( $weather_code == 906 ) {
		return "#81A4FE";
	} elseif ( $weather_code == 951 ) {
		return "#628EFB";
	} elseif ( $weather_code > 951 AND $weather_code < 962 ) {
		return "##628EFB";
	}
}

/**
 * Get available currencies for fixer.io api
 *
 * @return array
 */
function colormag_get_available_currencies() {
	return
		array(
			'eur' => 'Euro Member Countries',
			'aud' => 'Australian Dollar',
			'bgn' => 'Bulgarian Lev',
			'brl' => 'Brazilian Real',
			'cad' => 'Canadian Dollar',
			'chf' => 'Swiss Franc',
			'cny' => 'Chinese Yuan Renminbi',
			'czk' => 'Czech Republic Koruna',
			'dkk' => 'Danish Krone',
			'gbp' => 'British Pound',
			'hkd' => 'Hong Kong Dollar',
			'hrk' => 'Croatian Kuna',
			'huf' => 'Hungarian Forint',
			'idr' => 'Indonesian Rupiah',
			'ils' => 'Israeli Shekel',
			'inr' => 'Indian Rupee',
			'jpy' => 'Japanese Yen',
			'krw' => 'Korean (South) Won',
			'mxn' => 'Mexican Peso',
			'myr' => 'Malaysian Ringgit',
			'nok' => 'Norwegian Krone',
			'nzd' => 'New Zealand Dollar',
			'php' => 'Philippine Peso',
			'pln' => 'Polish Zloty',
			'ron' => 'Romanian (New) Leu',
			'rub' => 'Russian Ruble',
			'sek' => 'Swedish Krona',
			'sgd' => 'Singapore Dollar',
			'thb' => 'Thai Baht',
			'try' => 'Turkish Lira',
			'usd' => 'United States Dollar',
			'zar' => 'South African Rand',
		);
}

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
            <li <?php comment_class(); ?> id="li-comment-<?php comment_ID(); ?>"<?php echo colormag_schema_markup( 'comment' ); ?>>
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

	