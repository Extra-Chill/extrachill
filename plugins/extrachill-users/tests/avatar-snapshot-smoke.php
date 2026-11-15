<?php
/**
 * Standalone coverage of the avatar URL snapshot: php tests/avatar-snapshot-smoke.php
 *
 * @package ExtraChill\Users
 */

define( 'ABSPATH', __DIR__ );

$avatar_smoke = array(
	'blog'     => 1,
	'switches' => 0,
	'lookups'  => 0,
	'meta'     => array(),
	'images'   => array( 10, 20, 30, 31, 40, 50, 81, 82, 83 ),
);

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function esc_url( $value ) { return $value; }
function esc_attr( $value ) { return $value; }
function is_email( $value ) { return false; }
function ec_get_blog_id( $key ) { return 'community' === $key ? 2 : 0; }
function get_current_blog_id() { return $GLOBALS['avatar_smoke']['blog']; }
function switch_to_blog( $blog_id ) {
	++$GLOBALS['avatar_smoke']['switches'];
	$GLOBALS['avatar_smoke']['previous'] = $GLOBALS['avatar_smoke']['blog'];
	$GLOBALS['avatar_smoke']['blog']     = $blog_id;
}
function restore_current_blog() {
	$GLOBALS['avatar_smoke']['blog'] = $GLOBALS['avatar_smoke']['previous'];
}
function get_user_by( $field, $value ) { return (object) array( 'ID' => (int) $value ); }
function get_user_meta( $user_id, $key, $single ) { return $GLOBALS['avatar_smoke']['meta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['avatar_smoke']['meta'][ $user_id ][ $key ] = $value; }
function delete_user_meta( $user_id, $key ) { unset( $GLOBALS['avatar_smoke']['meta'][ $user_id ][ $key ] ); }
function wp_attachment_is_image( $id ) {
	avatar_smoke_check( 2 === get_current_blog_id(), 'Attachment lookups must run on the community blog.' );
	return in_array( (int) $id, $GLOBALS['avatar_smoke']['images'], true );
}
function wp_get_attachment_image_url( $id, $size ) {
	avatar_smoke_check( 2 === get_current_blog_id(), 'URL lookups must run on the community blog.' );
	++$GLOBALS['avatar_smoke']['lookups'];
	return 'https://community.example/' . $id . '-' . $size . '.jpg';
}
function get_users( $args ) {
	avatar_smoke_check( 0 === $args['blog_id'], 'Avatar user lookups must span the network.' );
	$ids = array();
	foreach ( $GLOBALS['avatar_smoke']['meta'] as $user_id => $meta ) {
		if ( ! isset( $meta['custom_avatar_id'] ) ) {
			continue;
		}
		if ( isset( $args['meta_value'] ) && (string) $meta['custom_avatar_id'] !== $args['meta_value'] ) {
			continue;
		}
		$ids[] = $user_id;
	}
	sort( $ids );
	if ( isset( $args['number'] ) ) {
		$ids = array_slice( $ids, $args['offset'], $args['number'] );
	}
	return $ids;
}
function avatar_smoke_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		exit( 1 );
	}
}
function avatar_smoke_snapshot( $attachment_id ) {
	return array(
		'attachment_id' => $attachment_id,
		'urls'          => array(
			'thumbnail' => 'https://community.example/' . $attachment_id . '-thumbnail.jpg',
			'medium'    => 'https://community.example/' . $attachment_id . '-medium.jpg',
			'large'     => 'https://community.example/' . $attachment_id . '-large.jpg',
		),
	);
}
/**
 * @return mixed
 */
function avatar_smoke_state( $key ) { return $GLOBALS['avatar_smoke'][ $key ]; }
/**
 * @return mixed
 */
function avatar_smoke_meta( $user_id, $key ) { return $GLOBALS['avatar_smoke']['meta'][ $user_id ][ $key ] ?? null; }
function avatar_smoke_set_meta( $user_id, $key, $value ) { $GLOBALS['avatar_smoke']['meta'][ $user_id ][ $key ] = $value; }
function avatar_smoke_unset_meta( $user_id, $key ) { unset( $GLOBALS['avatar_smoke']['meta'][ $user_id ][ $key ] ); }
function avatar_smoke_set_blog( $blog_id ) { $GLOBALS['avatar_smoke']['blog'] = $blog_id; }
function avatar_smoke_reset_counters() {
	$GLOBALS['avatar_smoke']['switches'] = 0;
	$GLOBALS['avatar_smoke']['lookups']  = 0;
}

