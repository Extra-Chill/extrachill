<?php
/** Executable tests for the regenerate-og-card CLI adapter (extrachill-network#250). */

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			$GLOBALS['ogr_cli_formats'][] = compact( 'format', 'items', 'fields' );
		}
	}
}

namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	class OgrCliError extends \RuntimeException {}

	class WP_CLI {
		public static $messages = array();
		public static $warnings = array();

		public static function error( $message ) {
			throw new OgrCliError( $message );
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
		private $data;

		public function __construct( $code, $message, $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}

	class OgrCliAbility {
		public $inputs = array();
		public $result = array();

		public function execute( $input ) {
			$this->inputs[] = $input;
			return $this->result;
		}
	}

	$GLOBALS['ogr_cli_ability'] = new OgrCliAbility();

	function wp_get_ability( $name ) {
		if ( 'extrachill/regenerate-og-card' !== $name ) {
			throw new \RuntimeException( 'Unexpected ability requested: ' . $name );
		}
		return $GLOBALS['ogr_cli_ability'];
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function ogr_cli_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
		}
	}

	function ogr_cli_assert_true( $actual, $message ) {
		ogr_cli_assert_same( true, (bool) $actual, $message );
	}

	/** Compare arrays of assoc arrays regardless of key order (key SET/VALUE equality, not insertion order). */
	function ogr_cli_assert_inputs_same( $expected, $actual, $message ) {
		$normalize = static function ( $rows ) {
			return array_map(
				static function ( $row ) {
					ksort( $row );
					return $row;
				},
				$rows
			);
		};
		ogr_cli_assert_same( $normalize( $expected ), $normalize( $actual ), $message );
	}

	function ogr_cli_reset(): void {
		WP_CLI::$messages           = array();
		WP_CLI::$warnings           = array();
		$GLOBALS['ogr_cli_formats'] = array();
		$GLOBALS['ogr_cli_ability'] = new OgrCliAbility();
	}

	require_once dirname( __DIR__ ) . '/inc/Commands/Network/RegenerateOgCardCommand.php';

	use ExtraChill\CLI\Commands\Network\RegenerateOgCardCommand;

	$command = new RegenerateOgCardCommand();

	// -------------------------------------------------------------
	// 1. Argument translation: CLI --dashed-flags map to the ability's
	//    snake_case input contract exactly, with no logic beyond that
	//    translation (the "thin adapter" contract).
	// -------------------------------------------------------------
	ogr_cli_reset();
	$GLOBALS['ogr_cli_ability']->result = array(
		'dry_run'            => false,
		'force'              => true,
		'mode'               => 'single',
		'blog_id'            => 7,
		'requested'          => 1,
		'regenerated'        => 1,
		'reused'             => 0,
		'skipped_ineligible' => 0,
		'has_more'           => false,
		'next_offset'        => null,
		'cards'              => array(
			array(
				'post_id'     => 486727,
				'post_type'   => 'data_machine_events',
				'old_url'     => 'https://events.extrachill.com/wp-content/uploads/og-cards/old.png',
				'new_url'     => 'https://events.extrachill.com/wp-content/uploads/og-cards/new.png',
				'regenerated' => true,
				'reused'      => false,
			),
		),
		'errors'             => array(),
	);

	$command->__invoke( array(), array( 'post-id' => '486727', 'force' => true ) );

	ogr_cli_assert_inputs_same(
		array(
			array(
				'post_id' => 486727,
				'force'   => true,
				'dry_run' => false,
				'limit'   => 25,
				'offset'  => 0,
			),
		),
		$GLOBALS['ogr_cli_ability']->inputs,
		'Command translates --post-id/--force into the exact ability input contract (snake_case, correct types), nothing more.'
	);

	// -------------------------------------------------------------
	// 2. Every selector flag is forwarded: post-type, on-disk, blog-id,
	//    dry-run, limit, offset.
	// -------------------------------------------------------------
	ogr_cli_reset();
	$GLOBALS['ogr_cli_ability']->result = array(
		'dry_run' => true, 'force' => false, 'mode' => 'post_type', 'blog_id' => 7,
		'requested' => 0, 'regenerated' => 0, 'reused' => 0, 'skipped_ineligible' => 0,
		'has_more' => false, 'next_offset' => null, 'cards' => array(), 'errors' => array(),
	);
	$command->__invoke(
		array(),
		array(
			'post-type' => 'data_machine_events',
			'blog-id'   => '7',
			'dry-run'   => true,
			'limit'     => '10',
			'offset'    => '5',
		)
	);
	ogr_cli_assert_inputs_same(
		array(
			array(
				'force'      => false,
				'dry_run'    => true,
				'limit'      => 10,
				'offset'     => 5,
				'post_type'  => 'data_machine_events',
				'blog_id'    => 7,
			),
		),
		$GLOBALS['ogr_cli_ability']->inputs,
		'post-type/blog-id/dry-run/limit/offset all forward to the ability with correct types.'
	);

	ogr_cli_reset();
	$GLOBALS['ogr_cli_ability']->result = array(
		'dry_run' => false, 'force' => false, 'mode' => 'on_disk', 'blog_id' => 7,
		'requested' => 0, 'regenerated' => 0, 'reused' => 0, 'skipped_ineligible' => 0,
		'has_more' => false, 'next_offset' => null, 'cards' => array(), 'errors' => array(),
	);
	$command->__invoke( array(), array( 'on-disk' => true ) );
	ogr_cli_assert_inputs_same(
		array( array( 'force' => false, 'dry_run' => false, 'limit' => 25, 'offset' => 0, 'on_disk' => true ) ),
		$GLOBALS['ogr_cli_ability']->inputs,
		'--on-disk forwards as on_disk => true.'
	);

	// -------------------------------------------------------------
	// 3. Ability WP_Error surfaces as WP_CLI::error() — no swallowing,
	//    no re-interpretation, no logic added on top.
	// -------------------------------------------------------------
	ogr_cli_reset();
	$GLOBALS['ogr_cli_ability']->result = new WP_Error( 'missing_target', 'Specify post_id, post_type, on_disk, or blog_id.' );
	try {
		$command->__invoke( array(), array() );
		throw new \RuntimeException( 'Expected WP_CLI::error() to halt execution.' );
	} catch ( OgrCliError $e ) {
		ogr_cli_assert_same( 'Specify post_id, post_type, on_disk, or blog_id.', $e->getMessage(), 'The ability error message is surfaced verbatim.' );
	}

	// -------------------------------------------------------------
	// 4. Missing ability registration is a clean WP_CLI::error(), not a fatal.
	// -------------------------------------------------------------
	ogr_cli_reset();
	$GLOBALS['ogr_cli_ability'] = null;
	try {
		$command->__invoke( array(), array( 'post-id' => '1' ) );
		throw new \RuntimeException( 'Expected WP_CLI::error() when the ability is unavailable.' );
	} catch ( OgrCliError $e ) {
		ogr_cli_assert_true( str_contains( $e->getMessage(), 'extrachill/regenerate-og-card' ), 'Missing-ability error identifies the canonical ability slug.' );
	}

	// -------------------------------------------------------------
	// 5. Table rendering: cards are formatted, errors are surfaced, and
	//    the has_more/next_offset pagination hint is printed.
	// -------------------------------------------------------------
	ogr_cli_reset();
	$GLOBALS['ogr_cli_ability']->result = array(
		'dry_run'            => false,
		'force'              => true,
		'mode'               => 'post_type',
		'blog_id'            => 7,
		'requested'          => 2,
		'regenerated'        => 1,
		'reused'             => 0,
		'skipped_ineligible' => 1,
		'has_more'           => true,
		'next_offset'        => 25,
		'cards'              => array(
			array(
				'post_id'     => 1,
				'post_type'   => 'data_machine_events',
				'old_url'     => 'https://example.test/old-1.png',
				'new_url'     => 'https://example.test/new-1.png',
				'regenerated' => true,
				'reused'      => false,
			),
			array(
				'post_id'   => 2,
				'post_type' => 'guest-author',
				'old_url'   => 'https://example.test/old-2.png',
				'skipped'   => true,
				'reason'    => 'ineligible_post_type',
			),
		),
		'errors'             => array(
			array( 'post_id' => 3, 'error' => 'Post not found (orphaned card reference).' ),
		),
	);

	$command->__invoke( array(), array( 'post-type' => 'data_machine_events', 'force' => true ) );

	ogr_cli_assert_same( 1, count( $GLOBALS['ogr_cli_formats'] ), 'Exactly one format_items() call for the card table.' );
	ogr_cli_assert_same( 'table', $GLOBALS['ogr_cli_formats'][0]['format'], 'Default format is table.' );
	ogr_cli_assert_same(
		array( 'post_id', 'type', 'status', 'old_url', 'new_url' ),
		$GLOBALS['ogr_cli_formats'][0]['fields'],
		'Card table exposes post_id, type, status, and both URLs.'
	);
	ogr_cli_assert_same( 'regenerated', $GLOBALS['ogr_cli_formats'][0]['items'][0]['status'], 'A regenerated card reports that status.' );
	ogr_cli_assert_same( 'skipped (ineligible_post_type)', $GLOBALS['ogr_cli_formats'][0]['items'][1]['status'], 'A skipped card reports its skip reason.' );

	ogr_cli_assert_same( 1, count( WP_CLI::$warnings ), 'Errors trigger exactly one warning summary line.' );
	$joined_messages = implode( "\n", WP_CLI::$messages );
	ogr_cli_assert_true( str_contains( $joined_messages, 'More candidates remain — resume with --offset=25' ), 'has_more prints the exact next_offset to resume from.' );
	ogr_cli_assert_true( str_contains( $joined_messages, 'Regenerated: 1' ) && str_contains( $joined_messages, 'Skipped (ineligible): 1' ), 'Summary line reports regenerated and skipped counts.' );

	// -------------------------------------------------------------
	// 6. JSON format still routes through format_items (no separate
	//    hand-rolled JSON branch — thin adapter, one rendering path).
	// -------------------------------------------------------------
	ogr_cli_reset();
	$GLOBALS['ogr_cli_ability']->result = array(
		'dry_run' => true, 'force' => true, 'mode' => 'single', 'blog_id' => 7,
		'requested' => 1, 'regenerated' => 1, 'reused' => 0, 'skipped_ineligible' => 0,
		'has_more' => false, 'next_offset' => null,
		'cards'   => array( array( 'post_id' => 1, 'post_type' => 'data_machine_events', 'old_url' => 'a', 'new_url' => 'b', 'regenerated' => true, 'reused' => false ) ),
		'errors'  => array(),
	);
	$command->__invoke( array(), array( 'post-id' => '1', 'force' => true, 'dry-run' => true, 'format' => 'json' ) );
	ogr_cli_assert_same( 'json', $GLOBALS['ogr_cli_formats'][0]['format'], '--format=json is forwarded to format_items unchanged.' );

	fwrite( STDOUT, "RegenerateOgCardCommand tests passed.\n" );
}
