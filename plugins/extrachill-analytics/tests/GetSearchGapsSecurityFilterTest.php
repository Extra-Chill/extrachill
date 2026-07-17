<?php
/**
 * Search-gap report security-filter regression tests.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-search-gaps.php';

/**
 * Verifies that payload terms are removed before both result buckets aggregate.
 */
final class GetSearchGapsSecurityFilterTest extends TestCase {

	/**
	 * Restore the global database fixture.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Observed and encoded payload families must not occupy either report bucket.
	 */
	public function test_payload_terms_are_excluded_from_zero_and_low_result_buckets(): void {
		$attack_terms = array(
			'/etc/shells',
			'%252Fetc%252Fshells',
			';assert(base64_decode("Q09NTUFORA=="));',
			'%253Bassert%2528base64_decode%2528%2522U0FGRQ%253D%253D%2522%2529%2529%253B',
		);

		$GLOBALS['wpdb'] = new class( $attack_terms ) {
			/**
			 * Payload fixture terms.
			 *
			 * @var array<int,string>
			 */
			public $attack_terms;

			/**
			 * Values bound through wpdb::prepare().
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public $prepared_values = array();

			/**
			 * Number of scalar aggregate queries handled.
			 *
			 * @var int
			 */
			private $get_var_calls = 0;

			/**
			 * Set payload fixture terms.
			 *
			 * @param array<int,string> $attack_terms Payload fixtures.
			 */
			public function __construct( $attack_terms ) {
				$this->attack_terms = $attack_terms;
			}

			/**
			 * Capture bound values while returning the query for fixture dispatch.
			 *
			 * @param string           $query  SQL query.
			 * @param array<int,mixed> $values Bound values.
			 * @return string
			 */
			public function prepare( $query, $values ) {
				$this->prepared_values[] = (array) $values;
				return $query;
			}

			/**
			 * Return rows for each report query shape.
			 *
			 * @param string $query SQL query.
			 * @return array<int,object>
			 */
			public function get_results( $query ) {
				if ( false !== strpos( $query, 'MIN(' ) ) {
					return array(
						(object) array(
							'term'        => 'AC/DC',
							'min_results' => 0,
							'zero_cnt'    => 5,
							'low_cnt'     => 0,
							'cnt'         => 5,
						),
						(object) array(
							'term'        => 'P!nk',
							'min_results' => 2,
							'zero_cnt'    => 0,
							'low_cnt'     => 3,
							'cnt'         => 3,
						),
					);
				}

				if ( false !== strpos( $query, 'COALESCE(' ) ) {
					return array(
						(object) array(
							'source'   => 'nav',
							'cnt'      => 8,
							'zero_cnt' => 5,
						),
					);
				}

				return array(
					(object) array(
						'term' => $this->attack_terms[0],
						'cnt'  => 77,
					),
					(object) array(
						'term' => $this->attack_terms[1],
						'cnt'  => 4,
					),
					(object) array(
						'term' => $this->attack_terms[2],
						'cnt'  => 45,
					),
					(object) array(
						'term' => $this->attack_terms[3],
						'cnt'  => 3,
					),
					(object) array(
						'term' => 'AC/DC',
						'cnt'  => 5,
					),
					(object) array(
						'term' => 'P!nk',
						'cnt'  => 3,
					),
				);
			}

			/**
			 * Return human total followed by the legacy-unclassified subset.
			 *
			 * @param string $query SQL query (unused in fixture).
			 * @return int
			 */
			public function get_var( $query ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				++$this->get_var_calls;
				return 1 === $this->get_var_calls ? 8 : 3;
			}
		};

		$report = extrachill_analytics_ability_get_search_gaps(
			array(
				'days'        => 0,
				'limit'       => 10,
				'max_results' => 3,
			)
		);

		$this->assertSame(
			array(
				array(
					'term'  => 'AC/DC',
					'count' => 5,
				),
			),
			$report['zero_result']
		);
		$this->assertSame(
			array(
				array(
					'term'        => 'P!nk',
					'count'       => 3,
					'min_results' => 2,
				),
			),
			$report['low_result']
		);
		$this->assertSame( 129, $report['excluded_attack_searches'] );
		$this->assertSame( 4, $report['excluded_attack_terms'] );
		$this->assertSame( 129, $report['excluded_bot'] );
		$this->assertSame( 8, $report['total_searches'] );

		$bound_values = array_merge( ...$GLOBALS['wpdb']->prepared_values );
		foreach ( $attack_terms as $attack_term ) {
			$this->assertContains( $attack_term, $bound_values );
		}
	}
}
