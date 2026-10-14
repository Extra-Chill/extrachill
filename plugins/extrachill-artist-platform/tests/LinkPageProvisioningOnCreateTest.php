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

	public function test_create_artist_does_not_provision_a_link_page(): void {
		// Link Page creation is deliberate (#256).
		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Porch Lights Provisioning' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, (int) $result['link_page_id'] );
		$this->assertFalse( ec_get_link_page_for_artist( (int) $result['id'] ) );
	}

	public function test_create_link_page_ability_creates_a_published_page(): void {
		$this->ec_require_mysql_advisory_locks();
		$artist  = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Deliberate Band' ) );
		$created = extrachill_artist_platform_ability_create_artist_link_page( array( 'artist_id' => (int) $artist['id'] ) );

		$this->assertIsArray( $created );
		$this->assertGreaterThan( 0, (int) $created['link_page_id'] );
		$this->assertTrue( ec_artist_link_page_is_published( (int) $created['link_page_id'] ) );
		$this->assertNotSame( '', $created['edit_url'] );
	}

	public function test_create_link_page_ability_is_idempotent(): void {
		$this->ec_require_mysql_advisory_locks();
		$artist = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Idempotent Band' ) );
		$first  = extrachill_artist_platform_ability_create_artist_link_page( array( 'artist_id' => (int) $artist['id'] ) );
		$again  = extrachill_artist_platform_ability_create_artist_link_page( array( 'artist_id' => (int) $artist['id'] ) );

		$this->assertSame( (int) $first['link_page_id'], (int) $again['link_page_id'] );
	}

	public function test_create_link_page_ability_publishes_an_owned_draft(): void {
		$this->ec_require_mysql_advisory_locks();
		$artist  = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Draft Band' ) );
		$created = extrachill_artist_platform_ability_create_artist_link_page( array( 'artist_id' => (int) $artist['id'] ) );
		$page_id = (int) $created['link_page_id'];

		$draft = static function () use ( $page_id ) {
			wp_update_post( array( 'ID' => $page_id, 'post_status' => 'draft' ) );
		};
		function_exists( 'ec_with_link_page_storage_blog' ) ? ec_with_link_page_storage_blog( $draft ) : $draft();
		$this->assertFalse( ec_artist_link_page_is_published( $page_id ) );

		$again = extrachill_artist_platform_ability_create_artist_link_page( array( 'artist_id' => (int) $artist['id'] ) );

		$this->assertSame( $page_id, (int) $again['link_page_id'] );
		$this->assertTrue( ec_artist_link_page_is_published( $page_id ) );
	}
}
