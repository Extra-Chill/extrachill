<?php
/**
 * Shared post/term fixture lookup for standalone harnesses.
 *
 * Several standalone test files (DoorAccessTest, PromoterAuthorityTest, the
 * extrachill-booking BookingTestHarness) each guard a `get_post()` /
 * `wp_get_post_terms()` stub with `function_exists()`. When they share one
 * PHPUnit process (phpunit-membership.xml.dist), whichever file loads first
 * wins and the other files' fixtures become invisible to production code. The
 * stubs delegate here so every harness's seeded posts and terms are visible
 * regardless of load order.
 *
 * Precedence: event_management_test (always torn down) before promoter_test
 * (reset in setUp, never torn down), then the blog-scoped ec_artist_test.
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! function_exists( 'ec_events_test_fixture_post' ) ) {
	/**
	 * Seeded post for an ID, from whichever harness fixture holds it.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	function ec_events_test_fixture_post( $post_id ) {
		if ( isset( $GLOBALS['event_management_test']['posts'][ $post_id ] ) ) {
			return $GLOBALS['event_management_test']['posts'][ $post_id ];
		}
		if ( isset( $GLOBALS['promoter_test']['posts'][ $post_id ] ) ) {
			return $GLOBALS['promoter_test']['posts'][ $post_id ];
		}
		$state = $GLOBALS['ec_artist_test'] ?? null;
		if ( is_array( $state ) && isset( $state['blog_id'] ) ) {
			return $state['posts'][ $state['blog_id'] ][ $post_id ] ?? null;
		}
		return null;
	}
}

if ( ! function_exists( 'ec_events_test_fixture_post_terms' ) ) {
	/**
	 * Seeded terms for a post and taxonomy, from whichever harness fixture holds them.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	function ec_events_test_fixture_post_terms( $post_id, $taxonomy ) {
		if ( isset( $GLOBALS['event_management_test']['posts'][ $post_id ] ) ) {
			return $GLOBALS['event_management_test']['terms'][ $post_id ][ $taxonomy ] ?? array();
		}
		return $GLOBALS['promoter_test']['post_terms'][ $post_id ][ $taxonomy ] ?? array();
	}
}
