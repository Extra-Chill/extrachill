<?php
// phpcs:disable -- Isolated lifecycle runtime stubs intentionally retain production class names.
/** Run: php tests/booking-schema-lifecycle.php */
// Doubles are aliased to production names (no duplicate declarations).
namespace ExtraChillEvents\Tests\SchemaLifecycle {
	class BookingSchemaDouble {
		public static function install() { $GLOBALS['order'][] = 'booking'; }
		public static function maybe_install() { self::install(); }
	}
	class QualifyVerdictsTableDouble { public static function create_table() {} public static function maybe_install() {} }
	class ArtistUrlSubmissionsTableDouble { public static function create_table() {} public static function maybe_install() {} }
	class_alias( BookingSchemaDouble::class, 'ExtraChillEvents\\Core\\BookingSchema' );
	class_alias( QualifyVerdictsTableDouble::class, 'ExtraChillEvents\\Core\\QualifyVerdictsTable' );
	class_alias( ArtistUrlSubmissionsTableDouble::class, 'ExtraChillEvents\\Core\\ArtistUrlSubmissionsTable' );
}
namespace {
	define( 'ABSPATH', __DIR__ );
	function do_action( $hook, $context ) {
		if ( 'extrachill_events_after_venue_schema_install' !== $hook ) { throw new RuntimeException( 'Unexpected hook' ); }
		ExtraChillEvents\Providers\BookingProvider::install_schema( $context );
	}
	function flush_rewrite_rules() {}
	// Version-gated, lock-guarded install path: doubles carry no version
	// constants, so every schema reads stale and the lock is always granted.
	function apply_filters( $hook, $value ) { return $value; }
	function delete_option( $name ) { return true; }
	function get_option( $name, $default = false ) { return $default; }
	function get_site_option( $name, $default = false ) { return $default; }
	function wp_cache_delete( $key, $group = '' ) { return true; }
	$GLOBALS['wpdb'] = new class() {
		public $base_prefix = 'wp_';
		public function prepare( $query, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
		public function get_var( $query ) { return '1'; }
	};
	require __DIR__ . '/../../extrachill-events/inc/Providers/LifecycleProvider.php';
	require dirname( __DIR__ ) . '/inc/Providers/BookingProvider.php';
	// Promoter verification has no schema installer.
	$expected = array(
		'activate'             => array( 'booking' ),
		'maybe_install_schema' => array( 'booking' ),
	);
	foreach ( $expected as $method => $order ) {
		$GLOBALS['order'] = array();
		ExtraChillEvents\Providers\LifecycleProvider::$method();
		if ( $order !== $GLOBALS['order'] ) { throw new RuntimeException( $method . ' schema order changed: ' . implode( ',', $GLOBALS['order'] ) ); }
	}
	echo "Booking schema lifecycle order passed.\n";
}
