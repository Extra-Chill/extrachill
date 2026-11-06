<?php
// phpcs:ignoreFile -- Standalone test harness that stubs WordPress functions.
/** Standalone rendering regression test: php tests/ticket-checkout.php */
define( 'ABSPATH', __DIR__ );
define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function add_action( ...$args ) {}
function add_filter( ...$args ) { $GLOBALS['filters'][ $args[0] ] = array_slice( $args, 1 ); }
function get_permalink( $post_id ) {
	check( 42 === $post_id, 'Permalink uses event ID' );
	return 'https://events.extrachill.com/event/test-show/';
}
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

eval( 'namespace ExtraChillEvents\\Abilities; class TicketAbilities { public static function settings( $id ) { return $GLOBALS["ticket_settings"]; } }' );
$GLOBALS['ticket_settings'] = array( 'tickets_enabled' => true );
check( false === extrachill_events_hide_external_ticket_button( true, 42 ), 'Enabled first-party tickets replace the external ticket link' );
$GLOBALS['ticket_settings'] = array( 'tickets_enabled' => false );
check( true === extrachill_events_hide_external_ticket_button( true, 42 ), 'External ticket link stays when first-party tickets are off' );
check( false === extrachill_events_hide_external_ticket_button( false, 42 ), 'Never re-shows a link Data Machine Events hid (past show)' );
echo "Ticket link replacement passed.\n";

check( array( 'extrachill_events_ticket_offer_schema', 10, 3 ) === $GLOBALS['filters']['data_machine_events_offer_schema'], 'Offer filter registered with all three arguments' );
$external = array( '@type' => 'Offer', 'url' => 'https://external.example/tickets', 'price' => 99, 'validFrom' => '2026-06-01T12:00:00Z' );
check( $external === extrachill_events_ticket_offer_schema( $external, 42, array() ), 'Non-ticketed event schema passes through unchanged' );
$GLOBALS['ticket_settings']['tickets_enabled'] = true;
$GLOBALS['offer'] = array( 'enabled' => true, 'price_minor' => 2550, 'currency' => 'usd', 'remaining' => 3 );
$expected = array(
	'@type' => 'Offer',
	'url' => 'https://events.extrachill.com/event/test-show/',
	'price' => 25.5,
	'priceCurrency' => 'USD',
	'availability' => 'https://schema.org/InStock',
	'validFrom' => $external['validFrom'],
);
check( $expected === extrachill_events_ticket_offer_schema( $external, 42, array() ), 'First-party offer replaces scraped URL, price and currency, preserving validFrom' );
$GLOBALS['offer']['remaining'] = 0;
$schema = extrachill_events_ticket_offer_schema( array(), 42, array() );
check( 'https://schema.org/SoldOut' === $schema['availability'], 'Sold-out schema availability' );
check( ! isset( $schema['validFrom'] ), 'No invented validFrom when incoming offer is empty' );
$GLOBALS['offer']['remaining'] = null;
check( 'https://schema.org/InStock' === extrachill_events_ticket_offer_schema( array(), 42, array() )['availability'], 'Unlimited tickets are in stock' );
$GLOBALS['offer'] = null;
check( $external === extrachill_events_ticket_offer_schema( $external, 42, array() ), 'Unavailable public offer leaves schema unchanged' );
echo "Ticket offer schema passed.\n";

