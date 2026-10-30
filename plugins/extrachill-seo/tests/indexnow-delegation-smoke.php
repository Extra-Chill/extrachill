<?php
/**
 * Dependency-free smoke coverage for delegated IndexNow ownership.
 */

declare( strict_types=1 );

$root     = dirname( __DIR__ );
$indexnow = file_get_contents( $root . '/inc/core/indexnow.php' );
$analysis = file_get_contents( $root . '/inc/abilities/analysis-abilities.php' );
$settings = file_get_contents( $root . '/inc/core/settings.php' );
$failures = array();

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['indexnow_test_site_options'] = array( 'extrachill_seo_indexnow_key' => 'legacy-key' );
$GLOBALS['indexnow_test_blog_options'] = array(
	1 => array( 'datamachine_settings' => array() ),
	2 => array( 'datamachine_settings' => array( 'indexnow_api_key' => 'canonical-key' ) ),
);
$GLOBALS['indexnow_test_blog_id'] = 1;
$GLOBALS['indexnow_test_stack']   = array();
$GLOBALS['indexnow_test_hooks']   = array();
$GLOBALS['indexnow_test_cache_clears'] = array();

eval(
	'namespace DataMachine\Core; class PluginSettings {
		public static function clearCache(): void {
			$GLOBALS["indexnow_test_cache_clears"][] = $GLOBALS["indexnow_test_blog_id"];
		}
	}'
);

function get_site_option( $name, $default_value = false ) {
	return $GLOBALS['indexnow_test_site_options'][ $name ] ?? $default_value;
}

function delete_site_option( $name ) {
	unset( $GLOBALS['indexnow_test_site_options'][ $name ] );
	return true;
}

function get_option( $name, $default_value = false ) {
	return $GLOBALS['indexnow_test_blog_options'][ $GLOBALS['indexnow_test_blog_id'] ][ $name ] ?? $default_value;
}

function update_option( $name, $value ) {
	$GLOBALS['indexnow_test_blog_options'][ $GLOBALS['indexnow_test_blog_id'] ][ $name ] = $value;
	return true;
}

function get_sites( $args = array() ) {
	return array_keys( $GLOBALS['indexnow_test_blog_options'] );
}

function get_current_blog_id() {
	return $GLOBALS['indexnow_test_blog_id'];
}

function switch_to_blog( $site_id ) {
	$GLOBALS['indexnow_test_stack'][] = $GLOBALS['indexnow_test_blog_id'];
	$GLOBALS['indexnow_test_blog_id'] = (int) $site_id;
}

function restore_current_blog() {
	$GLOBALS['indexnow_test_blog_id'] = array_pop( $GLOBALS['indexnow_test_stack'] );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['indexnow_test_hooks'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_action( $hook, $callback, $priority, $accepted_args );
}

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function wp_get_ability( $name ) {
	return 'datamachine/indexnow-submit' === $name ? $GLOBALS['indexnow_test_ability'] : null;
}

class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}

class WP_Post {
	public function __construct( array $properties = array() ) {
		foreach ( $properties as $property => $value ) {
			$this->$property = $value;
		}
	}
}

function get_post_type_object( $post_type ) {
	return (object) array( 'publicly_queryable' => 'post' === $post_type );
}

class IndexNow_Test_Ability {
	public array $input = array();

	public function execute( $input ) {
		$this->input = $input;
		return array( 'success' => true, 'status_code' => 202, 'message' => 'accepted' );
	}
}

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$provider_owners = $indexnow . $analysis;
$assert( false === strpos( $provider_owners, 'api.indexnow.org' ), 'SEO source contains no raw IndexNow endpoint' );
$assert( false === strpos( $provider_owners, 'wp_remote_post' ), 'SEO IndexNow paths contain no direct HTTP POST' );
$assert( false !== strpos( $indexnow, "wp_get_ability( 'datamachine/indexnow-submit' )" ), 'policy delegates through the stable ability' );
$assert( false !== strpos( $indexnow, "->execute( array( 'urls' => \$urls ) )" ), 'delegated ability is executed through WordPress core' );

foreach ( array( 'transition_post_status', 'deleted_post', 'post_updated' ) as $hook ) {
	$assert( false !== strpos( $indexnow, "add_action( '{$hook}'" ), "policy retains {$hook}" );
}

