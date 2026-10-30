<?php
/**
 * Canonical identity coverage for missing resources.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use WP_Post;

use function ExtraChill\SEO\Core\ec_seo_get_final_canonical_url;
use function ExtraChill\SEO\Core\ec_seo_suppress_404_open_graph_url;
use function ExtraChill\SEO\Schema\ec_seo_suppress_404_schema_graph;

final class Canonical404Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ec_seo_test_is_404']         = false;
		$GLOBALS['ec_seo_test_is_singular']    = false;
		$GLOBALS['ec_seo_test_queried_object'] = null;
	}

	public function test_ordinary_404_has_no_canonical_identity(): void {
		$GLOBALS['ec_seo_test_is_404'] = true;

		$this->assertSame( '', ec_seo_get_final_canonical_url() );
		$this->assertArrayNotHasKey(
			'og:url',
			ec_seo_suppress_404_open_graph_url( array( 'og:url' => 'https://events.example.com/missing/' ) )
		);
		$this->assertSame(
			array(),
			ec_seo_suppress_404_schema_graph( array( array( '@id' => 'https://events.example.com/missing/#webpage' ) ) )
		);
	}

	public function test_event_shaped_404_has_no_canonical_identity(): void {
		$GLOBALS['ec_seo_test_is_404']         = true;
		$GLOBALS['ec_seo_test_is_singular']    = true;
		$GLOBALS['ec_seo_test_queried_object'] = new WP_Post(
			array(
				'ID'        => 404,
				'post_type' => 'data_machine_events',
				'post_name' => 'missing-event',
				'permalink' => 'https://events.example.com/events/missing-event/',
			)
		);

		$this->assertSame( '', ec_seo_get_final_canonical_url() );
		$this->assertArrayNotHasKey(
			'og:url',
			ec_seo_suppress_404_open_graph_url( array( 'og:url' => 'https://events.example.com/events/missing-event/' ) )
		);
		$this->assertSame(
			array(),
			ec_seo_suppress_404_schema_graph( array( array( '@type' => 'MusicEvent' ) ) )
		);
	}

	public function test_normal_event_single_keeps_its_canonical_identity(): void {
		$permalink = 'https://events.example.com/events/real-event/';

		$GLOBALS['ec_seo_test_is_singular']    = true;
		$GLOBALS['ec_seo_test_queried_object'] = new WP_Post(
			array(
				'ID'        => 123,
				'post_type' => 'data_machine_events',
				'post_name' => 'real-event',
				'permalink' => $permalink,
			)
		);

		$this->assertSame( $permalink, ec_seo_get_final_canonical_url() );
		$this->assertSame(
			array( 'og:url' => $permalink ),
			ec_seo_suppress_404_open_graph_url( array( 'og:url' => $permalink ) )
		);
		$this->assertSame(
			array(
				array(
					'@type' => 'MusicEvent',
					'url'   => $permalink,
				),
			),
			ec_seo_suppress_404_schema_graph(
				array(
					array(
						'@type' => 'MusicEvent',
						'url'   => $permalink,
					),
				)
			)
		);
	}
}
