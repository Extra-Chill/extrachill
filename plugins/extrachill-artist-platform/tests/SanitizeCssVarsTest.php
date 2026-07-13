<?php

use PHPUnit\Framework\TestCase;

final class SanitizeCssVarsTest extends TestCase {
	/**
	 * Runs a callback capturing PHP deprecations/notices/warnings and asserts none fire.
	 *
	 * @param callable $callback
	 * @return mixed
	 */
	private function run_without_warnings( $callback ) {
		$captured = array();
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$captured ) {
				$captured[] = $errstr;
				return true;
			},
			E_DEPRECATED | E_NOTICE | E_WARNING
		);

		try {
			$result = $callback();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $captured, 'No deprecations/notices/warnings expected during sanitization.' );

		return $result;
	}

	public function test_non_array_input_returns_empty_array(): void {
		$this->assertSame( array(), extrachill_artist_platform_sanitize_css_vars( null ) );
		$this->assertSame( array(), extrachill_artist_platform_sanitize_css_vars( 'not-an-array' ) );
	}

	public function test_null_color_value_is_skipped_silently(): void {
		$result = $this->run_without_warnings(
			static function () {
				return extrachill_artist_platform_sanitize_css_vars(
					array(
						'--link-page-text-color' => null,
						'--link-page-accent-color' => '#ff5733',
					)
				);
			}
		);

		$this->assertArrayNotHasKey( '--link-page-text-color', $result );
		$this->assertSame( '#ff5733', $result['--link-page-accent-color'] );
	}

	public function test_array_color_value_is_skipped_silently(): void {
		$result = $this->run_without_warnings(
			static function () {
				return extrachill_artist_platform_sanitize_css_vars(
					array(
						'--link-page-bg' => array( '#ffffff' ),
						'--link-page-bg-color' => '#000000',
					)
				);
			}
		);

		$this->assertArrayNotHasKey( '--link-page-bg', $result );
		$this->assertSame( '#000000', $result['--link-page-bg-color'] );
	}

	public function test_valid_color_formats_are_preserved(): void {
		$input = array(
			'--link-page-short-hex-color'   => '#abc',
			'--link-page-long-hex-color'    => '#aabbcc',
			'--link-page-rgb-color'         => 'rgb(255, 0, 0)',
			'--link-page-rgba-color'        => 'rgba(255, 0, 0, 0.5)',
			'--link-page-hsl-color'         => 'hsl(120, 100%, 50%)',
			'--link-page-hsla-color'        => 'hsla(120, 100%, 50%, 0.3)',
		);

		$result = $this->run_without_warnings(
			static function () use ( $input ) {
				return extrachill_artist_platform_sanitize_css_vars( $input );
			}
		);

		$this->assertSame( $input, $result );
	}

	public function test_invalid_color_string_is_skipped_silently(): void {
		$result = extrachill_artist_platform_sanitize_css_vars(
			array(
				'--link-page-text-color' => 'not-a-color',
				'--link-page-accent-color' => '#00ff00',
			)
		);

		$this->assertArrayNotHasKey( '--link-page-text-color', $result );
		$this->assertSame( '#00ff00', $result['--link-page-accent-color'] );
	}

	public function test_ordinary_non_color_values_pass_through(): void {
		$result = extrachill_artist_platform_sanitize_css_vars(
			array(
				'overlay'             => '0.5',
				'--link-page-radius'  => '8px',
			)
		);

		$this->assertSame( '0.5', $result['overlay'] );
		$this->assertSame( '8px', $result['--link-page-radius'] );
	}

	public function test_keys_outside_allowlist_are_ignored(): void {
		$result = extrachill_artist_platform_sanitize_css_vars(
			array(
				'--evil-injected-css' => 'background:url(javascript:alert(1))',
				'--link-page-text-color' => '#123456',
			)
		);

		$this->assertArrayNotHasKey( '--evil-injected-css', $result );
		$this->assertSame( '#123456', $result['--link-page-text-color'] );
	}
}
