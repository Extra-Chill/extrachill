<?php
/**
 * Rolling indexable window for recurring artist/venue events.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace DataMachineEvents\Core {
	if ( ! class_exists( EventDatesTable::class ) ) {
		class EventDatesTable {
			public static function table_name(): string {
				return 'wp_dm_event_dates';
			}
		}
	}
}

namespace DataMachineEvents\Blocks\Calendar\Query {
	if ( ! class_exists( UpcomingFilter::class ) ) {
		class UpcomingFilter {
			public static function upcoming_where( string $datetime ): string {
				return "(ed.start_datetime >= '{$datetime}' OR ed.end_datetime >= '{$datetime}')";
			}
		}
	}
}

namespace ExtraChill\SEO\Tests\Unit\Core {

	use PHPUnit\Framework\TestCase;

	use function ExtraChill\SEO\Core\ec_seo_recurring_event_noindex;
	use function ExtraChill\SEO\Core\ec_seo_recurring_event_rank;
	use function ExtraChill\SEO\Core\ec_seo_recurring_event_sitemap_args;
	use function ExtraChill\SEO\Core\ec_seo_recurring_event_sitemap_where;

	final class RecurringEventsTest extends TestCase {

		/** @return array<int,array{post_id:int,start_datetime:string,end_datetime:null}> */
		private function rows( int $count ): array {
			$rows = array();
			for ( $i = $count; $i >= 1; --$i ) {
				$rows[] = array(
					'post_id'        => $i,
					'start_datetime' => sprintf( '2026-01-%02d 20:00:00', $i ),
					'end_datetime'   => null,
				);
			}
			return $rows;
		}

		private function noindex( array $rows, int $post_id, string $now = '2026-01-01 00:00:00' ): bool {
			$rank = ec_seo_recurring_event_rank( $rows, $post_id, $now );
			return ec_seo_recurring_event_noindex( $rank );
		}

		public function test_group_of_one_is_indexable(): void {
			$this->assertSame( array( 'rank' => 1, 'total' => 1 ), ec_seo_recurring_event_rank( $this->rows( 1 ), 1, '2026-01-01 00:00:00' ) );
			$this->assertFalse( $this->noindex( $this->rows( 1 ), 1 ) );
		}

		public function test_group_of_k_is_indexable(): void {
			foreach ( range( 1, 4 ) as $post_id ) {
				$this->assertFalse( $this->noindex( $this->rows( 4 ), $post_id ) );
			}
		}

		public function test_group_over_k_noindexes_next_occurrence(): void {
			$this->assertFalse( $this->noindex( $this->rows( 6 ), 4 ) );
			$this->assertTrue( $this->noindex( $this->rows( 6 ), 5 ) );
			$this->assertSame( 6, ec_seo_recurring_event_rank( $this->rows( 6 ), 5, '2026-01-01 00:00:00' )['total'] );
		}

		public function test_window_rolls_forward_after_date_passes(): void {
			$this->assertTrue( $this->noindex( $this->rows( 6 ), 5 ) );
			$this->assertFalse( $this->noindex( $this->rows( 6 ), 5, '2026-01-01 21:00:00' ) );
			$this->assertNull( ec_seo_recurring_event_rank( $this->rows( 6 ), 1, '2026-01-01 21:00:00' ) );
		}

		public function test_sitemap_clause_is_set_based_and_lazy(): void {
			$GLOBALS['wpdb'] = (object) array(
				'posts'              => 'wp_posts',
				'term_relationships' => 'wp_term_relationships',
				'term_taxonomy'      => 'wp_term_taxonomy',
			);
			$query           = new class() {
				public function get( string $key ) {
					return array(
						'ec_seo_recurring_window' => true,
						'post_type'               => 'data_machine_events',
					)[ $key ] ?? null;
				}
			};

			$args = ec_seo_recurring_event_sitemap_args( array(), 'data_machine_events' );
			$this->assertTrue( $args['ec_seo_recurring_window'] );
			$this->assertSame( array( 'x' => 1 ), ec_seo_recurring_event_sitemap_args( array( 'x' => 1 ), 'post' ) );

			$where = ec_seo_recurring_event_sitemap_where( ' AND 1=1', $query );
			$this->assertStringContainsString( 'wp_posts.ID NOT IN (SELECT post_id FROM', $where );
			$this->assertStringContainsString( 'ROW_NUMBER() OVER', $where );
			$this->assertStringContainsString( 'PARTITION BY artists.term_id, venues.term_id', $where );
			$this->assertStringContainsString( 'occurrence_rank > 4', $where );
			$this->assertStringContainsString( 'wp_dm_event_dates ed', $where );
		}
	}
}
