<?php
/**
 * Focused contract and presenter tests for the outbound command.
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

	class OutboundCommandTestAbility {
		public $inputs = array();

		public function execute( $input ) {
			$this->inputs[] = $input;

			return array(
				'period'         => '2026-06-19 to 2026-07-17',
				'total'          => 1234,
				'by_category'    => array(
					array(
						'category' => 'ticketing',
						'clicks'   => 1234,
						'share'    => 1,
					),
				),
				'by_destination' => array(),
				'by_source'      => array(),
				'note'           => 'All stored outbound browser beacons are counted.',
			);
		}
	}

	$outbound_command_test_ability = new OutboundCommandTestAbility();

	function wp_get_ability( $name ) {
		global $outbound_command_test_ability;

		if ( 'extrachill/get-outbound-clicks' !== $name ) {
			throw new RuntimeException( 'Unexpected ability requested: ' . $name );
		}

		return $outbound_command_test_ability;
	}

	function is_wp_error( $value ) {
		return false;
	}

	function outbound_command_test_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException(
				$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
			);
		}
	}

	function outbound_command_test_assert_contains( $needle, $haystack, $message ) {
		if ( false === strpos( $haystack, $needle ) ) {
			throw new RuntimeException( $message . '\nMissing: ' . $needle );
		}
	}
}

namespace WP_CLI\Utils {
	$outbound_command_test_formats = array();

	function format_items( $format, $items, $fields ) {
		global $outbound_command_test_formats;

		$outbound_command_test_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/OutboundCommand.php';

	use ExtraChill\CLI\Commands\Analytics\OutboundCommand;

	global $outbound_command_test_ability, $outbound_command_test_formats;

	$help = ( new ReflectionMethod( OutboundCommand::class, '__invoke' ) )->getDocComment();
	outbound_command_test_assert_contains( '[--include-bots]', $help, 'Help must retain the legacy option for compatibility.' );
	outbound_command_test_assert_contains( 'Deprecated compatibility flag; ignored.', $help, 'Help must identify the legacy option as a no-op.' );
	outbound_command_test_assert_contains( 'always includes all', $help, 'Help must document actual stored-row coverage.' );
	outbound_command_test_assert_contains( 'not a trustworthy human/bot filter', $help, 'Help must explain why no human-only claim is made.' );

	$command = new OutboundCommand();
	$command(
		array(),
		array(
			'days'         => '7',
			'blog-id'      => '2',
			'category'     => 'ticketing',
			'limit'        => '10',
			'by'           => 'category',
			'format'       => 'json',
			'include-bots' => true,
		)
	);

	$expected_input = array(
		'days'     => 7,
		'blog_id'  => 2,
		'category' => 'ticketing',
		'limit'    => 10,
	);
	outbound_command_test_assert_same( $expected_input, $outbound_command_test_ability->inputs[0], 'Only supported ability arguments may be mapped.' );
	outbound_command_test_assert_same( false, array_key_exists( 'include_bots', $outbound_command_test_ability->inputs[0] ), 'The deprecated flag must not imply filtering through ability input.' );
	outbound_command_test_assert_same( 'json', $outbound_command_test_formats[0]['format'], 'JSON output format must be preserved.' );
	outbound_command_test_assert_same( array( 'category', 'clicks', 'share' ), $outbound_command_test_formats[0]['fields'], 'JSON columns must remain backward compatible.' );
	outbound_command_test_assert_same( '1,234', $outbound_command_test_formats[0]['items'][0]['clicks'], 'JSON row formatting must remain backward compatible.' );
	outbound_command_test_assert_same( '100.0%', $outbound_command_test_formats[0]['items'][0]['share'], 'JSON percentage formatting must remain backward compatible.' );

	$command( array(), array() );
	outbound_command_test_assert_same(
		array(
			'days'     => 28,
			'blog_id'  => 0,
			'category' => '',
			'limit'    => 25,
		),
		$outbound_command_test_ability->inputs[1],
		'Default argument mapping must remain stable.'
	);
	outbound_command_test_assert_same( 'table', $outbound_command_test_formats[1]['format'], 'Table output format must be preserved.' );
	outbound_command_test_assert_same( array( 'category', 'clicks', 'share' ), $outbound_command_test_formats[1]['fields'], 'Table columns must remain backward compatible.' );
	outbound_command_test_assert_contains( 'Total outbound clicks: 1,234', implode( "\n", WP_CLI::$messages ), 'Table summary must retain formatted totals.' );
	outbound_command_test_assert_contains( 'All stored outbound browser beacons are counted.', implode( "\n", WP_CLI::$messages ), 'Table output must retain the ability coverage note.' );

	fwrite( STDOUT, "OutboundCommand tests passed.\n" );
}
