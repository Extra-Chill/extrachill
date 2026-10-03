<?php
/**
 * Canonical URL error handling coverage.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Term;

use function ExtraChill\SEO\Core\ec_seo_get_canonical_url;
use function ExtraChill\SEO\Core\ec_seo_get_final_canonical_url;

final class CanonicalErrorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ec_seo_test_is_404']                = false;
		$GLOBALS['ec_seo_test_is_tax']                = false;
		$GLOBALS['ec_seo_test_is_category']           = false;
		$GLOBALS['ec_seo_test_is_tag']                = false;
		$GLOBALS['ec_seo_test_queried_object']        = null;
		$GLOBALS['ec_seo_test_term_link_error']       = false;
		$GLOBALS['ec_seo_test_filters']['extrachill_seo_canonical_url'] = array();
	}

	public function test_filter_wp_error_is_normalized_to_empty_string(): void {
		add_filter(
			'extrachill_seo_canonical_url',
			static function () {
				return new WP_Error();
			}
		);

		$this->assertSame( '', ec_seo_get_final_canonical_url() );
	}

	public function test_failed_term_link_returns_empty_string_before_pagination(): void {
		$GLOBALS['ec_seo_test_is_tax']          = true;
		$GLOBALS['ec_seo_test_term_link_error'] = true;
		$GLOBALS['ec_seo_test_queried_object']  = new WP_Term(
			array(
				'term_id'  => 42,
				'taxonomy' => 'genre',
				'slug'     => 'jazz',
			)
		);

		$this->assertSame( '', ec_seo_get_canonical_url() );
		$this->assertSame( '', ec_seo_get_final_canonical_url() );
	}
}
