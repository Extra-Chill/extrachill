<?php
/** Standalone regression coverage for the shared topic and reply upvote control. */

function add_action() {}

function get_current_user_id() {
	return $GLOBALS['upvote_test_user_id'];
}

function get_user_meta( $user_id, $key, $single ) {
	return array( 42 );
}

function get_post_meta( $post_id, $key, $single ) {
	return '3';
}

function add_query_arg( $args ) {
	return '/recent/?page=2&filter=topics';
}

function home_url( $path ) {
	return 'https://community.example.test' . $path;
}

function wp_login_url( $redirect ) {
	return 'https://community.example.test/wp-login.php?redirect_to=' . rawurlencode( $redirect );
}

function esc_attr( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES );
}

function esc_attr_e( $value, $domain ) {
	echo esc_attr( $value );
}

function esc_html( $value ) {
	return esc_attr( $value );
}

function esc_url( $value ) {
	return esc_attr( $value );
}

function ec_icon( $id ) {
	return '<svg><use href="#' . esc_attr( $id ) . '"></use></svg>';
}

require dirname( __DIR__ ) . '/inc/social/upvote.php';

function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function render_upvote( $user_id, $post_id, $type ) {
	$GLOBALS['upvote_test_user_id'] = $user_id;
	ob_start();
	extrachill_community_render_upvote( $post_id, $type );
	return ob_get_clean();
}

foreach ( array( 'topic' => 42, 'reply' => 43 ) as $type => $post_id ) {
	$guest = render_upvote( 0, $post_id, $type );
	check( 1 === preg_match( '/<a class="upvote-icon button-3 button-small" href="([^"]+)" aria-label="Log in to upvote">/', $guest, $link ), "Guest {$type} arrow is a labelled, styled link." );
	$url = parse_url( html_entity_decode( $link[1], ENT_QUOTES ) );
	parse_str( $url['query'] ?? '', $args );
	check( '/wp-login.php' === $url['path'] && 'https://community.example.test/recent/?page=2&filter=topics' === ( $args['redirect_to'] ?? '' ), "Guest {$type} link returns to the feed with its query parameters." );
	check( ! str_contains( $guest, 'data-post-id' ) && str_contains( $guest, 'href="#circle-up-outline"' ), "Guest {$type} link is not a voting control." );
	check( str_contains( $guest, '<span class="upvote-count">4</span>' ), "Guest {$type} count is still visible." );

	$member  = render_upvote( 7, $post_id, $type );
	$upvoted = 42 === $post_id;
	$state   = $upvoted ? 'true' : 'false';
	$icon    = $upvoted ? 'circle-up' : 'circle-up-outline';
	check( str_contains( $member, "<span class=\"upvote-icon\" data-post-id=\"{$post_id}\" data-type=\"{$type}\" data-upvoted=\"{$state}\">" ), "Member {$type} arrow retains its voting data." );
	check( str_contains( $member, 'href="#' . $icon . '"' ) && str_contains( $member, '<span class="upvote-count">4</span>' ), "Member {$type} icon and count still reflect the vote state." );
}

echo "All upvote control tests passed.\n";
