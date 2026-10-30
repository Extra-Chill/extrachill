<?php
/**
 * Standalone multisite checks for main-term artist binding deletion.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- Standalone WordPress multisite mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ec_test_actions'] = array();

function add_action( string $hook, string $callback, int $priority, int $accepted_args ): void {
	$GLOBALS['ec_test_actions'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
}

function ec_get_blog_id( string $key ): int {
	return array(
		'main'   => 1,
		'artist' => 4,
	)[ $key ] ?? 0;
}

function get_current_blog_id(): int {
	return $GLOBALS['ec_test']['current_blog_id'];
}

function switch_to_blog( int $blog_id ): void {
	$GLOBALS['ec_test']['blog_stack'][]    = $GLOBALS['ec_test']['current_blog_id'];
	$GLOBALS['ec_test']['current_blog_id'] = $blog_id;
}

function restore_current_blog(): void {
	$GLOBALS['ec_test']['current_blog_id'] = array_pop( $GLOBALS['ec_test']['blog_stack'] );
}

function get_term_meta( int $term_id, string $key, bool $single ) {
	return $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['term_meta'][ $term_id ][ $key ] ?? ( $single ? '' : array() );
}

function get_post( int $post_id ) {
	return $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $post_id ] ?? null;
}

function get_post_meta( int $post_id, string $key, bool $single ) {
	return $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
}

function delete_post_meta( int $post_id, string $key, $value ): bool {
	$current = get_post_meta( $post_id, $key, true );
	if ( (string) $current !== (string) $value ) {
		return false;
	}

	unset( $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $post_id ][ $key ] );
	$GLOBALS['ec_test']['deletions'][] = array( get_current_blog_id(), $post_id, $key, $value );
	return true;
}

function get_posts( array $args ): array {
	$GLOBALS['ec_test']['queries'][] = array( get_current_blog_id(), $args );
	$matches                         = array();

	foreach ( $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'] as $post_id => $post ) {
		if ( $args['post_type'] !== $post->post_type ) {
			continue;
		}
		if ( 'any' === $args['post_status'] && in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			continue;
		}
		if ( is_array( $args['post_status'] ) && ! in_array( $post->post_status, $args['post_status'], true ) ) {
			continue;
		}

		$value = get_post_meta( $post_id, $args['meta_key'], true );
		if ( (string) $value === (string) $args['meta_value'] ) {
			$matches[] = (int) $post_id;
		}
	}

	return array_slice( $matches, 0, $args['posts_per_page'] );
}

require_once dirname( __DIR__ ) . '/inc/integrations/artist-term-binding-deletion.php';

function ec_test_reset( int $current_blog_id = 1 ): void {
	$GLOBALS['ec_test'] = array(
		'current_blog_id' => $current_blog_id,
		'blog_stack'      => array(),
		'deletions'       => array(),
		'queries'         => array(),
		'blogs'           => array(
			1 => array(
				'posts'     => array(),
				'post_meta' => array(),
				'term_meta' => array(),
			),
			4 => array(
				'posts'     => array(),
				'post_meta' => array(),
				'term_meta' => array(),
			),
		),
	);
}

function ec_test_add_profile( int $profile_id, int $term_id = 0, string $post_status = 'publish' ): void {
	$GLOBALS['ec_test']['blogs'][4]['posts'][ $profile_id ] = (object) array(
		'ID'          => $profile_id,
		'post_type'   => 'artist_profile',
		'post_status' => $post_status,
	);
	if ( $term_id > 0 ) {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][ $profile_id ]['_artist_term_id'] = $term_id;
	}
}

function ec_test_add_artist_post( int $post_id, string $post_type, string $post_status = 'publish' ): void {
	$GLOBALS['ec_test']['blogs'][4]['posts'][ $post_id ] = (object) array(
		'ID'          => $post_id,
		'post_type'   => $post_type,
		'post_status' => $post_status,
	);
}

function ec_test_profile_term_id( int $profile_id ) {
	return $GLOBALS['ec_test']['blogs'][4]['post_meta'][ $profile_id ]['_artist_term_id'] ?? '';
}

function ec_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

ec_test_assert(
	array(
		'hook'          => 'pre_delete_term',
		'callback'      => 'extrachill_network_delete_artist_term_profile_binding',
		'priority'      => 10,
		'accepted_args' => 2,
	) === $GLOBALS['ec_test_actions'][0],
	'Cleanup must run before WordPress deletes term metadata.'
);

ec_test_reset();
ec_test_add_profile( 25, 101 );
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( '' === ec_test_profile_term_id( 25 ), 'A reciprocal profile binding must be removed.' );
ec_test_assert( array() === $GLOBALS['ec_test']['queries'], 'A reciprocal profile binding must use the fast path.' );
ec_test_assert( 1 === get_current_blog_id(), 'Reciprocal cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_profile( 25, 101 );
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( '' === ec_test_profile_term_id( 25 ), 'A unique profile-only stale binding must be reconciled.' );
ec_test_assert( 4 === $GLOBALS['ec_test']['queries'][0][0], 'The inverse lookup must run on the Artist blog.' );
ec_test_assert( 2 === $GLOBALS['ec_test']['queries'][0][1]['posts_per_page'], 'The inverse lookup must detect ambiguity.' );

ec_test_reset();
ec_test_add_profile( 25, 101, 'trash' );
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( '' === ec_test_profile_term_id( 25 ), 'A trashed profile must not retain a stale term binding.' );
ec_test_assert( in_array( 'trash', $GLOBALS['ec_test']['queries'][0][1]['post_status'], true ), 'The inverse query must explicitly include trash.' );
ec_test_assert( 1 === get_current_blog_id(), 'Trashed-profile cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_profile( 25, 101, 'auto-draft' );
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( '' === ec_test_profile_term_id( 25 ), 'An auto-draft profile must not retain a stale term binding.' );
ec_test_assert( in_array( 'auto-draft', $GLOBALS['ec_test']['queries'][0][1]['post_status'], true ), 'The inverse query must explicitly include auto-draft.' );
ec_test_assert( 1 === get_current_blog_id(), 'Auto-draft cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_profile( 25, 202 );
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( 202 === ec_test_profile_term_id( 25 ), 'A conflicting reciprocal reference must fail closed.' );
ec_test_assert( 1 === get_current_blog_id(), 'Non-reciprocal cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_profile( 25, 202 );
ec_test_add_profile( 26, 101 );
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( 202 === ec_test_profile_term_id( 25 ), 'A non-reciprocal referenced profile must remain untouched.' );
ec_test_assert( '' === ec_test_profile_term_id( 26 ), 'A unique inverse must be removed when the populated reference is non-reciprocal.' );
ec_test_assert( 1 === get_current_blog_id(), 'Unique inverse cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_profile( 25 );
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( array() === $GLOBALS['ec_test']['deletions'], 'A term-only stale binding must not mutate the unbound profile.' );

ec_test_reset();
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( array() === $GLOBALS['ec_test']['deletions'], 'A deleted profile must not cause another deletion.' );
ec_test_assert( 1 === get_current_blog_id(), 'Deleted-profile cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_profile( 26, 101 );
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( '' === ec_test_profile_term_id( 26 ), 'A unique inverse must be removed when the populated reference is missing.' );
ec_test_assert( 1 === get_current_blog_id(), 'Missing-reference inverse cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_artist_post( 25, 'post' );
ec_test_add_profile( 26, 101 );
$GLOBALS['ec_test']['blogs'][1]['posts'][25] = (object) array(
	'ID'          => 25,
	'post_type'   => 'artist_profile',
	'post_status' => 'publish',
);
$GLOBALS['ec_test']['blogs'][1]['post_meta'][25]['_artist_term_id']       = 101;
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( '' === ec_test_profile_term_id( 26 ), 'A unique inverse must be removed when the referenced Artist-blog post has the wrong type.' );
ec_test_assert( 101 === $GLOBALS['ec_test']['blogs'][1]['post_meta'][25]['_artist_term_id'], 'A colliding main-blog post ID must not be mutated.' );
ec_test_assert( 1 === get_current_blog_id(), 'Wrong-type cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_profile( 25, 101 );
ec_test_add_profile( 26, 101 );
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( 101 === ec_test_profile_term_id( 25 ), 'Ambiguous profile-only bindings must remain untouched.' );
ec_test_assert( 101 === ec_test_profile_term_id( 26 ), 'Every conflicting profile binding must remain untouched.' );

ec_test_reset();
ec_test_add_profile( 25, 202 );
ec_test_add_profile( 26, 101 );
ec_test_add_profile( 27, 101 );
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( 202 === ec_test_profile_term_id( 25 ), 'An unrelated referenced profile must remain untouched during ambiguous cleanup.' );
ec_test_assert( 101 === ec_test_profile_term_id( 26 ), 'The first ambiguous inverse must remain untouched.' );
ec_test_assert( 101 === ec_test_profile_term_id( 27 ), 'The second ambiguous inverse must remain untouched.' );
ec_test_assert( array() === $GLOBALS['ec_test']['deletions'], 'A stale populated reference with ambiguous inverses must fail closed.' );
ec_test_assert( 1 === get_current_blog_id(), 'Ambiguous inverse cleanup must restore the main blog.' );

ec_test_reset();
ec_test_add_profile( 25, 101 );
ec_test_add_profile( 26, 101 );
$GLOBALS['ec_test']['blogs'][1]['term_meta'][101]['_artist_profile_id'] = 25;
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( '' === ec_test_profile_term_id( 25 ), 'The exact reciprocal profile must be cleaned during a conflict.' );
ec_test_assert( 101 === ec_test_profile_term_id( 26 ), 'A conflicting one-sided profile must not be touched.' );

ec_test_reset();
ec_test_add_profile( 25, 101 );
$GLOBALS['ec_test']['blogs'][1]['posts'][25] = (object) array(
	'ID'          => 25,
	'post_type'   => 'post',
	'post_status' => 'publish',
);
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( '' === ec_test_profile_term_id( 25 ), 'A colliding main-blog post ID must not prevent Artist-side cleanup.' );
ec_test_assert( array() === $GLOBALS['ec_test']['blogs'][1]['post_meta'], 'A colliding main-blog post must never be mutated.' );

ec_test_reset( 4 );
ec_test_add_profile( 25, 101 );
extrachill_network_delete_artist_term_profile_binding( 101, 'artist' );
ec_test_assert( 101 === ec_test_profile_term_id( 25 ), 'Deletion outside the main blog must be ignored.' );
ec_test_assert( 4 === get_current_blog_id(), 'Every early return must preserve caller blog context.' );
ec_test_assert( array() === $GLOBALS['ec_test']['blog_stack'], 'Every path must leave the blog stack balanced.' );

fwrite( STDOUT, "ArtistTermBindingDeletionTest passed.\n" );
