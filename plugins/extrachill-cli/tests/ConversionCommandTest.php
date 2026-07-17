<?php
/**
 * Focused machine and table presenter tests for the conversion command.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_CLI {
		public static $messages = array();
		public static $printed  = array();

		public static function error( $message ) {
			throw new RuntimeException( $message );
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}

		public static function print_value( $value, $args ) {
			self::$printed[] = compact( 'value', 'args' );
		}
	}

	class ConversionCommandTestAbility {
		public $result;

		public function __construct( $result ) {
			$this->result = $result;
		}

		public function execute( $input ) {
			return $this->result;
		}
	}

	$conversion_command_test_result = array(
		'period'         => '2025-07-16 to 2026-07-16',
		'since'          => '2025-07-16 00:00:00',
		'as_of'          => '2026-07-16 00:00:00',
		'entry_blog_id'  => 1,
		'platform_blogs' => array(),
		'overall'        => array(
			'entry_sessions'   => 1234,
			'reached_any'      => 321,
			'reached_any_rate' => 0.2601,
			'same_session'     => array( 'events' => 0.1, 'community' => 0.05, 'artist' => 0.02, 'any' => 0.17 ),
			'return'           => array( 'events' => 0.04, 'community' => 0.03, 'artist' => 0.02, 'any' => 0.09 ),
			'returned_rate'    => 0.42,
		),
		'by_article'     => array(
			array(
				'post_id'                  => 173,
				'title'                    => 'Mama Say Mama Sa Mama Coosa: The Story Behind an Iconic Michael Jackson Lyric',
				'url'                      => 'https://extrachill.com/mama-say-mama-sa-mama-coosa/',
				'path'                     => '/mama-say-mama-sa-mama-coosa/',
				'entry_sessions'           => 1234,
				'reached_any'              => 321,
				'reached_any_rate'         => 0.2601,
				'reached_any_same_count'   => 210,
				'same_session'             => array( 'any' => 0.1702 ),
				'reached_any_return_count' => 111,
				'return'                   => array( 'any' => 0.0899 ),
				'returned_count'           => 518,
				'returned_rate'            => 0.4198,
			),
		),
		'by_category'    => array(),
		'outcomes'       => array(
			'overall'               => array( 'newsletter_signup' => array( 'count' => 12, 'rate' => 0.0097 ) ),
			'by_article'            => array( array( 'post_id' => 173, 'newsletter_signup' => array( 'count' => 4, 'rate' => 0.0032 ) ) ),
			'by_category'           => array(),
			'coverage'              => array( 'identified_visitors' => 0.82, 'newsletter_events' => 0.76 ),
			'attribution_semantics' => array( 'direct_source' => 'Entry article was the direct source.' ),
		),
		'future_metric'  => array( 'count' => 7, 'rate' => 0.5 ),
	);

	$conversion_command_test_ability = new ConversionCommandTestAbility( $conversion_command_test_result );

	function wp_get_ability( $name ) {
		global $conversion_command_test_ability;
		return 'extrachill/get-conversion-map' === $name ? $conversion_command_test_ability : null;
	}

	function is_wp_error( $value ) {
		return false;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function conversion_command_test_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}
}

namespace WP_CLI\Utils {
	$conversion_command_test_formats = array();

	function format_items( $format, $items, $fields ) {
		global $conversion_command_test_formats;
		$conversion_command_test_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/ConversionCommand.php';

	use ExtraChill\CLI\Commands\Analytics\ConversionCommand;

	global $conversion_command_test_formats, $conversion_command_test_result;

	$command = new ConversionCommand();
	$command( array(), array( 'by' => 'article', 'format' => 'json' ) );
	conversion_command_test_assert_same( $conversion_command_test_result, WP_CLI::$printed[0]['value'], 'JSON must preserve the complete typed ability result.' );
	conversion_command_test_assert_same( array( 'format' => 'json' ), WP_CLI::$printed[0]['args'], 'JSON must use WP-CLI structured output.' );
	conversion_command_test_assert_same( 0.82, WP_CLI::$printed[0]['value']['outcomes']['coverage']['identified_visitors'], 'JSON must retain outcome coverage.' );
	conversion_command_test_assert_same( $conversion_command_test_result['future_metric'], WP_CLI::$printed[0]['value']['future_metric'], 'JSON must retain newly added ability fields without presenter changes.' );

	$command( array(), array( 'by' => 'article', 'format' => 'csv' ) );
	$command( array(), array( 'by' => 'article' ) );

	$csv = $conversion_command_test_formats[0];
	conversion_command_test_assert_same( array_keys( $conversion_command_test_result ), $csv['fields'], 'CSV must retain every top-level ability field without a presenter allowlist.' );
	conversion_command_test_assert_same( '2025-07-16 to 2026-07-16', $csv['items'][0]['period'], 'CSV scalar values must retain their source type before formatting.' );
	conversion_command_test_assert_same( $conversion_command_test_result['outcomes'], json_decode( $csv['items'][0]['outcomes'], true ), 'CSV must preserve the complete outcomes envelope as JSON.' );
	conversion_command_test_assert_same( $conversion_command_test_result['future_metric'], json_decode( $csv['items'][0]['future_metric'], true ), 'CSV must preserve newly added ability fields without presenter changes.' );

	$table = $conversion_command_test_formats[1];
	conversion_command_test_assert_same( array( 'title', 'entry_sessions', 'same_any', 'return_any', 'returned', 'reached_any' ), $table['fields'], 'Table columns must remain backward compatible.' );
	conversion_command_test_assert_same( 'Mama Say Mama Sa Mama Coosa: The Story Behind an Iconic…', $table['items'][0]['title'], 'Table titles must retain compact display truncation.' );
	conversion_command_test_assert_same( '1,234', $table['items'][0]['entry_sessions'], 'Table counts must retain human formatting.' );
	conversion_command_test_assert_same( '26.0%', $table['items'][0]['reached_any'], 'Table rates must retain percentage formatting.' );

	fwrite( STDOUT, "ConversionCommand tests passed.\n" );
}
