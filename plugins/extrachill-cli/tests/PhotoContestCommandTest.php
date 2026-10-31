<?php
/** Executable tests for the photo-contest CLI adapter (extrachill-cli#164). */

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			$GLOBALS['pcc_cli_formats'][] = compact( 'format', 'items', 'fields' );
		}
	}
}

namespace {
	use ExtraChill\CLI\Commands\Content\PhotoContestCommand;

	// Real WordPress (the managed PHPUnit harness loads every *Test.php) already
	// declares WP_CLI, WP_Error, wp_get_ability and is_wp_error, so the doubles
	// below would fatal. Run standalone: php tests/PhotoContestCommandTest.php.
	$pcc_cli_wp_bootstrapped = function_exists( 'wp_get_ability' ) || class_exists( 'WP_Error', false );

	if ( ! $pcc_cli_wp_bootstrapped ) {
	define( 'ABSPATH', __DIR__ . '/' );

	class PccCliError extends \RuntimeException {}

	class WP_CLI {
		public static $messages = array();
		public static $warnings = array();

		public static function error( $message ) {
			throw new PccCliError( $message );
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}

		public static function success( $message ) {
			self::$messages[] = 'Success: ' . $message;
		}

		public static function warning( $message ) {
			self::$warnings[] = $message;
		}
	}

	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code, $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}

	class PccCliAbility {
		public $inputs = array();
		public $result = array();

		public function execute( $input ) {
			$this->inputs[] = $input;
			return $this->result;
		}
	}

	// Each ability slug resolves to its own double; a null entry simulates a
	// missing registration (owner plugin inactive).
	$GLOBALS['pcc_cli_abilities'] = array();

