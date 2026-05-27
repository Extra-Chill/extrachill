<?php
/**
 * Unit tests for the `extrachill/list-concert-import-sources` ability's
 * end-user filtering (#54, Fix 1).
 *
 * End users must never see unconfigured sources — "API key not configured"
 * is platform plumbing, not actionable UX. Admins can explicitly request the
 * unconfigured list for setup/debugging.
 */

use ExtraChill\Users\Concert_Import\ImportSource;
use ExtraChill\Users\Concert_Import\ExternalEvent;

/**
 * Tiny in-memory ImportSource used to flip is_configured() per test.
 *
 * Lives inside this test file so we don't pollute the source tree with a
 * test-only fixture class.
 */
final class FakeConfigurableImportSource implements ImportSource {

	public function __construct(
		private string $slug,
		private string $label,
		private bool $configured
	) {
	}

	public function slug(): string {
		return $this->slug;
	}
	public function label(): string {
		return $this->label;
	}
	public function rate_limit(): array {
		return array( 'requests_per_second' => 1.0, 'requests_per_day' => 1000 );
	}
	public function is_configured(): bool {
		return $this->configured;
	}
	public function preview( string $username ) {
		return array( 'total' => 0, 'username' => $username );
	}
	public function fetch_page( string $username, int $page ) {
		return array( 'events' => array(), 'total_pages' => 1, 'total' => 0, 'page' => 1 );
	}
}

class Test_List_Concert_Import_Sources_Ability extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private int $user_id;

	protected function setUp(): void {
		parent::setUp();
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );

		// Replace registered sources with controlled fakes so the test owns
		// the configured/unconfigured matrix.
		add_filter( 'extrachill_concert_import_sources', array( $this, 'replace_sources' ), 999 );
	}

	protected function tearDown(): void {
		remove_filter( 'extrachill_concert_import_sources', array( $this, 'replace_sources' ), 999 );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function replace_sources( $sources ): array {
		return array(
			new FakeConfigurableImportSource( 'configured-source', 'Configured Source', true ),
			new FakeConfigurableImportSource( 'unconfigured-source', 'Unconfigured Source', false ),
		);
	}

	public function test_end_user_only_sees_configured_sources(): void {
		$result = extrachill_users_ability_list_concert_import_sources( array() );

		$this->assertArrayHasKey( 'sources', $result );
		$slugs = array_map( static fn( $s ) => $s['slug'], $result['sources'] );

		$this->assertContains( 'configured-source', $slugs );
		$this->assertNotContains(
			'unconfigured-source',
			$slugs,
			'End users must not see unconfigured import sources — "not configured" is admin plumbing.'
		);
	}

	public function test_subscriber_cannot_override_filter_with_include_unconfigured(): void {
		// Subscribers do not have `manage_options`. Passing the flag must be
		// silently ignored so end users can't bypass the filter.
		$result = extrachill_users_ability_list_concert_import_sources(
			array( 'include_unconfigured' => true )
		);
		$slugs = array_map( static fn( $s ) => $s['slug'], $result['sources'] );

		$this->assertNotContains(
			'unconfigured-source',
			$slugs,
			'Non-admin callers must not be able to surface unconfigured sources by passing the flag.'
		);
	}

	public function test_admin_with_flag_sees_all_sources(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = extrachill_users_ability_list_concert_import_sources(
			array( 'include_unconfigured' => true )
		);
		$slugs = array_map( static fn( $s ) => $s['slug'], $result['sources'] );

		$this->assertContains( 'configured-source', $slugs );
		$this->assertContains( 'unconfigured-source', $slugs );
	}

	public function test_admin_without_flag_still_sees_only_configured(): void {
		// Admin power is opt-in: omitting the flag means the admin gets the
		// same view an end user would. Avoids accidentally surfacing
		// unconfigured sources in normal admin browsing.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = extrachill_users_ability_list_concert_import_sources( array() );
		$slugs  = array_map( static fn( $s ) => $s['slug'], $result['sources'] );

		$this->assertContains( 'configured-source', $slugs );
		$this->assertNotContains( 'unconfigured-source', $slugs );
	}
}
