<?php
/**
 * Event roundup permission contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

use ExtraChillEvents\Abilities\EventRoundupAbilities;

require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventRoundupAbilities.php';

/**
 * The roundup build ability composes query + render, so all three abilities
 * must share one permission gate (#932).
 */
final class EventRoundupPermissionTest extends BookingTestCase {

	/**
	 * Read the ability source once.
	 */
	private function source(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/EventRoundupAbilities.php' );
	}

	/** Every roundup ability uses the shared permission callback. */
	public function test_all_roundup_abilities_share_permission_callback(): void {
		$source = $this->source();

		$this->assertSame( 3, substr_count( $source, 'wp_register_ability(' ) );
		$this->assertSame(
			3,
			substr_count( $source, "'permission_callback' => array( self::class, 'canRunRoundup' )" ),
			'Query, render, and build must all use canRunRoundup().'
		);
	}

	/** The query step is not stricter than the build that calls it. */
	public function test_query_does_not_require_manage_options(): void {
		$this->assertStringNotContainsString( "'manage_options'", $this->source() );
	}

	/** The shared capability is the image-upload capability. */
	public function test_shared_capability_is_upload_files(): void {
		$this->assertSame( 'upload_files', EventRoundupAbilities::ROUNDUP_CAPABILITY );
		$this->assertTrue( is_callable( array( EventRoundupAbilities::class, 'canRunRoundup' ) ) );
	}
}
