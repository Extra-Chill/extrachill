<?php
/** Standalone breadcrumb experiment contract and rendering checks. */
define( 'ABSPATH', __DIR__ );
$filters = array();
$admin = false;
$blog = 1;
$active = false;
$trail = '';
function add_action() {}
function add_filter( $name, $callback, ...$args ) { $GLOBALS['filters'][ $name ][] = $callback; }
function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $name ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); }
	return $value;
}
function get_site_option( $key, $default = false ) { return $default; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function is_admin() { return $GLOBALS['admin']; }
function ec_get_blog_id( $key ) { return 'main' === $key ? 1 : 2; }
function ec_get_blog_slug_by_id( $id ) { return 1 === $id ? 'main' : 'community'; }
function ec_get_site_url( $key ) { return 'https://' . $key . '.example.com'; }
function untrailingslashit( $url ) { return rtrim( $url, '/' ); }
function wp_script_is() { return true; }
function wp_enqueue_script() {}
function wp_json_encode( $value ) { return json_encode( $value ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return esc_attr( $value ); }
function esc_url( $value ) { return esc_attr( $value ); }
function ec_icon() { return '<svg><use href="#chevron-down"></use></svg>'; }
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: $message\n"; }
require dirname( __DIR__ ) . '/inc/core/experiments.php';
require dirname( __DIR__ ) . '/inc/theme/network-dropdown.php';
$key = 'network-dropdown-everywhere';
$definition = extrachill_normalize_experiment_definition( extrachill_network_register_dropdown_experiment( array() )[ $key ], $key );
check( is_array( $definition ) && 2 === $definition['definition_version'], 'definition normalizes' );
check( array( 'control' => 50, 'treatment' => 50 ) === $definition['variants'], '50/50 variants' );
$listed = extrachill_list_experiments()['items'];
check( $key === $listed[0]['key'] && 'inactive' === $listed[0]['state'], 'CLI list data ships inactive' );
check( 'ROOT' === extrachill_network_dropdown_experiment_root( 'ROOT' ), 'inactive renders nothing extra' );
// Simulate operator activation without persisting any lifecycle state.
add_filter( 'extrachill_experiment_definitions', function ( $definitions ) use ( $key ) { $definitions[ $key ]['default_state'] = 'active'; return $definitions; } );
add_filter( 'extrachill_breadcrumbs_override_trail', function () { return $GLOBALS['trail']; } );
$html = extrachill_network_dropdown_experiment_root( 'ROOT' );
check( str_starts_with( $html, 'ROOT<span' ), 'candidate follows root' );
check( str_contains( $html, 'hidden inert aria-hidden="true"' ), 'candidate hidden and inert' );
check( str_contains( $html, 'data-ec-experiment-key="network-dropdown-everywhere"' ) && str_contains( $html, 'data-ec-experiment-variant="control"' ), 'cache-neutral experiment metadata' );
check( str_contains( $html, 'data-ec-track="network-dropdown-open"' ) && str_contains( $html, 'data-ec-track="network-dropdown-link"' ), 'shared dropdown tracking' );
check( 'Blog' === extrachill_network_dropdown_current_label(), 'main label is Blog' );
$blog = 2;
check( 'Community' === extrachill_network_dropdown_current_label(), 'current site label matches dropdown' );
$trail = '<span class="network-dropdown-target">Community</span>';
check( 'ROOT' === extrachill_network_dropdown_experiment_root( 'ROOT' ), 'existing dropdown emits nothing extra' );
$trail = '';
$admin = true;
check( 'ROOT' === extrachill_network_dropdown_experiment_root( 'ROOT' ), 'admin excluded' );
$admin = false;
check( ! extrachill_network_dropdown_experiment_eligible( array( 'blog_id' => 2, 'existing_dropdown' => '1' ), 'breadcrumb-network-dropdown' ), 'existing dropdown context excluded' );
