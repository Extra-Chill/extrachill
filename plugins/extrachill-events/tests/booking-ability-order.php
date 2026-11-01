<?php
// phpcs:disable -- Standalone runtime doubles and source evaluation isolate the registration list.
/** Run: php tests/booking-ability-order.php */
// Doubles live in a test namespace and are aliased to production names, so
// static analysis never sees a second declaration of a production class.
namespace ExtraChillEvents\Tests\AbilityOrder {
	class SchemaDouble {
		public static $ready = true;
		public static function is_ready() { return static::$ready; }
	}
	class VenueSchemaDouble extends SchemaDouble { public static $ready = true; }
	class BookingSchemaDouble extends SchemaDouble { public static $ready = true; }
	class PromoterAuthoritySchemaDouble extends SchemaDouble { public static $ready = true; }
	class_alias( VenueSchemaDouble::class, 'ExtraChillEvents\\Core\\VenueSchema' );
	class_alias( BookingSchemaDouble::class, 'ExtraChillEvents\\Core\\BookingSchema' );
	class_alias( PromoterAuthoritySchemaDouble::class, 'ExtraChillEvents\\Core\\PromoterAuthoritySchema' );
}
namespace {
	define( 'ABSPATH', __DIR__ );
	function add_filter( $hook, $callback ) { $GLOBALS['filters'][$hook][] = $callback; }
	function apply_filters( $hook, $value ) {
		foreach ( $GLOBALS['filters'][$hook] ?? array() as $callback ) { $value = $callback( $value ); }
		return $value;
	}
	require dirname( __DIR__ ) . '/inc/Providers/BookingProvider.php';
	add_filter( 'extrachill_events_ability_classes', array( ExtraChillEvents\Providers\BookingProvider::class, 'ability_classes' ) );
	$source = file_get_contents( dirname( __DIR__ ) . '/inc/Providers/AbilitiesProvider.php' );
	$start = strpos( $source, '$abilities = array(' );
	$end = strpos( $source, "require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowLocationGuard.php'" );
	$frozen = json_decode( file_get_contents( __DIR__ . '/fixtures/ability-classes-p1d-before.json' ), true );
	foreach ( array( false, true ) as $venue_ready ) {
		foreach ( array( false, true ) as $booking_ready ) {
			foreach ( array( false, true ) as $promoter_ready ) {
				ExtraChillEvents\Core\VenueSchema::$ready = $venue_ready;
				ExtraChillEvents\Core\BookingSchema::$ready = $booking_ready;
				ExtraChillEvents\Core\PromoterAuthoritySchema::$ready = $promoter_ready;
				eval( substr( $source, $start, $end - $start ) );
				$expected = $frozen;
				if ( ! $venue_ready ) { $expected = array_diff( $expected, array_slice( $frozen, 3, 7 ), array( 'PromoterWorkspaceAbilities' ) ); }
				if ( ! $booking_ready ) { $expected = array_diff( $expected, array_slice( $frozen, 10, 12 ) ); }
				if ( ! $promoter_ready ) { $expected = array_diff( $expected, array( 'PromoterAuthorityAbilities', 'PromoterVenueGrantAbilities', 'PromoterWorkspaceAbilities' ) ); }
				if ( array_values( $expected ) !== $abilities ) { throw new RuntimeException( 'Ability order changed: ' . json_encode( array( $venue_ready, $booking_ready, $promoter_ready, array_values( $expected ), $abilities ) ) ); }
			}
		}
	}
	echo "Ability order unchanged for all schema gates.\n";
}
