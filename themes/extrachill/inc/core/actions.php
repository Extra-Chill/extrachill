<?php
/**
 * Centralized Action Hook Registration
 *
 * Default handlers for extensible template hooks throughout theme.
 * Plugins can override by hooking at different priorities or replacing handlers.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

function extrachill_default_below_copyright() {
	include get_template_directory() . '/inc/footer/footer-bottom-menu.php';
}
add_action( 'extrachill_below_copyright', 'extrachill_default_below_copyright', 10 );

function extrachill_hook_taxonomy_badges_above_title() {
	$post_id = get_the_ID();
	extrachill_display_taxonomy_badges( false !== $post_id ? $post_id : null );
}
add_action( 'extrachill_above_post_title', 'extrachill_hook_taxonomy_badges_above_title' );

/**
 * Render the comments section through core's comments_template() so the
 * comment query runs and have_comments()/wp_list_comments() see the post's
 * approved comments. Requiring the template directly skips that query and
 * leaves the list empty.
 */
function extrachill_hook_comments_section() {
	comments_template( '/inc/single/comments.php' );
}
add_action( 'extrachill_comments_section', 'extrachill_hook_comments_section' );

/**
 * Theme default for the comment form heading. Supplied as a
 * comment_form_defaults value rather than a comment_form() argument so
 * plugins can retitle the form on a later priority.
 *
 * @param array $defaults Comment form defaults.
 * @return array
 */
function extrachill_comment_form_defaults( $defaults ) {
	$defaults['title_reply'] = __( 'Leave a Comment', 'extrachill' );
	return $defaults;
}
add_filter( 'comment_form_defaults', 'extrachill_comment_form_defaults' );

function extrachill_default_archive_header() {
	include get_template_directory() . '/inc/archives/archive-header.php';
}
add_action( 'extrachill_archive_header', 'extrachill_default_archive_header', 10 );

function extrachill_default_search_header() {
	include get_template_directory() . '/inc/archives/archive-header.php';
}
add_action( 'extrachill_search_header', 'extrachill_default_search_header', 10 );

function extrachill_render_secondary_header() {
	include get_template_directory() . '/inc/header/secondary-header.php';
}
add_action( 'extrachill_after_header', 'extrachill_render_secondary_header', 5 );
