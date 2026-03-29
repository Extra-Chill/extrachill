<?php
/**
 * Event Time Audit Abilities
 *
 * Audits event times against venue timezone expectations and provides
 * bulk timezone correction for events with wrong times.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventTimeAuditAbilities {

	private static bool $registered = false;

	/**
	 * US state → expected IANA timezone map.
	 * Used to flag events whose venue timezone doesn't match their location's expected timezone.
	 *
	 * @var array<string, string>
	 */
	private const STATE_TIMEZONE_MAP = array(
		'alabama'        => 'America/Chicago',
		'alaska'         => 'America/Anchorage',
		'arizona'        => 'America/Phoenix',
		'arkansas'       => 'America/Chicago',
		'california'     => 'America/Los_Angeles',
		'colorado'       => 'America/Denver',
		'connecticut'    => 'America/New_York',
		'delaware'       => 'America/New_York',
		'florida'        => 'America/New_York',
		'georgia'        => 'America/New_York',
		'hawaii'         => 'Pacific/Honolulu',
		'idaho'          => 'America/Boise',
		'illinois'       => 'America/Chicago',
		'indiana'        => 'America/Indiana/Indianapolis',
		'iowa'           => 'America/Chicago',
		'kansas'         => 'America/Chicago',
		'kentucky'       => 'America/New_York',
		'louisiana'      => 'America/Chicago',
		'maine'          => 'America/New_York',
		'maryland'       => 'America/New_York',
		'massachusetts'  => 'America/New_York',
		'michigan'       => 'America/Detroit',
		'minnesota'      => 'America/Chicago',
		'mississippi'    => 'America/Chicago',
		'missouri'       => 'America/Chicago',
		'montana'        => 'America/Denver',
		'nebraska'       => 'America/Chicago',
		'nevada'         => 'America/Los_Angeles',
		'new-hampshire'  => 'America/New_York',
		'new-jersey'     => 'America/New_York',
		'new-mexico'     => 'America/Denver',
		'new-york'       => 'America/New_York',
		'north-carolina' => 'America/New_York',
		'north-dakota'   => 'America/Chicago',
		'ohio'           => 'America/New_York',
		'oklahoma'       => 'America/Chicago',
		'oregon'         => 'America/Los_Angeles',
		'pennsylvania'   => 'America/New_York',
		'rhode-island'   => 'America/New_York',
		'south-carolina' => 'America/New_York',
		'south-dakota'   => 'America/Chicago',
		'tennessee'      => 'America/Chicago',
		'texas'          => 'America/Chicago',
		'utah'           => 'America/Denver',
		'vermont'        => 'America/New_York',
		'virginia'       => 'America/New_York',
		'washington'     => 'America/Los_Angeles',
		'west-virginia'  => 'America/New_York',
		'wisconsin'      => 'America/Chicago',
		'wyoming'        => 'America/Denver',
	);

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/audit-event-times',
			array(
				'label'               => __( 'Audit Event Times', 'extrachill-events' ),
				'description'         => __( 'Scan events for timezone mismatches and suspicious times (e.g. UTC timezone on US venues, shows at 1-6 AM).', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'flow_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Filter to events from a specific flow.', 'extrachill-events' ),
						),
						'location' => array(
							'type'        => 'string',
							'description' => __( 'Filter by location term slug or ID.', 'extrachill-events' ),
						),
						'venue'    => array(
							'type'        => 'string',
							'description' => __( 'Filter by venue term slug or ID.', 'extrachill-events' ),
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => __( 'Maximum events to scan. Use 0 for all.', 'extrachill-events' ),
							'default'     => 500,
						),
						'offset'   => array(
							'type'        => 'integer',
							'description' => __( 'Offset for batched audits.', 'extrachill-events' ),
							'default'     => 0,
						),
					),
				),
				'output_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'checked_count'  => array( 'type' => 'integer' ),
						'flagged_count'  => array( 'type' => 'integer' ),
						'results'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'executeAuditEventTimes' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/fix-event-times',
			array(
				'label'               => __( 'Fix Event Times', 'extrachill-events' ),
				'description'         => __( 'Bulk-fix event times by converting between timezones. Updates block attributes in post content.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'from', 'to' ),
					'properties' => array(
						'from'    => array(
							'type'        => 'string',
							'description' => __( 'Source timezone (the wrong one currently stored).', 'extrachill-events' ),
						),
						'to'      => array(
							'type'        => 'string',
							'description' => __( 'Target timezone (the correct one to convert to).', 'extrachill-events' ),
						),
						'flow_id' => array(
							'type'        => 'integer',
							'description' => __( 'Scope to events from a specific flow.', 'extrachill-events' ),
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => __( 'Preview changes without applying.', 'extrachill-events' ),
							'default'     => true,
						),
						'limit'   => array(
							'type'        => 'integer',
							'description' => __( 'Maximum events to fix. Use 0 for all.', 'extrachill-events' ),
							'default'     => 500,
						),
					),
				),
				'output_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'checked_count' => array( 'type' => 'integer' ),
						'fixed_count'   => array( 'type' => 'integer' ),
						'dry_run'       => array( 'type' => 'boolean' ),
						'results'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'executeFixEventTimes' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => true,
					),
				),
			)
		);
	}

	/**
	 * Execute audit-event-times ability.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeAuditEventTimes( array $input ) {
		$limit   = ( isset( $input['limit'] ) && 0 !== (int) $input['limit'] ) ? (int) $input['limit'] : -1;
		$offset  = (int) ( $input['offset'] ?? 0 );
		$flow_id = ! empty( $input['flow_id'] ) ? (int) $input['flow_id'] : 0;

		$query_args = array(
			'post_type'      => 'data_machine_events',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'fields'         => 'ids',
		);

		if ( $flow_id > 0 ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_datamachine_post_flow_id',
					'value' => (string) $flow_id,
				),
			);
		}

		if ( ! empty( $input['location'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'location',
					'field'    => is_numeric( $input['location'] ) ? 'term_id' : 'slug',
					'terms'    => $input['location'],
				),
			);
		}

		if ( ! empty( $input['venue'] ) ) {
			$venue_tax_query = array(
				'taxonomy' => 'venue',
				'field'    => is_numeric( $input['venue'] ) ? 'term_id' : 'slug',
				'terms'    => $input['venue'],
			);
			if ( isset( $query_args['tax_query'] ) ) {
				$query_args['tax_query']['relation'] = 'AND';
				$query_args['tax_query'][]           = $venue_tax_query;
			} else {
				$query_args['tax_query'] = array( $venue_tax_query );
			}
		}

		$post_ids = get_posts( $query_args );

		$results = array();
		$flagged = 0;

		foreach ( $post_ids as $post_id ) {
			$issue = $this->auditSingleEvent( (int) $post_id );
			if ( null !== $issue ) {
				$results[] = $issue;
				++$flagged;
			}
		}

		return array(
			'checked_count' => count( $post_ids ),
			'flagged_count' => $flagged,
			'results'       => $results,
		);
	}

	/**
	 * Audit a single event for time/timezone issues.
	 *
	 * @param int $post_id Event post ID.
	 * @return array|null Issue data, or null if no issue.
	 */
	private function auditSingleEvent( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$block_attrs = $this->getEventBlockAttrs( $post->post_content );
		if ( null === $block_attrs ) {
			return null;
		}

		$start_time = $block_attrs['startTime'] ?? '';
		$venue_name = $block_attrs['venue'] ?? '';

		// Get venue timezone from term meta.
		$venue_terms = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
		$venue_tz    = '';
		if ( ! empty( $venue_terms ) && ! is_wp_error( $venue_terms ) ) {
			$venue_tz = get_term_meta( $venue_terms[0], '_venue_timezone', true );
		}

		// Get location to derive expected timezone.
		$location_terms = wp_get_object_terms( $post_id, 'location', array( 'fields' => 'all' ) );
		$expected_tz    = $this->deriveExpectedTimezone( $location_terms );
		$location_name  = ! empty( $location_terms ) && ! is_wp_error( $location_terms )
			? $location_terms[0]->name
			: '';

		// Get flow ID for context.
		$event_flow_id = (int) get_post_meta( $post_id, '_datamachine_post_flow_id', true );

		$issues = array();

		// Check 1: UTC timezone on a venue that should be in a US timezone.
		if ( 'UTC' === $venue_tz && ! empty( $expected_tz ) && 'UTC' !== $expected_tz ) {
			$issues[] = 'venue_tz_utc';
		}

		// Check 2: Missing venue timezone entirely.
		if ( empty( $venue_tz ) && ! empty( $expected_tz ) ) {
			$issues[] = 'venue_tz_missing';
		}

		// Check 3: Venue timezone doesn't match expected timezone for this location.
		if ( ! empty( $venue_tz ) && ! empty( $expected_tz ) && 'UTC' !== $venue_tz ) {
			if ( ! $this->timezonesEquivalent( $venue_tz, $expected_tz ) ) {
				$issues[] = 'venue_tz_mismatch';
			}
		}

		// Check 4: Suspicious show time (1:00-6:00 AM) for US venues.
		if ( ! empty( $start_time ) && ! empty( $expected_tz ) && str_starts_with( $expected_tz, 'America/' ) ) {
			$hour = (int) explode( ':', $start_time )[0];
			if ( $hour >= 1 && $hour <= 6 ) {
				$issues[] = 'suspicious_time';
			}
		}

		if ( empty( $issues ) ) {
			return null;
		}

		return array(
			'post_id'     => $post_id,
			'title'       => $post->post_title,
			'venue'       => $venue_name,
			'start_time'  => $start_time,
			'venue_tz'    => $venue_tz ?: '(none)',
			'expected_tz' => $expected_tz ?: '(unknown)',
			'location'    => $location_name,
			'flow_id'     => $event_flow_id ?: '',
			'issues'      => implode( ', ', $issues ),
		);
	}

	/**
	 * Execute fix-event-times ability.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeFixEventTimes( array $input ) {
		$from    = $input['from'] ?? '';
		$to      = $input['to'] ?? '';
		$dry_run = $input['dry_run'] ?? true;
		$flow_id = ! empty( $input['flow_id'] ) ? (int) $input['flow_id'] : 0;
		$limit   = ( isset( $input['limit'] ) && 0 !== (int) $input['limit'] ) ? (int) $input['limit'] : -1;

		if ( empty( $from ) || empty( $to ) ) {
			return new \WP_Error( 'missing_params', __( 'Both "from" and "to" timezones are required.', 'extrachill-events' ) );
		}

		try {
			$from_tz = new \DateTimeZone( $from );
			$to_tz   = new \DateTimeZone( $to );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'invalid_timezone', sprintf( __( 'Invalid timezone: %s', 'extrachill-events' ), $e->getMessage() ) );
		}

		// Find events whose venue has the "from" timezone.
		$query_args = array(
			'post_type'      => 'data_machine_events',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'venue',
					'field'    => 'term_id',
					'terms'    => $this->getVenueTermsByTimezone( $from ),
				),
			),
		);

		if ( $flow_id > 0 ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_datamachine_post_flow_id',
					'value' => (string) $flow_id,
				),
			);
		}

		$venue_ids = $this->getVenueTermsByTimezone( $from );
		if ( empty( $venue_ids ) ) {
			return array(
				'checked_count' => 0,
				'fixed_count'   => 0,
				'dry_run'       => $dry_run,
				'from'          => $from,
				'to'            => $to,
				'results'       => array(),
				'message'       => sprintf( __( 'No venues found with timezone "%s".', 'extrachill-events' ), $from ),
			);
		}

		$post_ids = get_posts( $query_args );
		$results  = array();
		$fixed    = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$block_attrs = $this->getEventBlockAttrs( $post->post_content );
			if ( null === $block_attrs ) {
				continue;
			}

			$changes = $this->convertBlockTimes( $block_attrs, $from_tz, $to_tz );
			if ( empty( $changes ) ) {
				continue;
			}

			$result_item = array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
				'venue'   => $block_attrs['venue'] ?? '',
			);

			foreach ( $changes as $field => $change ) {
				$result_item[ $field . '_old' ] = $change['old'];
				$result_item[ $field . '_new' ] = $change['new'];
			}

			if ( ! $dry_run ) {
				$this->applyTimeChanges( $post, $changes, $to );
			}

			$result_item['status'] = $dry_run ? 'preview' : 'fixed';
			$results[]             = $result_item;
			++$fixed;
		}

		return array(
			'checked_count' => count( $post_ids ),
			'fixed_count'   => $fixed,
			'dry_run'       => $dry_run,
			'from'          => $from,
			'to'            => $to,
			'results'       => $results,
		);
	}

	/**
	 * Get event-details block attributes from post content.
	 *
	 * @param string $content Post content.
	 * @return array|null Block attributes, or null if not found.
	 */
	private function getEventBlockAttrs( string $content ): ?array {
		$blocks = parse_blocks( $content );
		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				return $block['attrs'] ?? array();
			}
		}
		return null;
	}

	/**
	 * Convert time fields from one timezone to another.
	 *
	 * @param array         $attrs   Block attributes.
	 * @param \DateTimeZone $from_tz Source timezone.
	 * @param \DateTimeZone $to_tz   Target timezone.
	 * @return array<string, array{old: string, new: string}> Changed fields.
	 */
	private function convertBlockTimes( array $attrs, \DateTimeZone $from_tz, \DateTimeZone $to_tz ): array {
		$changes = array();

		$time_fields = array(
			array( 'date' => 'startDate', 'time' => 'startTime' ),
			array( 'date' => 'endDate', 'time' => 'endTime' ),
		);

		foreach ( $time_fields as $pair ) {
			$date_val = $attrs[ $pair['date'] ] ?? '';
			$time_val = $attrs[ $pair['time'] ] ?? '';

			if ( empty( $date_val ) || empty( $time_val ) ) {
				continue;
			}

			try {
				$dt = new \DateTime( $date_val . ' ' . $time_val, $from_tz );
				$dt->setTimezone( $to_tz );

				$new_date = $dt->format( 'Y-m-d' );
				$new_time = $dt->format( 'H:i' );

				if ( $new_date !== $date_val ) {
					$changes[ $pair['date'] ] = array(
						'old' => $date_val,
						'new' => $new_date,
					);
				}

				if ( $new_time !== $time_val ) {
					$changes[ $pair['time'] ] = array(
						'old' => $time_val,
						'new' => $new_time,
					);
				}
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return $changes;
	}

	/**
	 * Apply time changes to a post.
	 *
	 * @param \WP_Post $post    The event post.
	 * @param array    $changes Field changes from convertBlockTimes().
	 * @param string   $new_tz  New timezone identifier for venue term update.
	 */
	private function applyTimeChanges( \WP_Post $post, array $changes, string $new_tz ): void {
		$blocks      = parse_blocks( $post->post_content );
		$block_index = null;

		foreach ( $blocks as $i => $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				$block_index = $i;
				break;
			}
		}

		if ( null === $block_index ) {
			return;
		}

		foreach ( $changes as $field => $change ) {
			$blocks[ $block_index ]['attrs'][ $field ] = $change['new'];
		}

		$new_content = serialize_blocks( $blocks );

		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $new_content,
			)
		);

		// Update venue timezone term meta.
		$venue_terms = wp_get_object_terms( $post->ID, 'venue', array( 'fields' => 'ids' ) );
		if ( ! empty( $venue_terms ) && ! is_wp_error( $venue_terms ) ) {
			update_term_meta( $venue_terms[0], '_venue_timezone', $new_tz );
		}
	}

	/**
	 * Get venue term IDs that have a specific timezone.
	 *
	 * @param string $timezone Timezone identifier.
	 * @return int[] Venue term IDs.
	 */
	private function getVenueTermsByTimezone( string $timezone ): array {
		global $wpdb;

		$term_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tm.term_id FROM {$wpdb->termmeta} tm
				JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
				WHERE tt.taxonomy = 'venue'
				AND tm.meta_key = '_venue_timezone'
				AND tm.meta_value = %s",
				$timezone
			)
		);

		return array_map( 'intval', $term_ids );
	}

	/**
	 * Derive expected timezone from location term hierarchy.
	 *
	 * Walks up the location hierarchy to find a state, then maps to timezone.
	 *
	 * @param array|\WP_Error $location_terms Location terms for the event.
	 * @return string Expected timezone identifier, or empty string.
	 */
	private function deriveExpectedTimezone( $location_terms ): string {
		if ( empty( $location_terms ) || is_wp_error( $location_terms ) ) {
			return '';
		}

		// Walk up the hierarchy from the most specific term.
		foreach ( $location_terms as $term ) {
			$current = $term;
			while ( $current ) {
				$slug = $current->slug;
				if ( isset( self::STATE_TIMEZONE_MAP[ $slug ] ) ) {
					return self::STATE_TIMEZONE_MAP[ $slug ];
				}
				if ( $current->parent > 0 ) {
					$current = get_term( $current->parent, 'location' );
					if ( is_wp_error( $current ) ) {
						break;
					}
				} else {
					break;
				}
			}
		}

		return '';
	}

	/**
	 * Check if two timezones are effectively the same.
	 *
	 * Compares UTC offsets at the current time to handle aliases
	 * (e.g., America/New_York and US/Eastern).
	 *
	 * @param string $tz1 First timezone identifier.
	 * @param string $tz2 Second timezone identifier.
	 * @return bool True if equivalent.
	 */
	private function timezonesEquivalent( string $tz1, string $tz2 ): bool {
		if ( $tz1 === $tz2 ) {
			return true;
		}

		try {
			$now     = new \DateTime( 'now' );
			$offset1 = ( new \DateTimeZone( $tz1 ) )->getOffset( $now );
			$offset2 = ( new \DateTimeZone( $tz2 ) )->getOffset( $now );
			return $offset1 === $offset2;
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
