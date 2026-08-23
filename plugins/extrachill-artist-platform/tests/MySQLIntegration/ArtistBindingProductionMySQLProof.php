<?php
/**
 * Production WordPress two-session MySQL proof for artist bindings.
 *
 * @package ExtraChillArtistPlatform
 */

use PHPUnit\Framework\TestCase;

/** Exercises production binding functions, metadata, caches, and delete hooks. */
final class ArtistBindingProductionMySQLProof extends TestCase {
	/** @var mysqli Independent advisory-lock contender. */
	private $contender;

	/** @var int Disposable artist profile ID. */
	private $profile_id = 0;

	/** @var int[] Disposable canonical term IDs. */
	private $term_ids = array();

	protected function setUp(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedFunctions.mysql_mysqli_init,WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect,WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error -- Independent contention is required.
		mysqli_report( MYSQLI_REPORT_OFF );
		$this->contender  = mysqli_init();
		$connected        = mysqli_real_connect( $this->contender, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$connection_error = mysqli_connect_error();
		// phpcs:enable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedFunctions.mysql_mysqli_init,WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect,WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error
		$this->assertTrue( $connected, $connection_error ? $connection_error : 'MySQL connection failed.' );
		$version         = $this->contender->query( 'SELECT VERSION()' )->fetch_row()[0];
		$version_pattern = getenv( 'MYSQL_EXPECT_VERSION_PATTERN' );
		$version_pattern = $version_pattern ? $version_pattern : '/^8\.4\./';
		$this->assertMatchesRegularExpression( $version_pattern, $version, 'Unexpected integration database version.' );
		$this->assertSame( '', (string) $wpdb->last_error );

		switch_to_blog( 4 );
		$this->profile_id = wp_insert_post(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
				'post_title'  => 'Binding Proof ' . wp_generate_uuid4(),
				'post_name'   => 'binding-proof-' . wp_generate_uuid4(),
			),
			true
		);
		restore_current_blog();
		$this->assertIsInt( $this->profile_id );
		$this->term_ids = array( $this->createTerm(), $this->createTerm(), $this->createTerm() );
	}

	protected function tearDown(): void {
		if ( ! empty( $GLOBALS['ec_artist_binding_lock'] ) ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
			ec_release_artist_binding_lock( $GLOBALS['ec_artist_binding_lock'] );
		}
		if ( $this->profile_id > 0 ) {
			switch_to_blog( 4 );
			wp_delete_post( $this->profile_id, true );
			restore_current_blog();
		}
		switch_to_blog( 1 );
		foreach ( $this->term_ids as $term_id ) {
			wp_delete_term( $term_id, 'artist' );
		}
		restore_current_blog();
		if ( $this->contender instanceof mysqli ) {
			$this->contender->close();
		}
	}

