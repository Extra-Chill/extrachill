<?php
/**
 * Priority promoters: auto-flag hook, backfill, and abilities (#942).
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\PriorityPromoterAbilities;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/priority-promoter-stubs.php';
require_once __DIR__ . '/Stubs/promoted-events-dm-event-dates-table-double.php';
require_once __DIR__ . '/Stubs/promoted-events-dm-upcoming-filter-double.php';
require_once dirname( __DIR__, 3 ) . '/inc/admin/priority-promoters.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/PriorityPromoterAbilities.php';

/** Covers the priority promoter primitive end to end against in-memory doubles. */
final class PriorityPromoterTest extends TestCase {

	private const HOUSE    = 94440;
	private const OTHER    = 501;
	private const EVENT    = 1001;
	private const NONEVENT = 2002;

	/** @var mixed The runtime $wpdb, restored after each test. */
	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		priority_promoter_test_reset();
		$GLOBALS['priority_promoter_test']['terms']      = array(
			self::HOUSE => (object) array(
				'term_id' => self::HOUSE,
				'name'    => 'Extra Chill',
				'slug'    => 'extra-chill',
			),
			self::OTHER => (object) array(
				'term_id' => self::OTHER,
				'name'    => 'Someone Else',
				'slug'    => 'someone-else',
			),
		);
		$GLOBALS['priority_promoter_test']['post_types'] = array(
			self::EVENT    => 'data_machine_events',
			self::NONEVENT => 'post',
		);
		$this->original_wpdb                             = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                                 = new \PriorityPromoterFakeWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test double.
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the runtime database.
		priority_promoter_test_reset();
		parent::tearDown();
	}

	private function mark_house_priority(): void {
		$GLOBALS['priority_promoter_test']['term_meta'][ self::HOUSE ]['_ec_priority_promoter'] = '1';
	}

	private function assign_promoters( int $post_id, array $term_ids ): void {
		$GLOBALS['priority_promoter_test']['object_terms'][ $post_id ]['promoter'] = $term_ids;
	}

	private function is_priority_event( int $post_id ): bool {
		return '1' === get_post_meta( $post_id, '_extrachill_priority_event', true );
	}

	public function test_assigning_priority_promoter_flags_event_and_busts_cache(): void {
		$this->mark_house_priority();
		$this->assign_promoters( self::EVENT, array( self::HOUSE ) );
		wp_cache_set( 'extrachill_priority_event_ids', array( 7 ), 'extrachill-events' );

		ec_events_flag_priority_promoter_event( self::EVENT, array( 'extra-chill' ), array( self::HOUSE ), 'promoter' );

		$this->assertTrue( $this->is_priority_event( self::EVENT ) );
		$this->assertFalse( wp_cache_get( 'extrachill_priority_event_ids', 'extrachill-events' ) );
	}

	public function test_non_priority_promoter_does_not_flag_event(): void {
		$this->mark_house_priority();
		$this->assign_promoters( self::EVENT, array( self::OTHER ) );

		ec_events_flag_priority_promoter_event( self::EVENT, array( 'someone-else' ), array( self::OTHER ), 'promoter' );

		$this->assertFalse( $this->is_priority_event( self::EVENT ) );
	}

	public function test_hook_ignores_other_taxonomies_and_post_types(): void {
		$this->mark_house_priority();
		$this->assign_promoters( self::EVENT, array( self::HOUSE ) );
		$this->assign_promoters( self::NONEVENT, array( self::HOUSE ) );

		ec_events_flag_priority_promoter_event( self::EVENT, array(), array( 99 ), 'venue' );
		ec_events_flag_priority_promoter_event( self::NONEVENT, array(), array( self::HOUSE ), 'promoter' );

		$this->assertFalse( $this->is_priority_event( self::EVENT ) );
		$this->assertFalse( $this->is_priority_event( self::NONEVENT ) );
	}

	public function test_hook_is_noop_without_priority_promoters(): void {
		$this->assign_promoters( self::EVENT, array( self::HOUSE ) );

		ec_events_flag_priority_promoter_event( self::EVENT, array(), array( self::HOUSE ), 'promoter' );

		$this->assertFalse( $this->is_priority_event( self::EVENT ) );
	}

	public function test_removing_promoter_preserves_existing_priority_flag(): void {
		$this->mark_house_priority();
		update_post_meta( self::EVENT, '_extrachill_priority_event', true );
		$this->assign_promoters( self::EVENT, array() );

		ec_events_flag_priority_promoter_event( self::EVENT, array(), array(), 'promoter' );
		ec_set_priority_promoter( self::HOUSE, false );

		$this->assertTrue( $this->is_priority_event( self::EVENT ) );
	}

	public function test_priority_promoter_ids_are_cached_and_invalidated(): void {
		$this->assertSame( array(), ec_get_priority_promoter_ids() );

		$this->mark_house_priority();
		$this->assertSame( array(), ec_get_priority_promoter_ids(), 'Cached value served until invalidated.' );

		ec_set_priority_promoter( self::HOUSE, true );
		$this->assertSame( array( self::HOUSE ), ec_get_priority_promoter_ids() );
	}

	public function test_marking_promoter_backfills_upcoming_published_events(): void {
		$GLOBALS['wpdb']->seeded_post_ids = array( 11, 12 );
		update_post_meta( 12, '_extrachill_priority_event', true );

		$flagged = ec_set_priority_promoter( self::HOUSE, true );

		$this->assertSame( 1, $flagged, 'Already-priority events are not re-counted.' );
		$this->assertTrue( $this->is_priority_event( 11 ) );
		$this->assertTrue( $this->is_priority_event( 12 ) );

		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$query = $GLOBALS['wpdb']->queries[0];
		$this->assertStringContainsString( "p.post_status = 'publish'", $query );
		$this->assertStringContainsString( "tt.taxonomy = 'promoter'", $query );
		$this->assertStringContainsString( 'tt.term_id = ' . self::HOUSE, $query );
		$this->assertStringContainsString( 'ed.start_datetime >=', $query );
	}

	public function test_remarking_existing_priority_promoter_skips_backfill(): void {
		$this->mark_house_priority();
		$GLOBALS['wpdb']->seeded_post_ids = array( 11 );

		$this->assertSame( 0, ec_set_priority_promoter( self::HOUSE, true ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_set_priority_promoter_ability_by_slug(): void {
		$GLOBALS['wpdb']->seeded_post_ids = array( 11 );
		$abilities                        = new PriorityPromoterAbilities();

		$result = $abilities->setPriorityPromoter( array( 'promoter' => 'extra-chill' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( self::HOUSE, $result['promoter']['term_id'] );
		$this->assertTrue( $result['promoter']['priority'] );
		$this->assertSame( 1, $result['events_flagged'] );
		$this->assertSame( array( self::HOUSE ), ec_get_priority_promoter_ids() );
	}

	public function test_set_priority_promoter_ability_errors(): void {
		$abilities = new PriorityPromoterAbilities();

		$missing = $abilities->setPriorityPromoter( array() );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'missing_promoter', $missing->get_error_code() );

		$unknown = $abilities->setPriorityPromoter( array( 'promoter' => 'nobody' ) );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'promoter_not_found', $unknown->get_error_code() );
	}

	public function test_list_priority_promoters_ability(): void {
		$abilities = new PriorityPromoterAbilities();
		$this->assertSame(
			array(
				'promoters' => array(),
				'count'     => 0,
			),
			$abilities->listPriorityPromoters( array() )
		);

		ec_set_priority_promoter( self::HOUSE, true );
		$listed = $abilities->listPriorityPromoters( array() );

		$this->assertSame( 1, $listed['count'] );
		$this->assertSame( 'extra-chill', $listed['promoters'][0]['slug'] );
	}

	public function test_abilities_register_expected_names(): void {
		( new PriorityPromoterAbilities() )->register();

		$registered = $GLOBALS['priority_promoter_test']['abilities'];
		$this->assertArrayHasKey( 'extrachill/list-priority-promoters', $registered );
		$this->assertArrayHasKey( 'extrachill/set-priority-promoter', $registered );
		$this->assertSame( array( 'promoter' ), $registered['extrachill/set-priority-promoter']['input_schema']['required'] );
	}
}
