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
function home_url() { return ec_get_site_url( ec_get_blog_slug_by_id( $GLOBALS['blog'] ) ); }
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
check( is_array( $definition ) && 3 === $definition['definition_version'], 'definition normalizes as v3' );
check( array( 'control' => 50, 'treatment' => 50 ) === $definition['variants'], '50/50 variants' );
$listed = extrachill_list_experiments()['items'];
check( $key === $listed[0]['key'] && 'inactive' === $listed[0]['state'], 'CLI list data ships inactive' );
check( 'ROOT' === extrachill_network_dropdown_experiment_root( 'ROOT' ), 'inactive renders nothing extra' );
check( '' === extrachill_network_dropdown_experiment_pending(), 'inactive leaves no treatment candidate' );
// Simulate operator activation without persisting any lifecycle state.
add_filter( 'extrachill_experiment_definitions', function ( $definitions ) use ( $key ) { $definitions[ $key ]['default_state'] = 'active'; return $definitions; } );
add_filter( 'extrachill_breadcrumbs_override_trail', function () { return $GLOBALS['trail']; } );
// Main site: root has no site crumb, so control is today's root and treatment appends `› Blog ▾`.
$main_root = '<a href="https://main.example.com">Extra Chill</a>';
$html = extrachill_network_dropdown_experiment_root( $main_root );
$treatment = extrachill_network_dropdown_experiment_pending();
check( str_starts_with( $html, '<span class="ec-network-dropdown-experiment" data-ec-experiment-key' ) && str_ends_with( $html, '>' . $main_root . '</span>' ), 'main root wrapped as measured element' );
check( ! str_contains( $html, 'ec-network-dropdown-experiment-control' ), 'main root has no control site crumb' );
check( ! str_contains( $html, 'hidden' ) && ! str_contains( $html, 'inert' ), 'measured wrapper visible for both arms (symmetric exposure)' );
check( str_contains( $html, 'data-ec-experiment-key="network-dropdown-everywhere"' ) && str_contains( $html, 'data-ec-experiment-variant="control"' ), 'cache-neutral experiment metadata' );
check( str_starts_with( $treatment, '<span class="ec-network-dropdown-experiment-treatment" hidden inert aria-hidden="true"> › ' ), 'treatment candidate hidden and inert' );
check( ! str_contains( $treatment, 'data-ec-experiment' ), 'treatment candidate is not the measured element' );
check( str_contains( $treatment, 'data-ec-track="network-dropdown-open"' ) && str_contains( $treatment, 'data-ec-track="network-dropdown-link"' ), 'shared dropdown tracking' );
check( str_contains( $treatment, '>Community</a>' ) && preg_match( '/<button[^>]*>\s*Blog/', $treatment ), 'main treatment is Blog dropdown' );
ob_start();
extrachill_network_dropdown_experiment_after_root();
check( $treatment === ob_get_clean(), 'after-root hook outputs treatment candidate' );
ob_start();
extrachill_network_dropdown_experiment_after_root();
check( '' === ob_get_clean(), 'treatment candidate emitted once' );
check( 'Blog' === extrachill_network_dropdown_current_label(), 'main label is Blog' );
$blog = 2;
check( 'Community' === extrachill_network_dropdown_current_label(), 'current site label matches dropdown' );
// Site root `Extra Chill › Community`: treatment swaps the site crumb, never duplicates it.
$site_root = '<a href="https://main.example.com">Extra Chill</a> › <a href="https://community.example.com">Community</a>';
$html = extrachill_network_dropdown_experiment_root( $site_root );
$treatment = extrachill_network_dropdown_experiment_pending();
check( str_starts_with( $html, '<span class="ec-network-dropdown-experiment" ' ) && str_contains( $html, '><a href="https://main.example.com">Extra Chill</a></span>' ), 'measured wrapper holds only the shared Extra Chill prefix' );
check( str_ends_with( $html, '<span class="ec-network-dropdown-experiment-control"> › <a href="https://community.example.com">Community</a></span>' ), 'control segment is the existing site crumb, visible' );
check( strip_tags( $html ) === strip_tags( $site_root ), 'control renders exactly today\'s breadcrumb text' );
check( preg_match( '/<button[^>]*>\s*Community/', $treatment ) && ! str_contains( $treatment, '>Community</a>' ), 'treatment is the Community dropdown' );
$treatment_text = preg_replace( '/\s+/', ' ', strip_tags( '<a>Extra Chill</a>' . preg_replace( '/<ul.*<\/ul>/s', '', $treatment ) ) );
check( 1 === substr_count( $treatment_text, 'Community' ), 'treatment has no duplicate site label' );
check( 'Extra Chill › Community ' === $treatment_text, 'treatment reads Extra Chill › [Community ▾]' );
check( ' &rsaquo; <a href="https://community.example.com/">Community</a>' === extrachill_network_dropdown_split_root( '<a href="https://main.example.com">Extra Chill</a> &rsaquo; <a href="https://community.example.com/">Community</a>' )[1], 'entity separator and trailing slash accepted' );
$other = extrachill_network_dropdown_experiment_root( '<a href="https://main.example.com">Extra Chill</a> › <a href="https://elsewhere.example.com">Elsewhere</a>' );
check( ! str_contains( $other, 'ec-network-dropdown-experiment-control' ), 'trailing link to another site is not treated as the site crumb' );
check( 2 === count( extrachill_network_dropdown_split_root( $site_root ) ) && '' !== extrachill_network_dropdown_split_root( $site_root )[1], 'split finds site crumb' );
check( array( '<a href="https://community.example.com">Community</a>', '' ) === extrachill_network_dropdown_split_root( '<a href="https://community.example.com">Community</a>' ), 'lone root link is not a site crumb' );
$trail = '<span class="network-dropdown-target">Community</span>';
check( 'ROOT' === extrachill_network_dropdown_experiment_root( 'ROOT' ) && '' === extrachill_network_dropdown_experiment_pending(), 'existing dropdown emits nothing extra' );
$trail = '';
$admin = true;
check( 'ROOT' === extrachill_network_dropdown_experiment_root( 'ROOT' ), 'admin excluded' );
$admin = false;
check( ! extrachill_network_dropdown_experiment_eligible( array( 'blog_id' => 2, 'existing_dropdown' => '1' ), 'breadcrumb-network-dropdown' ), 'existing dropdown context excluded' );
