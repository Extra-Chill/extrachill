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
if ( ! function_exists( 'extrachill_entry_meta' ) ) :
    /**
     * Shows meta information of post.
     */
    function extrachill_entry_meta() {
        global $post;

        if ( 'post' === get_post_type() || ( isset( $post->_is_forum_post ) && $post->_is_forum_post ) ) {
            // Define human-readable time class if needed
            $human_diff_time = get_theme_mod( 'colormag_post_meta_date_setting', 'post_date' ) === 'post_human_readable_date' ? 'human-diff-time' : '';

            // Determine if this is a forum post
            $is_forum_post = isset( $post->_is_forum_post ) && $post->_is_forum_post;

            // Add an additional class for forum posts
            $forum_class = $is_forum_post ? ' forum-meta' : '';

            echo '<div class="below-entry-meta ' . esc_attr( $human_diff_time . $forum_class ) . '">';

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

            // Right-side location link
            if ( is_singular( 'post' ) && !$is_forum_post ) {
                $locations = get_the_terms( get_the_ID(), 'location' );
                if ( $locations && !is_wp_error( $locations ) ) {
                    foreach ( $locations as $location ) {
                        // Create a unique class based on location slug
                        $location_class = 'location-' . sanitize_html_class( $location->slug );

                        echo '<div class="post-location-meta ' . esc_attr( $location_class ) . '">';
                        echo '<a href="' . esc_url( get_term_link( $location ) ) . '" class="location-link">' . esc_html( $location->name ) . '</a>';
                        echo '</div>';
                    }
                }
            }

            echo '</div>'; // Close below-entry-meta div
        }
    }
endif;


	

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


/*
 * Adding the custom meta box for supporting the post formats
 */

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

	