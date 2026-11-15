<?php
/**
 * iOS Smart App Banner meta coverage (extrachill#1416).
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

use function ExtraChill\SEO\Core\ec_seo_build_apple_itunes_app_content;
use function ExtraChill\SEO\Core\ec_seo_get_app_store_id;
use function ExtraChill\SEO\Core\ec_seo_get_apple_itunes_app_meta;

final class AppBannerTest extends TestCase {

	private const CANONICAL = 'https://extrachill.com/2026/09/some-article/';

	/** @var mixed */
	private $saved_canonical_filters;

	protected function setUp(): void {
		$GLOBALS['ec_seo_test_site_options'] = array();
		$GLOBALS['ec_seo_test_is_404']       = false;

		$this->saved_canonical_filters = $GLOBALS['ec_seo_test_filters']['extrachill_seo_canonical_url'] ?? null;
		$GLOBALS['ec_seo_test_filters']['extrachill_seo_canonical_url'] = array();
		add_filter(
			'extrachill_seo_canonical_url',
			static function () {
				return self::CANONICAL;
			}
		);
	}

	protected function tearDown(): void {
		$GLOBALS['ec_seo_test_site_options'] = array();
		$GLOBALS['ec_seo_test_is_404']       = false;

		if ( null === $this->saved_canonical_filters ) {
			unset( $GLOBALS['ec_seo_test_filters']['extrachill_seo_canonical_url'] );
		} else {
			$GLOBALS['ec_seo_test_filters']['extrachill_seo_canonical_url'] = $this->saved_canonical_filters;
		}
	}

	public function test_no_output_when_store_id_unset(): void {
		$this->assertSame( '', ec_seo_get_app_store_id() );
		$this->assertSame( '', ec_seo_get_apple_itunes_app_meta() );
	}

	public function test_no_output_when_store_id_blank_or_garbage(): void {
		foreach ( array( '', '   ', 'pending', array( 'x' ) ) as $value ) {
			$GLOBALS['ec_seo_test_site_options']['extrachill_app_store_id'] = $value;
			$this->assertSame( '', ec_seo_get_apple_itunes_app_meta(), 'value: ' . var_export( $value, true ) );
		}
	}

	public function test_play_package_alone_does_not_emit_ios_meta(): void {
		$GLOBALS['ec_seo_test_site_options']['extrachill_play_package'] = 'com.extrachill.app';

		$this->assertSame( '', ec_seo_get_apple_itunes_app_meta() );
	}

	public function test_meta_carries_store_id_and_canonical_app_argument(): void {
		$GLOBALS['ec_seo_test_site_options']['extrachill_app_store_id'] = '1234567890';

		$this->assertSame(
			'<meta name="apple-itunes-app" content="app-id=1234567890, app-argument=' . self::CANONICAL . '" />' . "\n",
			ec_seo_get_apple_itunes_app_meta()
		);
	}

	public function test_store_id_is_normalized_to_digits(): void {
		$GLOBALS['ec_seo_test_site_options']['extrachill_app_store_id'] = ' id1234567890 ';

		$this->assertSame( '1234567890', ec_seo_get_app_store_id() );
	}

	public function test_app_argument_omitted_without_canonical(): void {
		$GLOBALS['ec_seo_test_site_options']['extrachill_app_store_id'] = '1234567890';
		$GLOBALS['ec_seo_test_is_404']                                  = true;

		$this->assertSame(
			'<meta name="apple-itunes-app" content="app-id=1234567890" />' . "\n",
			ec_seo_get_apple_itunes_app_meta()
		);
	}

	public function test_content_builder_requires_store_id(): void {
		$this->assertSame( '', ec_seo_build_apple_itunes_app_content( '', self::CANONICAL ) );
		$this->assertSame(
			'app-id=42, app-argument=' . self::CANONICAL,
			ec_seo_build_apple_itunes_app_content( '42', self::CANONICAL )
		);
	}

	public function test_canonical_is_attribute_escaped(): void {
		$GLOBALS['ec_seo_test_site_options']['extrachill_app_store_id'] = '42';
		add_filter(
			'extrachill_seo_canonical_url',
			static function () {
				return 'https://extrachill.com/?q="><script>';
			},
			20
		);

		$meta = ec_seo_get_apple_itunes_app_meta();

		$this->assertStringNotContainsString( '"><script', $meta );
		$this->assertStringContainsString( 'app-argument=https://extrachill.com/?q=&quot;&gt;&lt;script&gt;', $meta );
	}
}
