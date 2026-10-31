<?php
/**
 * MCP observability handler that records usage as analytics events.
 *
 * @package ExtraChillMcp\Usage
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Usage;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Emits `mcp_session_started` and `mcp_tool_invoked` into extrachill-analytics.
 *
 * Replaces the adapter's `NullMcpObservabilityHandler`. The adapter already
 * calls `record_event( 'mcp.request', … )` on every routed request with the
 * method, session id, per-component tags and duration; this turns the two
 * requests worth counting into durable rows and drops the rest.
 *
 * Why this exists at all: Roadie shipped without usage instrumentation and the
 * one correction that mattered — that every single recorded tool call was
 * `present_question`, i.e. the agent interrogated people instead of acting —
 * came from two analytics events somebody thought to add late. MCP inherits
 * that risk exactly, because ability descriptions are the discovery surface
 * and discovery is the thing that already failed once. Shipping the surface
 * without the counter would repeat the mistake knowingly.
 *
 * Failure policy: instrumentation never breaks transport. Every path here is
 * best-effort and returns void; a missing analytics ability, a disabled
 * plugin, or a throwing emit is swallowed.
 */
final class AnalyticsObservabilityHandler implements McpObservabilityHandlerInterface {

	/**
	 * Adapter event name for a routed MCP request.
	 */
	private const REQUEST_EVENT = 'mcp.request';

	/**
	 * Record an observability event.
	 *
	 * @param array<string,mixed> $tags        Event tags.
	 * @param float|null          $duration_ms Duration in milliseconds.
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		if ( self::REQUEST_EVENT !== $event ) {
			return;
		}

		$method = isset( $tags['method'] ) && is_string( $tags['method'] ) ? $tags['method'] : '';

		switch ( $method ) {
			case 'initialize':
				$this->record_session_started( $tags );
				break;
			case 'tools/call':
				$this->record_tool_invoked( $tags, $duration_ms );
				break;
		}
	}

	/**
	 * @param array<string,mixed> $tags Request tags.
	 */
	private function record_session_started( array $tags ): void {
		$this->emit(
			'mcp_session_started',
			array(
				'session_id' => $this->string_tag( $tags, 'session_id' ),
				'server_id'  => $this->string_tag( $tags, 'server_id' ),
				'transport'  => $this->string_tag( $tags, 'transport' ),
				'status'     => $this->string_tag( $tags, 'status' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $tags        Request tags.
	 * @param float|null          $duration_ms Request duration.
	 */
	private function record_tool_invoked( array $tags, ?float $duration_ms ): void {
		// Always drain, even on an error request, so a failed call cannot
		// leave a stale ability name for the next one in the same process.
		$invocation = InvocationContext::drain();

		$payload = array(
			'tool'       => $this->string_tag( $tags, 'tool_name' ),
			'ability'    => $invocation['ability'],
			'site'       => $invocation['site'],
			'session_id' => $this->string_tag( $tags, 'session_id' ),
			'status'     => $this->string_tag( $tags, 'status' ),
		);

		if ( null !== $duration_ms ) {
			$payload['duration_ms'] = (int) round( $duration_ms );
		}

		$failure = $this->string_tag( $tags, 'failure_reason' );
		if ( null !== $failure ) {
			$payload['failure_reason'] = $failure;
		}

		$this->emit( 'mcp_tool_invoked', $payload );
	}

	/**
	 * Read a tag as a non-empty string.
	 *
	 * @param array<string,mixed> $tags Tag bag.
	 * @return string|null
	 */
	private function string_tag( array $tags, string $key ): ?string {
		$value = $tags[ $key ] ?? null;

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		return '' === $value ? null : $value;
	}

	/**
	 * Emit through the canonical analytics ability.
	 *
	 * @param array<string,mixed> $event_data Payload merged into event_data.
	 */
	private function emit( string $event_type, array $event_data ): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return;
		}

		$ability = wp_get_ability( 'extrachill/track-analytics-event' );
		if ( ! $ability ) {
			return;
		}

		$event_data = array_filter(
			$event_data,
			static fn( $value ): bool => null !== $value
		);

		$event_data['user_id'] = get_current_user_id();

		try {
			$ability->execute(
				array(
					'event_type' => $event_type,
					'event_data' => $event_data,
				)
			);
		} catch ( \Throwable $error ) {
			// Usage instrumentation must never take down the transport.
			unset( $error );
		}
	}
}
