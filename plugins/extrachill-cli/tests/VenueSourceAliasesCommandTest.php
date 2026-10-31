<?php
/**
 * Contract tests for `wp extrachill venues source-aliases`.
 *
 * Run standalone: php tests/VenueSourceAliasesCommandTest.php
 */

namespace {
	// Real WordPress is bootstrapped when get_term_by exists; the doubles below
	// would collide, so everything lives in a conditional block (unconditional
	// declarations are hoisted above any early return).
	$venue_source_aliases_wp_bootstrapped = function_exists( 'get_term_by' );

	if ( ! $venue_source_aliases_wp_bootstrapped ) {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_Term { public $term_id; public function __construct( $id ) { $this->term_id = $id; } }

	class VenueSourceAliasesTestError extends \RuntimeException {}

	class WP_CLI {
		public static $messages = array();
		public static function error( $message ) { throw new VenueSourceAliasesTestError( $message ); }
		public static function log( $message ) { self::$messages[] = $message; }
		public static function line( $message ) { self::$messages[] = $message; }
	}

	class WP_Error {
		private $message;
		public function __construct( $code, $message ) { $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}

	class VenueSourceAliasesTestAbility {
		public $inputs = array();
		private $callback;
		public function __construct( $callback ) { $this->callback = $callback; }
		public function execute( $input ) { $this->inputs[] = $input; return ( $this->callback )( $input ); }
	}

	$GLOBALS['vsa_abilities'] = array();
	$GLOBALS['vsa_terms']     = array();

	function wp_get_ability( $slug ) { return $GLOBALS['vsa_abilities'][ $slug ] ?? null; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function get_term_by( $field, $value, $taxonomy ) { return $GLOBALS['vsa_terms'][ $field . ':' . $value ] ?? false; }

	require_once dirname( __DIR__ ) . '/inc/Commands/Events/VenueDiscoveryCommand.php';

	$failures = 0;
	$assert   = static function ( $condition, $message ) use ( &$failures ) {
		if ( ! $condition ) {
			++$failures;
			fwrite( STDERR, "FAIL: {$message}\n" );
		}
	};
	$command  = new \ExtraChill\CLI\Commands\Events\VenueDiscoveryCommand();

	// Add: comma split keeps a fingerprint whose street contains a comma intact.
	$update = new VenueSourceAliasesTestAbility(
		static fn( $input ) => array(
			'term_id'        => 23143,
			'name'           => 'Round Rock Amp',
			'added'          => $input['add'] ?? array(),
			'removed'        => array(),
			'source_aliases' => $input['add'] ?? array(),
		)
	);
	$GLOBALS['vsa_abilities']['data-machine-events/update-venue-source-aliases'] = $update;
	$command->source_aliases( array( '23143' ), array( 'add' => 'ticketmaster:Z7r9jZa7-j, fingerprint:Round Rock Amphitheater|301 W Bagdad Ave, Round Rock' ) );
	$assert(
		array( 'ticketmaster:Z7r9jZa7-j', 'fingerprint:Round Rock Amphitheater|301 W Bagdad Ave, Round Rock' ) === $update->inputs[0]['add'],
		'splits only before alias prefixes, preserving commas inside a fingerprint'
	);
	$assert( '23143' === $update->inputs[0]['venue'] && ! isset( $update->inputs[0]['remove'] ), 'passes the venue through and omits empty remove' );

	// List: resolves a slug to a term ID for get-venue, which takes an integer id.
	$GLOBALS['vsa_terms']['slug:round-rock-amp'] = new WP_Term( 23143 );
	$get = new VenueSourceAliasesTestAbility(
		static fn( $input ) => array(
			'term_id'        => $input['id'],
			'name'           => 'Round Rock Amp',
			'source_aliases' => array( 'ticketmaster:Z7r9jZa7-j' ),
		)
	);
	$GLOBALS['vsa_abilities']['data-machine-events/get-venue'] = $get;
	WP_CLI::$messages = array();
	$command->source_aliases( array( 'round-rock-amp' ), array( 'format' => 'json' ) );
	$assert( array( 'id' => 23143 ) === $get->inputs[0], 'list resolves a slug to the term ID' );
	$assert( '{"term_id":23143,"name":"Round Rock Amp","source_aliases":["ticketmaster:Z7r9jZa7-j"]}' === end( WP_CLI::$messages ), 'json output carries the aliases' );

	// Ability errors surface as CLI errors instead of a silent success.
	$GLOBALS['vsa_abilities']['data-machine-events/update-venue-source-aliases'] = new VenueSourceAliasesTestAbility(
		static fn() => new WP_Error( 'venue_alias_conflict', 'Alias "ticketmaster:X" already belongs to venue 2 (Other).' )
	);
	try {
		$command->source_aliases( array( '23143' ), array( 'add' => 'ticketmaster:X' ) );
		$assert( false, 'conflict must error' );
	} catch ( VenueSourceAliasesTestError $error ) {
		$assert( str_contains( $error->getMessage(), 'already belongs to venue 2' ), 'conflict message is surfaced' );
	}

	// Missing ability (wrong --url) fails with guidance.
	unset( $GLOBALS['vsa_abilities']['data-machine-events/get-venue'] );
	try {
		$command->source_aliases( array( '23143' ), array() );
		$assert( false, 'missing ability must error' );
	} catch ( VenueSourceAliasesTestError $error ) {
		$assert( str_contains( $error->getMessage(), '--url=events.extrachill.com' ), 'missing ability points at the events site' );
	}

	if ( $failures ) {
		exit( 1 );
	}
	echo "VenueSourceAliasesCommandTest: 7 assertions passed.\n";
	}
}
