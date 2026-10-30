<?php
/**
 * Shared social links primitive: catalog, sanitizer, renderer.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class SocialLinksPrimitiveTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	public function test_link_page_catalog_is_the_shared_catalog(): void {
		$this->assertSame( ec_social_link_types(), ec_link_page_social_types() );
		$this->assertArrayHasKey( 'instagram', ec_social_link_types() );
		$this->assertTrue( ec_social_link_types()['custom']['has_custom_label'] );
	}

	public function test_sanitizer_keeps_custom_label_only_for_types_that_allow_it(): void {
		$clean = ec_sanitize_social_links(
			array(
				array( 'type' => 'custom', 'url' => 'https://merch.example', 'custom_label' => '<b>Merch</b>' ),
				array( 'type' => 'instagram', 'url' => 'https://instagram.com/x', 'custom_label' => 'Ignored' ),
			)
		);
		$this->assertSame(
			array(
				array( 'id' => 'custom-1', 'type' => 'custom', 'url' => 'https://merch.example', 'custom_label' => 'Merch' ),
				array( 'id' => 'instagram-2', 'type' => 'instagram', 'url' => 'https://instagram.com/x' ),
			),
			$clean
		);
	}

	public function test_sanitizer_assumes_https_for_schemeless_urls_and_rejects_other_schemes(): void {
		$clean = ec_sanitize_social_links( array( array( 'type' => 'website', 'url' => 'band.example/tour' ) ) );
		$this->assertSame( 'https://band.example/tour', $clean[0]['url'] );

		$error = ec_sanitize_social_links( array( array( 'type' => 'website', 'url' => 'javascript:alert(1)' ) ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'invalid_social_url', $error->get_error_code() );
	}

	public function test_link_page_wrapper_keeps_historical_error_codes(): void {
		$this->assertSame( 'invalid_link_page_social_links', ec_sanitize_link_page_social_links( 'nope' )->get_error_code() );
		$this->assertSame( 'invalid_link_page_social_type', ec_sanitize_link_page_social_links( array( array( 'type' => 'myspace', 'url' => 'https://x.example' ) ) )->get_error_code() );
		$this->assertSame( 'too_many_link_page_social_links', ec_sanitize_link_page_social_links( array_fill( 0, 21, array( 'type' => 'website', 'url' => 'https://x.example' ) ) )->get_error_code() );
	}

	public function test_sanitizer_limit_is_configurable_per_consumer(): void {
		$links = array_fill( 0, 3, array( 'type' => 'website', 'url' => 'https://x.example' ) );
		$this->assertSame( 'too_many_social_links', ec_sanitize_social_links( $links, array( 'max' => 2 ) )->get_error_code() );
		$this->assertCount( 3, ec_sanitize_social_links( $links, array( 'max' => 3 ) ) );
	}

	public function test_renderer_emits_icon_links_with_labels(): void {
		$html = ec_render_social_links(
			array(
				array( 'type' => 'instagram', 'url' => 'https://instagram.com/x' ),
				array( 'type' => 'custom', 'url' => 'https://merch.example', 'custom_label' => 'Merch' ),
			)
		);
		$this->assertStringStartsWith( '<div class="extrch-link-page-socials">', $html );
		$this->assertStringContainsString( 'class="extrch-social-icon"', $html );
		$this->assertStringContainsString( '<i class="fab fa-instagram" aria-hidden="true"></i>', $html );
		$this->assertStringContainsString( 'aria-label="Instagram"', $html );
		$this->assertStringContainsString( 'aria-label="Merch"', $html );
	}

	public function test_renderer_supports_consumer_classes_and_empty_input(): void {
		$this->assertSame( '', ec_render_social_links( array() ) );
		$html = ec_render_social_links( array( array( 'type' => 'website', 'url' => 'https://x.example' ) ), array( 'class' => 'profile-links', 'link_class' => 'profile-link' ) );
		$this->assertStringStartsWith( '<div class="profile-links">', $html );
		$this->assertStringContainsString( 'class="profile-link"', $html );
	}

	public function test_link_page_renderer_marks_below_position(): void {
		$links = array( array( 'type' => 'website', 'url' => 'https://x.example' ) );
		$this->assertStringContainsString( 'extrch-link-page-socials extrch-socials-below', ec_render_link_page_social_links( $links, 'below' ) );
		$this->assertStringContainsString( 'extrch-socials-below', ec_render_stored_link_page_social_links( $links, 'below' ) );
		$this->assertStringNotContainsString( 'extrch-socials-below', ec_render_stored_link_page_social_links( $links ) );
	}
}
