<?php
/**
 * Concert import abilities.
 *
 * Surfaces the import framework to REST + chat + CLI. Three abilities:
 *  - extrachill/list-concert-import-sources — enumerate registered sources
 *    so the UI can render per-source cards.
 *  - extrachill/preview-concert-import       — one-shot username probe used
 *    by the confirmation dialog.
 *  - extrachill/start-concert-import         — kicks off the background run.
 *  - extrachill/get-concert-import-status    — polled by the React UI.
 *
 * @package ExtraChill\Users
 * @since 0.13.0
 */

defined( 'ABSPATH' ) || exit;

use ExtraChill\Users\Concert_Import\ImportOrchestrator;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_concert_import_abilities' );

/**
 * Register concert import abilities.
 */
function extrachill_users_register_concert_import_abilities() {

	wp_register_ability(
		'extrachill/list-concert-import-sources',
		array(
			'label'               => __( 'List Concert Import Sources', 'extrachill-users' ),
			'description'         => __( 'List all registered concert-history import sources and the current user\'s saved username for each.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'include_unconfigured' => array(
						'type'        => 'boolean',
						'description' => 'When true, include sources whose API key has not been provisioned. Admins only. Defaults to false so end users never see "API key not configured" plumbing.',
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'sources' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_list_concert_import_sources',
			'permission_callback' => 'is_user_logged_in',
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
		'extrachill/preview-concert-import',
		array(
			'label'               => __( 'Preview Concert Import', 'extrachill-users' ),
			'description'         => __( 'Validate the username against the external source and return the total event count so the user can confirm before kicking off the full import.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'source'   => array(
						'type'        => 'string',
						'description' => 'Source slug (e.g. "setlist-fm").',
					),
					'username' => array(
						'type'        => 'string',
						'description' => 'External-platform username for this user.',
					),
				),
				'required'   => array( 'source', 'username' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'source'   => array( 'type' => 'string' ),
					'username' => array( 'type' => 'string' ),
					'total'    => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_preview_concert_import',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/start-concert-import',
		array(
			'label'               => __( 'Start Concert Import', 'extrachill-users' ),
			'description'         => __( 'Kick off a background import run for the current user from a registered source.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'source'   => array( 'type' => 'string' ),
					'username' => array( 'type' => 'string' ),
				),
				'required'   => array( 'source', 'username' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'run_id' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_start_concert_import',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/get-concert-import-status',
		array(
			'label'               => __( 'Get Concert Import Status', 'extrachill-users' ),
			'description'         => __( 'Return the current user\'s recent import runs with progress + summary counters.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'runs' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_concert_import_status',
			'permission_callback' => 'is_user_logged_in',
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
}

// ─── Execute callbacks ───────────────────────────────────────────────────────

/**
 * Shape a single source for the UI.
 *
 * @param \ExtraChill\Users\Concert_Import\ImportSource $source
 * @param int                                           $user_id
 * @return array<string, mixed>
 */
function extrachill_users_shape_concert_import_source( $source, int $user_id ): array {
	$slug     = $source->slug();
	$username = (string) get_user_meta( $user_id, ImportOrchestrator::username_meta_key( $slug ), true );
	$rate     = $source->rate_limit();

	return array(
		'slug'         => $slug,
		'label'        => $source->label(),
		'configured'   => $source->is_configured(),
		'username'     => $username,
		'rate_limit'   => array(
			'requests_per_second' => isset( $rate['requests_per_second'] ) ? (float) $rate['requests_per_second'] : 0.0,
			'requests_per_day'    => isset( $rate['requests_per_day'] ) ? (int) $rate['requests_per_day'] : 0,
		),
	);
}

/**
 * Shape a run row for the UI.
 *
 * @param array<string, mixed> $run
 * @return array<string, mixed>
 */
function extrachill_users_shape_concert_import_run( array $run ): array {
	return array(
		'id'                     => (int) $run['id'],
		'source_slug'            => (string) $run['source_slug'],
		'status'                 => (string) $run['status'],
		'username'               => (string) $run['external_username'],
		'next_page'              => isset( $run['next_page'] ) ? (int) $run['next_page'] : null,
		'total_pages'            => isset( $run['total_pages'] ) ? (int) $run['total_pages'] : null,
		'next_attempt_at'        => $run['next_attempt_at'] ?: null,
		'requests_today'         => (int) ( $run['requests_today'] ?? 0 ),
		'requests_today_date'    => $run['requests_today_date'] ?: null,
		'total_events_seen'      => (int) ( $run['total_events_seen'] ?? 0 ),
		'total_events_matched'   => (int) ( $run['total_events_matched'] ?? 0 ),
		'total_events_created'   => (int) ( $run['total_events_created'] ?? 0 ),
		'total_events_unmatched' => (int) ( $run['total_events_unmatched'] ?? 0 ),
		'total_events_skipped'   => (int) ( $run['total_events_skipped'] ?? 0 ),
		'started_at'             => $run['started_at'] ?: null,
		'updated_at'             => $run['updated_at'] ?: null,
		'completed_at'           => $run['completed_at'] ?: null,
		'error_message'          => $run['error_message'] ?: null,
	);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function extrachill_users_ability_list_concert_import_sources( array $input ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}

	// Admins can ask to see unconfigured sources for setup / debugging. End
	// users never see "not yet available" plumbing — they only see sources
	// they can actually use.
	$include_unconfigured = ! empty( $input['include_unconfigured'] ) && current_user_can( 'manage_options' );

	$sources = ImportOrchestrator::sources();
	$out     = array();
	foreach ( $sources as $source ) {
		if ( ! $include_unconfigured && ! $source->is_configured() ) {
			continue;
		}
		$out[] = extrachill_users_shape_concert_import_source( $source, $user_id );
	}

	return array( 'sources' => $out );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function extrachill_users_ability_preview_concert_import( array $input ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}

	$slug     = sanitize_text_field( (string) ( $input['source'] ?? '' ) );
	$username = sanitize_text_field( (string) ( $input['username'] ?? '' ) );

	$source = ImportOrchestrator::get_source( $slug );
	if ( ! $source ) {
		return new WP_Error( 'unknown_source', 'Unknown import source.', array( 'status' => 400 ) );
	}
	if ( ! $source->is_configured() ) {
		return new WP_Error(
			'source_not_configured',
			sprintf( 'The %s import is not configured on this platform yet.', $source->label() ),
			array( 'status' => 503 )
		);
	}
	if ( '' === trim( $username ) ) {
		return new WP_Error( 'missing_username', 'Username is required.', array( 'status' => 400 ) );
	}

	$result = $source->preview( $username );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'source'   => $slug,
		'username' => isset( $result['username'] ) ? (string) $result['username'] : $username,
		'total'    => isset( $result['total'] ) ? (int) $result['total'] : 0,
	);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function extrachill_users_ability_start_concert_import( array $input ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}

	$slug     = sanitize_text_field( (string) ( $input['source'] ?? '' ) );
	$username = sanitize_text_field( (string) ( $input['username'] ?? '' ) );

	$result = ImportOrchestrator::start( $user_id, $slug, $username );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array( 'run_id' => (int) $result['run_id'] );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function extrachill_users_ability_get_concert_import_status( array $input ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}

	$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
	$limit = max( 1, min( 100, $limit ) );

	$rows = ImportOrchestrator::get_user_runs( $user_id, $limit );
	$out  = array();
	foreach ( $rows as $row ) {
		$out[] = extrachill_users_shape_concert_import_run( $row );
	}

	return array( 'runs' => $out );
}
