<?php

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

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( 'ec_get_artist_id' === $hook && is_array( $value ) ) {
			return (int) ( $value['artist_id'] ?? 0 );
		}

		if ( 'ec_get_link_page_id' === $hook ) {
			return (int) ( $GLOBALS['ec_test']['link_page_id'] ?? 0 );
		}

		return $value;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post_id ) {
		return $GLOBALS['ec_test']['posts'][ $post_id ]->post_status ?? false;
	}
}

if ( ! function_exists( 'ec_get_link_page_data' ) ) {
	function ec_get_link_page_data() {
		return $GLOBALS['ec_test']['subscribe_data'] ?? array();
	}
}

if ( ! function_exists( 'ec_get_link_page_defaults_for' ) ) {
	function ec_get_link_page_defaults_for() {
		return array(
			'--link-page-card-bg-color'         => '#fff',
			'--link-page-link-text-color'        => '#000',
			'--link-page-title-font-family'      => 'sans-serif',
			'--link-page-body-font-family'       => 'sans-serif',
			'--link-page-background-type'        => 'color',
			'--link-page-background-color'       => '#fff',
			'--link-page-button-hover-bg-color'  => '#000',
			'--link-page-button-border-color'    => '#000',
			'--link-page-button-bg-color'        => '#fff',
			'--link-page-muted-text-color'       => '#555',
			'--link-page-input-bg'               => '#fff',
			'--link-page-button-radius'          => '4px',
		);
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://artist.example/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $value ) {
		echo esc_html( $value );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $value ) {
		echo esc_attr( $value );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return ! empty( $GLOBALS['ec_test']['logged_in'] );
	}
}

if ( ! function_exists( 'ec_render_template' ) ) {
	function ec_render_template( $template_name, $args = array() ) {
		if ( 'subscribe-inline-form' !== $template_name ) {
			return '';
		}

		extract( $args, EXTR_SKIP );
		ob_start();
		include dirname( __DIR__ ) . '/inc/link-pages/live/templates/subscribe-inline-form.php';
		return ob_get_clean();
	}
}

require_once dirname( __DIR__ ) . '/inc/artist-profiles/frontend/subscribe-section.php';

final class ArtistProfileSubscribeTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'posts'          => array(
				42 => (object) array(
					'ID'          => 42,
					'post_title'  => 'Test Artist',
					'post_status' => 'publish',
				),
			),
			'link_page_id'   => 84,
			'meta'           => array( 84 => array() ),
			'subscribe_data' => array(),
		);
	}

	public function test_registers_a_bounded_profile_section(): void {
		$sections = ec_register_artist_profile_subscribe_section( array() );

		$this->assertSame( 'subscribe', $sections[0]['id'] );
		$this->assertSame( 15, $sections[0]['priority'] );
		$this->assertSame( 'ec_render_artist_profile_subscribe_section', $sections[0]['render'] );
		$this->assertSame( 'ec_is_artist_profile_subscribe_section_visible', $sections[0]['visible'] );
	}

	public function test_visibility_rejects_unpublished_and_disabled_artists(): void {
		$this->assertTrue( ec_is_artist_profile_subscribe_section_visible( 42 ) );

		$GLOBALS['ec_test']['meta'][84]['_link_page_subscribe_display_mode'] = array( 'disabled' );
		$this->assertFalse( ec_is_artist_profile_subscribe_section_visible( 42 ) );

		$GLOBALS['ec_test']['meta'][84] = array();
		$GLOBALS['ec_test']['posts'][42]->post_status = 'draft';
		$this->assertFalse( ec_is_artist_profile_subscribe_section_visible( 42 ) );
	}

	/**
	 * @dataProvider authentication_states
	 */
	public function test_profile_form_is_public_and_accessible( bool $logged_in ): void {
		$GLOBALS['ec_test']['logged_in'] = $logged_in;

		ob_start();
		ec_render_artist_profile_subscribe_section( 42 );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Subscribe to Test Artist', $html );
		$this->assertStringContainsString( 'data-subscribe-api-url="https://artist.example/wp-json/extrachill/v1/artists/42/subscribe"', $html );
		$this->assertStringContainsString( 'autocomplete="email"', $html );

		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $document );
		$input = $xpath->query( '//input[@type="email"]' )->item( 0 );
		$label = $xpath->query( '//label[@for="' . $input->getAttribute( 'id' ) . '"]' )->item( 0 );
		$status = $xpath->query( '//*[@role="status" and @aria-live="polite"]' )->item( 0 );

		$this->assertNotNull( $label );
		$this->assertNotNull( $status );
		$this->assertSame( 'Email Address', trim( $label->textContent ) );
	}

	public static function authentication_states(): array {
		return array(
			'logged out' => array( false ),
			'logged in'  => array( true ),
		);
	}

	public function test_reused_form_preserves_custom_description_and_domain_endpoint(): void {
		$html = ec_render_template(
			'subscribe-inline-form',
			array(
				'artist_id'         => 42,
				'artist_name'       => 'Test Artist',
				'data'              => array( '_link_page_subscribe_description' => 'Artist-approved updates only.' ),
				'subscribe_api_url' => 'https://artist.example/wp-json/extrachill/v1/artists/42/subscribe',
			)
		);

		$this->assertStringContainsString( 'Artist-approved updates only.', $html );
		$this->assertStringContainsString( 'extrch-link-page-subscribe-inline-form-container', $html );
		$this->assertStringContainsString( 'https://artist.example/wp-json/extrachill/v1/artists/42/subscribe', $html );
	}

	public function test_profile_styles_include_mobile_single_column_layout(): void {
		$styles = file_get_contents( dirname( __DIR__ ) . '/assets/css/artist-profile-subscribe.css' );

		$this->assertStringContainsString( '@media (max-width: 600px)', $styles );
		$this->assertStringContainsString( '.extrch-profile-subscribe-inline-form-container', $styles );
		$this->assertStringContainsString( 'grid-template-columns: 1fr;', $styles );
	}
}
