<?php
/**
 * Mediavine Blocklist Rules (Blog)
 *
 * Blog-only ad blocking rules for extrachill.com.
 *
 * Block ads on:
 * - the homepage
 * - all pages, unless a feature opts one in via `extrachill_page_allows_ads`
 * - search results
 *
 * Allow ads everywhere else (including all archives).
 * Ad blocking output is handled globally by extrachill-users.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_blog_should_block_ads( $should_block_ads, $context ) {
	if ( $should_block_ads ) {
		return true;
	}

	if ( empty( $context['blog_id'] ) || 1 !== (int) $context['blog_id'] ) {
		return false;
	}

	if ( ! empty( $context['is_front_page'] ) ) {
		return true;
	}

	if ( ! empty( $context['is_page'] ) ) {
		$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		/**
		 * Let a feature opt a specific main-site page back into ads.
		 *
		 * @param bool                 $allows  Whether this page may show ads. Default false.
		 * @param int                  $post_id Queried page ID, or 0.
		 * @param array<string, mixed> $context Ad request context.
		 */
		return ! ( $post_id > 0 && (bool) apply_filters( 'extrachill_page_allows_ads', false, $post_id, $context ) );
	}

	if ( ! empty( $context['is_search'] ) ) {
		return true;
	}

	return false;
}
add_filter( 'extrachill_should_block_ads', 'extrachill_blog_should_block_ads', 10, 2 );
