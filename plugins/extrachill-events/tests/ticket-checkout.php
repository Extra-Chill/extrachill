<?php
// phpcs:ignoreFile -- Standalone test harness that stubs WordPress functions.
/** Standalone rendering regression test: php tests/ticket-checkout.php */
define( 'ABSPATH', __DIR__ );
define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function ec_is_events_site() { return true; }
function is_singular( $type ) { return $GLOBALS['singular']; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain = '' ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html_e( $value, $domain = '' ) { echo esc_html__( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function wp_kses_post( $value ) { return $value; }
function number_format_i18n( $value, $decimals = 0 ) { return number_format( $value, $decimals ); }
function ec_render_turnstile_widget( $args ) { return '<div class="cf-turnstile"></div>'; }
function wp_get_ability( $name ) {
	check( 'extrachill/get-event-ticket-offer' === $name, 'Public offer ability used' );
	return new class {
		public function execute( $input ) {
			check( array( 'event' => 42 ) === $input, 'Event reference supplied' );
			return $GLOBALS['offer'];
		}
	};
}
function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function render_checkout() {
	ob_start();
	extrachill_events_render_ticket_checkout( 42, 'https://external.example/tickets', 'upcoming' );
	return ob_get_clean();
}
require EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/ticket-checkout.php';
require EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/ad-free-pages.php';
$GLOBALS['singular'] = true;
$_GET = array();
$GLOBALS['offer'] = array( 'enabled' => true, 'sale_open' => true, 'price_minor' => 2500, 'currency' => 'usd', 'remaining' => 3 );
$html = render_checkout();
foreach ( array( 'Buy tickets', 'USD 25.00', '3 remaining', 'max="3"', 'name="quantity"', 'name="name"', 'name="email"', 'cf-turnstile', 'class="button-1"' ) as $expected ) {
	check( str_contains( $html, $expected ), 'Open offer: ' . $expected );
}
check( ! extrachill_events_is_ad_free_page(), 'Ordinary ticket page is not ad-free' );
$GLOBALS['offer']['remaining'] = null;
check( str_contains( render_checkout(), 'max="10"' ), 'Unlimited capacity respects checkout limit' );
$GLOBALS['offer']['sale_open'] = false;
check( '' === render_checkout(), 'Closed sale with room left (past show) is hidden, not sold out' );
$GLOBALS['offer']['remaining'] = 0;
check( str_contains( render_checkout(), 'Sold out' ), 'Sold-out state' );
check( ! str_contains( render_checkout(), '<form' ), 'Sold-out has no form' );
$GLOBALS['offer']['enabled'] = false;
check( '' === render_checkout(), 'Disabled offer is hidden' );
$GLOBALS['offer'] = null;
check( '' === render_checkout(), 'Missing ability result is hidden' );
$_GET = array( 'tickets' => 'success', 'session_id' => 'cs_test_example' );
check( str_contains( render_checkout(), 'Your tickets are on the way' ), 'Success confirmation independent of offer availability' );
check( str_contains( render_checkout(), 'passes arrive by email' ), 'Email delivery note' );
check( extrachill_events_is_ad_free_page(), 'Success is ad-free' );
$_GET = array( 'tickets' => 'cancelled' );
check( str_contains( render_checkout(), 'Checkout was cancelled' ), 'Neutral cancellation' );
check( extrachill_events_is_ad_free_page(), 'Cancellation is ad-free' );
$GLOBALS['offer'] = array( 'enabled' => true, 'sale_open' => true, 'price_minor' => 1000, 'currency' => 'usd', 'remaining' => 5 );
check( str_contains( render_checkout(), '<form' ), 'Cancelled checkout can retry' );
$_GET = array( 'tickets' => 'success' );
check( '' === extrachill_events_ticket_return_state(), 'Success needs session reference' );
$_GET = array( 'tickets' => array( 'success' ) );
check( '' === extrachill_events_ticket_return_state(), 'Malformed state ignored' );
$_GET = array( 'tickets' => 'success', 'session_id' => array( 'cs_test' ) );
check( '' === extrachill_events_ticket_return_state(), 'Malformed session ignored' );
$_GET = array( 'tickets' => 'cancelled' );
$GLOBALS['singular'] = false;
check( ! extrachill_events_is_ad_free_page(), 'Return state only affects event pages' );
echo "Ticket checkout rendering passed.\n";
