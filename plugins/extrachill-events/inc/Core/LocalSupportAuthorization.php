<?php
/**
 * Local support identity and authorization policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates canonical event bindings and exact organizer/artist authority.
 *
 * Mutation lock order is Local Support request/interest rows, exact Events
 * taxonomy relationships, future promoter organization/member rows, the exact
 * direct venue membership, future promoter grant rows, artist profile binding,
 * canonical term binding, artist roster, then user roster. Promoter consumption
 * must use organize_local_support, never ACTION_ACCESS_VENUE.
 */
class LocalSupportAuthorization {
	public const ACTION_ORGANIZE_LOCAL_SUPPORT = 'organize_local_support';
	private const MAX_ARTIST_AUTHORITY_IDS     = 100;

	/** @var VenueAuthorization */
	private $venues;

	/** @var string[] Artist relationship advisory locks held through commit/rollback. */
	private $artist_locks = array();

	/** @var string[] Events mapping advisory locks held through commit/rollback. */
	private $mapping_locks = array();

	/** @var \SplObjectStorage Active service-owned transaction scopes. */
	private $transaction_scopes;

	/** @var object|null Opaque owner token held only by LocalSupportService. */
	private $transaction_owner;

	public function __construct( ?VenueAuthorization $venues = null ) {
		$this->venues             = $venues ? $venues : new VenueAuthorization();
		$this->transaction_scopes = new \SplObjectStorage();
	}

