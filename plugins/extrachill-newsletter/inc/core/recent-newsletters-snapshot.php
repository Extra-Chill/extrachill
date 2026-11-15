<?php
/**
 * Recent Newsletters Snapshot
 *
 * Keeps a network-readable snapshot of the latest published newsletters so the
 * sidebar on every network site can render them without switching blogs.
 *
 * Stored as a network (site) option rather than a global object-cache entry:
 * the snapshot must survive cache flushes and hosts without a persistent object
 * cache, otherwise every request would fall back to a cross-blog query.
 *
 * @package ExtraChillNewsletter
 * @since 0.11.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network option key holding the recent newsletters snapshot.
 */
define( 'EXTRACHILL_NEWSLETTER_RECENT_SNAPSHOT_OPTION', 'extrachill_newsletter_recent_snapshot' );

/**
 * Number of newsletters kept in the snapshot (matches the sidebar).
 */
define( 'EXTRACHILL_NEWSLETTER_RECENT_SNAPSHOT_LIMIT', 3 );

/**
 * Build the recent newsletters snapshot from the current blog.
 *
 * Must run while the newsletter blog is the current blog.
 *
 * @return array<int, array{title: string, permalink: string, date: string}> Snapshot items.
 */
function extrachill_newsletter_build_recent_snapshot() {
	$query = new WP_Query(
		array(
			'post_type'      => 'newsletter',
			'posts_per_page' => EXTRACHILL_NEWSLETTER_RECENT_SNAPSHOT_LIMIT,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	$items = array();
	foreach ( $query->posts as $newsletter_post ) {
		$newsletter_id = is_object( $newsletter_post ) ? (int) $newsletter_post->ID : (int) $newsletter_post;

		$items[] = array(
			'title'     => (string) get_the_title( $newsletter_id ),
			'permalink' => (string) get_permalink( $newsletter_id ),
			'date'      => (string) get_the_date( '', $newsletter_id ),
		);
	}

	return $items;
}

/**
 * Rebuild and store the snapshot. Must run on the newsletter blog.
 *
 * @return array Snapshot items.
 */
function extrachill_newsletter_refresh_recent_snapshot() {
	$items = extrachill_newsletter_build_recent_snapshot();
	update_site_option( EXTRACHILL_NEWSLETTER_RECENT_SNAPSHOT_OPTION, $items );

	return $items;
}

/**
 * Get the recent newsletters snapshot.
 *
 * Reads the network option. When it is missing, builds it once on the
 * newsletter blog (switching only if needed) and stores it.
 *
 * @return array<int, array{title: string, permalink: string, date: string}> Snapshot items.
 */
function extrachill_newsletter_get_recent_snapshot() {
	$snapshot = get_site_option( EXTRACHILL_NEWSLETTER_RECENT_SNAPSHOT_OPTION, false );
	if ( is_array( $snapshot ) ) {
		return $snapshot;
	}

	$newsletter_blog_id = (int) ec_get_blog_id( 'newsletter' );
	if ( ! $newsletter_blog_id ) {
		return array();
	}

	if ( get_current_blog_id() === $newsletter_blog_id ) {
		return extrachill_newsletter_refresh_recent_snapshot();
	}

	switch_to_blog( $newsletter_blog_id );
	try {
		$items = extrachill_newsletter_refresh_recent_snapshot();
	} finally {
		restore_current_blog();
	}

	return $items;
}

/**
 * Whether the current blog is the newsletter blog.
 *
 * @return bool
 */
function extrachill_newsletter_snapshot_on_newsletter_blog() {
	$newsletter_blog_id = (int) ec_get_blog_id( 'newsletter' );

	return $newsletter_blog_id && get_current_blog_id() === $newsletter_blog_id;
}

/**
 * Refresh the snapshot when a newsletter enters, leaves, or changes while published.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param mixed   $post       Post object (WP_Post in practice).
 */
function extrachill_newsletter_snapshot_on_transition( $new_status, $old_status, $post ) {
	if ( ! $post instanceof WP_Post || 'newsletter' !== $post->post_type ) {
		return;
	}

	if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
		return;
	}

	if ( ! extrachill_newsletter_snapshot_on_newsletter_blog() ) {
		return;
	}

	extrachill_newsletter_refresh_recent_snapshot();
}
add_action( 'transition_post_status', 'extrachill_newsletter_snapshot_on_transition', 10, 3 );

/**
 * Refresh the snapshot after a newsletter is permanently deleted.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    Post object.
 */
function extrachill_newsletter_snapshot_on_delete( $post_id, $post = null ) {
	if ( ! is_object( $post ) || 'newsletter' !== $post->post_type ) {
		return;
	}

	if ( ! extrachill_newsletter_snapshot_on_newsletter_blog() ) {
		return;
	}

	extrachill_newsletter_refresh_recent_snapshot();
}
add_action( 'deleted_post', 'extrachill_newsletter_snapshot_on_delete', 10, 2 );
