<?php
/** Executable tests for the Link Pages migration CLI adapter. */

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			$GLOBALS['migration_cli_formats'][] = compact( 'format', 'items', 'fields' );
		}
	}
}

namespace {
	if ( function_exists( 'wp_get_ability' ) ) {
		class StorageMigrationCommandDiscoveryTest extends WP_UnitTestCase {
			public function test_standalone_contract_is_wired_into_homeboy() {
				$this->assertFileExists( dirname( __DIR__ ) . '/homeboy.json' );
			}
		}
		return;
	}

	define( 'ABSPATH', __DIR__ . '/' );

	class MigrationCliError extends \RuntimeException {}
	class MigrationCliHalt extends \RuntimeException {}

	class WP_CLI {
		public static $messages = array();
		public static function error( $message ) {
			throw new MigrationCliError( $message ); }
		public static function line( $message ) {
			self::$messages[] = $message; }
		public static function log( $message ) {
			self::$messages[] = $message; }
		public static function halt( $code ) {
			throw new MigrationCliHalt( 'halt', (int) $code ); }
	}

	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code, $message, $data = null ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data; }
		public function get_error_code() {
			return $this->code; }
		public function get_error_message() {
			return $this->message; }
		public function get_error_data() {
			return $this->data; }
	}

	class MigrationCliAbility {
		public $inputs = array();
		public $result = array( 'mode' => 'plan', 'fingerprint' => 'abc', 'ready' => true );
		public function execute( $input ) {
			$this->inputs[] = $input;
			return $this->result; }
	}

	class MigrationCliParticipantRegistry {
		public $participants = array();
		public function snapshot() {
			return $this->participants; }
	}

	$GLOBALS['migration_cli_ability'] = new MigrationCliAbility();
	$GLOBALS['migration_cli_registry'] = new MigrationCliParticipantRegistry();
	$GLOBALS['migration_cli_blog_id'] = 4;
	$GLOBALS['migration_cli_sites'] = array( 4 => true, 13 => true );

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( $name ) {
			return 'extrachill/migrate-link-page-storage' === $name ? $GLOBALS['migration_cli_ability'] : null; }
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ) {
			return $value instanceof WP_Error; }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) {
			return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		function ec_get_blog_id( $key ) {
			return array( 'artist' => 4, 'link_pages' => 13 )[ $key ] ?? 0; }
	}
	if ( ! function_exists( 'get_current_blog_id' ) ) {
		function get_current_blog_id() {
			return $GLOBALS['migration_cli_blog_id']; }
	}
	if ( ! function_exists( 'get_site' ) ) {
		function get_site( $blog_id ) {
			return ! empty( $GLOBALS['migration_cli_sites'][ $blog_id ] ) ? (object) array( 'blog_id' => $blog_id ) : null; }
	}
	if ( ! function_exists( 'ec_link_page_migration_participant_registry' ) ) {
		function ec_link_page_migration_participant_registry() {
			return $GLOBALS['migration_cli_registry']; }
	}

	function migration_cli_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) ); } }
	function migration_cli_assert( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message ); } }
	function migration_cli_reset() {
		WP_CLI::$messages = array();
		$GLOBALS['migration_cli_formats'] = array();
		$GLOBALS['migration_cli_ability'] = new MigrationCliAbility();
		$GLOBALS['migration_cli_registry']->participants = array(
			array( 'name' => 'artist-platform', 'contract_version' => '1' ),
			array( 'name' => 'analytics', 'contract_version' => '1' ),
		);
		$GLOBALS['migration_cli_blog_id'] = 4;
	}
	function migration_cli_expect_error( $command, $args, $assoc_args, $message ) {
		try {
			$command( $args, $assoc_args );
		} catch ( MigrationCliError $error ) {
			migration_cli_assert( false !== strpos( $error->getMessage(), $message ), 'Unexpected table error: ' . $error->getMessage() );
			return $error->getMessage();
		}
		throw new \RuntimeException( 'Expected table error: ' . $message );
	}
	function migration_cli_expect_json_error( $command, $args, $assoc_args, $code ) {
		try {
			$command( $args, $assoc_args + array( 'format' => 'json' ) );
		} catch ( MigrationCliHalt $halt ) {
			migration_cli_assert_same( 1, $halt->getCode(), 'JSON errors must halt nonzero.' );
			migration_cli_assert_same( 1, count( WP_CLI::$messages ), 'JSON errors must emit exactly one line.' );
			$decoded = json_decode( WP_CLI::$messages[0], true );
			migration_cli_assert( is_array( $decoded ), 'JSON error output must be parseable without an Error: prefix.' );
			migration_cli_assert_same( $code, $decoded['code'], 'JSON error code mismatch.' );
			migration_cli_assert( array_key_exists( 'data', $decoded ), 'JSON error data must always be present.' );
			return $decoded;
		}
		throw new \RuntimeException( 'Expected JSON error: ' . $code );
	}

	require_once dirname( __DIR__ ) . '/inc/CommandRegistry.php';
	require_once dirname( __DIR__ ) . '/inc/Commands/LinkPages/StorageMigrationCommand.php';

	$command = new \ExtraChill\CLI\Commands\LinkPages\StorageMigrationCommand();
	migration_cli_assert_same(
		\ExtraChill\CLI\Commands\LinkPages\StorageMigrationCommand::class,
		\ExtraChill\CLI\CommandRegistry::map()['extrachill link-pages migrate-storage'] ?? null,
		'The exact platform command must be registered.'
	);

	migration_cli_reset();
	$command( array(), array( 'source' => 'artist', 'destination' => 'link_pages' ) );
	migration_cli_assert_same(
		array(
			'mode'                  => 'plan',
			'source_blog_id'        => 4,
			'destination_blog_id'   => 13,
			'required_participants' => array( 'analytics' ),
		),
		$GLOBALS['migration_cli_ability']->inputs[0],
		'Plan must map exact sites and caller-required participants.'
	);
	migration_cli_assert_same( 'Dry run only. No options, database rows, files, routing, or source data were changed.', WP_CLI::$messages[0], 'Plan must identify itself as read-only.' );

	migration_cli_reset();
	$command( array(), array( 'source' => '4', 'destination' => '13', 'apply' => true, 'expect' => 'fingerprint-1' ) );
	migration_cli_assert_same( 'apply', $GLOBALS['migration_cli_ability']->inputs[0]['mode'], 'Apply mode mapping failed.' );
	migration_cli_assert_same( 'fingerprint-1', $GLOBALS['migration_cli_ability']->inputs[0]['expected_fingerprint'], 'Apply fingerprint mapping failed.' );

	foreach ( array( 'validate', 'rollback' ) as $mode ) {
		migration_cli_reset();
		$GLOBALS['migration_cli_ability']->result = array( 'mode' => $mode, 'journal_id' => 'journal-1', 'status' => 'valid' );
		$command( array(), array( $mode => 'journal-1', 'format' => 'json' ) );
		migration_cli_assert_same( array( 'mode' => $mode, 'journal_id' => 'journal-1' ), $GLOBALS['migration_cli_ability']->inputs[0], ucfirst( $mode ) . ' mapping failed.' );
		migration_cli_assert_same( $GLOBALS['migration_cli_ability']->result, json_decode( WP_CLI::$messages[0], true ), ucfirst( $mode ) . ' JSON result was incomplete.' );
	}

	migration_cli_reset();
	$GLOBALS['migration_cli_ability']->result = array(
		'mode' => 'plan', 'counts' => array( 'posts' => 2 ), 'collisions' => array(), 'missing' => array(), 'participants' => array( 'analytics' => array( 'rows' => 2 ) ), 'validation' => null,
	);
	$command( array(), array( 'source' => '4', 'destination' => '13' ) );
	$rows = $GLOBALS['migration_cli_formats'][0]['items'];
	migration_cli_assert_same( array_keys( $GLOBALS['migration_cli_ability']->result ), array_column( $rows, 'field' ), 'Table output must contain every top-level result field.' );

	migration_cli_reset();
	$GLOBALS['migration_cli_blog_id'] = 13;
	migration_cli_expect_error( $command, array(), array( 'source' => '4', 'destination' => '13' ), 'global --user=<network-admin>' );

	migration_cli_reset();
	$GLOBALS['migration_cli_ability'] = null;
	migration_cli_expect_json_error( $command, array(), array( 'source' => '4', 'destination' => '13' ), 'migration_ability_unavailable' );

	foreach ( array( null, '0' ) as $analytics_version ) {
		migration_cli_reset();
		$GLOBALS['migration_cli_registry']->participants = array( array( 'name' => 'artist-platform', 'contract_version' => '1' ) );
		if ( null !== $analytics_version ) {
			$GLOBALS['migration_cli_registry']->participants[] = array( 'name' => 'analytics', 'contract_version' => $analytics_version ); }
		$error = migration_cli_expect_json_error( $command, array(), array( 'source' => '4', 'destination' => '13' ), 'required_participant_unavailable' );
		migration_cli_assert_same( 'analytics', $error['data']['participant'], 'Required participant diagnostics were lost.' );
	}

	migration_cli_reset();
	$GLOBALS['migration_cli_ability']->result = new WP_Error( 'rest_forbidden', 'Network permission required.', array( 'capability' => 'manage_network_options' ) );
	$error = migration_cli_expect_json_error( $command, array(), array( 'source' => '4', 'destination' => '13' ), 'rest_forbidden' );
	migration_cli_assert_same( 'manage_network_options', $error['data']['capability'], 'Permission error data was lost.' );
	migration_cli_assert( false !== strpos( $error['message'], 'global --user=<network-admin>' ), 'JSON permission guidance was lost.' );

	migration_cli_reset();
	$GLOBALS['migration_cli_ability']->result = new WP_Error( 'migration_failed', 'Apply failed.', array( 'journal_id' => 'journal-2', 'journal_status' => 'failed', 'step' => 'copy' ) );
	$message = migration_cli_expect_error( $command, array(), array( 'source' => '4', 'destination' => '13' ), 'Diagnostics:' );
	migration_cli_assert( false !== strpos( $message, '--rollback=journal-2' ), 'Rollbackable table errors must include rollback guidance.' );
	migration_cli_reset();
	$GLOBALS['migration_cli_ability']->result = new WP_Error( 'migration_failed', 'Apply failed.', array( 'journal_id' => 'journal-3', 'journal_status' => 'rolled_back', 'step' => 'copy' ) );
	$message = migration_cli_expect_error( $command, array(), array( 'source' => '4', 'destination' => '13' ), 'Diagnostics:' );
	migration_cli_assert( false === strpos( $message, '--rollback=' ), 'Non-rollbackable table errors must not include rollback guidance.' );

	$invalid_cases = array(
		array( array( 'extra' ), array( 'source' => '4', 'destination' => '13' ), 'invalid_positional_arguments' ),
		array( array(), array( 'source' => '4', 'destination' => '13', 'bogus' => true ), 'unknown_arguments' ),
		array( array(), array( 'validate' => '' ), 'missing_journal_id' ),
		array( array(), array( 'rollback' => ' ' ), 'missing_journal_id' ),
		array( array(), array( 'apply' => true, 'validate' => 'journal' ), 'conflicting_modes' ),
		array( array(), array( 'validate' => 'journal', 'rollback' => 'journal' ), 'conflicting_modes' ),
		array( array(), array( 'expect' => 'fingerprint', 'source' => '4', 'destination' => '13' ), 'invalid_expectation_mode' ),
		array( array(), array( 'validate' => 'journal', 'source' => '4' ), 'conflicting_journal_arguments' ),
		array( array(), array( 'source' => '4', 'destination' => '4' ), 'invalid_sites' ),
		array( array(), array( 'source' => '-1', 'destination' => '13' ), 'invalid_sites' ),
		array( array(), array( 'source' => 'unknown', 'destination' => '13' ), 'invalid_sites' ),
	);
	foreach ( $invalid_cases as $case ) {
		migration_cli_reset();
		migration_cli_expect_json_error( $command, $case[0], $case[1], $case[2] );
	}

	fwrite( STDOUT, "StorageMigrationCommand tests passed.\n" );
}