$assert(
	false !== strpos( $indexnow, "add_filter( 'datamachine_indexnow_skip_auto_submit', __NAMESPACE__ . '\\\\ec_seo_indexnow_skip_generic_auto_submit', 10, 3 )" ),
	'generic auto-submit suppression uses priority 10 and accepts three arguments'
);
$assert( false !== strpos( $indexnow, 'ec_seo_indexnow_is_supported_post' ), 'post-type exclusion policy remains local' );
$assert( false !== strpos( $indexnow, 'ec_seo_indexnow_is_meaningful_update' ), 'meaningful-update policy remains local' );

$assert( false !== strpos( $settings, "get_option( 'datamachine_settings'" ), 'canonical Data Machine settings are used' );
$assert( false !== strpos( $settings, "empty( \$settings['indexnow_api_key'] )" ), 'migration does not overwrite a canonical key' );
$assert( false !== strpos( $settings, "\$settings['indexnow_api_key'] = \$legacy_key" ), 'legacy key migrates one way to the canonical key' );
$assert( false !== strpos( $settings, 'delete_site_option( EC_SEO_OPTION_INDEXNOW_KEY )' ), 'legacy key is retired after migration' );
$assert( false !== strpos( $settings, "class_exists( '\\DataMachine\\Core\\PluginSettings' )" ), 'cache invalidation is guarded when Data Machine is unavailable' );

require_once $root . '/inc/core/settings.php';
require_once $root . '/inc/core/indexnow.php';

$assert( 'legacy-key' === $GLOBALS['indexnow_test_blog_options'][1]['datamachine_settings']['indexnow_api_key'], 'empty canonical key receives legacy value' );
$assert( 'canonical-key' === $GLOBALS['indexnow_test_blog_options'][2]['datamachine_settings']['indexnow_api_key'], 'existing canonical key is preserved' );
$assert( ! isset( $GLOBALS['indexnow_test_site_options']['extrachill_seo_indexnow_key'] ), 'legacy network key is removed after all sites migrate' );
$assert( array( 1 ) === $GLOBALS['indexnow_test_cache_clears'], 'migration clears cache in the blog context where it writes' );

ExtraChill\SEO\Core\ec_seo_set_indexnow_key( 'replacement-key' );
$assert( 'replacement-key' === $GLOBALS['indexnow_test_blog_options'][1]['datamachine_settings']['indexnow_api_key'], 'config writes the canonical key' );
$assert( true === $GLOBALS['indexnow_test_blog_options'][1]['datamachine_settings']['indexnow_enabled'], 'config keeps canonical enabled state in sync' );
$assert( array( 1, 1 ) === $GLOBALS['indexnow_test_cache_clears'], 'config write clears the Data Machine settings cache' );

$GLOBALS['indexnow_test_ability'] = new IndexNow_Test_Ability();
$result = ExtraChill\SEO\Core\ec_seo_indexnow_submit_urls(
	array( 'https://extrachill.com/post/', 'invalid', 'https://extrachill.com/post/' )
);
$assert( true === $result['success'], 'delegated result is returned' );
$assert(
	array( 'urls' => array( 'https://extrachill.com/post/' ) ) === $GLOBALS['indexnow_test_ability']->input,
	'URLs are sanitized and deduplicated before stable ability delegation'
);

$published = new WP_Post(
	array(
		'post_type'    => 'post',
		'post_title'   => 'New title',
		'post_content' => 'Content',
		'post_excerpt' => '',
		'post_name'    => 'post',
		'post_parent'  => 0,
	)
);
$before    = clone $published;
$assert( ExtraChill\SEO\Core\ec_seo_indexnow_is_supported_post( $published ), 'publicly queryable posts are supported' );
$assert(
	! ExtraChill\SEO\Core\ec_seo_indexnow_is_supported_post( new WP_Post( array( 'post_type' => 'revision' ) ) ),
	'non-public post types are excluded'
);
$assert( ! ExtraChill\SEO\Core\ec_seo_indexnow_is_meaningful_update( $published, $before ), 'unchanged saves are ignored' );
$before->post_title = 'Old title';
$assert( ExtraChill\SEO\Core\ec_seo_indexnow_is_meaningful_update( $published, $before ), 'content-facing changes are meaningful' );

$suppression_hooks = $GLOBALS['indexnow_test_hooks']['datamachine_indexnow_skip_auto_submit'] ?? array();
$assert( 10 === $suppression_hooks[0]['priority'], 'duplicate suppression runs at priority 10' );
$assert( 3 === $suppression_hooks[0]['accepted_args'], 'duplicate suppression accepts all DMB filter arguments' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

fwrite( STDOUT, "IndexNow delegation smoke passed.\n" );
