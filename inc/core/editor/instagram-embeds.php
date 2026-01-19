<?php
/**
 * Instagram Embed Handler
 * Custom embed functionality for Instagram posts, reels, and profiles
 *
 * @package ExtraChill
 * @since 1.0
 */

function custom_instagram_embed_handler( $matches, $attr, $url, $rawattr ) {
	if ( preg_match( '#https?://(www\.)?instagram\.com/[a-zA-Z0-9_.-]+/?$#i', $url ) ) {
		$embed = sprintf(
			'<blockquote class="instagram-media" data-instgrm-permalink="%s" data-instgrm-version="14" style=" background:#FFF; border:0; border-radius:3px; box-shadow:0 0 1px 0 rgba(0,0,0,0.5),0 1px 10px 0 rgba(0,0,0,0.15); margin: 1px; max-width:540px; min-width:326px; padding:0; width:99.375%%; width:-webkit-calc(100%% - 2px); width:calc(100%% - 2px);"><a href="%s" target="_blank"></a></blockquote><script async src="//www.instagram.com/embed.js"></script>',
			esc_url( $url ),
			esc_url( $url )
		);
	} else {
		$embed = sprintf(
			'<iframe src="%s/embed" width="400" height="500" frameborder="0" scrolling="no" allowtransparency="true"></iframe>',
			esc_url( $matches[0] )
		);
	}

	return apply_filters( 'custom_instagram_embed', $embed, $embed, $matches, $attr, $url, $rawattr );
}

function register_custom_instagram_embed_handler() {
	wp_embed_register_handler(
		'instagram',
		'#https?://(www\.)?instagram\.com/(p|reel)/[a-zA-Z0-9_-]+#i',
		'custom_instagram_embed_handler'
	);

	wp_embed_register_handler(
		'instagram_profile',
		'#https?://(www\.)?instagram\.com/[a-zA-Z0-9_.-]+/?$#i',
		'custom_instagram_embed_handler'
	);
}
add_action( 'init', 'register_custom_instagram_embed_handler' );
