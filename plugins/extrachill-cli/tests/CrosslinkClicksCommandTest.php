<?php
/**
 * Focused contract and presenter tests for the crosslink-clicks command.
 */

namespace {
	$crosslink_clicks_test_wp_bootstrapped = function_exists( 'get_user_by' );

	if ( ! $crosslink_clicks_test_wp_bootstrapped ) {
		// Real WordPress (or the WP Codebox managed PHPUnit harness) would
		// collide with these global doubles, so the file only runs standalone:
		// `php tests/CrosslinkClicksCommandTest.php` (see homeboy.json self_checks).
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

		class CrosslinkClicksCommandTestAbility {
			public $inputs = array();

			public function execute( $input ) {
				$this->inputs[] = $input;

				return array(
					'period' => '2026-10-18 → 2026-11-15',
					'totals' => array(
						'posts'           => 1,
						'pageviews'       => 591,
						'clicks'          => 3,
						'inserted_clicks' => 2,
						'manual_clicks'   => 1,
						'click_rate'      => 0.0051,
					),
					'posts'  => array(
						array(
							'post_id'         => 96350,
							'title'           => 'Grateful Dead story',
							'pageviews'       => 591,
							'clicks'          => 3,
							'click_visitors'  => 2,
							'click_rate'      => 0.0051,
							'inserted_links'  => 1,
							'inserted_clicks' => 2,
							'manual_clicks'   => 1,
							'destinations'    => array(
								array(
									'destination' => 'artist.extrachill.com/artists/grateful-dead/',
									'clicks'      => 2,
									'link_kind'   => 'network',
									'origin'      => 'inserted',
								),
							),
						),
					),
					'note'   => 'content_link_click is new.',
				);
			}
		}

		$crosslink_clicks_test_ability = new CrosslinkClicksCommandTestAbility();

		function wp_get_ability( $name ) {
			global $crosslink_clicks_test_ability;
			if ( 'extrachill/get-crosslink-clicks' !== $name ) {
				throw new RuntimeException( 'Unexpected ability requested: ' . $name );
			}
			return $crosslink_clicks_test_ability;
		}

		function is_wp_error( $value ) {
			return false;
		}

		function wp_json_encode( $value, $flags = 0 ) {
			return json_encode( $value, $flags );
		}

		function crosslink_clicks_test_assert_same( $expected, $actual, $message ) {
			if ( $expected !== $actual ) {
				throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
			}
		}

		function crosslink_clicks_test_assert_contains( $needle, $haystack, $message ) {
			if ( false === strpos( $haystack, $needle ) ) {
				throw new RuntimeException( $message . "\nMissing: " . $needle );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! $crosslink_clicks_test_wp_bootstrapped ) {
		$crosslink_clicks_test_formats = array();

		function format_items( $format, $items, $fields ) {
			global $crosslink_clicks_test_formats;
			$crosslink_clicks_test_formats[] = compact( 'format', 'items', 'fields' );
		}
	}
}

namespace {
	use ExtraChill\CLI\Commands\Analytics\CrosslinkClicksCommand;

	if ( ! $crosslink_clicks_test_wp_bootstrapped ) {
		require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/CrosslinkClicksCommand.php';

		global $crosslink_clicks_test_ability, $crosslink_clicks_test_formats;

		$command = new CrosslinkClicksCommand();
		$command( array(), array() );
		crosslink_clicks_test_assert_same(
			array(
				'days'    => 28,
				'blog_id' => 0,
				'post_id' => 0,
				'limit'   => 25,
			),
			$crosslink_clicks_test_ability->inputs[0],
			'Default argument mapping must be stable.'
		);
		$out = implode( "\n", WP_CLI::$messages );
		crosslink_clicks_test_assert_contains( '1 posts · 3 clicks / 591 human pageviews (0.51%) · inserted 2 · manual 1', $out, 'Table summary must show totals.' );
		crosslink_clicks_test_assert_same( 'table', $crosslink_clicks_test_formats[0]['format'], 'Table format must be used.' );
		crosslink_clicks_test_assert_same( '0.51%', $crosslink_clicks_test_formats[0]['items'][0]['click_rate'], 'Rate must be a percent.' );
		crosslink_clicks_test_assert_same( 1, count( $crosslink_clicks_test_formats ), 'Destinations only print for a single post.' );

		$command( array(), array( 'post-id' => '96350', 'days' => '7', 'blog-id' => '1', 'limit' => '5' ) );
		crosslink_clicks_test_assert_same(
			array(
				'days'    => 7,
				'blog_id' => 1,
				'post_id' => 96350,
				'limit'   => 5,
			),
			$crosslink_clicks_test_ability->inputs[1],
			'Options must map to ability input.'
		);
		crosslink_clicks_test_assert_same( array( 'destination', 'clicks', 'link_kind', 'origin' ), $crosslink_clicks_test_formats[2]['fields'], 'Single-post view must break down destinations.' );

		WP_CLI::$messages = array();
		$command( array(), array( 'format' => 'json' ) );
		$decoded = json_decode( WP_CLI::$messages[0], true );
		crosslink_clicks_test_assert_same( 96350, $decoded['posts'][0]['post_id'], 'JSON must pass the full report through.' );

		fwrite( STDOUT, "CrosslinkClicksCommand tests passed.\n" );
	}
}
