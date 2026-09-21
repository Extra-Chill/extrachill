<?php
/** Phased fixture helpers for the disposable MariaDB multisite CLI proof. */

if ( ! is_multisite() ) {
	throw new RuntimeException( 'Multisite was not enabled.' );
}

function parity_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
}

function parity_restore_root() {
	while ( ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
		restore_current_blog();
	}
}

function parity_site( $id, $domain ) {
	$site = get_site( $id );
	if ( ! $site ) {
		$created = wpmu_create_blog( $domain, '/', $domain, 1, array( 'public' => 0 ), 1 );
		parity_assert( ! is_wp_error( $created ) && $id === (int) $created, 'Site ID allocation mismatch for ' . $domain );
	}
}

function parity_attachment( $id, $parent, $name, $author ) {
	$uploads  = wp_upload_dir();
	$relative = ltrim( trailingslashit( $uploads['subdir'] ) . $name, '/' );
	$absolute = trailingslashit( $uploads['basedir'] ) . $relative;
	wp_mkdir_p( dirname( $absolute ) );
	file_put_contents( $absolute, 'main-' . $id );
	$attachment = wp_insert_attachment(
		array(
			'import_id'      => $id,
			'post_author'    => $author,
			'post_title'     => $name,
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		),
		$absolute,
		$parent,
		true
	);
	parity_assert( $id === $attachment, 'Attachment import ID mismatch.' );
	update_post_meta( $id, '_wp_attached_file', $relative );
	$companions = array( 'size-' . $name, 'original-' . $name, 'thumb-' . $name, 'source-' . $name, 'video-' . $name, 'poster-' . $name, 'backup-' . $name );
	foreach ( $companions as $file ) {
		file_put_contents( trailingslashit( dirname( $absolute ) ) . $file, $file . '-' . $id );
	}
	update_post_meta(
		$id,
		'_wp_attachment_metadata',
		array(
			'file'                  => $relative,
			'sizes'                 => array( 'small' => array( 'file' => $companions[0] ) ),
			'original_image'        => $companions[1],
			'thumb'                 => $companions[2],
			'source_image'          => $companions[3],
			'animated_video'        => $companions[4],
			'animated_video_poster' => $companions[5],
		)
	);
	update_post_meta( $id, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => $companions[6] ) ) );
}

function parity_source_snapshot() {
	global $wpdb;
	$ids   = array( 100, 101, 200, 201, 300, 301 );
	$posts = array();
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		$posts[ $id ] = $post ? ec_link_page_migration_post_fields( $post ) : null;
	}
	$meta = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (100,101,200,201,300,301) ORDER BY meta_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact disposable snapshot.
	return array( 'posts' => $posts, 'meta' => $meta );
}

function parity_assert_source_unchanged() {
	parity_assert( get_site_option( 'ec_parity_source_snapshot' ) === parity_source_snapshot(), 'Source posts or raw metadata changed.' );
}

function parity_setup_sites() {
	parity_restore_root();
	$domains = array(
		2 => 'community.fixture.test', 3 => 'shop.fixture.test', 4 => 'artist.fixture.test',
		5 => 'fixture-five.test', 6 => 'fixture-six.test', 7 => 'events.fixture.test',
		8 => 'fixture-eight.test', 9 => 'newsletter.fixture.test', 10 => 'docs.fixture.test',
		11 => 'wire.fixture.test', 12 => 'studio.fixture.test', 13 => 'links.fixture.test',
	);
	foreach ( $domains as $id => $domain ) {
		parity_site( $id, $domain );
	}
	parity_assert( 4 === (int) ec_get_blog_id( 'artist' ) && 13 === (int) ec_get_blog_id( 'link_pages' ), 'Network logical site map mismatch.' );
	parity_restore_root();
}

