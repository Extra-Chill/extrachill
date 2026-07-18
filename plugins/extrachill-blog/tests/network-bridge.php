<?php
/**
 * Focused tests for geographic single-post bridge fallback.
 *
 * Run with: php tests/network-bridge.php
 *
 * @package ExtraChillBlog
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_BLOG_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_BLOG_PLUGIN_URL', 'https://extrachill.test/wp-content/plugins/extrachill-blog/' );

// Test doubles intentionally mirror WordPress and shared renderer signatures.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound

$test_cards_by_taxonomy    = array();
$test_resolver_calls       = array();
$test_is_post              = true;
$test_enqueued_styles      = array();
$test_enqueued_scripts     = array();
$test_registered_scripts   = array(
	'extrachill-experiment-assignment'  => array( 'registered' => true ),
	'extrachill-blog-geographic-bridge' => array(),
);
$test_experiment_available = true;
$test_post_type            = 'post';
$test_post_status          = 'publish';

/** Stub action registration. */
function add_action() {}

/** Stub filter registration. */
function add_filter() {}

/** Stub script registration. */
function wp_register_script( $handle, $src, $dependencies ) {
	global $test_registered_scripts;
	$test_registered_scripts[ $handle ] = array(
		'src'          => $src,
		'dependencies' => $dependencies,
	);
}

/** Stub current post ID. */
function get_the_ID() {
	return 64;
}

/** Stub singular post context. */
function is_singular( $post_type ) {
	global $test_is_post;
	return 'post' === $post_type && $test_is_post;
}

/** Stub translation. */
function __( $text ) {
	return $text;
}

/** Stub attribute escaping. */
function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

/** Stub HTML escaping. */
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

/** Stub URL escaping. */
function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

/** Stub WordPress JSON encoding. */
function wp_json_encode( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone WordPress test double.
}

/** Capture enqueued styles. */
function wp_enqueue_style( $handle ) {
	global $test_enqueued_styles;
	$test_enqueued_styles[] = $handle;
}

/** Capture enqueued scripts. */
function wp_enqueue_script( $handle ) {
	global $test_enqueued_scripts;
	$test_enqueued_scripts[] = $handle;
}

/** Report registered dependency state. */
function wp_script_is( $handle, $status ) {
	global $test_registered_scripts;
	return 'registered' === $status && ! empty( $test_registered_scripts[ $handle ] );
}

/** Return controlled post type. */
function get_post_type() {
	global $test_post_type;
	return $test_post_type;
}

/** Return controlled post status. */
function get_post_status() {
	global $test_post_status;
	return $test_post_status;
}

/** Mirror Network's cache-neutral attribute contract. */
function extrachill_experiment_attributes( $key, $surface, $context ) {
	global $test_experiment_available;
	if ( ! $test_experiment_available ) {
		return '';
	}

	return sprintf(
		'data-ec-experiment-key="%s" data-ec-experiment-surface="%s" data-ec-experiment-variant="control" data-ec-experiment-context="%s"',
		esc_attr( $key ),
		esc_attr( $surface ),
		esc_attr( wp_json_encode( $context ) )
	);
}

/** Stub the shared verified resolver and capture its contract. */
function extrachill_network_bridge_get_cards( $post_id, $taxonomies, $allowed_site_keys, $slot_order, $utm_source, $cache_prefix ) {
	global $test_cards_by_taxonomy, $test_resolver_calls;
	$key                   = implode( ',', $taxonomies );
	$test_resolver_calls[] = array(
		'post_id'           => $post_id,
		'taxonomies'        => $taxonomies,
		'allowed_site_keys' => $allowed_site_keys,
		'slot_order'        => $slot_order,
		'utm_source'        => $utm_source,
		'cache_prefix'      => $cache_prefix,
	);

	return $test_cards_by_taxonomy[ $key ] ?? array();
}

/** Stub the shared escaped card renderer. */
function extrachill_cross_site_link_button( $card, $class = '' ) {
	if ( empty( $card['url'] ) || empty( $card['label'] ) ) {
		return;
	}

	printf(
		'<a href="%s" class="button-3 button-small ec-cross-site-link %s">%s %s</a>',
		esc_url( $card['url'] ),
		esc_attr( $class ),
		esc_html( $card['term_name'] ?? '' ),
		esc_html( $card['label'] )
	);
}

