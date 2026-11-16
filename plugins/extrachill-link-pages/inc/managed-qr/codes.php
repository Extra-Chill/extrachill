<?php
/**
 * Team-owned managed QR codes. Storage is confined to the link-pages site.
 *
 * @package ExtraChillLinkPages
 */

use ExtraChill\Users\Teams\TeamMembershipRepository;

defined( 'ABSPATH' ) || exit;

/** Register private storage only on its owning site. */
function ec_register_managed_qr_codes(): void {
	if ( ! function_exists( 'ec_get_blog_id' ) || (int) ec_get_blog_id( 'link_pages' ) !== get_current_blog_id() ) {
		return;
	}
	register_post_type(
		'ec_qr_code',
		array(
			'public'   => false,
			'show_ui'  => false,
			'supports' => array( 'title', 'author' ),
		)
	);
}

/**
 * Check the authoritative, current team relationship (never post_author).
 *
 * @param string $type Team type.
 * @param int    $team_id Team identity.
 */
function ec_can_manage_qr_team( string $type, int $team_id ): bool {
	if ( ! get_current_user_id() || $team_id < 1 || ! class_exists( TeamMembershipRepository::class ) || ! in_array( $type, TeamMembershipRepository::TYPES, true ) ) {
		return false;
	}
	if ( is_super_admin() ) {
		return true;
	}
	$member = ( new TeamMembershipRepository() )->get( $type, $team_id, get_current_user_id() );
	return is_array( $member ) && TeamMembershipRepository::STATUS_ACTIVE === $member['status'];
}

/**
 * Strict validation before sanitization, including protocol-relative URLs.
 *
 * @param string $url Destination.
 */
function ec_managed_qr_valid_url( string $url ): bool {
	return false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true );
}

/**
 * Query one team's published codes, on storage only.
 *
 * @param string $type Team type.
 * @param int    $team_id Team identity.
 * @return WP_Post[]
 */
function ec_managed_qr_team_posts( string $type, int $team_id ): array {
	return get_posts(
		array(
			'post_type'   => 'ec_qr_code',
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small, quota-bounded CPT collections.
				array(
		'key'   => 'team_type',
		'value' => $type,
			),
				array(
			'key'   => 'team_id',
			'value' => $team_id,
			),
			),
		)
	);
}

/**
 * Return the immutable URL, suitable for extrachill/generate-qr-code.
 *
 * @param string $code Immutable code.
 */
function ec_managed_qr_short_url( string $code ): string {
	return trailingslashit( ec_get_site_url( 'link_pages' ) ) . 'q/' . rawurlencode( $code );
}

/**
 * Serialize a previously authorized code. Scan counts share analytics' 90-day retention.
 *
 * @param WP_Post $post QR record.
 * @return array<string, mixed>
 */
function ec_managed_qr_present( WP_Post $post ): array {
	global $wpdb;
	$scans = 0;
	if ( function_exists( 'extrachill_analytics_link_page_storage_clicks_table' ) ) {
		$table = extrachill_analytics_link_page_storage_clicks_table();
		$scans = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(click_count), 0) FROM %i WHERE link_page_id = %d', $table, $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Analytics aggregate, deliberately fresh.
	}
	return array(
		'id'          => $post->ID,
		'label'       => $post->post_title,
		'code'        => $post->post_name,
		'short_url'   => ec_managed_qr_short_url( $post->post_name ),
		'destination' => (string) get_post_meta( $post->ID, 'destination', true ),
		'team_type'   => (string) get_post_meta( $post->ID, 'team_type', true ),
		'team_id'     => (int) get_post_meta( $post->ID, 'team_id', true ),
		'scans'       => $scans,
	);
}

/**
 * Execute through the storage boundary, restoring the caller's blog on every path.
 *
 * @param string               $operation Operation name.
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|WP_Error
 */
function ec_managed_qr_execute( string $operation, array $input ) {
	if ( ! get_current_user_id() ) {
		return new WP_Error( 'qr_forbidden', 'Sign in to manage team QR codes.', array( 'status' => 403 ) );
	}
	$blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'link_pages' ) : 0;
	if ( ! $blog_id ) {
		return new WP_Error( 'qr_storage_unavailable', 'QR storage is unavailable.' );
	}
	switch_to_blog( $blog_id );
	try {
		ec_register_managed_qr_codes();
		return ec_managed_qr_execute_local( $operation, $input );
	} finally {
		restore_current_blog();
	}
}

/**
 * Storage-local implementation. Authorization is repeated on every operation.
 *
 * @param string               $operation Operation name.
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|WP_Error
 */
