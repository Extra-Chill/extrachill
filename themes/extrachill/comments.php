<?php
/**
 * The template for displaying Comments.
 *
 * The area of the page that contains both current comments
 * and the comment form. The actual display of comments is
 * handled by a callback to extrachill_comment() which is
 * located in the inc/single-post/comments.php file.
 *
 * @package ExtraChill
 * @since 1.0
 */

/*
 * If the current post is protected by a password and
 * the visitor has not yet entered the password we will
 * return early without loading the comments.
 */
if ( post_password_required() )
	return;
?>

<div id="comments" class="comments-area">

	<?php // You can start editing here -- including this comment! ?>

	<?php if ( have_comments() ) : ?>
		<h2 class="comments-title">
			<?php
				printf( _nx( 'One comment on &ldquo;%2$s&rdquo;', '%1$s comments on <strong>%2$s</strong>', get_comments_number(), 'comments title', 'extrachill' ),
					number_format_i18n( get_comments_number() ), '<span>' . get_the_title() . '</span>' );
			?>
		</h2>

		<?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : // are there comments to navigate through ?>
		<nav id="comment-nav-above" class="comment-navigation clearfix" role="navigation">
			<h4 class="screen-reader-text"><?php _e( 'Comment navigation', 'extrachill' ); ?></h4>
			<div class="nav-previous"><?php previous_comments_link( __( '&larr; Older Comments', 'extrachill' ) ); ?></div>
			<div class="nav-next"><?php next_comments_link( __( 'Newer Comments &rarr;', 'extrachill' ) ); ?></div>
		</nav><!-- #comment-nav-above -->
		<?php endif; // check for comment navigation ?>

		<ul class="comment-list">
			<?php
				wp_list_comments( array(
					'callback'    => 'extrachill_comment',
					'short_ping'  => true
				) );
			?>
		</ul><!-- .comment-list -->

		<?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : // are there comments to navigate through ?>
		<nav id="comment-nav-below" class="comment-navigation clearfix" role="navigation">
			<h4 class="screen-reader-text"><?php _e( 'Comment navigation', 'extrachill' ); ?></h4>
			<div class="nav-previous"><?php previous_comments_link( __( '&larr; Older Comments', 'extrachill' ) ); ?></div>
			<div class="nav-next"><?php next_comments_link( __( 'Newer Comments &rarr;', 'extrachill' ) ); ?></div>
		</nav><!-- #comment-nav-below -->
		<?php endif; // check for comment navigation ?>

	<?php endif; // have_comments() ?>

	<?php
		// If comments are closed and there are comments, let's leave a little note, shall we?
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