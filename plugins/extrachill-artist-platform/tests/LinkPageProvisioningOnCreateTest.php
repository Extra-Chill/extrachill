<?php
/**
 * Self-serve artist creation provisions a Link Page, and Link Page status is
 * read on the storage blog (Extra-Chill/extrachill-artist-platform#243, #245).
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class LinkPageProvisioningOnCreateTest extends EC_Artist_Platform_TestCase {
	private $owner_id;

	protected function setUp(): void {
		parent::setUp();
		$this->owner_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $this->owner_id, 'user_is_artist', '1' );
		wp_set_current_user( $this->owner_id );
	}

	public function test_status_helpers_reject_missing_pages(): void {
		$this->assertFalse( ec_artist_link_page_status( 0 ) );
		$this->assertFalse( ec_artist_link_page_status( 987654321 ) );
		$this->assertFalse( ec_artist_link_page_is_published( 987654321 ) );
	}

	public function test_create_artist_ability_provisions_a_published_link_page(): void {
		// Provisioning serializes through the canonical owner lock.
		$this->ec_require_mysql_advisory_locks();

		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Porch Lights Provisioning' ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'link_page_error', $result );
		$this->assertGreaterThan( 0, (int) $result['link_page_id'] );
		$this->assertTrue( ec_artist_link_page_is_published( (int) $result['link_page_id'] ) );
	}

	public function test_create_link_page_ability_is_idempotent(): void {
		$this->ec_require_mysql_advisory_locks();

		$artist = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Idempotent Band' ) );
		$again  = extrachill_artist_platform_ability_create_artist_link_page( array( 'artist_id' => (int) $artist['id'] ) );

		$this->assertIsArray( $again );
		$this->assertSame( (int) $artist['link_page_id'], (int) $again['link_page_id'] );
		$this->assertNotSame( '', $again['edit_url'] );
	}
}
