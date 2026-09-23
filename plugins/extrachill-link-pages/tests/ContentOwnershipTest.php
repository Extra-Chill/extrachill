<?php
/**
 * Page-owned content, font emission, and render-from-storage tests (#37).
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class ContentOwnershipTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Fallback Title', 'post_name' => 'owned-page' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'profile', 'post_status' => 'publish' );
		$this->assertTrue( ec_assign_link_page_owner( 40, 'post:4:profile:20' ) );
	}

	private function errorCode( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result->get_error_code();
	}

	public function test_display_title_falls_back_to_post_title_when_unowned(): void {
		$data = ec_read_link_page_persistence( 40 );
		$this->assertSame( 'Fallback Title', $data['display_title'] );
		$this->assertFalse( $data['display_title_is_owned'] );
		$this->assertSame( 0, $data['profile_image_id'] );
		$this->assertSame( '', $data['profile_image_url'] );
		$this->assertSame( array(), $data['social_links'] );
	}

	public function test_display_title_profile_image_and_social_links_are_page_owned(): void {
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'display_title'     => '<b>Owned Title</b>',
				'profile_image_id'  => 55,
				'social_links'      => array( array( 'type' => 'instagram', 'url' => 'https://instagram.com/x' ) ),
			)
		);
		$this->assertIsArray( $saved );
		$this->assertSame( 'Owned Title', get_post_meta( 40, '_link_page_display_title', true ) );
		// WordPress stores scalars as strings.
		$this->assertSame( '55', get_post_meta( 40, '_link_page_profile_image_id', true ) );
		$this->assertSame(
			array( array( 'id' => 'instagram-1', 'type' => 'instagram', 'url' => 'https://instagram.com/x' ) ),
			get_post_meta( 40, '_link_page_social_links', true )
		);

		$data = ec_read_link_page_persistence( 40 );
		$this->assertSame( 'Owned Title', $data['display_title'] );
		$this->assertTrue( $data['display_title_is_owned'] );
		$this->assertSame( 55, $data['profile_image_id'] );
		$this->assertSame( 'https://media.example/55.jpg', $data['profile_image_url'] );
		$this->assertSame( 'instagram', $data['social_links'][0]['type'] );
	}

	public function test_unknown_social_type_and_missing_url_are_rejected(): void {
		$this->assertSame(
			'invalid_link_page_social_type',
			$this->errorCode( ec_save_link_page_persistence( 40, array( 'social_links' => array( array( 'type' => 'myspace', 'url' => 'https://myspace.com/x' ) ) ) ) )
		);
		$this->assertSame(
			'invalid_link_page_social_url',
			$this->errorCode( ec_save_link_page_persistence( 40, array( 'social_links' => array( array( 'type' => 'website', 'url' => '' ) ) ) ) )
		);
		$this->assertSame( '', get_post_meta( 40, '_link_page_social_links', true ) );
	}

	public function test_render_readiness_succeeds_with_zero_projection_providers_when_page_owns_content(): void {
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'display_title'    => 'Owned Title',
				'profile_image_id' => 55,
				'social_links'     => array( array( 'type' => 'website', 'url' => 'https://example.com' ) ),
				'css_vars'         => array( '--link-page-title-font-family' => 'Loft Sans' ),
				'links'            => array( array( 'id' => 'new-1', 'section_title' => 'Listen', 'links' => array( array( 'id' => '', 'link_text' => 'Song', 'link_url' => 'https://example.com/song' ) ) ) ),
			)
		);
		$this->assertIsArray( $saved );
		$this->assertTrue( ec_link_page_render_readiness( 40 ) );

		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertFalse( is_wp_error( $projection ) );
		$this->assertSame( 'Owned Title', $projection['display_title'] );
		$this->assertSame( 'https://media.example/55.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'website', $projection['social_links'][0]['type'] );
		$this->assertIsCallable( $projection['social_renderer'] );

		$data = ec_read_link_page_persistence( 40 );
		$prepared = ec_prepare_link_page_public_render( $projection, $data );
		$this->assertFalse( is_wp_error( $prepared ) );
		ob_start();
		ec_render_link_page_public_head( 40, $data, $prepared );
		$head = ob_get_clean();
		$this->assertStringContainsString( 'Owned Title | extrachill.link', $head );

		$body = ec_render_link_page_section( $data['link_sections'][0], 40, false );
		$this->assertStringContainsString( 'Song', $body );
	}

	public function test_existing_page_without_owned_content_still_renders_via_deprecated_fallback(): void {
		// Mirrors an unmigrated artist page today: nothing is page-owned
		// yet, so a live provider still supplies everything and the page
		// must keep rendering exactly as it does now.
		ec_register_link_page_public_projection_provider(
			'fixture',
			static function () {
				return array(
					'display_title'   => 'Live Artist Name',
					'bio'             => 'Live bio',
					'profile_img_url' => 'https://media.example/live.jpg',
					'social_links'    => array( array( 'type' => 'spotify', 'url' => 'https://spotify.com/artist' ) ),
				);
			}
		);
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertFalse( is_wp_error( $projection ) );
		$this->assertSame( 'Live Artist Name', $projection['display_title'] );
		$this->assertSame( 'https://media.example/live.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'spotify', $projection['social_links'][0]['type'] );
	}

	public function test_owned_content_wins_over_a_still_registered_projection_provider(): void {
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'display_title'    => 'Owned Title',
				'profile_image_id' => 55,
				'social_links'     => array( array( 'type' => 'website', 'url' => 'https://example.com' ) ),
			)
		);
		$this->assertIsArray( $saved );
		ec_register_link_page_public_projection_provider(
			'fixture',
			static function () {
				return array(
					'display_title'   => 'Live Artist Name',
					'profile_img_url' => 'https://media.example/live.jpg',
					'social_links'    => array( array( 'type' => 'spotify', 'url' => 'https://spotify.com/artist' ) ),
				);
			}
		);
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertSame( 'Owned Title', $projection['display_title'] );
		$this->assertSame( 'https://media.example/55.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'website', $projection['social_links'][0]['type'] );
	}

	public function test_unowned_display_title_still_prefers_a_live_projection_over_the_post_title_fallback(): void {
		ec_register_link_page_public_projection_provider(
			'fixture',
			static function () {
				return array( 'display_title' => 'Live Artist Name' );
			}
		);
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertSame( 'Live Artist Name', $projection['display_title'] );
	}

	public function test_font_catalog_stack_and_local_css_resolution(): void {
		$this->assertSame( "'Loft Sans', Helvetica, Arial, sans-serif", ec_link_page_font_stack( 'Loft Sans' ) );
		$this->assertSame( "'Custom Font', 'Helvetica', Arial, sans-serif", ec_link_page_font_stack( 'Custom Font' ) );
		$this->assertNull( ec_link_page_google_font_param( 'Loft Sans' ) );
		$this->assertSame( 'Roboto:wght@400;600;700', ec_link_page_google_font_param( 'Roboto' ) );
		$this->assertStringContainsString( 'family=Roboto', ec_link_page_google_fonts_url( array( 'Loft Sans', 'Roboto' ) ) );
		$this->assertSame( '', ec_link_page_google_fonts_url( array( 'Loft Sans' ) ) );

		// Portable: with no site answering the filter, the runtime emits no
		// local @font-face and assumes no theme. The font still renders
		// through its CSS fallback stack.
		$this->assertSame( '', ec_link_page_local_font_face_url( 'Loft Sans' ) );
		$this->assertSame( '', ec_link_page_local_fonts_css( array( 'Loft Sans', 'Roboto' ) ) );

		// A site that hosts the face supplies its location through the filter.
		add_filter(
			'ec_link_page_local_font_face_url',
			static function ( $url, $font_value ) {
				return 'Loft Sans' === $font_value ? 'https://fonts.example.test/LoftSans' : $url;
			},
			10,
			2
		);
		$css = ec_link_page_local_fonts_css( array( 'Loft Sans', 'Roboto' ) );
		$this->assertStringContainsString( "@font-face{font-family:'Loft Sans'", $css );
		$this->assertStringContainsString( 'https://fonts.example.test/LoftSans.woff2', $css );
		$this->assertStringNotContainsString( 'Roboto', $css );

		// A resolved stack (from an owner projection override) round-trips
		// unchanged instead of being re-wrapped.
		$this->assertSame( "'Loft Sans', Helvetica, Arial, sans-serif", ec_link_page_font_stack( "'Loft Sans', Helvetica, Arial, sans-serif" ) );
	}

	public function test_font_enqueue_emits_google_url_and_local_font_face_for_owned_fonts(): void {
		ec_save_link_page_persistence(
			40,
			array(
				'css_vars' => array(
					'--link-page-title-font-family' => 'Loft Sans',
					'--link-page-body-font-family'  => 'Roboto',
				),
			)
		);
		// The site supplies the local face location (the runtime assumes no theme).
		add_filter(
			'ec_link_page_local_font_face_url',
			static function ( $url, $font_value ) {
				return 'Loft Sans' === $font_value ? 'https://fonts.example.test/LoftSans' : $url;
			},
			10,
			2
		);
		ec_enqueue_link_page_fonts( 40 );
		$this->assertContains( 'extrch-link-page-fonts', $GLOBALS['ec_test']['enqueued_styles'] );
		$this->assertStringContainsString( 'family=Roboto', $GLOBALS['ec_test']['registered_styles']['extrch-link-page-fonts']['src'] );
		$this->assertStringContainsString( "@font-face{font-family:'Loft Sans'", $GLOBALS['ec_test']['inline_styles']['extrch-link-page'][0] );
	}

	public function test_integer_fields_round_trip_as_strings_without_rollback(): void {
		// Regression: verification compared the stored "55" with int 55 and
		// rolled every integer write back.
		$saved = ec_save_link_page_persistence( 40, array( 'profile_image_id' => 55, 'background_image_id' => 77 ) );
		$this->assertIsArray( $saved );
		$this->assertSame( '55', get_post_meta( 40, '_link_page_profile_image_id', true ) );
		$this->assertSame( 55, ec_read_link_page_persistence( 40 )['profile_image_id'] );
		// Re-saving the same value is a no-op success, not a failure.
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'profile_image_id' => 55 ) ) );
	}

	public function test_meta_value_match_is_storage_aware(): void {
		$this->assertTrue( ec_link_page_meta_value_matches( '55', 55 ) );
		$this->assertTrue( ec_link_page_meta_value_matches( '1', true ) );
		$this->assertTrue( ec_link_page_meta_value_matches( '', false ) );
		$this->assertFalse( ec_link_page_meta_value_matches( '56', 55 ) );
		$this->assertTrue( ec_link_page_meta_value_matches( array( 'a' => 1 ), array( 'a' => 1 ) ) );
		$this->assertFalse( ec_link_page_meta_value_matches( array( 'a' => '1' ), array( 'a' => 1 ) ) );
	}
}
