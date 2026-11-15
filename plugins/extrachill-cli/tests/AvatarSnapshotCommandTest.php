<?php
/**
 * Focused behavior tests for the avatar snapshot backfill CLI adapter.
 */

namespace {
	if ( ! function_exists( 'get_user_by' ) ) {
		// Real WordPress (or the WP Codebox managed PHPUnit harness) is
		// already bootstrapped and this file's global function/class doubles
		// would collide with core, so skip the whole file. Run it standalone
		// instead: `php tests/AvatarSnapshotCommandTest.php` (see homeboy.json self_checks).
		define( 'ABSPATH', __DIR__ . '/' );
		define( 'AVATAR_SNAPSHOT_COMMAND_STANDALONE', true );

		$GLOBALS['avatar_snapshot_messages'] = array();
		$GLOBALS['avatar_snapshot_calls']    = array();

		class WP_CLI {
			public static function error( string $message ): void {
				throw new RuntimeException( $message );
			}

			public static function success( string $message ): void {
				$GLOBALS['avatar_snapshot_messages'][] = 'Success: ' . $message;
			}

			public static function log( string $message ): void {
				$GLOBALS['avatar_snapshot_messages'][] = $message;
			}
		}

		function get_user_by( string $field, string $value ): bool {
			return false;
		}

		/**
		 * @param mixed $value Value.
		 */
		function wp_json_encode( $value, int $flags = 0 ): string {
			return (string) json_encode( $value, $flags );
		}

		/**
		 * @param array<string,mixed> $args Backfill args.
		 * @return array<string,int|bool>
		 */
		function extrachill_users_backfill_avatar_snapshots( array $args ): array {
			$GLOBALS['avatar_snapshot_calls'][] = $args;
			$dry_run                            = ! empty( $args['dry_run'] );
			return array(
				'scanned'     => 3,
				'fresh'       => 1,
				'written'     => $dry_run ? 0 : 2,
				'would_write' => $dry_run ? 2 : 0,
				'batches'     => 1,
				'dry_run'     => $dry_run,
			);
		}

		/**
		 * @param mixed $expected Expected value.
		 * @param mixed $actual   Actual value.
		 */
		function avatar_snapshot_assert_same( $expected, $actual, string $message ): void {
			if ( $expected !== $actual ) {
				throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( defined( 'AVATAR_SNAPSHOT_COMMAND_STANDALONE' ) ) {
		/**
		 * @param array<int,array<string,string>> $items  Rows.
		 * @param array<int,string>               $fields Columns.
		 */
		function format_items( string $format, array $items, array $fields ): void {
			$GLOBALS['avatar_snapshot_messages'][] = $format . ':' . count( $items );
		}
	}
}

namespace {
	use ExtraChill\CLI\Commands\Users\AvatarSnapshotCommand;

	if ( defined( 'AVATAR_SNAPSHOT_COMMAND_STANDALONE' ) ) {
		require_once dirname( __DIR__ ) . '/inc/Commands/Users/AvatarSnapshotCommand.php';

		$command = new AvatarSnapshotCommand();

		$command->backfill(
			array(),
			array(
				'batch-size' => '25',
				'limit'      => '50',
				'dry-run'    => true,
				'format'     => 'json',
			)
		);
		avatar_snapshot_assert_same(
			array( array( 'batch_size' => 25, 'limit' => 50, 'dry_run' => true ) ),
			$GLOBALS['avatar_snapshot_calls'],
			'Dry run must map CLI flags onto the users backfill function.'
		);
		avatar_snapshot_assert_same(
			'{"scanned":3,"fresh":1,"written":0,"would_write":2,"batches":1,"dry_run":true}',
			$GLOBALS['avatar_snapshot_messages'][0],
			'JSON output must carry the users report.'
		);

		$GLOBALS['avatar_snapshot_calls']    = array();
		$GLOBALS['avatar_snapshot_messages'] = array();
		$command->backfill( array(), array() );
		avatar_snapshot_assert_same(
			array( array( 'batch_size' => 100, 'limit' => 0, 'dry_run' => false ) ),
			$GLOBALS['avatar_snapshot_calls'],
			'Defaults must be a bounded batch over all users, writing.'
		);
		avatar_snapshot_assert_same(
			array( 'table:6', 'Success: Wrote 2 avatar snapshot(s).' ),
			$GLOBALS['avatar_snapshot_messages'],
			'Real run must print the report table and the write count.'
		);

		fwrite( STDOUT, "AvatarSnapshotCommand tests passed.\n" );
	}
}
