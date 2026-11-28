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
class WP_Error {}
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) {
	check( 60 === $ttl, 'Holds cached for 60 seconds' );
	$GLOBALS['transients'][ $key ] = $value;
}
function ec_cross_site_rest_request_http( $site, $method, $route, $args = array() ) {
	$GLOBALS['owner_calls'][] = array( $site, $method, $route, $args );
	return $GLOBALS['holds_result'] ?? array( 'held' => 0 );
}
function is_singular( $type ) { return $GLOBALS['singular']; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain = '' ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html_e( $value, $domain = '' ) { echo esc_html__( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function wp_kses_post( $value ) { return $value; }
function wp_kses( $value, $allowed ) { check( isset( $allowed['a']['href'] ), 'Terms link allowed through kses' ); return $value; }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function apply_filters( $hook, $value ) { return $value; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function ec_get_site_url( $key ) { return 'main' === $key ? 'https://extrachill.com' : null; }
function number_format_i18n( $value, $decimals = 0 ) { return number_format( $value, $decimals ); }
function ec_render_turnstile_widget( $args ) { return '<div class="cf-turnstile"></div>'; }
function wp_get_ability( $name ) {
	check( 'extrachill/get-event-ticket-offer' === $name, 'Public offer ability used' );
	return new class {
		public function execute( $input ) {
			check( 42 === $input['event'], 'Event reference supplied' );
			$GLOBALS['offer_input'] = $input;
			return $GLOBALS['offer'];
		}
	};
}
function get_current_user_id() { return $GLOBALS['current_user_id'] ?? 0; }
function get_queried_object_id() { return 42; }
function extrachill_events_user_can_manage_event( $user_id, $event_id ) { return 42 === $event_id && in_array( $user_id, $GLOBALS['managers'] ?? array(), true ); }
function checked( $a, $b = true ) { echo $a === $b ? ' checked="checked"' : ''; }
function disabled( $a, $b = true ) { echo $a === $b ? ' disabled="disabled"' : ''; }
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
foreach ( array( 'Buy tickets', 'USD 25.00', '3 remaining', 'max="3"', 'name="quantity"', 'name="name"', 'name="email"', 'cf-turnstile', 'class="button-1"', 'class="ec-checkbox-row"', 'name="terms_accepted" type="checkbox" value="1" required', 'I agree to the <a href="https://extrachill.com/terms-of-service/"', 'Terms of Service</a>' ) as $expected ) {
	check( str_contains( $html, $expected ), 'Open offer: ' . $expected );
}
check( array( array( 'shop', 'GET', '/wp-abilities/v1/abilities/extrachill/get-event-ticket-holds/run', array( 'user_id' => 0, 'query' => array( 'input' => array( 'event' => 42 ) ) ) ) ) === $GLOBALS['owner_calls'], 'Holds read from Shop owner runtime' );
render_checkout();
check( 1 === count( $GLOBALS['owner_calls'] ), 'Zero holds cached too' );
$GLOBALS['transients'] = array();
$GLOBALS['holds_result'] = array( 'held' => 2 );
$html = render_checkout();
check( str_contains( $html, '1 remaining' ) && str_contains( $html, 'max="1"' ), 'Partial holds reduce display and quantity max' );
check( 3 === $GLOBALS['offer']['remaining'] && $GLOBALS['offer']['sale_open'], 'Committed offer contract unchanged' );
$GLOBALS['transients'] = array();
$GLOBALS['holds_result'] = array( 'held' => 3 );
$html = render_checkout();
check( str_contains( $html, '<p class="notice notice-info">All remaining tickets are being held in checkout. Check back in a few minutes.</p>' ), 'Fully held notice uses theme classes' );
check( ! str_contains( $html, '<form' ) && ! str_contains( $html, 'Sold out' ), 'Fully held is not sold out and has no form' );
$GLOBALS['transients'] = array();
$GLOBALS['holds_result'] = array( 'held' => 8 );
check( ! str_contains( render_checkout(), '<form' ), 'Excess holds clamp availability to zero' );
$GLOBALS['transients'] = array();
// Match the Shop bootstrap transport stub: errors pass through unchanged.
$GLOBALS['holds_result'] = new WP_Error();
$html = render_checkout();
check( str_contains( $html, '3 remaining' ) && str_contains( $html, 'max="3"' ), 'Cross-site error falls back to zero holds' );
$GLOBALS['holds_result'] = array( 'held' => 0 );
$calls = count( $GLOBALS['owner_calls'] );
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
check( $calls === count( $GLOBALS['owner_calls'] ), 'Unlimited, closed, sold-out, disabled and missing offers skip holds transport' );
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
$_GET = array();
$GLOBALS['singular'] = true;
$GLOBALS['managers'] = array( 7 );
$GLOBALS['current_user_id'] = 0;
check( ! extrachill_events_is_ad_free_page(), 'Logged-out visitors see ads on event pages' );
$GLOBALS['current_user_id'] = 8;
check( ! extrachill_events_is_ad_free_page(), 'Non-managers see ads on event pages' );
$GLOBALS['current_user_id'] = 7;
check( extrachill_events_is_ad_free_page(), 'Managers see no ads on events they manage' );
$GLOBALS['singular'] = false;
check( ! extrachill_events_is_ad_free_page(), 'Manager exemption only applies to event pages' );
$GLOBALS['current_user_id'] = 0;
$GLOBALS['singular'] = true;

// Ticket types and codes (extrachill#1716).
$_GET                    = array( 'ticket_code' => ' friends ' );
$GLOBALS['transients']   = array();
$GLOBALS['holds_result'] = array( 'held' => 2, 'by_type' => array( 'vip' => 2 ) );
$GLOBALS['offer']        = array(
	'enabled'      => true,
	'sale_open'    => true,
	'price_minor'  => 1500,
	'currency'     => 'usd',
	'remaining'    => 20,
	'ticket_types' => array(
		array( 'id' => 'ga', 'name' => 'GA', 'price_minor' => 1500, 'unit_price_minor' => 1200, 'remaining' => null, 'code_applies' => true ),
		array( 'id' => 'vip', 'name' => 'VIP', 'price_minor' => 4000, 'unit_price_minor' => 4000, 'remaining' => 2, 'code_applies' => false ),
	),
	'code'         => array( 'code' => 'FRIENDS', 'percent_off' => 20, 'amount_off_minor' => 0, 'max_uses' => 0, 'ticket_types' => array() ),
	'code_invalid' => false,
);
$html = render_checkout();
check( 'FRIENDS' === $GLOBALS['offer_input']['code'], 'Entered code is trimmed, upper-cased and sent to the offer' );
check( str_contains( $html, 'Code FRIENDS applied.' ), 'Applied code is confirmed' );
check( str_contains( $html, 'name="code" value="FRIENDS"' ), 'Checkout form carries the code' );
check( str_contains( $html, 'GA · USD 12.00' ) && str_contains( $html, '(was USD 15.00)' ), 'Discounted type shows its old price' );
check( str_contains( $html, 'VIP · USD 40.00' ) && str_contains( $html, 'Sold out' ), 'Type fully held in checkout shows sold out' );
check( 1 === preg_match( '/value="vip" required\s+disabled="disabled"/', $html ), 'Sold-out type cannot be picked' );
check( 1 === preg_match( '/value="ga" required\s+checked="checked"/', $html ), 'First open type is picked by default' );
check( str_contains( $html, 'max="10"' ), 'Show-wide limit still caps quantity' );
$GLOBALS['offer']['code']         = null;
$GLOBALS['offer']['code_invalid'] = true;
check( str_contains( render_checkout(), 'That code does not work for this show.' ), 'Unknown code is reported' );
$_GET = array();
unset( $GLOBALS['offer_input']['code'] );
render_checkout();
check( ! isset( $GLOBALS['offer_input']['code'] ), 'No code means none is sent' );
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
$GLOBALS['holds_result'] = array( 'held' => 3 );
$GLOBALS['transients'] = array();
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