function ec_managed_qr_execute_local( string $operation, array $input ) {
	if ( 'list' === $operation ) {
		$items = array();
		if ( ! class_exists( TeamMembershipRepository::class ) ) {
			return new WP_Error( 'qr_teams_unavailable', 'Team membership is unavailable.' );
		}
		$repository = new TeamMembershipRepository();
		foreach ( TeamMembershipRepository::TYPES as $type ) {
			$ids = $repository->term_ids(
				$type,
				array(
					'user_id' => get_current_user_id(),
					'status'  => TeamMembershipRepository::STATUS_ACTIVE,
				)
			);
			foreach ( $ids as $team_id ) {
				if ( ec_can_manage_qr_team( $type, (int) $team_id ) ) {
					foreach ( ec_managed_qr_team_posts( $type, (int) $team_id ) as $post ) {
						$items[] = ec_managed_qr_present( $post );
					}
				}
			}
		}
		return array( 'items' => $items );
	}
	$post = null;
	$type = (string) ( $input['team_type'] ?? '' );
	$team = (int) ( $input['team_id'] ?? 0 );
	if ( 'create' !== $operation ) {
		$post = get_post( (int) ( $input['id'] ?? 0 ) );
		if ( ! $post instanceof WP_Post || 'ec_qr_code' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'qr_not_found', 'QR code unavailable.', array( 'status' => 404 ) );
		}
		$type = (string) get_post_meta( $post->ID, 'team_type', true );
		$team = (int) get_post_meta( $post->ID, 'team_id', true );
	}
	if ( ! ec_can_manage_qr_team( $type, $team ) ) {
		return new WP_Error( 'qr_forbidden', 'An active membership on the owning team is required.', array( 'status' => 403 ) );
	}
	if ( 'create' === $operation || isset( $input['destination'] ) ) {
		if ( ! ec_managed_qr_valid_url( (string) ( $input['destination'] ?? '' ) ) ) {
			return new WP_Error( 'qr_invalid_url', 'Use a complete HTTP or HTTPS URL.', array( 'status' => 400 ) );
		}
	}
	if ( 'create' === $operation ) {
		// Reuse the runtime's advisory lock so parallel creates cannot exceed quota.
		return ec_with_link_page_lock_scope(
			$team,
			static function () use ( $input, $type, $team ) {
				if ( count( ec_managed_qr_team_posts( $type, $team ) ) >= 100 ) {
					return new WP_Error( 'qr_quota', 'Each team can have up to 100 QR codes.', array( 'status' => 409 ) );
				}
				$code = bin2hex( random_bytes( 6 ) );
				$id   = wp_insert_post(
					array(
						'post_type'   => 'ec_qr_code',
						'post_status' => 'publish',
						'post_author' => get_current_user_id(),
						'post_title'  => sanitize_text_field( (string) ( $input['label'] ?? '' ) ),
						'post_name'   => $code,
						'meta_input'  => array(
							'team_type'   => $type,
							'team_id'     => $team,
							'destination' => esc_url_raw( (string) $input['destination'] ),
						),
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$created = get_post( $id );
				return $created instanceof WP_Post ? ec_managed_qr_present( $created ) : new WP_Error( 'qr_create_failed', 'Could not read created QR code.' );
			}
		);
	}
	if ( 'delete' === $operation && $post ) {
		return wp_trash_post( $post->ID ) ? array( 'deleted' => true ) : new WP_Error( 'qr_delete_failed', 'Could not trash QR code.' );
	}
	if ( 'update' === $operation && $post ) {
		if ( isset( $input['label'] ) ) {
			$result = wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => sanitize_text_field( (string) $input['label'] ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( isset( $input['destination'] ) ) {
			$destination = esc_url_raw( (string) $input['destination'] );
			if ( false === update_post_meta( $post->ID, 'destination', $destination ) && get_post_meta( $post->ID, 'destination', true ) !== $destination ) {
				return new WP_Error( 'qr_update_failed', 'Could not update destination.' );
			}
		}
	}
	$current = $post instanceof WP_Post ? get_post( $post->ID ) : null;
	return $current instanceof WP_Post ? ec_managed_qr_present( $current ) : new WP_Error( 'qr_invalid_operation', 'Invalid QR operation.' );
}

/**
 * Resolve /q only through the existing public runtime; no management UI here.
 *
 * @param mixed  $route Existing special route.
 * @param string $path Requested path.
 * @return mixed
 */
function ec_managed_qr_public_route( $route, string $path ) {
	if ( ! preg_match( '#^q/([^/]+)/?$#', $path, $matches ) ) {
		return $route;
	}
	// Never let a CDN/browser retain an old destination or skip scan recording.
	nocache_headers();
	$result = ec_with_link_page_storage_blog(
		static function () use ( $matches ) {
			$posts = get_posts(
				array(
					'post_type'   => 'ec_qr_code',
					'post_status' => 'publish',
					'name'        => $matches[1],
					'numberposts' => 1,
				)
			);
			if ( ! $posts ) {
				return '';
			}
			$post = $posts[0];
			$url  = (string) get_post_meta( $post->ID, 'destination', true );
			if ( ! ec_managed_qr_valid_url( $url ) ) {
				return '';
			}
			if ( function_exists( 'extrachill_analytics_handle_link_click_db_write' ) ) {
				// Use the QR post ID and immutable short URL: renames/edits retain counts.
				extrachill_analytics_handle_link_click_db_write( $post->ID, ec_managed_qr_short_url( $post->post_name ), 'QR scan' );
			}
			return $url;
		}
	);
	return array(
		'url'    => is_string( $result ) && '' !== $result ? $result : ec_get_site_url( 'link_pages' ),
		'status' => 302,
	);
}

/** Register deliberate core abilities transport, with team checks inside callbacks. */
function ec_register_managed_qr_abilities(): void {
	foreach ( array( 'create', 'list', 'update', 'delete', 'get-stats' ) as $operation ) {
		wp_register_ability(
			'extrachill/' . $operation . '-managed-qr-code',
			array(
				'label'               => ucfirst( $operation ) . ' managed QR code',
				'description'         => 'Manage team-owned QR codes. Scan counts represent fan reach over the analytics retention window (90 days). Pass the returned short_url to extrachill/generate-qr-code for a PNG.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'team_type'   => array(
							'type' => 'string',
							'enum' => array( 'artist', 'venue', 'promoter' ),
						),
						'team_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'label'       => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'destination' => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'permission_callback' => 'is_user_logged_in',
				'execute_callback'    => static function ( $input ) use ( $operation ) {
					return ec_managed_qr_execute( $operation, $input );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}
}

add_action( 'init', 'ec_register_managed_qr_codes', 6 );
add_action( 'wp_abilities_api_init', 'ec_register_managed_qr_abilities' );
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'ec_link_page_public_special_route', 'ec_managed_qr_public_route', 10, 2 );
}
