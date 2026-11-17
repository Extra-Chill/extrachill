<?php
/**
 * Ad tracking and analytics tag ID validation, save, and render tests (#1570).
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class TrackingIdsTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array(
			'ID'          => 40,
			'post_type'   => 'ec_link_page',
			'post_status' => 'publish',
			'post_title'  => 'Tracked Page',
			'post_name'   => 'tracked-page',
		);
		$GLOBALS['ec_test']['blogs'][4]['posts'][20] = (object) array(
			'ID'          => 20,
			'post_type'   => 'profile',
			'post_status' => 'publish',
		);
		$this->assertTrue( ec_assign_link_page_owner( 40, 'post:4:profile:20' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function valid_ad_tracking_ids(): array {
		return array(
			'empty disables'          => array( '' ),
			'16 digits'               => array( '1234567890123456' ),
			'15 digits'               => array( '123456789012345' ),
			'13 digit stored legacy'  => array( '1559507561216' ),
		);
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_ad_tracking_ids(): array {
		return array(
			'too short'        => array( '123456789012' ),
			'too long'         => array( '12345678901234567' ),
			'letters'          => array( '12345678901234a' ),
			'analytics tag'    => array( 'G-ABC1234' ),
			'inner space'      => array( '1234567 89012345' ),
			'trailing newline' => array( "123456789012345\n" ),
			'non-string'       => array( 1234567890123456 ),
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function valid_analytics_tag_ids(): array {
		return array(
			'empty disables' => array( '' ),
			'measurement'    => array( 'G-4NRPL4Z17C' ),
			'ads'            => array( 'AW-123456789' ),
			'google tag'     => array( 'GT-ABCD123' ),
			'floodlight'     => array( 'DC-1234567' ),
		);
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_analytics_tag_ids(): array {
		return array(
			'lowercase body'    => array( 'G-abc1234' ),
			'lowercase prefix'  => array( 'gt-ABCD123' ),
			'unknown prefix'    => array( 'UA-1234567-1' ),
			'tag manager'       => array( 'GTM-ABC1234' ),
			'missing body'      => array( 'G-' ),
			'too short body'    => array( 'G-AB1' ),
			'script injection'  => array( 'G-ABC");alert(1);//' ),
			'pixel id'          => array( '1234567890123456' ),
			'trailing newline'  => array( "G-ABC1234\n" ),
			'non-string'        => array( array( 'G-ABC1234' ) ),
		);
	}

	/**
	 * @dataProvider valid_ad_tracking_ids
	 */
	public function test_ad_tracking_validator_accepts_numeric_ids( string $id ): void {
		$this->assertTrue( ec_validate_link_page_ad_tracking_id( $id ) );
	}

	/**
	 * @dataProvider invalid_ad_tracking_ids
	 *
	 * @param mixed $id Candidate.
	 */
	public function test_ad_tracking_validator_rejects_malformed_ids( $id ): void {
		$result = ec_validate_link_page_ad_tracking_id( $id );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_link_page_ad_tracking_id', $result->get_error_code() );
		$this->assertStringContainsString( 'ad tracking ID', $result->get_error_message() );
	}

	/**
	 * @dataProvider valid_analytics_tag_ids
	 */
	public function test_analytics_tag_validator_accepts_current_formats( string $id ): void {
		$this->assertTrue( ec_validate_link_page_analytics_tag_id( $id ) );
	}

	/**
	 * @dataProvider invalid_analytics_tag_ids
	 *
	 * @param mixed $id Candidate.
	 */
	public function test_analytics_tag_validator_rejects_malformed_ids( $id ): void {
		$result = ec_validate_link_page_analytics_tag_id( $id );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_link_page_analytics_tag_id', $result->get_error_code() );
		$this->assertStringContainsString( 'analytics tag ID', $result->get_error_message() );
	}

	public function test_save_rejects_malformed_ids_without_writing(): void {
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'meta_pixel_id' => '1234567890123456', 'google_tag_id' => 'GT-ABCD123' ) ) );
		$before = $GLOBALS['ec_test']['blogs'][4]['post_meta'];

		$pixel = ec_save_link_page_persistence( 40, array( 'meta_pixel_id' => 'fb-123' ) );
		$this->assertInstanceOf( WP_Error::class, $pixel );
		$this->assertSame( 'invalid_link_page_ad_tracking_id', $pixel->get_error_code() );

		$tag = ec_save_link_page_persistence( 40, array( 'google_tag_id' => 'g-abc1234' ) );
		$this->assertInstanceOf( WP_Error::class, $tag );
		$this->assertSame( 'invalid_link_page_analytics_tag_id', $tag->get_error_code() );

		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['post_meta'] );
		$this->assertSame( '1234567890123456', get_post_meta( 40, '_link_page_meta_pixel_id', true ) );
		$this->assertSame( 'GT-ABCD123', get_post_meta( 40, '_link_page_google_tag_id', true ) );
	}

	public function test_save_trims_and_clears_ids(): void {
		$saved = ec_save_link_page_persistence( 40, array( 'meta_pixel_id' => ' 123456789012345 ', 'google_tag_id' => ' G-ABC1234 ' ) );
		$this->assertIsArray( $saved );
		$this->assertSame( '123456789012345', get_post_meta( 40, '_link_page_meta_pixel_id', true ) );
		$this->assertSame( 'G-ABC1234', get_post_meta( 40, '_link_page_google_tag_id', true ) );

		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'meta_pixel_id' => '', 'google_tag_id' => '' ) ) );
		$this->assertSame( '', get_post_meta( 40, '_link_page_meta_pixel_id', true ) );
		$this->assertSame( '', get_post_meta( 40, '_link_page_google_tag_id', true ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function rendered_analytics_tag_ids(): array {
		return array(
			'measurement' => array( 'G-4NRPL4Z17C' ),
			'ads'         => array( 'AW-123456789' ),
			'google tag'  => array( 'GT-ABCD123' ),
		);
	}

	/**
	 * @dataProvider rendered_analytics_tag_ids
	 */
	public function test_render_outputs_gtag_snippet( string $id ): void {
		$head = $this->render_head( array( 'google_tag_id' => $id ) );
		$this->assertStringContainsString( 'https://www.googletagmanager.com/gtag/js?id=' . $id, $head );
		$this->assertStringContainsString( 'gtag("config","' . $id . '")', $head );
	}

	public function test_render_outputs_pixel_snippet_for_stored_legacy_id(): void {
		$head = $this->render_head( array( 'meta_pixel_id' => '1559507561216' ) );
		$this->assertStringContainsString( 'fbq("init","1559507561216")', $head );
		$this->assertStringContainsString( 'tr?id=1559507561216', $head );
	}

	public function test_render_skips_malformed_stored_ids(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_link_page_google_tag_id']  = 'GT-STORED1';
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_link_page_meta_pixel_id'] = '123456789012345';
		$head = $this->render_head( array() );
		$this->assertStringContainsString( 'gtag/js?id=GT-STORED1', $head );
		$this->assertStringContainsString( 'fbq("init","123456789012345")', $head );

		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_link_page_google_tag_id']  = 'g-lowercase';
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_link_page_meta_pixel_id'] = '12345';
		$head = $this->render_head( array() );
		$this->assertStringNotContainsString( 'googletagmanager.com/gtag', $head );
		$this->assertStringNotContainsString( 'fbq(', $head );
	}

	/**
	 * Save settings and render the public head.
	 *
	 * @param array<string,string> $settings Settings to save first.
	 */
	private function render_head( array $settings ): string {
		if ( $settings ) {
			$this->assertIsArray( ec_save_link_page_persistence( 40, $settings ) );
		}
		$data     = ec_read_link_page_persistence( 40 );
		$prepared = ec_prepare_link_page_public_render( ec_get_link_page_public_projection( 40 ), $data );
		$this->assertIsArray( $prepared );
		ob_start();
		ec_render_link_page_public_head( 40, $data, $prepared );
		return (string) ob_get_clean();
	}
}
