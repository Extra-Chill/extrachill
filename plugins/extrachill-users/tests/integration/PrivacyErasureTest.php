<?php
/** Network privacy lifecycle regression tests. */
class Test_Users_Privacy_Erasure extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		extrachill_users_install_concert_tracking_table();
		extrachill_users_install_concert_import_runs_table();
		extrachill_users_install_entity_subscriptions_table();
		extrachill_users_install_notifications_table();
	}

	private function seed( $user_id, $count = 1 ) {
		global $wpdb;
		foreach ( range( 1, $count ) as $i ) {
			$wpdb->insert( $wpdb->base_prefix . 'ec_concert_tracking', array(
				'user_id'  => $user_id,
				'event_id' => $i,
				'blog_id'  => 1,
			) );
			$wpdb->insert( $wpdb->base_prefix . 'ec_concert_import_runs', array(
				'user_id'           => $user_id,
				'source_slug'       => 'test',
				'external_username' => 'private',
			) );
			$wpdb->insert( $wpdb->base_prefix . 'ec_entity_subscriptions', array(
				'user_id'     => $user_id,
				'entity_type' => 'artist',
				'taxonomy'    => 'artist',
				'entity_slug' => 'test-' . $i,
			) );
			$wpdb->insert( $wpdb->base_prefix . 'ec_notifications', array(
				'user_id' => $user_id,
				'title'   => 'private',
				'link'    => '',
			) );
		}
		update_user_meta( $user_id, 'ec_concert_import_setlistfm_username', 'private' );
	}

	public function test_core_workflow_drains_partial_batches_and_retries() {
		global $wpdb;
		$user  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->seed( $user, 3 );
		$this->seed( $other );
		$wpdb->insert( $wpdb->base_prefix . 'ec_notifications', array(
			'user_id'  => $other,
			'actor_id' => $user,
			'title'    => 'private actor',
			'link'     => '',
		) );
		update_user_meta( $user, 'other_plugin_private_data', 'owner handles this' );
		$first = extrachill_users_erase_user_data_batch( $user, 2 );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 0, $first['failed'] );
		$this->assertSame( 9, $first['removed'] );
		$second = extrachill_users_erase_user_data_batch( $user, 2 );
		$this->assertFalse( $second['done'] ); // Exactly two actor/recipient notifications drained.
		$retry = extrachill_users_erase_user_data_batch( $user, 2 );
		$this->assertTrue( $retry['done'] );
		$this->assertSame( 0, $retry['removed'] );
		$this->assertSame( 'owner handles this', get_user_meta( $user, 'other_plugin_private_data', true ) );
		$this->assertSame( 'private', get_user_meta( $other, 'ec_concert_import_setlistfm_username', true ) );
		$erasers  = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$response = call_user_func( $erasers['extrachill-users']['callback'], get_userdata( $user )->user_email, 4 );
		$this->assertTrue( $response['done'] );
	}

	public function test_database_failure_is_reported_and_retry_recovers() {
		global $wpdb;
		$user  = self::factory()->user->create();
		$table = $wpdb->base_prefix . 'ec_entity_subscriptions';
		$wpdb->query( "ALTER TABLE {$table} CHANGE user_id broken_user_id bigint(20) unsigned NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture table.
		$previous = $wpdb->suppress_errors( true );
		try {
			$result = extrachill_users_erase_user_data_batch( $user );
			$this->assertFalse( $result['done'] );
			$this->assertTrue( $result['items_retained'] );
			$this->assertSame( 1, $result['failed'] );
			$this->assertStringNotContainsString( 'broken_user_id', implode( ' ', $result['messages'] ) );
		} finally {
			$wpdb->query( "ALTER TABLE {$table} CHANGE broken_user_id user_id bigint(20) unsigned NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture table.
			$wpdb->suppress_errors( $previous );
		}
		$this->assertTrue( extrachill_users_erase_user_data_batch( $user )['done'] );
	}

	public function test_core_eraser_finishes_with_retained_items_on_storage_failure() {
		global $wpdb;
		$user  = self::factory()->user->create();
		$table = $wpdb->base_prefix . 'ec_entity_subscriptions';
		$wpdb->query( "ALTER TABLE {$table} CHANGE user_id broken_user_id bigint(20) unsigned NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture table.
		$previous = $wpdb->suppress_errors( true );
		try {
			$erasers  = apply_filters( 'wp_privacy_personal_data_erasers', array() );
			$response = call_user_func( $erasers['extrachill-users']['callback'], get_userdata( $user )->user_email, 1 );
			$this->assertTrue( $response['done'], 'A failing eraser must not make core page forever.' );
			$this->assertTrue( $response['items_retained'] );
		} finally {
			$wpdb->query( "ALTER TABLE {$table} CHANGE broken_user_id user_id bigint(20) unsigned NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture table.
			$wpdb->suppress_errors( $previous );
		}
	}

	public function test_removing_user_from_one_site_keeps_network_data() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}
		global $wpdb;
		$user = self::factory()->user->create();
		$this->seed( $user );
		$blog = self::factory()->blog->create();
		add_user_to_blog( $blog, $user, 'subscriber' );
		switch_to_blog( $blog );
		try {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user );
		} finally {
			restore_current_blog();
		}
		$this->assertNotFalse( get_userdata( $user ) );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}ec_concert_tracking WHERE user_id = %d", $user ) ) );
		$this->assertSame( 'private', get_user_meta( $user, 'ec_concert_import_setlistfm_username', true ) );
	}

	public function test_network_tables_are_erased_from_another_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}
		$user = self::factory()->user->create();
		$this->seed( $user );
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		try {
			$result = extrachill_users_erase_user_data_batch( $user );
			$this->assertTrue( $result['done'] );
			$this->assertSame( 5, $result['removed'] );
		} finally {
			restore_current_blog();
		}
	}

	public function test_missing_table_and_orphan_diagnostics() {
		global $wpdb;
		$table = $wpdb->base_prefix . 'ec_concert_import_runs';
		$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture table.
		try {
			$user = self::factory()->user->create();
			$this->assertTrue( extrachill_users_erase_user_data_batch( $user )['done'] );
			$wpdb->insert( $wpdb->base_prefix . 'ec_notifications', array(
				'user_id'  => 999999999,
				'actor_id' => 0,
				'title'    => 'secret',
				'link'     => '',
			) );
			$counts = extrachill_users_privacy_orphan_counts();
			$this->assertSame( 0, $counts['ec_concert_import_runs.user_id'] );
			$this->assertSame( 1, $counts['ec_notifications.user_id'] );
			$this->assertSame( 0, $counts['ec_notifications.actor_id'] );
			$this->assertStringNotContainsString( 'secret', wp_json_encode( $counts ) );
		} finally {
			extrachill_users_install_concert_import_runs_table();
		}
	}

	public function test_account_deletion_drains_all_batches_and_delegates_before_id_removal() {
		$user = self::factory()->user->create();
		$this->seed( $user, 1001 );
		$called = false;
		$owner  = function ( $id ) use ( $user, &$called ) {
			if ( $id === $user ) {
				$called = true;
				$this->assertNotFalse( get_userdata( $id ) );
				$this->assertSame( 0, extrachill_users_erase_user_data_batch( $id )['removed'] );
			}
		};
		add_action( 'extrachill_users_erase_user_data', $owner );
		try {
			if ( is_multisite() ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				wpmu_delete_user( $user );
			} else {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user );
			}
		} finally {
			remove_action( 'extrachill_users_erase_user_data', $owner );
		}
		$this->assertTrue( $called );
		$this->assertFalse( get_userdata( $user ) );
		$this->assertSame( 0, extrachill_users_erase_user_data_batch( $user )['removed'] );
	}
}
