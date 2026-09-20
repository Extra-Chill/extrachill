<?php
/**
 * Tests for MCP usage instrumentation.
 *
 * The contract these lock down is the one Roadie's six months taught: a
 * usage event that records only the advertised tool name is worthless here,
 * because this server advertises exactly two tools. `mcp_tool_invoked` must
 * carry the resolved ability name the caller actually reached, and the
 * instrumentation must never be able to break the transport it observes.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Tests\Support\AnalyticsStub;
use ExtraChillMcp\Usage\AnalyticsObservabilityHandler;
use ExtraChillMcp\Usage\InvocationContext;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group mcp-usage
 */
class UsageInstrumentationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		InvocationContext::drain();

		if ( ! AnalyticsStub::install() ) {
			$this->markTestSkipped( 'A real Abilities registry owns wp_get_ability(); the stub must not shadow it.' );
		}
	}

	public function tear_down(): void {
		InvocationContext::drain();
		AnalyticsStub::reset();

		parent::tear_down();
	}

	/**
	 * The context carries the resolved name and clears itself once drained.
	 */
	public function test_invocation_context_records_and_drains_once(): void {
		InvocationContext::record( 'extrachill/get-venue', 'events' );

		$first = InvocationContext::drain();
		$this->assertSame( 'extrachill/get-venue', $first['ability'] );
		$this->assertSame( 'events', $first['site'] );

		$second = InvocationContext::drain();
		$this->assertNull(
			$second['ability'],
			'A drained context must not report a stale ability to the next call.'
		);
		$this->assertNull( $second['site'] );
	}

	/**
	 * An empty name is not a recording — it would otherwise mask the previous
	 * real one and report it against the wrong call.
	 */
	public function test_invocation_context_ignores_blank_ability(): void {
		InvocationContext::record( 'extrachill/get-venue', 'events' );
		InvocationContext::record( '   ' );

		$this->assertSame( 'extrachill/get-venue', InvocationContext::drain()['ability'] );
	}

	/**
	 * Only the two interesting methods produce events.
	 */
	public function test_handler_ignores_unrelated_events_and_methods(): void {
		$handler = new AnalyticsObservabilityHandler();

		$handler->record_event( 'mcp.component.registration', array( 'method' => 'initialize' ) );
		$handler->record_event( 'mcp.request', array( 'method' => 'tools/list' ) );
		$handler->record_event( 'mcp.request', array( 'method' => 'ping' ) );

		$this->assertSame( array(), AnalyticsStub::$captured );
	}

	/**
	 * `initialize` is the session boundary.
	 */
	public function test_initialize_records_a_session(): void {
		( new AnalyticsObservabilityHandler() )->record_event(
			'mcp.request',
			array(
				'method'     => 'initialize',
				'session_id' => 'sess-abc',
				'server_id'  => 'extrachill-mcp',
				'transport'  => 'http',
				'status'     => 'success',
			)
		);

		$event = $this->single_captured();
		$this->assertSame( 'mcp_session_started', $event['event_type'] );
		$this->assertSame( 'sess-abc', $event['event_data']['session_id'] );
		$this->assertSame( 'extrachill-mcp', $event['event_data']['server_id'] );
	}

	/**
	 * The whole point: the resolved ability travels with the tool call.
	 */
	public function test_tools_call_records_the_resolved_ability_not_only_the_meta_tool(): void {
		InvocationContext::record( 'extrachill/get-venue', 'events' );

		( new AnalyticsObservabilityHandler() )->record_event(
			'mcp.request',
			array(
				'method'     => 'tools/call',
				'tool_name'  => 'extrachill/ability-call',
				'session_id' => 'sess-abc',
				'status'     => 'success',
			),
			12.7
		);

		$event = $this->single_captured();
		$this->assertSame( 'mcp_tool_invoked', $event['event_type'] );
		$this->assertSame( 'extrachill/ability-call', $event['event_data']['tool'] );
		$this->assertSame(
			'extrachill/get-venue',
			$event['event_data']['ability'],
			'Without the resolved ability this event reads "ability-call" forever and teaches nothing.'
		);
		$this->assertSame( 'events', $event['event_data']['site'] );
		$this->assertSame( 13, $event['event_data']['duration_ms'] );
	}

	/**
	 * A tool call with nothing recorded still counts, without a stale name.
	 */
	public function test_tools_call_without_a_resolved_ability_still_records(): void {
		( new AnalyticsObservabilityHandler() )->record_event(
			'mcp.request',
			array(
				'method'    => 'tools/call',
				'tool_name' => 'extrachill/ability-search',
				'status'    => 'success',
			)
		);

		$event = $this->single_captured();
		$this->assertSame( 'extrachill/ability-search', $event['event_data']['tool'] );
		$this->assertArrayNotHasKey( 'ability', $event['event_data'] );
	}

	/**
	 * A failed call drains too, or the next call inherits its ability name.
	 */
	public function test_failed_tools_call_drains_the_context(): void {
		InvocationContext::record( 'extrachill/get-venue', 'events' );

		$handler = new AnalyticsObservabilityHandler();
		$handler->record_event(
			'mcp.request',
			array(
				'method'         => 'tools/call',
				'tool_name'      => 'extrachill/ability-call',
				'status'         => 'error',
				'failure_reason' => 'Ability not found.',
			)
		);
		$handler->record_event(
			'mcp.request',
			array(
				'method'    => 'tools/call',
				'tool_name' => 'extrachill/ability-call',
				'status'    => 'success',
			)
		);

		$this->assertCount( 2, AnalyticsStub::$captured );
		$this->assertSame( 'error', AnalyticsStub::$captured[0]['event_data']['status'] );
		$this->assertSame( 'Ability not found.', AnalyticsStub::$captured[0]['event_data']['failure_reason'] );
		$this->assertArrayNotHasKey(
			'ability',
			AnalyticsStub::$captured[1]['event_data'],
			'The second call must not inherit the first call\'s resolved ability.'
		);
	}

	/**
	 * A deactivated analytics plugin must not break the transport.
	 */
	public function test_missing_analytics_ability_is_silent(): void {
		AnalyticsStub::$available = false;

		( new AnalyticsObservabilityHandler() )->record_event(
			'mcp.request',
			array(
				'method'    => 'tools/call',
				'tool_name' => 'extrachill/ability-call',
			)
		);

		$this->assertSame( array(), AnalyticsStub::$captured );
	}

	/**
	 * Nor must a throwing analytics ability.
	 */
	public function test_throwing_analytics_ability_is_swallowed(): void {
		AnalyticsStub::$throws = true;

		( new AnalyticsObservabilityHandler() )->record_event(
			'mcp.request',
			array( 'method' => 'initialize' )
		);

		$this->assertSame( array(), AnalyticsStub::$captured );
	}

	/**
	 * @return array{event_type:string,event_data:array<string,mixed>}
	 */
	private function single_captured(): array {
		$this->assertCount( 1, AnalyticsStub::$captured );

		return AnalyticsStub::$captured[0];
	}
}