	function wp_get_ability( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['pcc_cli_abilities'] ) ) {
			throw new \RuntimeException( 'Unexpected ability requested: ' . $name );
		}
		return $GLOBALS['pcc_cli_abilities'][ $name ];
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function pcc_cli_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
		}
	}

	function pcc_cli_assert_true( $actual, $message ) {
		pcc_cli_assert_same( true, (bool) $actual, $message );
	}

	function pcc_cli_reset(): void {
		WP_CLI::$messages             = array();
		WP_CLI::$warnings             = array();
		$GLOBALS['pcc_cli_formats']   = array();
		$GLOBALS['pcc_cli_abilities'] = array(
			'extrachill/photo-contest-top'  => new PccCliAbility(),
			'extrachill/photo-contest-hide' => new PccCliAbility(),
			'extrachill/photo-contest-list' => new PccCliAbility(),
		);
	}

	require_once dirname( __DIR__ ) . '/inc/Commands/Content/PhotoContestCommand.php';

	$command = new PhotoContestCommand();

	$pcc_sample_entry = array(
		'id'            => 98765,
		'display_name'  => 'Chubes',
		'instagram'     => 'extrachill',
		'image_url'     => 'https://extrachill.com/wp-content/uploads/entry.jpg',
		'thumbnail_url' => 'https://extrachill.com/wp-content/uploads/entry-150x150.jpg',
		'vote_count'    => 42,
		'hidden'        => false,
		'share_url'     => 'https://extrachill.com/contest/?entry=98765',
	);

	// -------------------------------------------------------------
	// 1. top: CLI --dashed-flags map to the ability's snake_case input
	//    contract exactly (post_id/contest_id/count/include_hidden), and the
	//    ranked table exposes the documented columns.
	// -------------------------------------------------------------
	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-top']->result = array(
		'entries' => array(
			$pcc_sample_entry,
			array(
				'id'           => 98766,
				'display_name' => 'Anon',
				'instagram'    => '',
				'vote_count'   => 7,
				'hidden'       => true,
				'share_url'    => 'https://extrachill.com/contest/?entry=98766',
			),
		),
	);

	$command->top(
		array(),
		array(
			'post'           => '12345',
			'contest'        => 'contest-1729',
			'limit'          => '5',
			'include-hidden' => true,
		)
	);

	pcc_cli_assert_same(
		array(
			array(
				'post_id'        => 12345,
				'contest_id'     => 'contest-1729',
				'count'          => 5,
				'include_hidden' => true,
			),
		),
		$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-top']->inputs,
		'top translates --post/--contest/--limit/--include-hidden into the exact ability input contract (snake_case, correct types), nothing more.'
	);

	pcc_cli_assert_same( 1, count( $GLOBALS['pcc_cli_formats'] ), 'Exactly one format_items() call for the ranked table.' );
	pcc_cli_assert_same( 'table', $GLOBALS['pcc_cli_formats'][0]['format'], 'Default format is table.' );
	pcc_cli_assert_same(
		array( 'rank', 'entry_id', 'votes', 'name', 'instagram', 'hidden', 'share_url' ),
		$GLOBALS['pcc_cli_formats'][0]['fields'],
		'Ranked table exposes rank, entry_id, votes, name, instagram, hidden, share_url.'
	);
	pcc_cli_assert_same(
		array(
			'rank'      => 1,
			'entry_id'  => 98765,
			'votes'     => 42,
			'name'      => 'Chubes',
			'instagram' => '@extrachill',
			'hidden'    => '',
			'share_url' => 'https://extrachill.com/contest/?entry=98765',
		),
		$GLOBALS['pcc_cli_formats'][0]['items'][0],
		'First row is rank 1 with an @-prefixed instagram handle and a blank hidden flag.'
	);
	pcc_cli_assert_same( 2, $GLOBALS['pcc_cli_formats'][0]['items'][1]['rank'], 'Rank increments per entry.' );
	pcc_cli_assert_same( 'yes', $GLOBALS['pcc_cli_formats'][0]['items'][1]['hidden'], 'Hidden entries render hidden=yes.' );
	pcc_cli_assert_same( '', $GLOBALS['pcc_cli_formats'][0]['items'][1]['instagram'], 'Entries without an instagram handle stay blank (no bare @).' );

	// -------------------------------------------------------------
	// 2. top defaults: count falls back to 10 and include_hidden is omitted
	//    when the flag is absent; --format=json/csv forwards to format_items
	//    unchanged (one rendering path, no hand-rolled JSON branch).
	// -------------------------------------------------------------
	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-top']->result = array( 'entries' => array( $pcc_sample_entry ) );
	$command->top(
		array(),
		array(
			'post'    => '12345',
			'contest' => 'contest-1729',
			'format'  => 'json',
		)
	);
	pcc_cli_assert_same(
		array(
			array(
				'post_id'    => 12345,
				'contest_id' => 'contest-1729',
				'count'      => 10,
			),
		),
		$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-top']->inputs,
		'top defaults count to 10 and omits include_hidden when the flag is absent.'
	);
	pcc_cli_assert_same( 'json', $GLOBALS['pcc_cli_formats'][0]['format'], '--format=json is forwarded to format_items unchanged.' );

	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-top']->result = array( 'entries' => array( $pcc_sample_entry ) );
	$command->top( array(), array( 'post' => '12345', 'contest' => 'contest-1729', 'format' => 'csv' ) );
	pcc_cli_assert_same( 'csv', $GLOBALS['pcc_cli_formats'][0]['format'], '--format=csv is forwarded to format_items unchanged.' );

	// -------------------------------------------------------------
	// 3. top: an empty ranking is a warning, not a table or an error.
	// -------------------------------------------------------------
	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-top']->result = array( 'entries' => array() );
	$command->top( array(), array( 'post' => '12345', 'contest' => 'contest-1729' ) );
	pcc_cli_assert_same( array( 'No entries found.' ), WP_CLI::$warnings, 'Empty rankings warn instead of rendering a table.' );
	pcc_cli_assert_same( 0, count( $GLOBALS['pcc_cli_formats'] ), 'No format_items() call when there are no entries.' );

	// -------------------------------------------------------------
	// 4. top: ability WP_Error surfaces as WP_CLI::error() verbatim — the
	//    ability owns validation (missing post/contest included).
	// -------------------------------------------------------------
	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-top']->result = new WP_Error( 'invalid_contest_id', 'A contest ID is required.' );
	try {
		$command->top( array(), array( 'post' => '12345' ) );
		throw new \RuntimeException( 'Expected WP_CLI::error() to halt execution.' );
	} catch ( PccCliError $e ) {
		pcc_cli_assert_same( 'A contest ID is required.', $e->getMessage(), 'The ability error message is surfaced verbatim.' );
	}

	// -------------------------------------------------------------
	// 5. Missing ability registration is a clean WP_CLI::error() naming the
	//    canonical ability slug and the owner plugin — not a fatal.
	// -------------------------------------------------------------
	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-top'] = null;
	try {
		$command->top( array(), array( 'post' => '12345', 'contest' => 'contest-1729' ) );
		throw new \RuntimeException( 'Expected WP_CLI::error() when the ability is unavailable.' );
	} catch ( PccCliError $e ) {
		pcc_cli_assert_true( str_contains( $e->getMessage(), 'extrachill/photo-contest-top' ), 'Missing-ability error identifies the canonical ability slug.' );
		pcc_cli_assert_true( str_contains( $e->getMessage(), 'extrachill-content-blocks' ), 'Missing-ability error points at the owner plugin.' );
	}

	// -------------------------------------------------------------
	// 6. hide: positional <entry_id> maps to entry_id with hidden=true by
	//    default; --unhide flips hidden to false; the ability message is the
	//    success output.
	// -------------------------------------------------------------
	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-hide']->result = array( 'message' => 'Entry 98765 hidden.' );
	$command->hide( array( '98765' ), array() );
	pcc_cli_assert_same(
		array(
			array(
				'entry_id' => 98765,
				'hidden'   => true,
			),
		),
		$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-hide']->inputs,
		'hide maps the positional entry ID to entry_id with hidden=true by default.'
	);
	pcc_cli_assert_same( array( 'Success: Entry 98765 hidden.' ), WP_CLI::$messages, 'The ability message is the success output.' );

	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-hide']->result = array( 'message' => 'Entry 98765 restored.' );
	$command->hide( array( '98765' ), array( 'unhide' => true ) );
	pcc_cli_assert_same(
		array(
			array(
				'entry_id' => 98765,
				'hidden'   => false,
			),
		),
		$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-hide']->inputs,
		'--unhide flips hidden to false.'
	);
	pcc_cli_assert_same( array( 'Success: Entry 98765 restored.' ), WP_CLI::$messages, 'Restore surfaces the ability message.' );

	// -------------------------------------------------------------
	// 7. hide: a missing/invalid entry ID errors before touching the
	//    ability; ability WP_Errors pass through verbatim.
	// -------------------------------------------------------------
	pcc_cli_reset();
	try {
		$command->hide( array(), array() );
		throw new \RuntimeException( 'Expected WP_CLI::error() for a missing entry ID.' );
	} catch ( PccCliError $e ) {
		pcc_cli_assert_same( 'A valid entry ID is required.', $e->getMessage(), 'Missing entry ID is a clean usage error.' );
	}
	pcc_cli_assert_same( array(), $GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-hide']->inputs, 'The ability is never executed without a valid entry ID.' );

	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-hide']->result = new WP_Error( 'entry_not_found', 'Entry not found.' );
	try {
		$command->hide( array( '98765' ), array() );
		throw new \RuntimeException( 'Expected WP_CLI::error() to halt execution.' );
	} catch ( PccCliError $e ) {
		pcc_cli_assert_same( 'Entry not found.', $e->getMessage(), 'hide surfaces the ability error message verbatim.' );
	}

	// -------------------------------------------------------------
	// 8. list: --post/--contest/--include-hidden map to the ability input;
	//    rows render without a rank column; entries missing the hidden key
	//    (public payloads strip it) render a blank hidden cell.
	// -------------------------------------------------------------
	pcc_cli_reset();
	$pcc_public_entry = $pcc_sample_entry;
	unset( $pcc_public_entry['hidden'] );
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-list']->result = array( 'entries' => array( $pcc_public_entry ) );
	$command->list(
		array(),
		array(
			'post'    => '12345',
			'contest' => 'contest-1729',
		)
	);
	pcc_cli_assert_same(
		array(
			array(
				'post_id'    => 12345,
				'contest_id' => 'contest-1729',
			),
		),
		$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-list']->inputs,
		'list translates --post/--contest into the exact ability input contract and omits include_hidden when absent.'
	);
	pcc_cli_assert_same(
		array( 'entry_id', 'votes', 'name', 'instagram', 'hidden', 'share_url' ),
		$GLOBALS['pcc_cli_formats'][0]['fields'],
		'List table exposes entry_id, votes, name, instagram, hidden, share_url (no rank).'
	);
	pcc_cli_assert_same( '', $GLOBALS['pcc_cli_formats'][0]['items'][0]['hidden'], 'Public payloads without a hidden key render a blank hidden cell.' );

	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-list']->result = array( 'entries' => array( $pcc_sample_entry ) );
	$command->list(
		array(),
		array(
			'post'           => '12345',
			'contest'        => 'contest-1729',
			'include-hidden' => true,
			'format'         => 'json',
		)
	);
	pcc_cli_assert_same(
		array(
			array(
				'post_id'        => 12345,
				'contest_id'     => 'contest-1729',
				'include_hidden' => true,
			),
		),
		$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-list']->inputs,
		'list forwards --include-hidden as include_hidden => true.'
	);
	pcc_cli_assert_same( 'json', $GLOBALS['pcc_cli_formats'][0]['format'], 'list forwards --format=json to format_items unchanged.' );

	// -------------------------------------------------------------
	// 9. list: ability WP_Error (e.g. contest_not_found) passes through, and
	//    an empty list is a warning.
	// -------------------------------------------------------------
	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-list']->result = new WP_Error( 'contest_not_found', 'Contest not found.' );
	try {
		$command->list( array(), array( 'post' => '12345', 'contest' => 'nope' ) );
		throw new \RuntimeException( 'Expected WP_CLI::error() to halt execution.' );
	} catch ( PccCliError $e ) {
		pcc_cli_assert_same( 'Contest not found.', $e->getMessage(), 'list surfaces the ability error message verbatim.' );
	}

	pcc_cli_reset();
	$GLOBALS['pcc_cli_abilities']['extrachill/photo-contest-list']->result = array( 'entries' => array() );
	$command->list( array(), array( 'post' => '12345', 'contest' => 'contest-1729' ) );
	pcc_cli_assert_same( array( 'No entries found.' ), WP_CLI::$warnings, 'Empty lists warn instead of rendering a table.' );

	fwrite( STDOUT, "PhotoContestCommand tests passed.\n" );
}
}
