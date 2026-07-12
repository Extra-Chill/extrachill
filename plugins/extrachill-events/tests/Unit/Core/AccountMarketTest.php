<?php
/**
 * Account market integration tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return (bool) ( $GLOBALS['test_is_user_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		if ( 'extrachill/get-user-settings' === $name ) {
			return $GLOBALS['test_account_market_ability'] ?? null;
		}
		return 'extrachill/update-user-settings' === $name ? ( $GLOBALS['test_update_scene_ability'] ?? null ) : null;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error() {
		return false;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $value ) ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ) {
		return filter_var( $value, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax() {
		return (bool) ( $GLOBALS['test_is_tax'] ?? false );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() {
		return (bool) ( $GLOBALS['test_is_front_page'] ?? false );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code ) {
		$GLOBALS['test_status_header'] = $code;
	}
}

if ( ! function_exists( 'ec_is_events_site' ) ) {
	function ec_is_events_site() {
		return true;
	}
}

if ( ! function_exists( 'extrachill_events_is_near_me_page' ) ) {
	function extrachill_events_is_near_me_page() {
		return (bool) ( $GLOBALS['test_is_near_me_page'] ?? false );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $name, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $name, $default = '' ) {
		if ( 'ec_events_router' === $name && ! empty( $GLOBALS['test_is_all_events_page'] ) ) {
			return 'all';
		}
		return $default;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $value ) {
		return $value;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number ) {
		return number_format( (int) $number );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $value ) {
		echo esc_html( $value );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $value ) {
		echo esc_html( $value );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/' ) . '/';
	}
}

if ( ! function_exists( 'ec_get_site_url' ) ) {
	function ec_get_site_url() {
		return 'https://community.example';
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg() {
		return 'https://events.example/all/';
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) {
		return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( $redirect ) {
		return 'https://events.example/login/?redirect_to=' . rawurlencode( $redirect );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://events.example' . $path;
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id;
		public string $name;
		public string $slug;
		public function __construct( int $term_id, string $name, string $slug ) {
			$this->term_id = $term_id;
			$this->name    = $name;
			$this->slug    = $slug;
		}
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['test_queried_term'] ?? null;
	}
}

if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors() {
		return $GLOBALS['test_term_ancestors'] ?? array();
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return 'https://events.example/location/' . $term->slug . '/';
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action, $name ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( $action ) . '">';
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {
		$GLOBALS['test_nocache_headers'] = true;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		return $nonce === 'nonce-' . $action;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/router-pages.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/account-market.php';

/**
 * Verifies the account preference integration and its precedence gates.
 */
