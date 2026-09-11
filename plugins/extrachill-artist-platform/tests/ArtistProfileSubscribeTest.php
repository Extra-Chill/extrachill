<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistProfileSubscribeTest extends EC_Artist_Platform_TestCase {
	private $profile_id;
	private $link_page_id;

	protected function setUp(): void {
		parent::setUp();

		$this->profile_id = $this->create_artist_profile(
			'Test Artist',
			array( 'post_name' => 'test-artist' )
		);

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->link_page_id = (int) self::factory()->post->create(
				array(
					'post_type'   => 'artist_link_page',
					'post_status' => 'publish',
				)
			);
			update_post_meta( $this->link_page_id, '_associated_artist_profile_id', $this->profile_id );
			update_post_meta( $this->profile_id, '_extrch_link_page_id', $this->link_page_id );
		} finally {
			restore_current_blog();
		}
	}

	private function subscribe_api_url(): string {
		switch_to_blog( $this->artist_blog_id() );
		try {
			return rest_url( 'extrachill/v1/artists/' . $this->profile_id . '/subscribe' );
		} finally {
			restore_current_blog();
		}
	}

	public function test_registers_a_bounded_profile_section(): void {
		$sections = ec_register_artist_profile_subscribe_section( array() );

		$this->assertSame( 'subscribe', $sections[0]['id'] );
		$this->assertSame( 15, $sections[0]['priority'] );
		$this->assertSame( 'ec_render_artist_profile_subscribe_section', $sections[0]['render'] );
		$this->assertSame( 'ec_is_artist_profile_subscribe_section_visible', $sections[0]['visible'] );
	}

	public function test_visibility_rejects_unpublished_and_disabled_artists(): void {
		switch_to_blog( $this->artist_blog_id() );
		$visible = ec_is_artist_profile_subscribe_section_visible( $this->profile_id );
		restore_current_blog();
		$this->assertTrue( $visible );

		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->link_page_id, '_link_page_subscribe_display_mode', 'disabled' );
		$disabled_visible = ec_is_artist_profile_subscribe_section_visible( $this->profile_id );
		delete_post_meta( $this->link_page_id, '_link_page_subscribe_display_mode' );
		wp_update_post(
			array(
				'ID'          => $this->profile_id,
				'post_status' => 'draft',
			)
		);
		clean_post_cache( $this->profile_id );
		$draft_visible = ec_is_artist_profile_subscribe_section_visible( $this->profile_id );
		restore_current_blog();

		$this->assertFalse( $disabled_visible );
		$this->assertFalse( $draft_visible );
	}

	/**
	 * @dataProvider authentication_states
	 */
	public function test_profile_form_is_public_and_accessible( bool $logged_in ): void {
		if ( $logged_in ) {
			wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		} else {
			wp_set_current_user( 0 );
		}

		switch_to_blog( $this->artist_blog_id() );
		try {
			ob_start();
			ec_render_artist_profile_subscribe_section( $this->profile_id );
			$html = ob_get_clean();
		} finally {
			restore_current_blog();
		}

		$this->assertStringContainsString( 'Subscribe to Test Artist', $html );
		$this->assertStringContainsString( 'data-subscribe-api-url="' . $this->subscribe_api_url() . '"', $html );
		$this->assertStringContainsString( 'autocomplete="email"', $html );

		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		$xpath  = new DOMXPath( $document );
		$input  = $xpath->query( '//input[@type="email"]' )->item( 0 );
		$label  = $xpath->query( '//label[@for="' . $input->getAttribute( 'id' ) . '"]' )->item( 0 );
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
		switch_to_blog( $this->artist_blog_id() );
		try {
			update_post_meta( $this->link_page_id, '_link_page_subscribe_description', 'Artist-approved updates only.' );

			$html = ec_render_template(
				'subscribe-inline-form',
				array(
					'artist_id'         => $this->profile_id,
					'artist_name'       => 'Test Artist',
					'data'              => array( '_link_page_subscribe_description' => 'Artist-approved updates only.' ),
					'subscribe_api_url' => $this->subscribe_api_url(),
				)
			);
		} finally {
			restore_current_blog();
		}

		$this->assertStringContainsString( 'Artist-approved updates only.', $html );
		$this->assertStringContainsString( 'extrch-link-page-subscribe-inline-form-container', $html );
		$this->assertStringContainsString( $this->subscribe_api_url(), $html );
	}

	public function test_profile_styles_include_mobile_single_column_layout(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local asset read, not a remote URL.
		$styles = file_get_contents( dirname( __DIR__ ) . '/assets/css/artist-profile-subscribe.css' );

		$this->assertStringContainsString( '@media (max-width: 600px)', $styles );
		$this->assertStringContainsString( '.extrch-profile-subscribe-inline-form-container', $styles );
		$this->assertStringContainsString( 'grid-template-columns: 1fr;', $styles );
	}
}
