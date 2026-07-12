<?php
/**
 * Focused presenter tests for the crosslink targets CLI command.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_CLI {
		public static $messages = array();

		public static function error( $message ) {
			throw new RuntimeException( $message );
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}
	}

	class CrosslinkTargetsCommandTestAbility {
		public function execute( $input ) {
			return array(
				'period'     => '2026-06-14 to 2026-07-12',
				'scanned'    => 1,
				'link_graph' => array(
					'available'            => true,
					'total_scanned'        => 100,
					'inbound_orphan_count' => 4,
				),
				'targets'    => array(
					array(
						'post_id'           => 88,
						'title'             => 'Target article',
						'category'          => 'Music News',
						'returned'          => 12,
						'inbound_links'     => 0,
						'orphan'            => true,
						'suggested_surface' => 'community',
						'score'             => 42,
					),
				),
			);
		}
	}

	$crosslink_targets_test_ability = new CrosslinkTargetsCommandTestAbility();

	function wp_get_ability( $name ) {
		global $crosslink_targets_test_ability;

		if ( 'extrachill/get-crosslink-targets' !== $name ) {
			throw new RuntimeException( 'Unexpected ability requested: ' . $name );
		}

		return $crosslink_targets_test_ability;
	}

	function is_wp_error( $value ) {
		return false;
	}

	function crosslink_targets_test_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException(
				$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
			);
		}
	}
}

namespace WP_CLI\Utils {
	$crosslink_targets_test_formats = array();

	function format_items( $format, $items, $fields ) {
		global $crosslink_targets_test_formats;

		$crosslink_targets_test_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/CrosslinkTargetsCommand.php';

	use ExtraChill\CLI\Commands\Analytics\CrosslinkTargetsCommand;

	global $crosslink_targets_test_formats;

	$command = new CrosslinkTargetsCommand();
	$command( array(), array( 'format' => 'json' ) );
	$command( array(), array( 'format' => 'csv' ) );
	$command( array(), array() );

	$expected_fields = array( 'post_id', 'title', 'category', 'returned', 'inbound_links', 'orphan', 'score' );
	crosslink_targets_test_assert_same( 'json', $crosslink_targets_test_formats[0]['format'], 'JSON format must be preserved.' );
	crosslink_targets_test_assert_same( 'csv', $crosslink_targets_test_formats[1]['format'], 'CSV format must be preserved.' );
	crosslink_targets_test_assert_same( 'table', $crosslink_targets_test_formats[2]['format'], 'Table format must be preserved.' );

	foreach ( $crosslink_targets_test_formats as $formatted ) {
		crosslink_targets_test_assert_same( $expected_fields, $formatted['fields'], 'Output fields must use the inbound-only contract.' );
		crosslink_targets_test_assert_same( false, array_key_exists( 'suggested_surface', $formatted['items'][0] ), 'Legacy suggested_surface payload data must be ignored.' );
	}

	fwrite( STDOUT, "CrosslinkTargetsCommand tests passed.\n" );
}
