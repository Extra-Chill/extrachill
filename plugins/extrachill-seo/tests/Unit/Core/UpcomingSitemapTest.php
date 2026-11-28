<?php
/** SQL membership tests against SQLite, with core's real sitemap provider defaults. */
namespace {
	require_once dirname( __DIR__, 2 ) . '/Fixtures/class-wp-sitemaps-registry.php';
	function wp_register_sitemap_provider( $name, $provider ) {
		return $GLOBALS['ec_test_registry']->add_provider( $name, $provider );
	}
	if ( ! function_exists( 'wp_sitemaps_get_max_urls' ) ) {
		function wp_sitemaps_get_max_urls( $type ) { return 2; }
	}
	if ( ! function_exists( 'remove_filter' ) ) {
		function remove_filter( $hook, $callback, $priority = 10 ) {
			foreach ( $GLOBALS['ec_seo_test_filters'][ $hook ][ $priority ] ?? array() as $key => $entry ) {
				if ( $entry['callback'] === $callback ) {
					unset( $GLOBALS['ec_seo_test_filters'][ $hook ][ $priority ][ $key ] );
				}
			}
		}
	}
	// Only the WP_Query SQL-building boundary is stubbed. All event predicates
	// and the index aggregate execute as SQL, rather than matching canned strings.
	if ( ! class_exists( 'WP_Query' ) ) {
		class WP_Query {
			public $posts = array();
			public $request;
			public $max_num_pages = 0;
			private $args;
			public function __construct( $args = null ) { if ( null !== $args ) { $this->query( $args ); } }
			public function get( $key ) { return $this->args[ $key ] ?? ''; }
			public function query( $args ) {
				$this->args = $args;
				$where = apply_filters( 'posts_where', " WHERE wp_posts.post_type = 'data_machine_events' AND wp_posts.post_status = 'publish'", $this );
				$order = is_array( $args['orderby'] ) ? 'post_modified DESC, ID DESC' : 'ID ASC';
				$limit = (int) $args['posts_per_page'];
				$offset = ( ( $args['paged'] ?? 1 ) - 1 ) * $limit;
				$this->request = "SELECT wp_posts.* FROM wp_posts {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}";
				$pre = apply_filters( 'posts_pre_query', null, $this );
				if ( null !== $pre ) { $this->posts = $pre; return $pre; }
				$this->posts = array_map( static fn( $row ) => new WP_Post( $row + array( 'permalink' => 'https://example.com/?p=' . $row['ID'] ) ), $GLOBALS['wpdb']->db->query( $this->request )->fetchAll( \PDO::FETCH_ASSOC ) );
				if ( ! $args['no_found_rows'] ) {
					$this->max_num_pages = (int) ceil( $GLOBALS['wpdb']->db->query( 'SELECT COUNT(*) FROM wp_posts ' . $where )->fetchColumn() / $limit );
				}
				return $this->posts;
			}
		}
	}
}

namespace ExtraChill\SEO\Tests\Unit\Core {
	use ExtraChill\SEO\Core\EC_Sitemaps_Upcoming_Provider;
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/ExpiredEventsTest.php';

