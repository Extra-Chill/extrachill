<?php
/**
 * Legacy Local Scene migration tooling.
 *
 * @package ExtraChill\Users
 */

/**
 * Resolve a legacy city only when it identifies one exact canonical location.
 *
 * @param string $value Legacy local_city value.
 * @return array|WP_Error Resolution with status and optional location.
 */
function extrachill_users_resolve_legacy_local_city( string $value ) {
	$value = trim( sanitize_text_field( $value ) );
	$slug  = sanitize_title( $value );

	if ( '' === $slug ) {
		return array( 'status' => 'unmatched' );
	}

	$search_value = $value;
	$state_value  = '';
	if ( preg_match( '/^(.+?),\s*([A-Za-z][A-Za-z\s]+)$/', $value, $parts ) || preg_match( '/^(.+?)\s+([A-Za-z]{2})$/', $value, $parts ) ) {
		$search_value = trim( $parts[1] );
		$state_value  = trim( $parts[2] );
		$state_map    = extrachill_users_local_scene_state_abbreviations();
		$state_value  = $state_map[ strtoupper( $state_value ) ] ?? $state_value;
	}

	$result = extrachill_users_ability_event_locations(
		array(
			'mode'   => 'search',
			'search' => $search_value,
			'limit'  => 20,
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$matches = array_values(
		array_filter(
			$result['locations'] ?? array(),
			static function ( $candidate ) use ( $value, $search_value, $state_value ) {
				$name  = isset( $candidate['name'] ) ? (string) $candidate['name'] : '';
				$label = isset( $candidate['hierarchy']['label'] ) ? (string) $candidate['hierarchy']['label'] : '';
				$state = isset( $candidate['hierarchy']['state'] ) ? (string) $candidate['hierarchy']['state'] : '';
				if ( '' !== $state_value ) {
					return 0 === strcasecmp( $search_value, $name ) && 0 === strcasecmp( $state_value, $state );
				}
				return 0 === strcasecmp( $value, $name ) || 0 === strcasecmp( $value, $label );
			}
		)
	);

	if ( 1 === count( $matches ) ) {
		return array(
			'status'   => 'matched',
			'location' => $matches[0],
		);
	}
	if ( count( $matches ) > 1 ) {
		return array( 'status' => 'ambiguous' );
	}

	// Legacy values may already contain a canonical slug rather than a label.
	$location = extrachill_users_resolve_local_scene( $slug );
	if ( ! is_wp_error( $location ) ) {
		return array(
			'status'   => 'matched',
			'location' => $location,
		);
	}
	if ( 'location_not_found' !== $location->get_error_code() ) {
		return $location;
	}

	return array( 'status' => 'unmatched' );
}

/**
 * Postal abbreviations accepted only for deterministic legacy matching.
 *
 * @return array<string,string>
 */
function extrachill_users_local_scene_state_abbreviations(): array {
	return array(
		'AL' => 'Alabama',
		'AK' => 'Alaska',
		'AZ' => 'Arizona',
		'AR' => 'Arkansas',
		'CA' => 'California',
		'CO' => 'Colorado',
		'CT' => 'Connecticut',
		'DE' => 'Delaware',
		'DC' => 'District of Columbia',
		'FL' => 'Florida',
		'GA' => 'Georgia',
		'HI' => 'Hawaii',
		'ID' => 'Idaho',
		'IL' => 'Illinois',
		'IN' => 'Indiana',
		'IA' => 'Iowa',
		'KS' => 'Kansas',
		'KY' => 'Kentucky',
		'LA' => 'Louisiana',
		'ME' => 'Maine',
		'MD' => 'Maryland',
		'MA' => 'Massachusetts',
		'MI' => 'Michigan',
		'MN' => 'Minnesota',
		'MS' => 'Mississippi',
		'MO' => 'Missouri',
		'MT' => 'Montana',
		'NE' => 'Nebraska',
		'NV' => 'Nevada',
		'NH' => 'New Hampshire',
		'NJ' => 'New Jersey',
		'NM' => 'New Mexico',
		'NY' => 'New York',
		'NC' => 'North Carolina',
		'ND' => 'North Dakota',
		'OH' => 'Ohio',
		'OK' => 'Oklahoma',
		'OR' => 'Oregon',
		'PA' => 'Pennsylvania',
		'RI' => 'Rhode Island',
		'SC' => 'South Carolina',
		'SD' => 'South Dakota',
		'TN' => 'Tennessee',
		'TX' => 'Texas',
		'UT' => 'Utah',
		'VT' => 'Vermont',
		'VA' => 'Virginia',
		'WA' => 'Washington',
		'WV' => 'West Virginia',
		'WI' => 'Wisconsin',
		'WY' => 'Wyoming',
		'AB' => 'Alberta',
		'BC' => 'British Columbia',
		'MB' => 'Manitoba',
		'NB' => 'New Brunswick',
		'NL' => 'Newfoundland and Labrador',
		'NS' => 'Nova Scotia',
		'NT' => 'Northwest Territories',
		'NU' => 'Nunavut',
		'ON' => 'Ontario',
		'PE' => 'Prince Edward Island',
		'QC' => 'Quebec',
		'SK' => 'Saskatchewan',
		'YT' => 'Yukon',
	);
}

/**
 * Scan and optionally migrate legacy Local Scene values.
 *
 * @param bool       $apply    Whether to persist deterministic matches.
 * @param int[]|null $user_ids Optional explicit users, primarily for bounded callers.
 * @return array|WP_Error Rows and status totals, or a dependency/write error.
 */
function extrachill_users_migrate_legacy_local_scenes( bool $apply = false, ?array $user_ids = null ) {
	if ( null === $user_ids ) {
		$user_ids = get_users(
			array(
				'fields'       => 'ids',
				'meta_key'     => 'local_city',
				'meta_value'   => '',
				'meta_compare' => '!=',
			)
		);
	}

	$rows   = array();
	$totals = array_fill_keys( array( 'matched', 'ambiguous', 'unmatched', 'already-set' ), 0 );
	foreach ( array_map( 'intval', $user_ids ) as $user_id ) {
		$legacy = trim( (string) get_user_meta( $user_id, 'local_city', true ) );
		if ( '' === $legacy ) {
			continue;
		}

		if ( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY ) || '' !== trim( (string) get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true ) ) ) {
			$status   = 'already-set';
			$location = '';
		} else {
			$resolution = extrachill_users_resolve_legacy_local_city( $legacy );
			if ( is_wp_error( $resolution ) ) {
				return $resolution;
			}
			$status   = $resolution['status'];
			$location = 'matched' === $status ? $resolution['location']['slug'] : '';

			if ( $apply && 'matched' === $status ) {
				$written = extrachill_users_set_local_scene( $user_id, $location );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}
		}

		++$totals[ $status ];
		$rows[] = array(
			'user_id'     => $user_id,
			'local_city'  => $legacy,
			'status'      => $status,
			'local_scene' => $location,
		);
	}

	return array(
		'rows'    => $rows,
		'totals'  => $totals,
		'applied' => $apply,
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Migrate deterministic legacy local_city values to canonical Local Scenes.
	 */
	$extrachill_users_migrate_local_scenes_command = static function ( $args, $assoc_args ) {
		$apply  = isset( $assoc_args['apply'] );
		$result = extrachill_users_migrate_legacy_local_scenes( $apply );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI\Utils\format_items( 'table', $result['rows'], array( 'user_id', 'local_city', 'status', 'local_scene' ) );
		$totals = $result['totals'];
		WP_CLI::success(
			sprintf(
				'%s: matched=%d ambiguous=%d unmatched=%d already-set=%d',
				$apply ? 'Apply complete' : 'Dry run',
				$totals['matched'],
				$totals['ambiguous'],
				$totals['unmatched'],
				$totals['already-set']
			)
		);
	};

	WP_CLI::add_command(
		'extrachill-users migrate-local-scenes',
		$extrachill_users_migrate_local_scenes_command,
		array(
			'shortdesc' => 'Dry-run or apply deterministic legacy Local Scene migration.',
			'synopsis'  => array(
				array(
					'type'        => 'flag',
					'name'        => 'apply',
					'description' => 'Persist exact unambiguous matches. Omit for a dry run.',
					'optional'    => true,
				),
			),
		)
	);
}
