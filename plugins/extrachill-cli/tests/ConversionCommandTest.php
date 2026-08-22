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
		public $inputs = array();

		public function __construct( $result ) {
			$this->result = $result;
		}

		public function execute( $input ) {
			$this->inputs[] = $input;
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
			'overall'               => array(
				'newsletter_signup' => array(
					'direct_source'   => array( 'count' => 12, 'coverage_status' => 'partial' ),
					'visitor_journey' => array( 'same_session_count' => 3, 'later_session_count' => 4, 'coverage_status' => 'measured' ),
				),
				'user_registration' => array(
					'direct_source'   => array( 'count' => null, 'coverage_status' => 'not_instrumented' ),
					'visitor_journey' => array( 'same_session_count' => 1, 'later_session_count' => 2, 'coverage_status' => 'partial' ),
				),
			),
			'by_article'            => array(),
			'by_category'           => array(),
			'coverage'              => array(
				'newsletter_signup' => array(
					'stored_events'                     => 20,
					'confirmed_bot_events_excluded'     => 1,
					'automatic_registration_excluded'   => 1,
					'deduplicated_outcomes'             => 18,
					'duplicate_events'                  => 1,
					'trusted_outcomes'                  => 17,
					'authenticated_server_outcomes'     => 5,
					'visitor_identified_browser_outcomes' => 12,
					'unclassified_outcomes'             => 1,
					'trust_coverage_status'             => 'partial',
					'with_source_url'                   => 15,
					'direct_source_attributed'          => 12,
					'missing_source_url'                => 3,
					'unresolved_source_url'             => 3,
					'with_visitor_identity'             => 18,
					'missing_visitor_identity'          => 0,
					'visitor_journey_attributed'        => 7,
					'identity_without_eligible_journey' => 10,
					'outcome_before_entry'              => 1,
				),
				'user_registration' => array(
					'stored_events'                     => 5,
					'confirmed_bot_events_excluded'     => 0,
					'automatic_registration_excluded'   => 0,
					'deduplicated_outcomes'             => 5,
					'duplicate_events'                  => 0,
					'trusted_outcomes'                  => 5,
					'authenticated_server_outcomes'     => 5,
					'visitor_identified_browser_outcomes' => 0,
					'unclassified_outcomes'             => 0,
					'trust_coverage_status'             => 'measured',
					'with_source_url'                   => 0,
					'direct_source_attributed'          => 0,
					'missing_source_url'                => 5,
					'unresolved_source_url'             => 0,
					'with_visitor_identity'             => 4,
					'missing_visitor_identity'          => 1,
					'visitor_journey_attributed'        => 3,
					'identity_without_eligible_journey' => 1,
					'outcome_before_entry'              => 0,
				),
			),
			'attribution_semantics' => 'The lenses are independent and may both attribute one outcome.',
		),
		'return_observation_days' => 14,
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

	function conversion_command_test_assert_contains( $needle, $haystack, $message ) {
		if ( false === strpos( $haystack, $needle ) ) {
			throw new RuntimeException( $message . '\nMissing: ' . $needle );
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
	$command( array(), array( 'by' => 'article', 'format' => 'json', 'return-observation-days' => '14' ) );
	conversion_command_test_assert_same(
		array(
			'days'                    => 28,
			'session_gap_mins'        => 30,
			'top_articles'            => 25,
			'min_entry_sessions'      => 1,
			'return_observation_days' => 14,
		),
		$conversion_command_test_ability->inputs[0],
		'CLI arguments must map to the owning ability contract.'
	);
	conversion_command_test_assert_same( $conversion_command_test_result, WP_CLI::$printed[0]['value'], 'JSON must preserve the complete typed ability result.' );
	conversion_command_test_assert_same( array( 'format' => 'json' ), WP_CLI::$printed[0]['args'], 'JSON must use WP-CLI structured output.' );
	conversion_command_test_assert_same( 12, WP_CLI::$printed[0]['value']['outcomes']['overall']['newsletter_signup']['direct_source']['count'], 'JSON outcome counts must remain numeric.' );
	conversion_command_test_assert_same( 20, WP_CLI::$printed[0]['value']['outcomes']['coverage']['newsletter_signup']['stored_events'], 'JSON coverage counts must remain numeric.' );
	conversion_command_test_assert_same( $conversion_command_test_result['future_metric'], WP_CLI::$printed[0]['value']['future_metric'], 'JSON must retain newly added ability fields without presenter changes.' );

	$command( array(), array( 'by' => 'article', 'format' => 'csv' ) );
	$command( array(), array( 'by' => 'article' ) );

	$csv = $conversion_command_test_formats[0];
	conversion_command_test_assert_same( array_keys( $conversion_command_test_result ), $csv['fields'], 'CSV must retain every top-level ability field without a presenter allowlist.' );
	conversion_command_test_assert_same( '2025-07-16 to 2026-07-16', $csv['items'][0]['period'], 'CSV scalar values must retain their source type before formatting.' );
	conversion_command_test_assert_same( $conversion_command_test_result['outcomes'], json_decode( $csv['items'][0]['outcomes'], true ), 'CSV must preserve the complete outcomes envelope as JSON.' );
	conversion_command_test_assert_same( $conversion_command_test_result['future_metric'], json_decode( $csv['items'][0]['future_metric'], true ), 'CSV must preserve newly added ability fields without presenter changes.' );

	conversion_command_test_assert_same( 7, $conversion_command_test_ability->inputs[1]['return_observation_days'], 'Default return observation days must match the ability default.' );

	$event_coverage = $conversion_command_test_formats[1];
	conversion_command_test_assert_same( array( 'outcome', 'stored', 'bot_excluded', 'auto_excluded', 'trusted', 'authenticated', 'visitor', 'unclassified', 'trust_coverage', 'deduplicated', 'duplicates' ), $event_coverage['fields'], 'Human output must expose owner-classified outcome trust coverage.' );
	conversion_command_test_assert_same( '20', $event_coverage['items'][0]['stored'], 'Human coverage counts must be formatted for display.' );
	conversion_command_test_assert_same( '17', $event_coverage['items'][0]['trusted'], 'Human coverage must expose the trustworthy outcome count.' );
	conversion_command_test_assert_same( 'partial', $event_coverage['items'][0]['trust_coverage'], 'Human coverage must disclose unclassified outcome gaps.' );

	$direct = $conversion_command_test_formats[2];
	conversion_command_test_assert_same( array( 'outcome', 'count', 'coverage', 'with_source', 'attributed', 'missing_source', 'unresolved_source' ), $direct['fields'], 'Direct-source output must expose source coverage.' );
	conversion_command_test_assert_same( 'partial', $direct['items'][0]['coverage'], 'Direct-source output must identify partial coverage.' );
	conversion_command_test_assert_same( 'n/a', $direct['items'][1]['count'], 'Not-instrumented outcome counts must not be rendered as zero.' );

	$journey = $conversion_command_test_formats[3];
	conversion_command_test_assert_same( array( 'outcome', 'same_session', 'later_session', 'coverage', 'with_identity', 'attributed', 'missing_identity', 'no_entry_journey', 'before_entry' ), $journey['fields'], 'Visitor-journey output must expose identity and journey coverage.' );
	conversion_command_test_assert_same( '3', $journey['items'][0]['same_session'], 'Visitor-journey output must retain same-session attribution.' );
	conversion_command_test_assert_same( '4', $journey['items'][0]['later_session'], 'Visitor-journey output must retain later-session attribution.' );

	$table = $conversion_command_test_formats[4];
	conversion_command_test_assert_same( array( 'title', 'entry_sessions', 'same_any', 'return_any', 'returned', 'reached_any' ), $table['fields'], 'Table columns must remain backward compatible.' );
	conversion_command_test_assert_same( 'Mama Say Mama Sa Mama Coosa: The Story Behind an Iconic…', $table['items'][0]['title'], 'Table titles must retain compact display truncation.' );
	conversion_command_test_assert_same( '1,234', $table['items'][0]['entry_sessions'], 'Table counts must retain human formatting.' );
	conversion_command_test_assert_same( '26.0%', $table['items'][0]['reached_any'], 'Table rates must retain percentage formatting.' );
	conversion_command_test_assert_contains( 'Return observation: 14 completed days.', implode( "\n", WP_CLI::$messages ), 'Human output must disclose conversion maturity.' );
	conversion_command_test_assert_contains( 'Direct-source lens', implode( "\n", WP_CLI::$messages ), 'Human output must label direct-source attribution.' );
	conversion_command_test_assert_contains( 'Visitor-journey lens', implode( "\n", WP_CLI::$messages ), 'Human output must label visitor-journey attribution.' );
	conversion_command_test_assert_contains( 'do not add them as unique people', implode( "\n", WP_CLI::$messages ), 'Human output must explain that attribution lenses overlap.' );

	fwrite( STDOUT, "ConversionCommand tests passed.\n" );
}