function parity_seed() {
	parity_assert( 4 === get_current_blog_id(), 'Seed must bootstrap on the source site.' );
	ec_register_link_page_post_type();
	$viewer = username_exists( 'viewer' );
	if ( ! $viewer ) {
		$viewer = wp_create_user( 'viewer', 'fixture-password', 'viewer@example.test' );
	}
	parity_assert( ! is_wp_error( $viewer ), 'Unprivileged operator creation failed.' );
	add_user_to_blog( 4, $viewer, 'subscriber' );
	$owner_users = array();
	foreach ( array( 'owner-one', 'owner-two' ) as $login ) {
		$user = username_exists( $login );
		if ( ! $user ) {
			$user = wp_create_user( $login, 'fixture-password', $login . '@example.test' );
		}
		parity_assert( ! is_wp_error( $user ), 'Owner user creation failed.' );
		add_user_to_blog( 4, $user, 'author' );
		$owner_users[] = (int) $user;
	}
	foreach ( array( 100 => array( 'Proof Owner One', 'proof-owner-one', $owner_users[0] ), 101 => array( 'Proof Owner Two', 'proof-owner-two', $owner_users[1] ) ) as $id => $owner ) {
		$inserted = wp_insert_post( array( 'import_id' => $id, 'post_author' => $owner[2], 'post_type' => 'artist_profile', 'post_status' => 'publish', 'post_title' => $owner[0], 'post_name' => $owner[1] ), true );
		parity_assert( $id === $inserted, 'Owner profile import ID mismatch.' );
	}
	$modified = '2025-02-03 04:05:06';
	foreach ( array( 200 => array( 'proof-one', 100, $owner_users[0] ), 201 => array( 'proof-two', 101, $owner_users[1] ) ) as $id => $page ) {
		$inserted = wp_insert_post(
			array(
				'import_id' => $id, 'post_author' => $page[2], 'post_type' => EC_LINK_PAGE_POST_TYPE,
				'post_status' => 'publish', 'post_title' => $page[0], 'post_name' => $page[0],
				'post_modified' => $modified, 'post_modified_gmt' => $modified,
			),
			true
		);
		parity_assert( $id === $inserted, 'Link Page import ID mismatch.' );
		add_post_meta( $id, EC_LINK_PAGE_OWNER_META_KEY, 'post:' . get_current_blog_id() . ':artist_profile:' . $page[1] );
		add_post_meta( $id, '_associated_artist_profile_id', $page[1] );
		add_post_meta( $page[1], '_extrch_link_page_id', $id );
	}
	parity_attachment( 300, 200, 'internal.jpg', $owner_users[0] );
	parity_attachment( 301, 100, 'external.jpg', $owner_users[0] );
	update_post_meta( 200, '_link_page_background_image_id', 300 );
	update_post_meta( 100, '_thumbnail_id', 301 );
	global $wpdb;
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 200, 'meta_key' => '_raw_duplicate', 'meta_value' => 'slashes\\and"quotes' ) );
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 200, 'meta_key' => '_raw_duplicate', 'meta_value' => 'a:1:{s:3:"raw";s:4:"test";}' ) );
	extrachill_analytics_link_page_create_table();
	$wpdb->insert( extrachill_analytics_link_page_views_table(), array( 'view_id' => 700, 'link_page_id' => 200, 'stat_date' => '2025-02-03', 'view_count' => 11 ) );
	$wpdb->insert( extrachill_analytics_link_page_clicks_table(), array( 'click_id' => 800, 'link_page_id' => 200, 'stat_date' => '2025-02-03', 'link_url' => 'https://example.test/song', 'link_text' => 'Song', 'click_count' => 7 ) );
	extrachill_analytics_events_create_table();
	$wpdb->insert( extrachill_analytics_events_table(), array( 'event_type' => 'page_view', 'event_data' => wp_json_encode( array( 'post_id' => 200 ) ), 'source_url' => 'https://artist.fixture.test/proof-one', 'blog_id' => 4, 'created_at' => current_time( 'mysql', true ) ) );
	switch_to_blog( 13 );
	ec_register_link_page_post_type();
	extrachill_analytics_link_page_create_table();
	$destination_public = (int) get_blog_details( 13 )->public;
	restore_current_blog();
	update_site_option( 'ec_parity_source_snapshot', parity_source_snapshot() );
	update_site_option( 'ec_parity_storage_before', ec_get_link_page_storage_blog_id() );
	update_site_option( 'ec_parity_destination_public', $destination_public );
	parity_assert( array() === ( $GLOBALS['_wp_switched_stack'] ?? array() ), 'Seed leaked multisite context.' );
}