final class AccountMarketTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolves_coordinates_from_user_ability(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'Charleston SC',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
						'hierarchy'   => array( 'label' => 'Charleston, South Carolina' ),
						'archive_url' => 'https://events.example/location/charleston-sc/',
					),
				);
			}
		};

		$this->assertSame(
			array(
				'lat'     => 32.7765,
				'lon'     => -79.9311,
				'slug'    => 'charleston-sc',
				'term_id' => 1618,
				'label'   => 'Charleston, South Carolina',
				'url'     => 'https://events.example/location/charleston-sc/',
			),
			extrachill_events_get_account_market()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fails_open_when_ability_is_unavailable(): void {
		$GLOBALS['test_is_user_logged_in'] = true;

		$this->assertNull( extrachill_events_get_account_market() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_adds_taxonomy_default_without_mutating_request_globals(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_front_page']          = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};
		$_GET = array();
		$result = extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) );

		$this->assertSame( array( 'location' => array( 1618 ) ), $result['tax_filter'] );
		$this->assertSame( array(), $_GET );
	}

	public function test_explicit_geo_and_taxonomy_filters_win(): void {
		$_GET = array(
			'lat' => '40.7',
			'lng' => '-74.0',
		);
		$this->assertTrue( extrachill_events_has_explicit_market() );

		$_GET = array( 'tax_filter' => array( 'location' => array( 42 ) ) );
		$this->assertTrue( extrachill_events_has_explicit_market() );

		$explicit_geo = array(
			'lat' => '40.7',
			'lng' => '-74.0',
		);
		$this->assertSame(
			$explicit_geo,
			extrachill_events_calendar_account_market_defaults( $explicit_geo, array( 'archive_term' => null ) )
		);

		$explicit_taxonomy = array( 'tax_filter' => array( 'location' => array( 42 ) ) );
		$this->assertSame(
			$explicit_taxonomy,
			extrachill_events_calendar_account_market_defaults( $explicit_taxonomy, array( 'archive_term' => null ) )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_explore_all_suppresses_fallback_without_mutating_preference(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_all_events_page']     = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};
		$_GET = array( 'explore_all' => '1' );

		$this->assertTrue( extrachill_events_is_exploring_all_markets() );
		$this->assertSame( array(), extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) ) );
		$this->assertSame( 1618, extrachill_events_get_account_market()['term_id'] );
		$this->assertSame( array( 'explore_all' => '1' ), $_GET );

		ob_start();
		extrachill_events_render_account_market_context();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Exploring all locations', $output );
		$this->assertStringContainsString( 'Use my Local Scene', $output );
	}

	public function test_explore_all_requires_exact_sanitized_flag(): void {
		$_GET = array( 'explore_all' => 'true' );
		$this->assertFalse( extrachill_events_is_exploring_all_markets() );

		$_GET = array( 'explore_all' => array( '1' ) );
		$this->assertFalse( extrachill_events_is_exploring_all_markets() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_near_me_adds_geo_defaults_without_taxonomy_filter(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_near_me_page']        = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};

		$result = extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) );

		$this->assertSame( 32.7765, $result['lat'] );
		$this->assertSame( -79.9311, $result['lng'] );
		$this->assertArrayNotHasKey( 'tax_filter', $result );
	}

	public function test_anonymous_request_does_not_resolve_account_market(): void {
		$GLOBALS['test_is_user_logged_in'] = false;

		$this->assertNull( extrachill_events_get_account_market() );
	}

	public function test_supported_surfaces_are_limited_to_primary_discovery_pages(): void {
		$GLOBALS['test_is_front_page'] = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_front_page']     = false;
		$GLOBALS['test_is_all_events_page'] = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_all_events_page'] = false;
		$GLOBALS['test_is_near_me_page']    = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_near_me_page'] = false;
		$this->assertFalse( extrachill_events_supports_account_market() );
	}

	public function test_location_directory_is_enabled_by_default(): void {
		$this->assertTrue( extrachill_events_location_directory_enabled() );
	}

	public function test_router_query_flags_use_the_query_being_parsed(): void {
		$query = new class( array( 'ec_events_router' => 'all' ) ) {
			public bool $is_404 = true;
			public bool $is_archive = false;
			public bool $is_home = true;
			private array $vars;

			public function __construct( array $vars ) {
				$this->vars = $vars;
			}

			public function get( $key, $default = '' ) {
				return $this->vars[ $key ] ?? $default;
			}

			public function is_main_query(): bool {
				return true;
			}
		};

		extrachill_events_router_query_flags( $query );

		$this->assertFalse( $query->is_404 );
		$this->assertTrue( $query->is_archive );
		$this->assertFalse( $query->is_home );
	}

	public function test_router_pages_preempt_core_404_handling(): void {
		$query = new class() {
			public function get( $key, $default = '' ) {
				return 'ec_events_location_index' === $key ? '1' : $default;
			}
		};

		$this->assertTrue( extrachill_events_router_pre_handle_404( false, $query ) );
		$this->assertSame( 200, $GLOBALS['test_status_header'] );
	}

	public function test_unrelated_queries_preserve_core_404_handling(): void {
		$query = new class() {
			public function get( $key, $default = '' ) {
				return $default;
			}
		};

		$this->assertNull( extrachill_events_router_pre_handle_404( null, $query ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_active_market_context_escapes_label_and_links_to_account_details(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_all_events_page']     = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'      => 'charleston',
						'term_id'   => 1618,
						'hierarchy' => array( 'label' => '<script>Charleston</script>' ),
					),
				);
			}
		};

		ob_start();
		extrachill_events_render_account_market_context();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Showing events for Charleston', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '/settings/#tab-account-details', $output );
		$this->assertStringContainsString( 'explore_all=1', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_logged_in_without_market_and_anonymous_prompts(): void {
		$GLOBALS['test_is_front_page']     = true;
		$GLOBALS['test_is_user_logged_in'] = true;

		ob_start();
		extrachill_events_render_home_market_router( array() );
		$logged_in = (string) ob_get_clean();
		$this->assertStringContainsString( 'Set Local Scene', $logged_in );

		$GLOBALS['test_is_user_logged_in'] = false;
		ob_start();
		extrachill_events_render_home_market_router( array() );
		$anonymous = (string) ob_get_clean();
		$this->assertStringContainsString( 'Search without an account', $anonymous );
		$this->assertStringContainsString( 'Sign in', $anonymous );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_homepage_promotes_saved_market_as_primary_city_route(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_front_page']          = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'hierarchy'   => array( 'label' => 'Charleston, South Carolina' ),
						'archive_url' => 'https://events.example/location/charleston/',
					),
				);
			}
		};

		ob_start();
		extrachill_events_render_home_market_router(
			array(
				array(
					'term_id' => 1618,
					'name'    => 'Charleston',
					'label'   => 'Charleston, South Carolina',
					'slug'    => 'charleston',
					'count'   => 883,
					'url'     => 'https://events.example/location/charleston/',
				),
				array(
					'term_id' => 42,
					'name'    => 'Austin',
					'label'   => 'Austin, Texas',
					'slug'    => 'austin',
					'count'   => 1458,
					'url'     => 'https://events.example/location/austin/',
				),
			)
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Your Local Scene', $output );
		$this->assertStringContainsString( 'Charleston, South Carolina', $output );
		$this->assertStringContainsString( 'https://events.example/location/charleston/', $output );
		$this->assertStringContainsString( '883 upcoming events', $output );
		$this->assertStringContainsString( 'location/charleston/tonight/', $output );
		$this->assertStringContainsString( 'location/charleston/this-weekend/', $output );
		$this->assertStringContainsString( 'Austin, Texas', $output );
		$this->assertStringContainsString( 'Browse all locations', $output );
		$this->assertStringNotContainsString( 'Showing events for', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_archive_cta_only_renders_for_selectable_city(): void {
		$GLOBALS['test_is_tax']         = true;
		$GLOBALS['test_queried_term']   = new WP_Term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors'] = array( 22 );

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$this->assertSame( '', (string) ob_get_clean() );

		$GLOBALS['test_term_ancestors'] = array( 22, 1 );
		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Is Charleston your local scene?', $output );
		$this->assertStringContainsString( 'Sign in to save', $output );
		$this->assertStringContainsString( rawurlencode( 'https://events.example/location/charleston/' ), $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_archive_cta_shows_save_form_or_current_confirmation(): void {
		$GLOBALS['test_is_tax']           = true;
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_queried_term']     = new WP_Term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors']   = array( 22, 1 );

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Make this my Local Scene', $output );
		$this->assertStringContainsString( 'extrachill_events_scene_nonce', $output );
		$this->assertTrue( $GLOBALS['test_nocache_headers'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_archive_cta_confirms_current_scene_without_save_form(): void {
		$GLOBALS['test_is_tax']           = true;
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_queried_term']     = new WP_Term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors']   = array( 22, 1 );
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'    => 'charleston',
						'term_id' => 1618,
					),
				);
			}
		};

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'This is your Local Scene.', $output );
		$this->assertStringNotContainsString( 'Make this my Local Scene', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_archive_update_requires_login_and_nonce_and_uses_settings_ability(): void {
		$term = new WP_Term( 1618, 'Charleston', 'charleston' );
		$calls = new ArrayObject();
		$GLOBALS['test_update_scene_ability'] = new class( $calls ) {
			private ArrayObject $calls;
			public function __construct( ArrayObject $calls ) {
				$this->calls = $calls;
			}
			public function execute( array $input ): array {
				$this->calls[] = $input;
				return array( 'local_scene' => $input['local_scene'] );
			}
		};

		$this->assertFalse( extrachill_events_update_archive_scene( $term, 'nonce-extrachill_events_save_scene_1618' ) );
		$GLOBALS['test_is_user_logged_in'] = true;
		$this->assertFalse( extrachill_events_update_archive_scene( $term, 'wrong' ) );
		$this->assertTrue( extrachill_events_update_archive_scene( $term, 'nonce-extrachill_events_save_scene_1618' ) );
		$this->assertSame( array( array( 'local_scene' => 'charleston' ) ), $calls->getArrayCopy() );
	}
}