	final class UpcomingSitemapTest extends TestCase {
		private $previous;
		protected function setUp(): void {
			$this->previous = $GLOBALS['wpdb'] ?? null;
			add_filter( 'posts_where', 'ExtraChill\\SEO\\Core\\ec_seo_recurring_event_sitemap_where', 10, 2 );
			$GLOBALS['wpdb'] = new class {
				public $posts = 'wp_posts';
				public $term_relationships = 'wp_term_relationships';
				public $term_taxonomy = 'wp_term_taxonomy';
				public $db;
				public $aggregates = array();
				public function __construct() { $this->db = new \PDO( 'sqlite::memory:' ); $this->db->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION ); }
				public function get_var( $sql ) { $this->aggregates[] = $sql; return $this->db->query( $sql )->fetchColumn(); }
			};
			$db = $GLOBALS['wpdb']->db;
			$db->exec( 'CREATE TABLE wp_posts (ID INTEGER, post_type TEXT, post_status TEXT, post_modified TEXT, post_modified_gmt TEXT)' );
			$db->exec( 'CREATE TABLE wp_7_datamachine_event_dates (post_id INTEGER, start_datetime TEXT, end_datetime TEXT)' );
			$db->exec( 'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)' );
			$db->exec( 'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER, term_id INTEGER, taxonomy TEXT)' );
			$db->exec( "INSERT INTO wp_term_taxonomy VALUES (1, 1, 'artist'), (2, 2, 'venue')" );
			foreach ( range( 1, 6 ) as $id ) {
				$this->event( $id, "2027-01-0{$id} 20:00:00", null, "2026-11-0{$id} 12:00:00" );
				$db->exec( "INSERT INTO wp_term_relationships VALUES ({$id}, 1), ({$id}, 2)" );
			}
			$this->event( 7, '2025-01-01 00:00:00', '2027-01-01 00:00:00', '2026-11-10 12:00:00' ); // Ongoing.
			$this->event( 8, '2026-11-01 00:00:00', null, '2026-11-11 12:00:00' ); // Recent past: retained only in core.
			$this->event( 9, '2027-01-01 00:00:00', null, '2026-11-12 12:00:00', 'draft' );
			$this->event( 10, null, null, '2026-11-13 12:00:00' ); // Undated.
			$this->event( 11, '2025-01-01 00:00:00', null, '2026-11-14 12:00:00' ); // Expired.
			$GLOBALS['ec_seo_test_current_time'] = '2026-11-18 12:00:00';
		}
		protected function tearDown(): void {
			remove_filter( 'posts_where', 'ExtraChill\\SEO\\Core\\ec_seo_recurring_event_sitemap_where', 10 );
			$GLOBALS['wpdb'] = $this->previous;
			unset( $GLOBALS['ec_seo_test_current_time'] );
		}
		private function event( $id, $start, $end, $modified, $status = 'publish' ): void {
			$db = $GLOBALS['wpdb']->db;
			$db->prepare( 'INSERT INTO wp_posts VALUES (?, ?, ?, ?, ?)' )->execute( array( $id, 'data_machine_events', $status, $modified, $modified ) );
			if ( $start ) { $db->prepare( 'INSERT INTO wp_7_datamachine_event_dates VALUES (?, ?, ?)' )->execute( array( $id, $start, $end ) ); }
		}
		public function test_upcoming_provider_is_registered_before_core_posts(): void {
			$GLOBALS['ec_test_registry'] = new \WP_Sitemaps_Registry();
			try {
				$GLOBALS['ec_test_registry']->add_provider( 'posts', new \WP_Sitemaps_Posts() );
				$this->assertSame( array( 'upcoming-events', 'posts' ), array_keys( $GLOBALS['ec_test_registry']->get_providers() ) );
			} finally {
				unset( $GLOBALS['ec_test_registry'] );
			}
		}
		public function test_upcoming_membership_order_pagination_and_recurring_window(): void {
			$provider = new EC_Sitemaps_Upcoming_Provider();
			$this->assertSame( 3, $provider->get_max_num_pages() );
			$urls = array_merge( $provider->get_url_list( 1 ), $provider->get_url_list( 2 ), $provider->get_url_list( 3 ) );
			$this->assertSame( array( 7, 4, 3, 2, 1 ), array_map( static fn( $url ) => (int) substr( $url['loc'], strrpos( $url['loc'], '=' ) + 1 ), $urls ) );
			$this->assertSame( '2026-11-10T12:00:00+00:00', $urls[0]['lastmod'] );
			$this->assertSame( array(), $provider->get_url_list( 4 ) );
		}
		public function test_index_lastmod_is_page_specific_and_one_aggregate_per_page(): void {
			$entry = array( 'loc' => 'example' );
			$this->assertSame( '2026-11-10T12:00:00+00:00', apply_filters( 'wp_sitemaps_index_entry', $entry, 'upcoming-events', '', 1 )['lastmod'] );
			$this->assertSame( '2026-11-03T12:00:00+00:00', apply_filters( 'wp_sitemaps_index_entry', $entry, 'upcoming-events', '', 2 )['lastmod'] );
			$this->assertSame( '2026-11-02T12:00:00+00:00', apply_filters( 'wp_sitemaps_index_entry', $entry, 'posts', 'data_machine_events', 1 )['lastmod'] );
			$this->assertSame( '2026-11-13T12:00:00+00:00', apply_filters( 'wp_sitemaps_index_entry', $entry, 'posts', 'data_machine_events', 4 )['lastmod'] );
			$this->assertCount( 4, $GLOBALS['wpdb']->aggregates );
			$this->assertSame( $entry, apply_filters( 'wp_sitemaps_index_entry', $entry, 'posts', 'post', 1 ) );
			$this->assertCount( 4, $GLOBALS['wpdb']->aggregates );
			$this->assertSame( $entry, apply_filters( 'wp_sitemaps_index_entry', $entry, 'upcoming-events', '', 9 ) );
		}
	}
}
