<?php
use ExtraChill\Partner\Client;
use ExtraChill\Partner\Renderer;
use ExtraChill\Partner\Settings;
use PHPUnit\Framework\TestCase;

final class PartnerTest extends TestCase {
	private $fixture;
	private $calls;
	private $status;

	protected function setUp(): void {
		$GLOBALS['options'] = array( Client::KEY_OPTION => 'secret', 'date_format' => 'F j, Y', 'time_format' => 'g:i a' );
		$GLOBALS['transients'] = $GLOBALS['filters'] = array();
		$GLOBALS['admin'] = false;
		$property = new ReflectionProperty( Renderer::class, 'schemas' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
		$this->fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/lo-fi.json' ), true );
		$this->calls = 0;
		$this->status = 200;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			++$this->calls;
			$this->assertSame( 'secret', $args['headers']['X-Extrachill-Partner-Key'] );
			$this->assertSame( 5, $args['timeout'] );
			$this->assertStringStartsWith( 'https://events.extrachill.com/wp-json/extrachill/v1/partner/events?limit=', $url );
			return array( 'response' => array( 'code' => $this->status ), 'body' => json_encode( $this->fixture ) );
		} );
	}

	public function testListSchemaAndDeduplication(): void {
		$this->fixture['events'][0]['schema']['name'] = '</script><script>alert(1)</script>';
		$html = Renderer::events();
		$this->assertStringContainsString( '<ul', $html );
		$this->assertStringContainsString( '<time datetime="2026-12-01T20:00:00-05:00">', $html );
		$this->assertStringContainsString( '8:00 pm EST', $html );
		$this->assertStringContainsString( 'Demo Band', $html );
		$this->assertStringContainsString( 'href="https://events.extrachill.com/checkout/?event=123" rel="noopener"', $html );
		$this->assertStringNotContainsString( '</script><script>', $html );
		$this->assertStringContainsString( '\u003C/script\u003E', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches );
		$this->assertCount( count( $this->fixture['events'] ), $matches[1] );
		foreach ( $this->fixture['events'] as $index => $event ) {
			$this->assertSame( $event['schema'], json_decode( $matches[1][ $index ], true ) );
		}
		// Buy buttons come from ticket.url only; events without one get no button.
		preg_match_all( '#<a class="extrachill-partner-buy" href="([^"]+)" rel="noopener">([^<]*)</a>#', $html, $buttons );
		$this->assertSame( array( 'https://events.extrachill.com/checkout/?event=123', 'https://tickets.example.com/late-night-vinyl' ), $buttons[1] );
		$this->assertSame( array( 'Get Tickets', 'Buy on Partner' ), $buttons[2] );
		$this->assertStringContainsString( 'Holiday Market &amp; Live Music', $html );
		$this->assertStringContainsString( 'The Porch Pickers, Low Tide Trio', $html );
		$this->assertStringNotContainsString( 'application/ld+json', Renderer::events() );
		$this->assertSame( 1, $this->calls );
	}

	public function testFailureServesStaleWithAdminOnlyNoticeAndBackoff(): void {
		Client::get();
		$GLOBALS['transients'] = array();
		$this->status = 401;
		$html = Renderer::events();
		$this->assertStringContainsString( 'Lo-Fi Live', $html );
		$this->assertStringNotContainsString( 'extrachill-partner-notice', $html );
		$GLOBALS['admin'] = true;
		$this->assertStringContainsString( 'extrachill-partner-notice', Renderer::events() );
		$this->assertSame( 2, $this->calls );
	}

	public function testTransportFailureAndMalformedResponseAreSafe(): void {
		Client::get();
		$GLOBALS['transients'] = array();
		add_filter( 'pre_http_request', function () { return new WP_Error(); } );
		$this->assertStringContainsString( 'Lo-Fi Live', Renderer::events() );
		$GLOBALS['transients'] = array();
		$GLOBALS['options'] = array( Client::KEY_OPTION => 'secret' );
		$GLOBALS['filters']['pre_http_request'] = array( function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => '{invalid' );
		} );
		$this->assertSame( '', Renderer::events() );
	}

	public function testLimitsAndCacheTtl(): void {
		add_filter( 'extrachill_partner_cache_ttl', function () { return 123; } );
		Client::get( 1000 );
		Client::get( 100 );
		$this->assertSame( 1, $this->calls );
		$this->assertContains( 123, $GLOBALS['ttls'] );
		$this->assertSame( 1, Client::limit( -10 ) );
	}

	public function testColdFailureDoesNotExposeNoticeToVisitors(): void {
		$this->status = 401;
		$this->assertSame( '', Renderer::events() );
		$GLOBALS['admin'] = true;
		$this->assertStringContainsString( 'extrachill-partner-notice', Renderer::events() );
	}

	public function testBookingContract(): void {
		$html = Renderer::booking();
		preg_match( '/src="([^"]+)"/', $html, $match );
		parse_str( parse_url( html_entity_decode( $match[1] ), PHP_URL_QUERY ), $query );
		$this->assertSame( '1', $query['booking-embed'] );
		$this->assertSame( 'https://partner.example', $query['parent-origin'] );
		$GLOBALS['transients'] = array();
		$this->fixture['venue']['booking_embed_url'] = null;
		$this->assertSame( '', Renderer::booking() );
	}

	public function testBlockAndShortcodeShareOutput(): void {
		$shortcode = $GLOBALS['shortcodes']['extrachill_events'];
		$first = $shortcode( array( 'limit' => 10 ) );
		$property = new ReflectionProperty( Renderer::class, 'schemas' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
		$attributes = array( 'limit' => 10 );
		ob_start();
		include $GLOBALS['blocks']['upcoming-events'] . '/render.php';
		$this->assertSame( $first, ob_get_clean() );
	}

	public function testEmptyStateAndSettingsValidation(): void {
		$this->fixture['events'] = array();
		$this->assertStringContainsString( 'No upcoming shows.', Renderer::events() );
		$GLOBALS['admin'] = true;
		$this->assertSame( 'secret', Settings::save( 'secret' ) );
		$this->assertSame( 'Lo-Fi Brewing', get_option( 'extrachill_partner_venue_name' ) );
		$this->status = 401;
		$this->assertSame( 'secret', Settings::save( 'secret' ) );
		$this->assertSame( 'secret', Settings::save( '' ) );
	}
}
