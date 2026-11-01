<?php
// phpcs:disable -- Isolated lifecycle runtime stubs intentionally retain production class names.
/** Run: php tests/booking-schema-lifecycle.php */
namespace ExtraChillEvents\Core {
	class VenueSchema {
		public static function install() { $GLOBALS['order'][] = 'venue'; }
		public static function maybe_install() { self::install(); }
	}
	class BookingSchema {
		public static function install() { $GLOBALS['order'][] = 'booking'; }
		public static function maybe_install() { self::install(); }
	}
	class PromoterAuthoritySchema {
		public static function install() { $GLOBALS['order'][] = 'promoter'; }
		public static function maybe_install() { self::install(); }
	}
	class QualifyVerdictsTable { public static function create_table() {} public static function maybe_install() {} }
	class ArtistUrlSubmissionsTable { public static function create_table() {} public static function maybe_install() {} }
}
namespace {
	define( 'ABSPATH', __DIR__ );
	function do_action( $hook, $context ) {
		if ( 'extrachill_events_after_venue_schema_install' !== $hook ) { throw new RuntimeException( 'Unexpected hook' ); }
		ExtraChillEvents\Providers\BookingProvider::install_schema( $context );
	}
	function flush_rewrite_rules() {}
	require dirname( __DIR__ ) . '/inc/Providers/LifecycleProvider.php';
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
	$entry = file_get_contents( dirname( __DIR__ ) . '/extrachill-events.php' );
	$facade = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Plugin.php' );
	if ( ! str_contains( $entry, 'extrachill_events();' ) || ! str_contains( $facade, '\\ExtraChillEvents\\Providers\\BookingProvider::register();' ) ) {
		throw new RuntimeException( 'Activation requires synchronous provider registration' );
	}
	echo "Booking schema lifecycle order passed.\n";
}
