<?php
/**
 * Mediavine Blocklist Rules (Blog)
 *
 * Blog-only ad blocking rules for extrachill.com.
 *
 * Block ads on:
 * - the homepage
 * - all pages
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

	if ( empty( $context['blog_id'] ) || (int) $context['blog_id'] !== 1 ) {
		return false;
	}

	if ( ! empty( $context['is_front_page'] ) ) {
		return true;
	}

	if ( ! empty( $context['is_page'] ) ) {
		return true;
	}

	if ( ! empty( $context['is_search'] ) ) {
		return true;
	}

	return false;
}
add_filter( 'extrachill_should_block_ads', 'extrachill_blog_should_block_ads', 10, 2 );