function parity_assert_applied() {
	parity_assert( 4 === get_current_blog_id(), 'Assertions must bootstrap on the source site.' );
	parity_assert_source_unchanged();
	$source_files = array();
	foreach ( array( 300, 301 ) as $attachment_id ) {
		$files = ec_link_page_migration_attachment_files( $attachment_id );
		parity_assert( ! is_wp_error( $files ), 'Source attachment inventory failed.' );
		$source_files = array_merge( $source_files, $files );
	}
	switch_to_blog( 13 );
	foreach ( array( 200 => array( 'proof-one', 100 ), 201 => array( 'proof-two', 101 ) ) as $id => $expected ) {
		$post = get_post( $id );
		parity_assert( $post && 'publish' === $post->post_status && $expected[0] === $post->post_name && '2025-02-03 04:05:06' === $post->post_modified, 'Destination Link Page fields mismatch.' );
		parity_assert( 'post:4:artist_profile:' . $expected[1] === get_post_meta( $id, EC_LINK_PAGE_OWNER_META_KEY, true ), 'Canonical owner binding mismatch.' );
	}
	parity_assert( 200 === (int) get_post( 300 )->post_parent && 0 === (int) get_post( 301 )->post_parent, 'Attachment parent map mismatch.' );
	$destination_uploads = wp_upload_dir();
	foreach ( $source_files as $file ) {
		$destination = trailingslashit( $destination_uploads['basedir'] ) . $file['path'];
		parity_assert( is_file( $destination ) && hash_equals( $file['sha256'], hash_file( 'sha256', $destination ) ), 'Destination file SHA mismatch: ' . $file['path'] );
	}
	$raw = get_post_meta( 200, '_raw_duplicate', false );
	parity_assert( array( 'slashes\\and"quotes', array( 'raw' => 'test' ) ) === $raw, 'Runtime raw metadata mismatch.' );
	$analytics = extrachill_analytics_link_page_migration_rows( array( 200, 201 ) );
	parity_assert( 1 === count( $analytics['views'] ) && 1 === count( $analytics['clicks'] ), 'Analytics rows were not copied.' );
	restore_current_blog();
	parity_assert( (int) get_site_option( 'ec_parity_storage_before' ) === ec_get_link_page_storage_blog_id(), 'Storage routing changed.' );
	parity_assert( (int) get_site_option( 'ec_parity_destination_public' ) === (int) get_blog_details( 13 )->public, 'Destination visibility changed.' );
	parity_assert( array() === ( $GLOBALS['_wp_switched_stack'] ?? array() ), 'Applied assertions leaked multisite context.' );
}

function parity_assert_rolled_back() {
	parity_assert_source_unchanged();
	switch_to_blog( 13 );
	foreach ( array( 200, 201, 300, 301 ) as $id ) {
		parity_assert( ! get_post( $id ), 'Destination object remains after rollback: ' . $id );
	}
	$analytics = extrachill_analytics_link_page_migration_rows( array( 200, 201 ) );
	parity_assert( array() === $analytics['views'] && array() === $analytics['clicks'], 'Destination Analytics rows remain after rollback.' );
	restore_current_blog();
	parity_assert( (int) get_site_option( 'ec_parity_storage_before' ) === ec_get_link_page_storage_blog_id(), 'Storage routing changed.' );
	parity_assert( (int) get_site_option( 'ec_parity_destination_public' ) === (int) get_blog_details( 13 )->public, 'Destination visibility changed.' );
	parity_assert( array() === ( $GLOBALS['_wp_switched_stack'] ?? array() ), 'Rollback assertions leaked multisite context.' );
}

