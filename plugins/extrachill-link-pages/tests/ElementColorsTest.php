<?php

use PHPUnit\Framework\TestCase;

final class ElementColorsTest extends TestCase {
	public function test_save_and_read_preserve_independent_colors(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish' );
		$colors = array(
			'--link-page-link-text-color' => '#123456',
			'--link-page-button-hover-text-color' => '#abcdef',
			'--link-page-icon-color' => '#654321',
		);
		$result = ec_save_link_page_persistence( 40, array( 'css_vars' => $colors ) );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$data = ec_read_link_page_persistence( 40 );
		foreach ( $colors as $key => $value ) {
			$this->assertSame( $value, $data['css_vars'][ $key ] );
		}
	}

	public function test_new_color_defaults_and_validation(): void {
		$defaults = ec_link_page_defaults_for( 'styles' );
		$this->assertSame( $defaults['--link-page-text-color'], $defaults['--link-page-icon-color'] );
		foreach ( array( '--link-page-icon-color', '--link-page-button-hover-text-color' ) as $key ) {
			$this->assertSame( array( $key => '#abc' ), ec_sanitize_link_page_css_vars( array( $key => '#abc' ) ) );
			$this->assertInstanceOf( WP_Error::class, ec_sanitize_link_page_css_vars( array( $key => 'url(evil)' ) ) );
		}
	}
}