require_once dirname( __DIR__ ) . '/inc/single/network-bridge.php';
extrachill_blog_register_geographic_bridge_script();

/** Assert strict equality. */
function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test failure output.
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

/** Build a card shaped like a verified Network resolver result. */
function bridge_card( $site_key, $term_name, $taxonomy ) {
	return array(
		'site_key'  => $site_key,
		'url'       => 'https://' . $site_key . '.extrachill.test/' . $taxonomy . '/' . strtolower( str_replace( ' ', '-', $term_name ) ) . '?utm_medium=network_bridge',
		'label'     => ucfirst( $site_key ),
		'term_name' => $term_name,
		'count'     => 1,
	);
}

/** Resolve cards with controlled primary and geographic results. */
function resolve_cards( $primary, $geographic ) {
	global $test_cards_by_taxonomy, $test_resolver_calls;
	$test_cards_by_taxonomy = array(
		'artist,festival' => $primary,
		'location,venue'  => $geographic,
	);
	$test_resolver_calls    = array();

	return extrachill_blog_network_bridge_cards( extrachill_blog_network_bridge_args() );
}

$artist_event = bridge_card( 'events', 'Kid Lake', 'artist' );
$city_event   = bridge_card( 'events', 'Charleston', 'location' );
$community    = bridge_card( 'community', 'Charleston', 'location' );
$cards        = resolve_cards( array( $artist_event ), array( $city_event, $community ) );
assert_same( 'Kid Lake', $cards[0]['term_name'], 'Artist cards must retain an occupied Events slot.' );
assert_same( 'Charleston', $cards[1]['term_name'], 'Geography should fill the vacant Community slot.' );
assert_same( array( 'events', 'community' ), $test_resolver_calls[1]['allowed_site_keys'], 'Geographic resolution must remain bounded to Events and Community.' );

$primary_community = bridge_card( 'community', 'Kid Lake', 'artist' );
$cards             = resolve_cards( array( $artist_event, $primary_community ), array( $city_event, $community ) );
assert_same( 1, count( $test_resolver_calls ), 'Geography must not resolve when primary cards occupy all fallback capacity.' );

$cards = resolve_cards( array(), array( $city_event, $community ) );
assert_same( array( 'events', 'community' ), array_column( $cards, 'site_key' ), 'Geography should provide verified fallback cards when primary entities do not resolve.' );
assert_same( 'ec_blog_network_bridge_geographic_', $test_resolver_calls[1]['cache_prefix'], 'Geography should use the shared cache path with a stable dedicated prefix.' );

$artist_profile = bridge_card( 'artist', 'Kid Lake', 'artist' );
$festival_wire  = bridge_card( 'wire', 'High Water', 'festival' );
$cards          = resolve_cards( array( $artist_profile, $festival_wire ), array( $city_event, $community ) );
assert_same( array( 'artist', 'events', 'wire', 'community' ), array_column( $cards, 'site_key' ), 'Mixed terms should preserve slot order while geography only fills capacity.' );
assert_same( 'High Water', $cards[2]['term_name'], 'A location card must not displace a primary festival destination.' );
assert_same( count( $cards ), count( array_unique( array_column( $cards, 'site_key' ) ) ), 'Treatment cards must not duplicate a destination slot.' );

$cards = resolve_cards( array(), array() );
assert_same( array(), $cards, 'Posts without a verified destination must fail closed.' );
ob_start();
extrachill_blog_network_bridge();
$empty_output = ob_get_clean();
assert_same( '', $empty_output, 'No-result bridges must render no empty container.' );