	/** Claim this authorization instance for one service-owned transaction token. */
	public function claim_transaction_owner( object $owner ) {
		if ( null === $this->transaction_owner ) {
			$this->transaction_owner = $owner;
			return true;
		}
		return $this->transaction_owner === $owner
			? true
			: new \WP_Error( 'local_support_transaction_owner_conflict', __( 'Local support authorization already belongs to another service.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/** Register a lock-current scope only for the owning service's opaque token. */
	public function open_transaction_scope( object $owner ) {
		if ( null === $this->transaction_owner || $this->transaction_owner !== $owner ) {
			return new \WP_Error( 'local_support_transaction_scope_required', __( 'Lock-current local support authorization requires its service transaction.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$scope = new \stdClass();
		$this->transaction_scopes->attach( $scope );
		return $scope;
	}

	/** Resolve a valid canonical event and its one exact venue. */
	public function event_context( int $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'invalid_local_support_event', __( 'A valid canonical event is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$venues = wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $venues ) || 1 !== count( (array) $venues ) ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event must have one canonical venue.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$venue_id = (int) reset( $venues );
		$venue    = get_term( $venue_id, 'venue' );
		if ( ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event venue binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return array(
			'event_id'      => $event_id,
			'venue_term_id' => $venue_id,
		);
	}

	/** Authorize and validate the organizer identity against the exact event. */
	public function authorize_organizer( array $request, int $user_id ) {
		$context = $this->event_context( (int) $request['event_id'] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( (int) $request['venue_term_id'] !== $context['venue_term_id'] ) {
			return $this->denied();
		}
		if ( 'venue' === $request['organizer_type'] ) {
			if ( (int) $request['organizer_id'] !== $context['venue_term_id'] ) {
				return $this->denied();
			}
			return $this->venues->authorize( $user_id, $context['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		}
		if ( 'artist' !== $request['organizer_type'] ) {
			return $this->denied();
		}
		$artist_id = (int) $request['organizer_id'];
		$attached  = $this->artist_attached_to_event( $context['event_id'], $artist_id );
		if ( true !== $attached ) {
			return is_wp_error( $attached ) ? $attached : $this->denied();
		}
		return $this->authorize_artist( $artist_id, $user_id );
	}

	/** Authorize an organizer from authority locked in the service-owned transaction. */
	public function authorize_organizer_locked( array $request, int $user_id, object $scope ) {
		$valid_scope = $this->validate_scope( $scope );
		if ( true !== $valid_scope ) {
			return $valid_scope;
		}
		$context = $this->locked_event_context( (int) $request['event_id'] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( (int) $request['venue_term_id'] !== $context['venue_term_id'] ) {
			return $this->denied();
		}
		if ( 'venue' === $request['organizer_type'] ) {
			if ( (int) $request['organizer_id'] !== $context['venue_term_id'] ) {
				return $this->denied();
			}
			return $this->authorize_locked_venue( $user_id, $context['venue_term_id'] );
		}
		if ( 'artist' !== $request['organizer_type'] ) {
			return $this->denied();
		}
		$artist_id = (int) $request['organizer_id'];
		$attached  = $this->locked_artist_attachment( $context['event_id'], $artist_id );
		if ( true !== $attached ) {
			return is_wp_error( $attached ) ? $attached : $this->denied();
		}
		return $this->authorize_artist_locked( $artist_id, $user_id, $scope );
	}

	/** Lock and read one exact event artist attachment in a service-owned scope. */
	public function artist_attached_to_event_locked( int $event_id, int $artist_term_id, object $scope ) {
		$valid_scope = $this->validate_scope( $scope );
		return true === $valid_scope ? $this->locked_artist_attachment( $event_id, $artist_term_id ) : $valid_scope;
	}

	/** Authorize one canonical artist manager through its bound profile. */
	public function authorize_artist( int $artist_term_id, int $user_id ) {
		$profile_id = $this->artist_profile_id( $artist_term_id );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		if ( ! function_exists( 'ec_get_artists_for_user' ) || ! in_array( $profile_id, array_map( 'intval', ec_get_artists_for_user( $user_id ) ), true ) ) {
			return $this->denied();
		}
		return true;
	}

	/** Lock and authorize the reciprocal persisted artist relationship. */
	public function authorize_artist_locked( int $artist_term_id, int $user_id, object $scope ) {
		global $wpdb;
		$valid_scope = $this->validate_scope( $scope );
		if ( true !== $valid_scope ) {
			return $valid_scope;
		}
		$profile_id = $this->artist_profile_id( $artist_term_id );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return $this->denied();
		}
		if ( preg_match( '/sqlite|pgsql|postgres/', strtolower( get_class( $wpdb ) ) ) ) {
			return new \WP_Error( 'local_support_artist_advisory_locks_unsupported', __( 'Artist mutation authorization requires MySQL advisory locks.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $user_id, $profile_id );
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Matches the canonical artist membership writer lock.
		if ( '1' !== (string) $acquired ) {
			return new \WP_Error( 'local_support_artist_authority_lock_failed', __( 'Artist authority could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$this->artist_locks[] = $lock_name;

		$artist_blog_id = (int) ec_get_blog_id( 'artist' );
		$main_blog_id   = (int) ec_get_blog_id( 'main' );
		$user_table     = $wpdb->usermeta;
		// Keep the writer's profile-then-term order. Its old-term inversion remains
		// an Artist Platform protocol issue: Extra-Chill/extrachill-artist-platform#196.
		switch_to_blog( $artist_blog_id );
		try {
			$artist_table          = $wpdb->postmeta;
			$profile_binding_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$artist_table} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE", $profile_id, '_artist_term_id' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical writer order: exact profile binding first.
			$profile_binding_error = (string) $wpdb->last_error;
		} finally {
			restore_current_blog();
		}
		if ( '' !== $profile_binding_error ) {
			return new \WP_Error( 'local_support_artist_authority_read_failed', __( 'Artist authority could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		switch_to_blog( $main_blog_id );
		try {
			$term_table         = $wpdb->termmeta;
			$term_binding_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$term_table} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE", $artist_term_id, '_artist_profile_id' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical writer order: reciprocal term binding second.
			$term_binding_error = (string) $wpdb->last_error;
		} finally {
			restore_current_blog();
		}
		if ( '' !== $term_binding_error ) {
			return new \WP_Error( 'local_support_artist_authority_read_failed', __( 'Artist authority could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( 1 !== count( (array) $profile_binding_rows ) || 1 !== count( (array) $term_binding_rows ) ) {
			return new \WP_Error( 'local_support_artist_binding_corrupt', __( 'The canonical artist binding is missing or duplicated.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$locked_term_id    = $this->authority_scalar_id( $profile_binding_rows[0]['meta_value'] );
		$locked_profile_id = $this->authority_scalar_id( $term_binding_rows[0]['meta_value'] );
		if ( null === $locked_term_id || null === $locked_profile_id ) {
			return new \WP_Error( 'local_support_artist_binding_corrupt', __( 'The canonical artist binding contains an invalid identifier.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( $artist_term_id !== $locked_term_id || $profile_id !== $locked_profile_id ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'The artist profile binding changed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		switch_to_blog( $artist_blog_id );
		try {
			$artist_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$artist_table} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE", $profile_id, '_artist_member_ids' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact artist-side reciprocal authority with one-row overflow probe.
			$artist_error = (string) $wpdb->last_error;
		} finally {
			restore_current_blog();
		}
		if ( '' !== $artist_error ) {
			return new \WP_Error( 'local_support_artist_authority_read_failed', __( 'Artist authority could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$user_rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, meta_value FROM {$user_table} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 2 FOR UPDATE", $user_id, '_artist_profile_ids' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact user-side reciprocal authority with one-row overflow probe.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_artist_authority_read_failed', __( 'Artist authority could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( 1 !== count( (array) $artist_rows ) || 1 !== count( (array) $user_rows ) ) {
			return count( (array) $artist_rows ) > 1 || count( (array) $user_rows ) > 1
				? new \WP_Error( 'local_support_artist_authority_rows_corrupt', __( 'Artist authority contains duplicate relationship records.', 'extrachill-events' ), array( 'status' => 409 ) )
				: $this->denied();
		}
		$member_ids  = $this->authority_ids( $artist_rows[0]['meta_value'] );
		$profile_ids = $this->authority_ids( $user_rows[0]['meta_value'] );
		if ( is_wp_error( $member_ids ) || is_wp_error( $profile_ids ) ) {
			return is_wp_error( $member_ids ) ? $member_ids : $profile_ids;
		}
		return in_array( $user_id, $member_ids, true ) && in_array( $profile_id, $profile_ids, true ) ? true : $this->denied();
	}

	/** Release relationship advisory locks after the owning transaction ends. */
	public function close_transaction_scope( object $scope ) {
		global $wpdb;
		if ( ! $this->transaction_scopes->contains( $scope ) ) {
			return true;
		}
		for ( $index = count( $this->artist_locks ) - 1; $index >= 0; --$index ) {
			$lock_name = $this->artist_locks[ $index ];
			$released  = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the canonical relationship lock after transaction completion.
			if ( '1' !== (string) $released ) {
				return new \WP_Error( 'local_support_artist_authority_release_failed', __( 'Artist authority lock cleanup failed.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
			array_splice( $this->artist_locks, $index, 1 );
		}
		for ( $index = count( $this->mapping_locks ) - 1; $index >= 0; --$index ) {
			$released = ArtistMappingLock::release( $this->mapping_locks[ $index ] );
			if ( is_wp_error( $released ) ) {
				return $released;
			}
			array_splice( $this->mapping_locks, $index, 1 );
		}
		$this->transaction_scopes->detach( $scope );
		return true;
	}

	/** Best-effort cleanup if a caller is interrupted before primary scope closure. */
	public function __destruct() {
		$scope_count = count( $this->transaction_scopes );
		while ( $scope_count > 0 ) {
			$this->transaction_scopes->rewind();
			$result = $this->close_transaction_scope( $this->transaction_scopes->current() );
			if ( is_wp_error( $result ) ) {
				break;
			}
			--$scope_count;
		}
	}

	/** Determine whether a canonical artist is explicitly attached to the event. */
	public function artist_attached_to_event( int $event_id, int $artist_term_id ) {
		if ( ! function_exists( 'extrachill_events_resolve_artist_term' ) ) {
			return new \WP_Error( 'local_support_artist_mapping_unavailable', __( 'Canonical artist mapping is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$mapped = extrachill_events_resolve_artist_term( $artist_term_id );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		$artists = wp_get_object_terms( $event_id, 'artist', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $artists ) ) {
			return new \WP_Error( 'local_support_event_artists_unavailable', __( 'Event artist bindings could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return in_array( (int) $mapped['term_id'], array_map( 'intval', (array) $artists ), true );
	}

	/** Resolve and verify a canonical term's bidirectional Artist Platform profile. */
	private function artist_profile_id( int $artist_term_id ) {
		$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		if ( $main_blog_id < 1 || $artist_blog_id < 1 || $artist_term_id < 1 ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'A valid canonical artist is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		switch_to_blog( $main_blog_id );
		try {
			$term       = get_term( $artist_term_id, 'artist' );
			$profile_id = $term && ! is_wp_error( $term ) ? absint( get_term_meta( $artist_term_id, '_artist_profile_id', true ) ) : 0;
		} finally {
			restore_current_blog();
		}
		if ( $profile_id < 1 ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'The canonical artist has no bound profile.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		switch_to_blog( $artist_blog_id );
		try {
			$profile = get_post( $profile_id );
			$bound   = absint( get_post_meta( $profile_id, '_artist_term_id', true ) );
		} finally {
			restore_current_blog();
		}
		if ( ! $profile || 'artist_profile' !== $profile->post_type || 'publish' !== $profile->post_status || $bound !== $artist_term_id ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'The artist profile binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $profile_id;
	}

	/** Lock the event's bounded venue relationship range and derive its venue. */
	private function locked_event_context( int $event_id ) {
		global $wpdb;
		$post = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'invalid_local_support_event', __( 'A valid canonical event is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$relationships = $wpdb->term_relationships;
		$taxonomy      = $wpdb->term_taxonomy;
		$rows          = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, tr.term_taxonomy_id FROM {$relationships} tr INNER JOIN {$taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tr.term_taxonomy_id ASC LIMIT 2 FOR UPDATE", $event_id, 'venue' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL REPEATABLE READ next-key lock protects the event venue range with one-row overflow probe.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_event_venue_lock_failed', __( 'The event venue could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( 1 !== count( (array) $rows ) ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event must have one canonical venue.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$venue_id = (int) $rows[0]['term_id'];
		$venue    = get_term( $venue_id, 'venue' );
		if ( $venue_id < 1 || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event venue binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return array(
			'event_id'      => $event_id,
			'venue_term_id' => $venue_id,
		);
	}

	/** Lock one exact mapped Events artist relationship and return attachment state. */
	private function locked_artist_attachment( int $event_id, int $artist_term_id ) {
		global $wpdb;
		if ( ! function_exists( 'extrachill_events_resolve_artist_term' ) ) {
			return new \WP_Error( 'local_support_artist_mapping_unavailable', __( 'Canonical artist mapping is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$mapped = extrachill_events_resolve_artist_term( $artist_term_id );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		$relationships = $wpdb->term_relationships;
		$taxonomy      = $wpdb->term_taxonomy;
		$rows          = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, tr.term_taxonomy_id FROM {$relationships} tr INNER JOIN {$taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s AND tt.term_id = %d ORDER BY tr.term_taxonomy_id ASC LIMIT 2 FOR UPDATE", $event_id, 'artist', (int) $mapped['term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REPEATABLE READ next-key lock protects the exact event/artist relationship and insertion gap.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_event_artists_unavailable', __( 'Event artist bindings could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( count( (array) $rows ) > 1 ) {
			return new \WP_Error( 'local_support_event_artist_binding_corrupt', __( 'The event artist binding is duplicated.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$mapping_lock = ArtistMappingLock::acquire( (int) $mapped['term_id'] );
		if ( is_wp_error( $mapping_lock ) ) {
			return new \WP_Error( 'local_support_artist_mapping_lock_failed', __( 'Canonical artist mapping could not be serialized.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$this->mapping_locks[] = $mapping_lock;

		$main_blog_id = (int) ec_get_blog_id( 'main' );
		switch_to_blog( $main_blog_id );
		try {
			$claims        = extrachill_events_read_artist_mapping_claims( (int) $mapped['term_id'] );
			$mapping_table = $wpdb->termmeta;
			$mapping_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$mapping_table} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE", $artist_term_id, '_extrachill_events_artist_term_id' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks the canonical-to-Events mapping used to identify the exact relationship.
			$mapping_error = (string) $wpdb->last_error;
		} finally {
			restore_current_blog();
		}
		if ( '' !== $mapping_error ) {
			return new \WP_Error( 'local_support_artist_mapping_unavailable', __( 'Canonical artist mapping could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( is_wp_error( $claims ) || array( $artist_term_id ) !== array_values( array_unique( array_map( 'intval', (array) $claims ) ) ) ) {
			return new \WP_Error( 'local_support_artist_mapping_claims_invalid', __( 'Canonical artist mapping has conflicting claims.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$locked_mapping = 1 === count( (array) $mapping_rows ) ? $this->authority_scalar_id( $mapping_rows[0]['meta_value'] ) : null;
		if ( null === $locked_mapping || $locked_mapping !== (int) $mapped['term_id'] ) {
			return new \WP_Error( 'local_support_artist_mapping_changed', __( 'Canonical artist mapping changed during authorization.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return 1 === count( (array) $rows );
	}

	/** Lock the actor's unique venue membership natural key and authorize from it. */
	private function authorize_locked_venue( int $user_id, int $venue_term_id ) {
		global $wpdb;
		$table = BookingSchema::memberships_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d AND user_id = %d FOR UPDATE", $venue_term_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique actor/venue authority natural key.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_read_failed', __( 'The venue membership could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return $this->venues->authorize_locked( $user_id, $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE, is_array( $row ) ? array( $row ) : array() );
	}

	/** Ensure a lock helper is called only with this authorization's active scope. */
	private function validate_scope( object $scope ) {
		return $this->transaction_scopes->contains( $scope )
			? true
			: new \WP_Error( 'local_support_transaction_scope_required', __( 'Lock-current local support authorization requires its service transaction.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	/** Decode one bounded relationship collection without repairing corrupt state. */
	private function authority_ids( $stored ) {
		$ids = maybe_unserialize( $stored );
		if ( ! is_array( $ids ) || count( $ids ) > self::MAX_ARTIST_AUTHORITY_IDS ) {
			return new \WP_Error( 'local_support_artist_authority_corrupt', __( 'Artist authority is invalid or exceeds its supported limit.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$normalized = array();
		foreach ( $ids as $id ) {
			if ( ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) || (int) $id < 1 ) {
				return new \WP_Error( 'local_support_artist_authority_corrupt', __( 'Artist authority contains an invalid identifier.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$normalized[] = (int) $id;
		}
		return count( $normalized ) === count( array_unique( $normalized ) ) ? $normalized : new \WP_Error( 'local_support_artist_authority_corrupt', __( 'Artist authority contains duplicate identifiers.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/** Decode one positive scalar binding identifier without coercing corrupt data. */
	private function authority_scalar_id( $stored ): ?int {
		$id = maybe_unserialize( $stored );
		return ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && (int) $id > 0 ? (int) $id : null;
	}

	/** Return a non-enumerating denial. */
	private function denied(): \WP_Error {
		return new \WP_Error( 'local_support_forbidden', __( 'You are not authorized for this local support request.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
