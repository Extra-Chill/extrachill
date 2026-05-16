<?php
/**
 * Tests for ec_seo_extract_event_details_block().
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

use function ExtraChill\SEO\Schema\ec_seo_extract_event_details_block;

final class SchemaEventExtractTest extends TestCase {

	public function test_extracts_event_details_block_attrs(): void {
		$content = '<!-- wp:data-machine-events/event-details {"startDate":"2026-04-20","startTime":"13:00","venue":"The Cabooze","price":"$60-$120"} -->'
			. '<div class="wp-block-data-machine-events-event-details">'
			. '<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->'
			. '</div>'
			. '<!-- /wp:data-machine-events/event-details -->';

		$attrs = ec_seo_extract_event_details_block( $content );

		$this->assertSame( '2026-04-20', $attrs['startDate'] );
		$this->assertSame( '13:00', $attrs['startTime'] );
		$this->assertSame( 'The Cabooze', $attrs['venue'] );
		$this->assertSame( '$60-$120', $attrs['price'] );
	}

	public function test_returns_empty_when_block_missing(): void {
		$content = '<!-- wp:paragraph --><p>No event-details here.</p><!-- /wp:paragraph -->';

		$this->assertSame( array(), ec_seo_extract_event_details_block( $content ) );
	}

	public function test_returns_empty_for_empty_content(): void {
		$this->assertSame( array(), ec_seo_extract_event_details_block( '' ) );
		$this->assertSame( array(), ec_seo_extract_event_details_block( "   \n  " ) );
	}

	public function test_finds_block_inside_nested_blocks(): void {
		$content = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group">'
			. '<!-- wp:columns -->'
			. '<div class="wp-block-columns">'
			. '<!-- wp:column -->'
			. '<div class="wp-block-column">'
			. '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01","venue":"Nested Hall"} -->'
			. '<div class="wp-block-data-machine-events-event-details"></div>'
			. '<!-- /wp:data-machine-events/event-details -->'
			. '</div>'
			. '<!-- /wp:column -->'
			. '</div>'
			. '<!-- /wp:columns -->'
			. '</div>'
			. '<!-- /wp:group -->';

		$attrs = ec_seo_extract_event_details_block( $content );

		$this->assertSame( '2027-01-01', $attrs['startDate'] );
		$this->assertSame( 'Nested Hall', $attrs['venue'] );
	}

	public function test_extracts_first_block_when_multiple_present(): void {
		$content = '<!-- wp:data-machine-events/event-details {"startDate":"2026-04-20","venue":"First"} -->'
			. '<div></div>'
			. '<!-- /wp:data-machine-events/event-details -->'
			. '<!-- wp:data-machine-events/event-details {"startDate":"2026-04-21","venue":"Second"} -->'
			. '<div></div>'
			. '<!-- /wp:data-machine-events/event-details -->';

		$attrs = ec_seo_extract_event_details_block( $content );

		$this->assertSame( 'First', $attrs['venue'] );
	}
}