resolve_cards( array(), array( $city_event ) );
ob_start();
extrachill_blog_network_bridge();
$mobile_output = ob_get_clean();
assert_same( true, false !== strpos( $mobile_output, 'network-bridge-links ec-cross-site-links' ), 'Bridge output should retain the existing responsive link-row classes.' );
assert_same( true, false !== strpos( $mobile_output, 'button-3 button-small ec-cross-site-link network-bridge-link' ), 'Mobile cards should use the shared compact card renderer.' );
assert_same( true, in_array( 'extrachill-network-bridge', $test_enqueued_styles, true ), 'A rendered bridge should enqueue the shared responsive stylesheet.' );
assert_same( true, false !== strpos( $mobile_output, 'utm_medium=network_bridge' ), 'Rendered destinations should retain shared UTM instrumentation.' );
assert_same( true, false !== strpos( $mobile_output, 'data-ec-experiment-key="geo-bridge-holdout"' ), 'Eligible bridges should use Network experiment markup.' );
assert_same( true, false !== strpos( $mobile_output, 'data-ec-experiment-variant="control"' ), 'Cached markup must contain only declared control state.' );
assert_same( true, false === strpos( $mobile_output, 'visitor-' ), 'Cached markup must not contain visitor identity.' );
assert_same( true, false !== strpos( $mobile_output, 'hidden inert aria-hidden="true"' ), 'Geographic candidates must be inert and unfocusable before activation.' );
assert_same( true, false !== strpos( $mobile_output, 'class="network-bridge-section related-tax-section"' ) && false !== strpos( $mobile_output, ' hidden>' ), 'A geography-only bridge must remain hidden in control.' );
assert_same( true, in_array( 'extrachill-blog-geographic-bridge', $test_enqueued_scripts, true ), 'Eligible bridges should enqueue treatment activation.' );
assert_same( array( 'extrachill-experiment-assignment' ), $test_registered_scripts['extrachill-blog-geographic-bridge']['dependencies'], 'Viewport exposure must remain wired through Network assignment.' );

$definition = extrachill_blog_register_bridge_experiment( array() );
assert_same( array( 'geo-bridge-holdout' ), array_keys( $definition ), 'Blog should register exactly one code-owned experiment.' );
assert_same(
	array(
		'control'   => 50,
		'treatment' => 50,
	),
	$definition['geo-bridge-holdout']['variants'],
	'The holdout must use exact 50/50 weights.'
);
assert_same( array( 'single-post-bridge' ), $definition['geo-bridge-holdout']['surfaces'], 'The experiment must be bounded to the single-post bridge.' );

resolve_cards( array(), array( $city_event ) );
assert_same( true, extrachill_blog_geographic_bridge_experiment_eligible( array( 'post_id' => '64' ), 'single-post-bridge' ), 'Published posts with verified geographic capacity should be eligible.' );
resolve_cards( array( $artist_event, $primary_community ), array( $city_event, $community ) );
assert_same( false, extrachill_blog_geographic_bridge_experiment_eligible( array( 'post_id' => '64' ), 'single-post-bridge' ), 'Posts without vacant geographic capacity must be ineligible.' );
assert_same( false, extrachill_blog_geographic_bridge_experiment_eligible( array( 'post_id' => '64' ), 'other-surface' ), 'Other surfaces must be ineligible.' );
$test_post_status = 'draft';
assert_same( false, extrachill_blog_geographic_bridge_experiment_eligible( array( 'post_id' => '64' ), 'single-post-bridge' ), 'Non-published posts must be ineligible.' );
$test_post_status = 'publish';

resolve_cards( array( $artist_profile ), array( $city_event ) );
$test_registered_scripts['extrachill-experiment-assignment'] = array();
ob_start();
extrachill_blog_network_bridge();
$dependency_output = ob_get_clean();
assert_same( true, false !== strpos( $dependency_output, 'Kid Lake' ), 'Primary artist cards must survive experiment dependency absence.' );
assert_same( true, false === strpos( $dependency_output, 'Charleston' ), 'Geographic cards must fail closed when Network assignment is absent.' );
assert_same( true, false === strpos( $dependency_output, 'data-ec-experiment-key' ), 'Dependency absence must emit no partial experiment markup.' );
$test_registered_scripts['extrachill-experiment-assignment'] = array( 'registered' => true );

resolve_cards( array(), array( $city_event ) );
$test_experiment_available = false;
ob_start();
extrachill_blog_network_bridge();
$provider_output = ob_get_clean();
assert_same( '', $provider_output, 'Missing or invalid experiment configuration must leave geography-only control empty.' );
$test_experiment_available = true;

fwrite( STDOUT, "Network bridge tests passed.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test output.