	public function test_production_protocol_serializes_all_boundaries(): void {
		global $wpdb;
		list( $first_term, $second_term, $third_term ) = $this->term_ids;

		// Two actual writers serialize; exactly one reciprocal final winner remains.
		$first         = $this->startWorker( $first_term );
		$second        = $this->startWorker( $second_term );
		$first_result  = $this->finishWorker( $first );
		$second_result = $this->finishWorker( $second );
		$this->assertTrue( $first_result['result'] );
		$this->assertTrue( $second_result['result'] );
		$winner = ec_get_artist_term_id( $this->profile_id );
		$this->assertContains( $winner, array( $first_term, $second_term ) );
		$this->assertSame( $this->profile_id, ec_get_artist_profile_id( $winner ) );
		$loser = $winner === $first_term ? $second_term : $first_term;
		$this->assertSame( 0, ec_get_artist_profile_id( $loser ) );

		// A consumer starts its transaction only after acquiring the global lock.
		$lock = ec_acquire_artist_binding_lock();
		$this->assertIsString( $lock );
		$this->assertNotFalse( $wpdb->query( 'START TRANSACTION' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Production consumer ordering proof.
		$this->assertSame(
			array(
				'profile_id' => $this->profile_id,
				'term_id'    => $winner,
			),
			ec_read_locked_artist_binding( $this->profile_id, $winner )
		);
		$worker = $this->startWorker( $third_term );
		$this->assertSame( 'artist_binding_release_transaction_active', ec_release_artist_binding_lock( $lock )->get_error_code() );
		$this->assertNotFalse( $wpdb->query( 'COMMIT' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Subordinate transaction must finish first.
		$this->assertTrue( ec_release_artist_binding_lock( $lock ) );
		$this->assertTrue( $this->finishWorker( $worker )['result'] );
		$this->assertSame( $third_term, ec_get_artist_term_id( $this->profile_id ) );

		// A real contender forces the production timeout path, then release permits entry.
		$this->assertSame( 1, $this->contenderLock( 1 ) );
		$this->assertFalse( ec_bind_artist_profile_to_term( $this->profile_id, $first_term ) );
		$this->assertSame( 'artist_binding_busy', ec_get_artist_binding_failure()->get_error_code() );
		$this->assertSame( 1, $this->contenderRelease() );
		$this->assertTrue( ec_bind_artist_profile_to_term( $this->profile_id, $first_term ) );

		// Core deletion retains the production lock while a real writer waits.
		$delete_worker = null;
		add_action(
			'before_delete_post',
			function ( $post_id ) use ( &$delete_worker, $second_term ) {
				if ( $post_id === $this->profile_id ) {
					$delete_worker = $this->startWorker( $second_term );
				}
			},
			PHP_INT_MAX,
			1
		);
		switch_to_blog( 4 );
		$this->assertNotFalse( wp_delete_post( $this->profile_id, true ) );
		restore_current_blog();
		$this->profile_id = 0;
		$this->assertIsArray( $delete_worker );
		$this->assertFalse( $this->finishWorker( $delete_worker )['result'] );

		// Acquisition from an existing transaction is rejected and deferred safely.
		$this->profile_id = $this->createProfile();
		$this->assertNotFalse( $wpdb->query( 'START TRANSACTION' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Boundary rejection proof.
		$rejected = ec_acquire_artist_binding_lock();
		$this->assertSame( 'artist_binding_nested_transaction', $rejected->get_error_code() );
		$this->assertNotFalse( $wpdb->query( 'ROLLBACK' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- End caller transaction before deferred release.
		ec_artist_binding_release_deferred_locks();
		$this->assertEmpty( $GLOBALS['ec_artist_binding_lock'] ?? null );
	}

	private function createProfile(): int {
		switch_to_blog( 4 );
		$profile_id = wp_insert_post(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
				'post_title'  => 'Boundary Proof ' . wp_generate_uuid4(),
			),
			true
		);
		restore_current_blog();
		$this->assertIsInt( $profile_id );
		return $profile_id;
	}

	private function createTerm(): int {
		switch_to_blog( 1 );
		$inserted = wp_insert_term( 'Binding Proof ' . wp_generate_uuid4(), 'artist' );
		restore_current_blog();
		$this->assertIsArray( $inserted );
		return (int) $inserted['term_id'];
	}

	private function startWorker( int $term_id ): array {
		$marker = tempnam( sys_get_temp_dir(), 'ec-binding-' );
		unlink( $marker );
		$command = array( PHP_BINARY, __DIR__ . '/artist-binding-worker.php', 'bind', (string) $this->profile_id, (string) $term_id, $marker );
		$pipes   = array();
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		$deadline = microtime( true ) + 5;
		while ( ! file_exists( $marker ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		$this->assertFileExists( $marker );
		unlink( $marker );
		return array( $process, $pipes );
	}

	private function finishWorker( array $worker ): array {
		list( $process, $pipes ) = $worker;

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status  = proc_close( $process );
		$result  = json_decode( $stdout, true );
		$message = $stderr ? $stderr : $stdout;
		$this->assertIsArray( $result, $message );
		$this->assertContains( $status, array( 0, 1 ), $stderr );
		return $result;
	}

	private function contenderLock( int $timeout ): int {
		$name      = 'ec_artist_binding_v1';
		$statement = $this->contender->prepare( 'SELECT GET_LOCK(?, ?)' );
		$statement->bind_param( 'si', $name, $timeout );
		$statement->execute();
		return (int) $statement->get_result()->fetch_row()[0];
	}

	private function contenderRelease(): int {
		$name      = 'ec_artist_binding_v1';
		$statement = $this->contender->prepare( 'SELECT RELEASE_LOCK(?)' );
		$statement->bind_param( 's', $name );
		$statement->execute();
		return (int) $statement->get_result()->fetch_row()[0];
	}
}
