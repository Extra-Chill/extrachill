<?php
/**
 * Minimal WordPress surface for the cold network handover contract.
 *
 * @package ExtraChillBooking\Tests
 */

// Test doubles intentionally use WordPress names and public test state.
// Multiple stub types and unused API arguments mirror WordPress in this cold fixture.
// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Generic.CodeAnalysis.UnusedFunctionParameter
// Local fixture reads cannot use the WordPress HTTP API (WordPress is not loaded).
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Generic.Commenting.DocComment.MissingShort, WordPress.Files.FileName

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['booking_network_multisite']     = true;
$GLOBALS['booking_network_events_site']   = false;
$GLOBALS['booking_network_registered']    = array();
$GLOBALS['booking_network_registrations'] = array();
$GLOBALS['booking_network_wrong']         = 0;
$GLOBALS['booking_network_required']      = array( 'extrachill-network/extrachill-network.php', 'extrachill-api/extrachill-api.php' );
$GLOBALS['booking_network_plugins']       = $GLOBALS['booking_network_required'];

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WP_Block_Type_Registry {
	public static function get_instance() { return new self(); }
	public function is_registered( $name ) { return isset( $GLOBALS['booking_network_registered'][ $name ] ); }
}
function __( $text, $domain ) { return $text; }
function esc_html__( $text, $domain ) { return $text; }
function esc_html( $text ) { return $text; }
function is_multisite() { return $GLOBALS['booking_network_multisite']; }
function ec_is_events_site() { return $GLOBALS['booking_network_events_site']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_plugin_active_for_network( $file ) { return in_array( $file, $GLOBALS['booking_network_plugins'], true ); }
function wp_die( $message, $title, $args ) {
	throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion.
}
function register_activation_hook( $file, $callback ) { $GLOBALS['booking_network_activation'] = $callback; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['booking_network_actions'][ $hook ][] = array( $callback, $priority ); }
function _doing_it_wrong( $function_name, $message, $version ) { ++$GLOBALS['booking_network_wrong']; }
function register_block_type( $path ) {
	$metadata = json_decode( file_get_contents( $path . '/block.json' ), true );
	$name     = $metadata['name'];
	if ( WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
		_doing_it_wrong( __FUNCTION__, 'Duplicate registration', '6.9' );
		return false;
	}
	$GLOBALS['booking_network_registered'][ $name ] = true;
	$GLOBALS['booking_network_registrations'][]     = $path;
	return true;
}
function get_plugin_data( $path ) {
	$data = array();
	foreach ( array( 'Plugin Name', 'Version', 'Network', 'Text Domain' ) as $header ) {
		preg_match( '/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*(.+)$/m', file_get_contents( $path ), $match );
		$data[ $header ] = trim( $match[1] ?? '' );
	}
	return $data;
}