function parity_collision( $kind, $remove ) {
	switch_to_blog( 13 );
	ec_register_link_page_post_type();
	$key = 'ec_parity_collision_' . $kind;
	if ( $remove ) {
		$id = (int) get_site_option( $key, 0 );
		if ( $id ) {
			wp_delete_post( $id, true );
		}
		delete_site_option( $key );
	} else {
		$data = 'id' === $kind
			? array( 'import_id' => 200, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'ID collision' )
			: array( 'import_id' => 900, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Slug collision', 'post_name' => 'proof-one' );
		$id = wp_insert_post( $data, true );
		parity_assert( ! is_wp_error( $id ), 'Collision fixture failed.' );
		update_site_option( $key, (int) $id );
	}
	restore_current_blog();
}

function parity_missing_file( $restore ) {
	$files = ec_link_page_migration_attachment_files( 300 );
	if ( $restore ) {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . get_site_option( 'ec_parity_missing_file_path' );
		file_put_contents( $path, base64_decode( get_site_option( 'ec_parity_missing_file' ) ) );
		delete_site_option( 'ec_parity_missing_file' );
		delete_site_option( 'ec_parity_missing_file_path' );
	} else {
		parity_assert( ! is_wp_error( $files ) && ! empty( $files[0]['path'] ), 'Missing-file fixture could not resolve a source file.' );
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . $files[0]['path'];
		update_site_option( 'ec_parity_missing_file', base64_encode( file_get_contents( $path ) ) );
		update_site_option( 'ec_parity_missing_file_path', $files[0]['path'] );
		unlink( $path );
	}
}

function parity_interrupt( $journal_id ) {
	$journal = ec_link_page_migration_get_journal( $journal_id );
	parity_assert( ! is_wp_error( $journal ), 'Interrupted journal fixture is unavailable.' );
	foreach ( $journal['entries'] as $entry ) {
		if ( 'post' !== ( $entry['type'] ?? '' ) || 200 !== (int) ( $entry['requested_id'] ?? 0 ) ) {
			continue;
		}
		switch_to_blog( 13 );
		wp_update_post( array( 'ID' => 200, 'post_name' => $entry['temporary_slug'] ) );
		$entry['actual_id'] = 0;
		$entry['phase']     = 'intent';
		unset( $entry['phase_post'] );
		update_network_option( null, ec_link_page_migration_journal_key( $journal_id, $entry['sequence'] ), $entry );
		restore_current_blog();
		break;
	}
	$header           = get_network_option( null, ec_link_page_migration_journal_key( $journal_id ) );
	$header['status'] = 'failed';
	update_network_option( null, ec_link_page_migration_journal_key( $journal_id ), $header );
	$index                           = get_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION );
	$index[ $journal_id ]['status'] = 'failed';
	update_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION, $index );
}

function parity_mutate_destination() {
	switch_to_blog( 13 );
	wp_update_post( array( 'ID' => 200, 'post_title' => 'User changed title' ) );
	restore_current_blog();
}

$phase = $args[0] ?? '';
switch ( $phase ) {
	case 'setup-sites': parity_setup_sites(); break;
	case 'seed': parity_seed(); break;
	case 'assert-applied': parity_assert_applied(); break;
	case 'assert-rolled-back': parity_assert_rolled_back(); break;
	case 'add-id-collision': parity_collision( 'id', false ); break;
	case 'remove-id-collision': parity_collision( 'id', true ); break;
	case 'add-slug-collision': parity_collision( 'slug', false ); break;
	case 'remove-slug-collision': parity_collision( 'slug', true ); break;
	case 'add-drift': add_post_meta( 200, '_ec_parity_drift', 'changed' ); break;
	case 'remove-drift': delete_post_meta( 200, '_ec_parity_drift' ); break;
	case 'remove-file': parity_missing_file( false ); break;
	case 'restore-file': parity_missing_file( true ); break;
	case 'interrupt': parity_interrupt( $args[1] ?? '' ); break;
	case 'mutate-destination': parity_mutate_destination(); break;
	default: throw new RuntimeException( 'Unknown parity fixture phase: ' . $phase );
}

parity_assert( array() === ( $GLOBALS['_wp_switched_stack'] ?? array() ), 'Fixture phase leaked multisite context.' );
echo wp_json_encode( array( 'status' => 'passed', 'phase' => $phase ) );