require dirname( __DIR__ ) . '/inc/avatar-snapshot.php';
require dirname( __DIR__ ) . '/inc/avatar-display.php';

// Read hit: a fresh snapshot renders with no switch and no attachment lookup.
avatar_smoke_set_meta( 1, 'custom_avatar_id', 10 );
avatar_smoke_set_meta( 1, 'ec_avatar_urls', avatar_smoke_snapshot( 10 ) );
avatar_smoke_reset_counters();
$data = extrachill_custom_avatar_data( array( 'size' => 96 ), 1 );
$html = extrachill_custom_avatar( null, 1, array( 'size' => 96 ) );
avatar_smoke_check( 'https://community.example/10-thumbnail.jpg' === $data['url'] && true === $data['found_avatar'], 'Snapshot hit serves the thumbnail URL.' );
avatar_smoke_check( false !== strpos( $html, 'srcset="https://community.example/10-thumbnail.jpg 1x, https://community.example/10-medium.jpg 2x"' ), 'Snapshot hit serves the 2x srcset.' );
avatar_smoke_check( 0 === avatar_smoke_state( 'switches' ) && 0 === avatar_smoke_state( 'lookups' ), 'Snapshot hit must not switch blogs.' );

// Miss: falls back to one community switch and lazily writes the snapshot.
avatar_smoke_set_meta( 2, 'custom_avatar_id', 20 );
avatar_smoke_reset_counters();
$data = extrachill_custom_avatar_data( array( 'size' => 500 ), 2 );
extrachill_custom_avatar( null, 2, array( 'size' => 96 ) );
avatar_smoke_check( 'https://community.example/20-large.jpg' === $data['url'], 'Snapshot miss still serves the avatar.' );
avatar_smoke_check( 1 === avatar_smoke_state( 'switches' ) && 1 === avatar_smoke_state( 'blog' ), 'Snapshot miss switches once and restores the blog.' );
avatar_smoke_check( avatar_smoke_snapshot( 20 ) === avatar_smoke_meta( 2, 'ec_avatar_urls' ), 'Snapshot miss lazily writes the snapshot.' );
avatar_smoke_check( avatar_smoke_snapshot( 20 ) === extrachill_users_get_avatar_snapshot( 2 ), 'Lazily written snapshot reads back.' );

// Stale: a snapshot built from a replaced attachment is rebuilt.
avatar_smoke_set_meta( 3, 'custom_avatar_id', 31 );
avatar_smoke_set_meta( 3, 'ec_avatar_urls', avatar_smoke_snapshot( 30 ) );
avatar_smoke_check( null === extrachill_users_get_avatar_snapshot( 3 ), 'Snapshot from another attachment reads as stale.' );
avatar_smoke_reset_counters();
$data = extrachill_custom_avatar_data( array( 'size' => 300 ), 3 );
avatar_smoke_check( 'https://community.example/31-medium.jpg' === $data['url'], 'Stale snapshot serves the current attachment.' );
avatar_smoke_check( 1 === avatar_smoke_state( 'switches' ) && avatar_smoke_snapshot( 31 ) === avatar_smoke_meta( 3, 'ec_avatar_urls' ), 'Stale snapshot is rebuilt.' );

// Unassign: refreshing after custom_avatar_id is removed clears the snapshot.
avatar_smoke_set_meta( 4, 'custom_avatar_id', 40 );
avatar_smoke_set_meta( 4, 'ec_avatar_urls', avatar_smoke_snapshot( 40 ) );
avatar_smoke_unset_meta( 4, 'custom_avatar_id' );
avatar_smoke_reset_counters();
avatar_smoke_check( null === extrachill_users_refresh_avatar_snapshot( 4 ), 'Unassigned refresh returns null.' );
avatar_smoke_check( null === avatar_smoke_meta( 4, 'ec_avatar_urls' ) && 0 === avatar_smoke_state( 'switches' ), 'Unassigned refresh deletes the snapshot without switching.' );
$data = extrachill_custom_avatar_data( array( 'size' => 96 ), 4 );
avatar_smoke_check( ! isset( $data['url'] ) && 0 === avatar_smoke_state( 'switches' ), 'User without an avatar falls back to Gravatar without switching.' );

// Assign while already on community: refresh does not switch.
avatar_smoke_set_meta( 4, 'custom_avatar_id', 40 );
avatar_smoke_set_blog( 2 );
avatar_smoke_reset_counters();
avatar_smoke_check( avatar_smoke_snapshot( 40 ) === extrachill_users_refresh_avatar_snapshot( 4 ), 'Assign refresh writes the snapshot.' );
avatar_smoke_check( 0 === avatar_smoke_state( 'switches' ), 'Refresh on the community blog does not switch.' );

