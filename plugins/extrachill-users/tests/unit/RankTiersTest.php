<?php
/**
 * Unit tests for the data-driven rank tier registry.
 *
 * Verifies the registry shape, resolver correctness at every threshold
 * boundary, next-tier/progress helpers, and the `ec_rank_tiers` filter
 * extensibility contract.
 */

class Test_Rank_Tiers extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		ec_flush_rank_tiers_cache();
	}

	public function tear_down(): void {
		remove_all_filters( 'ec_rank_tiers' );
		ec_flush_rank_tiers_cache();
		parent::tear_down();
	}

	/**
	 * The canonical ladder, ascending. Thresholds must match the registry
	 * exactly; labels reflect the current canonical set (Full Ice Tray, and
	 * Walk-In Freezer ranked above Frozen Foods Isle).
	 *
	 * @return array<int, array{0:float,1:string}>
	 */
	private function canonical_ladder(): array {
		return array(
			array( 0, 'Dew' ),
			array( 15, 'Droplet' ),
			array( 35, 'Puddle' ),
			array( 69, 'Crisp Air' ),
			array( 103, 'First Frost' ),
			array( 155, 'Overnight Freeze' ),
			array( 232, 'Ice Cube' ),
			array( 349, 'Full Ice Tray' ),
			array( 523, 'Bag of Ice' ),
			array( 785, 'Ice Maker' ),
			array( 1178, 'Cooler' ),
			array( 1768, 'Fridge' ),
			array( 2652, 'Freezer' ),
			array( 3978, 'Ice Machine' ),
			array( 5968, 'Frozen Foods Isle' ),
			array( 8952, 'Walk-In Freezer' ),
			array( 13428, 'Ice Rink' ),
			array( 20143, 'Flurry' ),
			array( 30214, 'Snowstorm' ),
			array( 45322, 'Ski Resort' ),
			array( 67983, 'Blizzard' ),
			array( 101974, 'Glacier' ),
			array( 152961, 'Antarctica' ),
			array( 229442, 'Ice Age' ),
			array( 344164, 'Upper Atmosphere' ),
			array( 516246, 'Frozen Deep Space' ),
		);
	}

	public function test_registry_is_sorted_ascending_and_well_formed(): void {
		$tiers = ec_get_rank_tiers();

		$this->assertNotEmpty( $tiers );

		$prev = -1;
		foreach ( $tiers as $tier ) {
			$this->assertArrayHasKey( 'key', $tier );
			$this->assertArrayHasKey( 'label', $tier );
			$this->assertArrayHasKey( 'min_points', $tier );
			$this->assertArrayHasKey( 'icon', $tier );
			$this->assertArrayHasKey( 'class_name', $tier );
			$this->assertGreaterThan( $prev, $tier['min_points'], 'Tiers must be strictly ascending by min_points.' );
			$prev = $tier['min_points'];
		}
	}

	public function test_label_at_each_threshold(): void {
		foreach ( $this->canonical_ladder() as $row ) {
			list( $points, $label ) = $row;
			$this->assertSame(
				$label,
				ec_get_rank_for_points( $points ),
				"Threshold {$points} should resolve to {$label}."
			);
			$this->assertSame(
				$label,
				ec_determine_rank_by_points( $points ),
				"determine_rank_by_points({$points}) should resolve to {$label}."
			);
		}
	}

	public function test_label_just_below_threshold_resolves_to_lower_tier(): void {
		$ladder = $this->canonical_ladder();

		// For every tier above the floor, one point below its threshold must
		// resolve to the tier directly beneath it.
		for ( $i = 1, $n = count( $ladder ); $i < $n; $i++ ) {
			$threshold     = $ladder[ $i ][0];
			$expected_prev = $ladder[ $i - 1 ][1];
			$this->assertSame(
				$expected_prev,
				ec_get_rank_for_points( $threshold - 1 ),
				"One below {$threshold} should be {$expected_prev}."
			);
		}
	}

	public function test_below_floor_returns_lowest_tier(): void {
		$this->assertSame( 'Dew', ec_get_rank_for_points( -100 ) );
		$this->assertSame( 'Dew', ec_get_rank_for_points( 0 ) );
	}

	public function test_full_tier_record_for_points(): void {
		$tier = ec_get_rank_tier_for_points( 9000 );
		$this->assertSame( 'walk_in_freezer', $tier['key'] );
		$this->assertSame( 'Walk-In Freezer', $tier['label'] );
		$this->assertSame( 8952.0, $tier['min_points'] );
	}

	public function test_next_rank_tier(): void {
		$next = ec_get_next_rank_tier( 0 );
		$this->assertSame( 'Droplet', $next['label'] );

		$next = ec_get_next_rank_tier( 232 );
		$this->assertSame( 'Full Ice Tray', $next['label'] );

		// Top tier has no next.
		$this->assertNull( ec_get_next_rank_tier( 516246 ) );
		$this->assertNull( ec_get_next_rank_tier( 9999999 ) );
	}

	public function test_progress_midway_between_tiers(): void {
		// Dew (0) -> Droplet (15). Halfway at 7.5 points (use 7 -> still Dew).
		$progress = ec_get_rank_progress( 7 );
		$this->assertSame( 'Dew', $progress['current']['label'] );
		$this->assertSame( 'Droplet', $progress['next']['label'] );
		$this->assertSame( 8.0, $progress['points_to_next'] );
		$this->assertSame( 15.0, $progress['span'] );
		$this->assertFalse( $progress['is_max'] );
		$this->assertEqualsWithDelta( ( 7 / 15 ) * 100, $progress['percent'], 0.001 );
	}

	public function test_progress_at_max_tier(): void {
		$progress = ec_get_rank_progress( 600000 );
		$this->assertTrue( $progress['is_max'] );
		$this->assertNull( $progress['next'] );
		$this->assertNull( $progress['points_to_next'] );
		$this->assertSame( 100.0, $progress['percent'] );
		$this->assertSame( 'Frozen Deep Space', $progress['current']['label'] );
	}

	public function test_filter_can_add_a_tier(): void {
		add_filter(
			'ec_rank_tiers',
			static function ( $tiers ) {
				$tiers[] = array(
					'key'        => 'snowflake',
					'label'      => 'Snowflake',
					'min_points' => 130,
					'icon'       => 'snowflake',
					'class_name' => 'rank-snowflake',
				);
				return $tiers;
			}
		);

		// Flush so the filter is re-applied through the real registry path.
		ec_flush_rank_tiers_cache();

		$labels = wp_list_pluck( ec_get_rank_tiers(), 'label' );
		$this->assertContains( 'Snowflake', $labels );

		// Inserted tier must slot in by min_points and resolve correctly.
		$this->assertSame( 'Snowflake', ec_get_rank_for_points( 140 ) );
		$this->assertSame( 'First Frost', ec_get_rank_for_points( 120 ) );
	}

	public function test_default_ladder_has_26_tiers(): void {
		$this->assertCount( 26, ec_get_default_rank_tiers() );
	}
}
