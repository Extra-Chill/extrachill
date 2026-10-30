<?php
/**
 * Focused WordPress registration stubs for delegated campaign smoke coverage.
 *
 * @package ExtraChillNewsletter\Tests
 */

function __( $value ) {
	return $value;
}

function is_newsletter_site() {
	return 9 === get_current_blog_id();
}

function post_type_exists( $post_type ) {
	return isset( $GLOBALS['newsletter_test']['post_types'][ $post_type ] );
}

function get_post_stati() {
	return array_fill_keys( array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'custom-review' ), (object) array() );
}

function register_post_type( $post_type, $args ) {
	$GLOBALS['newsletter_test']['post_types'][ $post_type ] = $args;
	return (object) array( 'name' => $post_type );
}

function unregister_post_type( $post_type ) {
	unset( $GLOBALS['newsletter_test']['post_types'][ $post_type ] );
	return true;
}

function wp_register_agent( $slug, $args ) {
	$GLOBALS['newsletter_test']['agents'][ $slug ] = $args;
	return (object) array( 'slug' => $slug );
}
