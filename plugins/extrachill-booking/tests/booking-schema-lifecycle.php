<?php
// phpcs:disable -- Isolated lifecycle runtime stubs intentionally retain production class names.
/** Run: php tests/booking-schema-lifecycle.php */
// Doubles are aliased to production names (no duplicate declarations).
namespace ExtraChillEvents\Tests\SchemaLifecycle {
	class VenueSchemaDouble {
		public static function install() { $GLOBALS['order'][] = 'venue'; }
		public static function maybe_install() { self::install(); }
	}
	class BookingSchemaDouble {
		public static function install() { $GLOBALS['order'][] = 'booking'; }
		public static function maybe_install() { self::install(); }
	}
	class PromoterAuthoritySchemaDouble {
		public static function install() { $GLOBALS['order'][] = 'promoter'; }
		public static function maybe_install() { self::install(); }
	}
	class QualifyVerdictsTableDouble { public static function create_table() {} public static function maybe_install() {} }
	class ArtistUrlSubmissionsTableDouble { public static function create_table() {} public static function maybe_install() {} }
	class_alias( VenueSchemaDouble::class, 'ExtraChillEvents\\Core\\VenueSchema' );
	class_alias( BookingSchemaDouble::class, 'ExtraChillEvents\\Core\\BookingSchema' );
	class_alias( PromoterAuthoritySchemaDouble::class, 'ExtraChillEvents\\Core\\PromoterAuthoritySchema' );
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
	require __DIR__ . '/../../extrachill-events/inc/Providers/LifecycleProvider.php';
	require dirname( __DIR__ ) . '/inc/Providers/BookingProvider.php';
	// Historical order per entry point (main before P1d): activation installs
	// promoter authority first; the plugins_loaded path installs it last.
	$expected = array(
		'activate'             => array( 'promoter', 'venue', 'booking' ),
		'maybe_install_schema' => array( 'venue', 'booking', 'promoter' ),
	);
	foreach ( $expected as $method => $order ) {
		$GLOBALS['order'] = array();
		ExtraChillEvents\Providers\LifecycleProvider::$method();
		if ( $order !== $GLOBALS['order'] ) { throw new RuntimeException( $method . ' schema order changed: ' . implode( ',', $GLOBALS['order'] ) ); }
	}
	echo "Booking schema lifecycle order passed.\n";
}
