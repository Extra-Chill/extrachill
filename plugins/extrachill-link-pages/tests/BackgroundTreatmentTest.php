<?php

use PHPUnit\Framework\TestCase;

final class BackgroundTreatmentTest extends TestCase {
	public function test_treatment_round_trips_through_persistence(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Photo', 'post_name' => 'photo' );
		$styles = array(
			'--link-page-image-blur'       => '20px',
			'--link-page-image-brightness' => '150%',
			'--link-page-image-position'   => '25% 75%',
			'--link-page-overlay-color'    => 'rgba(10, 20, 30, 0.3)',
		);
		$result = ec_save_link_page_persistence( 40, array( 'css_vars' => $styles ) );
		$this->assertIsArray( $result );
		$read = ec_read_link_page_persistence( 40 );
		foreach ( $styles as $key => $value ) {
			$this->assertSame( $value, $read['css_vars'][ $key ] );
		}
	}
}