// delete_attachment / edits: only community attachments invalidate.
avatar_smoke_set_meta( 5, 'custom_avatar_id', 50 );
avatar_smoke_set_meta( 5, 'ec_avatar_urls', avatar_smoke_snapshot( 50 ) );
avatar_smoke_set_blog( 1 );
extrachill_users_invalidate_avatar_snapshots( 50 );
avatar_smoke_check( null !== avatar_smoke_meta( 5, 'ec_avatar_urls' ), 'Attachment deletion on another blog leaves the snapshot.' );
avatar_smoke_set_blog( 2 );
extrachill_users_invalidate_avatar_snapshots_on_meta( 1, 50, '_edit_lock' );
avatar_smoke_check( null !== avatar_smoke_meta( 5, 'ec_avatar_urls' ), 'Unrelated attachment meta leaves the snapshot.' );
extrachill_users_invalidate_avatar_snapshots( 50 );
avatar_smoke_check( null === avatar_smoke_meta( 5, 'ec_avatar_urls' ) && null !== avatar_smoke_meta( 4, 'ec_avatar_urls' ), 'Community attachment deletion clears only its users.' );
avatar_smoke_set_meta( 5, 'ec_avatar_urls', avatar_smoke_snapshot( 50 ) );
extrachill_users_invalidate_avatar_snapshots_on_meta( 1, 50, '_wp_attachment_metadata' );
avatar_smoke_check( null === avatar_smoke_meta( 5, 'ec_avatar_urls' ), 'Attachment metadata edits clear the snapshot.' );
avatar_smoke_set_blog( 1 );

// A non-image attachment is snapshotted as "no URLs" so it stops switching.
avatar_smoke_set_meta( 6, 'custom_avatar_id', 60 );
avatar_smoke_reset_counters();
$data = extrachill_custom_avatar_data( array( 'size' => 96 ), 6 );
avatar_smoke_check( ! isset( $data['url'] ) && 1 === avatar_smoke_state( 'switches' ), 'Non-image avatar falls back to Gravatar.' );
avatar_smoke_check( array() === extrachill_users_get_avatar_snapshot( 6 )['urls'], 'Non-image avatar is snapshotted with no URLs.' );

// Backfill: dry run counts, real run writes with one switch per batch.
avatar_smoke_set_meta( 81, 'custom_avatar_id', 81 );
avatar_smoke_set_meta( 82, 'custom_avatar_id', 82 );
avatar_smoke_set_meta( 83, 'custom_avatar_id', 83 );
avatar_smoke_reset_counters();
$report = extrachill_users_backfill_avatar_snapshots(
	array(
		'batch_size' => 2,
		'dry_run'    => true,
	)
);
avatar_smoke_check( 9 === $report['scanned'] && 4 === $report['would_write'] && 0 === $report['written'] && 5 === $report['fresh'], 'Dry run counts missing snapshots.' );
avatar_smoke_check( 0 === avatar_smoke_state( 'switches' ) && null === avatar_smoke_meta( 81, 'ec_avatar_urls' ), 'Dry run writes nothing and does not switch.' );
$report = extrachill_users_backfill_avatar_snapshots(
	array(
		'batch_size' => 2,
		'limit'      => 8,
	)
);
avatar_smoke_check( 8 === $report['scanned'] && 3 === $report['written'] && 4 === $report['batches'], 'Backfill honours the limit and batch size.' );
avatar_smoke_check( 4 === avatar_smoke_state( 'switches' ) && 1 === avatar_smoke_state( 'blog' ), 'Backfill switches once per batch and restores the blog.' );
avatar_smoke_check( null === avatar_smoke_meta( 83, 'ec_avatar_urls' ), 'Backfill stops at the limit.' );
$report = extrachill_users_backfill_avatar_snapshots( array( 'batch_size' => 500 ) );
avatar_smoke_check( 9 === $report['scanned'] && 1 === $report['written'] && 1 === $report['batches'], 'Backfill resumes idempotently.' );
avatar_smoke_check( avatar_smoke_snapshot( 83 ) === avatar_smoke_meta( 83, 'ec_avatar_urls' ), 'Backfill writes the remaining snapshot.' );

echo "PASS: avatar snapshot reads without switching, rebuilds stale or missing snapshots, invalidates, and backfills.\n";
