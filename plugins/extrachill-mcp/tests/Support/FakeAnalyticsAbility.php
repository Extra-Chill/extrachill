<?php
/**
 * The object the stubbed `wp_get_ability()` hands back.
 *
 * Separate file because the coding standard allows one object structure per
 * file; it is only meaningful alongside AnalyticsStub, which owns its state.
 *
 * @package ExtraChillMcp\Tests\Support
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Tests\Support;

/**
 * The object `wp_get_ability()` hands back.
 */
final class FakeAnalyticsAbility {

	/**
	 * @param array<string,mixed> $input Ability input.
	 * @return int
	 */
	public function execute( array $input ): int {
		if ( AnalyticsStub::$throws ) {
			throw new \RuntimeException( 'analytics exploded' );
		}

		AnalyticsStub::$captured[] = array(
			'event_type' => is_string( $input['event_type'] ?? null ) ? $input['event_type'] : '',
			'event_data' => is_array( $input['event_data'] ?? null ) ? $input['event_data'] : array(),
		);

		return count( AnalyticsStub::$captured );
	}
}
