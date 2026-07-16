<?php
/**
 * Focused machine and table presenter tests for the conversion command.
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

	class ConversionCommandTestAbility {
		public function execute( $input ) {
			return array(
				'period'          => '2025-07-16 to 2026-07-16',
				'since'           => '2025-07-16 00:00:00',
				'as_of'           => '2026-07-16 00:00:00',
				'entry_blog_id'   => 1,
				'platform_blogs'  => array(),
				'overall'         => array(
					'entry_sessions'  => 1234,
					'reached_any'     => 321,
					'reached_any_rate'=> 0.2601,
					'same_session'    => array( 'events' => 0.1, 'community' => 0.05, 'artist' => 0.02, 'any' => 0.17 ),
					'return'          => array( 'events' => 0.04, 'community' => 0.03, 'artist' => 0.02, 'any' => 0.09 ),
					'returned_rate'   => 0.42,
				),
				'by_article'      => array(
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
				'by_category'     => array(),
			);
		}
	}

	$conversion_command_test_ability = new ConversionCommandTestAbility();

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

	global $conversion_command_test_formats;

	$command = new ConversionCommand();
	$command( array(), array( 'by' => 'article', 'format' => 'json' ) );
	$command( array(), array( 'by' => 'article', 'format' => 'csv' ) );
	$command( array(), array( 'by' => 'article' ) );

	$machine_fields = array( 'post_id', 'title', 'url', 'path', 'entry_sessions', 'reached_any', 'reached_any_rate', 'reached_any_same_count', 'same_session_rate', 'reached_any_return_count', 'return_rate', 'returned_count', 'returned_rate' );
	foreach ( array( 0, 1 ) as $index ) {
		$row = $conversion_command_test_formats[ $index ]['items'][0];
		conversion_command_test_assert_same( $machine_fields, $conversion_command_test_formats[ $index ]['fields'], 'Machine fields must expose stable identity and explicit typed metrics.' );
		conversion_command_test_assert_same( 173, $row['post_id'], 'Post ID must remain an integer.' );
		conversion_command_test_assert_same( 'Mama Say Mama Sa Mama Coosa: The Story Behind an Iconic Michael Jackson Lyric', $row['title'], 'Machine titles must not be truncated.' );
		conversion_command_test_assert_same( 'https://extrachill.com/mama-say-mama-sa-mama-coosa/', $row['url'], 'Machine rows must expose the canonical URL.' );
		conversion_command_test_assert_same( '/mama-say-mama-sa-mama-coosa/', $row['path'], 'Machine rows must expose the canonical path.' );
		conversion_command_test_assert_same( 1234, $row['entry_sessions'], 'Counts must remain integers.' );
		conversion_command_test_assert_same( 0.2601, $row['reached_any_rate'], 'Rates must remain numeric 0..1 values.' );
	}

	$table = $conversion_command_test_formats[2];
	conversion_command_test_assert_same( array( 'title', 'entry_sessions', 'same_any', 'return_any', 'returned', 'reached_any' ), $table['fields'], 'Table columns must remain backward compatible.' );
	conversion_command_test_assert_same( 'Mama Say Mama Sa Mama Coosa: The Story Behind an Iconic…', $table['items'][0]['title'], 'Table titles must retain compact display truncation.' );
	conversion_command_test_assert_same( '1,234', $table['items'][0]['entry_sessions'], 'Table counts must retain human formatting.' );
	conversion_command_test_assert_same( '26.0%', $table['items'][0]['reached_any'], 'Table rates must retain percentage formatting.' );

	fwrite( STDOUT, "ConversionCommand tests passed.\n" );
}
