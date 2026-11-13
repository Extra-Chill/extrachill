<?php
/**
 * Events a promoter or venue manager runs: list them, edit them.
 *
 * Gives non-team accounts (promoters and venue managers, often connecting
 * their own agent over the Extra Chill MCP) the two missing primitives
 * from extrachill#1301:
 *
 *  - extrachill/list-managed-events: published events at venues the user's
 *    venue team runs, or tagged with a promoter whose team they belong to.
 *    Holders of edit_others_posts may pass scope=all.
 *  - extrachill/update-managed-event: edit one of those events through Data
 *    Machine Events' own update path (EventUpdateAbilities), restricted to
 *    the show details a manager owns. Venue, promoter, organizer, title,
 *    artists, image, and post status are not accepted, so an edit can never
 *    move an event out of the editor's scope or publish it.
 *
 * Both abilities are `show_in_rest: true` on purpose. The MCP reaches the
 * Events site through core's /wp-abilities/v1 run route, which only serves
 * abilities registered with show_in_rest, and the authorization here is a
 * per-user, per-event decision (ManagedEventScope), not a staff-wide gate,
 * so exposing them to any signed-in account is safe: an account with no
 * team gets an empty list and a 403 on every update.
 *
 * Data Machine Events' generic `update-event` stays staff-only
 * (edit_others_posts). This plugin owns who a host is, so it owns the
 * narrower door rather than widening the vendor-neutral one.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\ManagedEventFinder;
use ExtraChillEvents\Core\ManagedEventScope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../Core/ManagedEventScope.php';
require_once __DIR__ . '/../Core/ManagedEventFinder.php';

/** Registers the managed-event list and update abilities. */
class ManagedEventAbilities {

	public const LIST_ABILITY   = 'extrachill/list-managed-events';
	public const UPDATE_ABILITY = 'extrachill/update-managed-event';

	/** Largest page the list returns. */
	public const MAX_LIMIT = 100;

	/**
	 * Fields a manager may change. Everything else is refused, including
	 * the venue and promoter assignment that defines their scope.
	 */
	public const UPDATABLE_FIELDS = array(
		'startDate',
		'startTime',
		'endDate',
		'endTime',
		'description',
		'price',
		'ticketUrl',
		'eventStatus',
		'eventType',
	);

	/** @var ManagedEventScope */
	private $scope;

	/** @var ManagedEventFinder */
	private $finder;

	/** @var callable fn( array $input ): array|\WP_Error, Data Machine Events' update path. */
	private $updater;

	/**
	 * @param ManagedEventScope|null  $scope    Scope resolver.
	 * @param ManagedEventFinder|null $finder   Event lookup.
	 * @param callable|null           $updater  Update path; defaults to Data Machine Events.
	 * @param bool                    $register Hook registration (false in tests).
	 */
	public function __construct( ?ManagedEventScope $scope = null, ?ManagedEventFinder $finder = null, ?callable $updater = null, bool $register = true ) {
		$this->scope   = $scope ?? new ManagedEventScope();
		$this->finder  = $finder ?? new ManagedEventFinder();
		$this->updater = $updater ?? array( self::class, 'data_machine_update' );
		if ( $register ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
		}
	}

	/** Register both abilities. */
	public function register(): void {
		$term_ref      = array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'id'   => array( 'type' => 'integer' ),
				'name' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
		$term_ref_list = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'type' => 'integer' ),
					'name' => array( 'type' => 'string' ),
				),
			),
		);

		wp_register_ability(
			self::LIST_ABILITY,
			array(
				'label'               => __( 'List Managed Events', 'extrachill-events' ),
				'description'         => __( 'List the published events you manage: shows at venues your venue team runs, or tagged with a promoter you belong to. Upcoming by default. Use the returned IDs with update-managed-event, set-event-perk, and the guest list abilities.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'when'             => array(
							'type'        => 'string',
							'enum'        => array( 'upcoming', 'past', 'any' ),
							'default'     => 'upcoming',
							'description' => __( 'upcoming: not yet ended (soonest first). past: ended (most recent first). any: both.', 'extrachill-events' ),
						),
						'date_from'        => array(
							'type'        => 'string',
							'pattern'     => '^\d{4}-\d{2}-\d{2}$',
							'description' => __( 'Only events starting on or after this date (YYYY-MM-DD).', 'extrachill-events' ),
						),
						'date_to'          => array(
							'type'        => 'string',
							'pattern'     => '^\d{4}-\d{2}-\d{2}$',
							'description' => __( 'Only events starting on or before this date (YYYY-MM-DD).', 'extrachill-events' ),
						),
						'venue_term_id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Only events at this venue.', 'extrachill-events' ),
						),
						'promoter_term_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Only events tagged with this promoter.', 'extrachill-events' ),
						),
						'scope'            => array(
							'type'        => 'string',
							'enum'        => array( 'mine', 'all' ),
							'default'     => 'mine',
							'description' => __( 'mine: events your teams manage. all: every event (editors and administrators only).', 'extrachill-events' ),
						),
						'limit'            => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_LIMIT,
							'default' => 20,
						),
						'page'             => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'events'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array( 'type' => 'integer' ),
									'title'      => array( 'type' => 'string' ),
									'start'      => array( 'type' => 'string' ),
									'end'        => array( 'type' => array( 'string', 'null' ) ),
									'venue'      => $term_ref,
									'promoters'  => $term_ref_list,
									'permalink'  => array( 'type' => 'string' ),
									'guest_list' => array(
										'type'       => 'object',
										'properties' => array(
											'enabled' => array( 'type' => 'boolean' ),
											'mode'    => array( 'type' => 'string' ),
											'password_required' => array( 'type' => 'boolean' ),
										),
									),
								),
							),
						),
						'managed' => array(
							'type'       => 'object',
							'properties' => array(
								'all'       => array( 'type' => 'boolean' ),
								'venues'    => $term_ref_list,
								'promoters' => $term_ref_list,
							),
						),
						'total'   => array( 'type' => 'integer' ),
						'page'    => array( 'type' => 'integer' ),
						'limit'   => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'list_events' ),
				'permission_callback' => array( $this, 'can_list' ),
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
			self::UPDATE_ABILITY,
			array(
				'label'               => __( 'Update Managed Event', 'extrachill-events' ),
				'description'         => __( 'Edit the details of a published event you manage: dates and times, description, price, ticket link, status (scheduled, postponed, cancelled, rescheduled), and event type. Venue and promoter cannot be changed here. Your edit is protected from later imports overwriting it.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'event'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Event post ID (from list-managed-events).', 'extrachill-events' ),
						),
						'startDate'   => array(
							'type'        => 'string',
							'description' => __( 'Start date, e.g. 2026-12-31.', 'extrachill-events' ),
						),
						'startTime'   => array(
							'type'        => 'string',
							'description' => __( 'Start time, e.g. 8pm or 20:00.', 'extrachill-events' ),
						),
						'endDate'     => array( 'type' => 'string' ),
						'endTime'     => array( 'type' => 'string' ),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'Event description (basic HTML allowed).', 'extrachill-events' ),
						),
						'price'       => array(
							'type'        => 'string',
							'description' => __( 'e.g. "$20 adv / $25 door" or "Free".', 'extrachill-events' ),
						),
						'ticketUrl'   => array(
							'type'        => 'string',
							'description' => __( 'Where to buy tickets (external link).', 'extrachill-events' ),
						),
						'eventStatus' => array(
							'type' => 'string',
							'enum' => array( 'EventScheduled', 'EventPostponed', 'EventCancelled', 'EventRescheduled' ),
						),
						'eventType'   => self::event_type_schema(),
					),
					'required'             => array( 'event' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'event_id'       => array( 'type' => 'integer' ),
						'status'         => array(
							'type' => 'string',
							'enum' => array( 'updated', 'no_change' ),
						),
						'updated_fields' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'warnings'       => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( $this, 'update_event' ),
				'permission_callback' => array( $this, 'can_update' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	/**
	 * List permission: a signed-in user whose agent principal (if any) may act
	 * for them. scope=all additionally needs edit_others_posts in the ceiling.
	 *
	 * @param array|null $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_list( $input = null ) {
		$scope = $this->scope->for_user( get_current_user_id() );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		if ( 'all' === ( is_array( $input ) ? ( $input['scope'] ?? 'mine' ) : 'mine' ) && ! $scope['unrestricted'] ) {
			return new \WP_Error( 'managed_events_scope_forbidden', __( 'Only editors can list every event.', 'extrachill-events' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * List the events the current user manages.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function list_events( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$allowed = $this->can_list( $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$scope = $this->scope->for_user( get_current_user_id() );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$dates = array();
		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			$dates[ $key ] = '';
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$date = (string) $input[ $key ];
				if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
					return new \WP_Error( 'managed_events_invalid_date', __( 'Dates must be real calendar dates in YYYY-MM-DD form.', 'extrachill-events' ), array( 'status' => 400 ) );
				}
				$dates[ $key ] = $date;
			}
		}

		$when  = in_array( $input['when'] ?? 'upcoming', array( 'upcoming', 'past', 'any' ), true ) ? (string) ( $input['when'] ?? 'upcoming' ) : 'upcoming';
		$all   = 'all' === ( $input['scope'] ?? 'mine' );
		$limit = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$page  = max( 1, (int) ( $input['page'] ?? 1 ) );

		$found = $this->finder->find(
			array(
				'unrestricted'     => $all,
				'venue_ids'        => $scope['venue_ids'],
				'promoter_ids'     => $scope['promoter_ids'],
				'when'             => $when,
				'date_from'        => $dates['date_from'],
				'date_to'          => $dates['date_to'],
				'venue_term_id'    => (int) ( $input['venue_term_id'] ?? 0 ),
				'promoter_term_id' => (int) ( $input['promoter_term_id'] ?? 0 ),
				'now'              => current_time( 'mysql' ),
				'limit'            => $limit,
				'offset'           => ( $page - 1 ) * $limit,
			)
		);
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$events = array();
		foreach ( $found['ids'] as $event_id ) {
			$event = $this->project_event( (int) $event_id );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		return array(
			'events'  => $events,
			'managed' => array(
				'all'       => $all,
				'venues'    => self::term_refs( $scope['venue_ids'], 'venue' ),
				'promoters' => self::term_refs( $scope['promoter_ids'], 'promoter' ),
			),
			'total'   => (int) $found['total'],
			'page'    => $page,
			'limit'   => $limit,
		);
	}

	/**
	 * Update permission: the event exists, is published, and is inside the
	 * current user's managed scope (or they hold edit_others_posts under the
	 * principal ceiling).
	 *
	 * @param array|null $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_update( $input = null ) {
		$input    = is_array( $input ) ? $input : array();
		$event_id = isset( $input['event'] ) ? (int) $input['event'] : 0;

		$scope = $this->scope->for_user( get_current_user_id() );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$post = $event_id > 0 ? get_post( $event_id ) : null;
		if ( ! $post || ManagedEventScope::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'managed_event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		if ( ! $this->scope->covers_event( $scope, $event_id ) ) {
			return new \WP_Error( 'managed_event_forbidden', __( 'You do not manage this event.', 'extrachill-events' ), array( 'status' => 403 ) );
		}

		if ( 'publish' !== $post->post_status && ! $scope['unrestricted'] ) {
			return new \WP_Error( 'managed_event_not_editable', __( 'Only published events can be edited here.', 'extrachill-events' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Apply a restricted update through Data Machine Events.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function update_event( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$allowed = $this->can_update( $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// Repeat the schema's allow-list so a direct execute() call cannot
		// smuggle a venue or promoter reassignment past additionalProperties.
		$refused = array_values( array_diff( array_keys( $input ), array_merge( array( 'event' ), self::UPDATABLE_FIELDS ) ) );
		if ( $refused ) {
			return new \WP_Error(
				'managed_event_field_not_allowed',
				__( 'These fields cannot be changed here: ', 'extrachill-events' ) . implode( ', ', $refused ),
				array(
					'status' => 400,
					'fields' => $refused,
				)
			);
		}

		$event_id = (int) $input['event'];
		$update   = array( 'event' => $event_id );
		foreach ( self::UPDATABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$update[ $field ] = $input[ $field ];
			}
		}
		if ( 1 === count( $update ) ) {
			return new \WP_Error( 'managed_event_no_fields', __( 'Send at least one field to change.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$result = ( $this->updater )( $update );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$item = is_array( $result['results'][0] ?? null ) ? $result['results'][0] : array();
		if ( ! in_array( $item['status'] ?? '', array( 'updated', 'no_change' ), true ) ) {
			$data           = (array) ( $item['error_data'] ?? array() );
			$data['status'] = (int) ( $item['error_status'] ?? $data['status'] ?? 400 );
			return new \WP_Error( (string) ( $item['error_code'] ?? 'managed_event_update_failed' ), (string) ( $item['error'] ?? __( 'The event could not be updated.', 'extrachill-events' ) ), $data );
		}

		// A manager editing their own show takes ownership of it, so a later
		// import that matches the event only fills empty fields. Data Machine
		// Events marks real web saves itself but skips agent contexts; a
		// manager's agent acting through this ability is still that person.
		if ( 'updated' === $item['status'] && class_exists( '\\DataMachineEvents\\Core\\EventOwnership' ) ) {
			\DataMachineEvents\Core\EventOwnership::markHumanOwned( $event_id, get_current_user_id() );
		}

		return array(
			'success'        => true,
			'event_id'       => $event_id,
			'status'         => (string) $item['status'],
			'updated_fields' => array_values( (array) ( $item['updated_fields'] ?? array() ) ),
			'warnings'       => array_values( (array) ( $item['warnings'] ?? array() ) ),
		);
	}

	/**
	 * Data Machine Events' own single-event update path: same date parsing,
	 * block rewrite, dates-table sync, and lifecycle hooks as update-event,
	 * without its staff-wide edit_others_posts gate (authorization already
	 * happened in can_update()).
	 *
	 * @param array $update One event update.
	 * @return array|\WP_Error
	 */
	public static function data_machine_update( array $update ) {
		if ( ! class_exists( '\\DataMachineEvents\\Abilities\\EventUpdateAbilities' ) ) {
			return new \WP_Error( 'managed_event_update_unavailable', __( 'Event editing is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		return ( new \DataMachineEvents\Abilities\EventUpdateAbilities() )->executeUpdateEvent( $update );
	}

	/**
	 * Project one event for the list.
	 *
	 * @param int $event_id Event post ID.
	 * @return array|null
	 */
	private function project_event( int $event_id ): ?array {
		$post = get_post( $event_id );
		if ( ! $post || ManagedEventScope::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$dates = class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ? \DataMachineEvents\Core\EventDatesTable::get( $event_id ) : null;
		$venue = self::term_refs( ManagedEventScope::event_term_ids( $event_id, 'venue' ), 'venue' );
		$link  = get_permalink( $event_id );

		return array(
			'id'         => $event_id,
			'title'      => (string) $post->post_title,
			'start'      => $dates ? (string) $dates->start_datetime : '',
			'end'        => $dates && ! empty( $dates->end_datetime ) ? (string) $dates->end_datetime : null,
			'venue'      => $venue[0] ?? null,
			'promoters'  => self::term_refs( ManagedEventScope::event_term_ids( $event_id, 'promoter' ), 'promoter' ),
			'permalink'  => $link ? (string) $link : '',
			'guest_list' => array(
				'enabled'           => function_exists( 'extrachill_events_perk_enabled' ) && extrachill_events_perk_enabled( $event_id ),
				'mode'              => function_exists( 'extrachill_events_get_perk_mode' ) ? extrachill_events_get_perk_mode( $event_id ) : '',
				'password_required' => function_exists( 'extrachill_events_perk_password_required' ) && extrachill_events_perk_password_required( $event_id ),
			),
		);
	}

	/**
	 * ID/name pairs for existing terms.
	 *
	 * @param int[]  $ids      Term IDs.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int,array{id:int,name:string}>
	 */
	private static function term_refs( array $ids, string $taxonomy ): array {
		$refs = array();
		foreach ( $ids as $id ) {
			$term = get_term( (int) $id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$refs[] = array(
					'id'   => (int) $term->term_id,
					'name' => html_entity_decode( (string) $term->name, ENT_QUOTES ),
				);
			}
		}
		return $refs;
	}

	/** Event type schema, closed to Data Machine Events' vocabulary when available. */
	private static function event_type_schema(): array {
		$schema = array(
			'type'        => 'string',
			'description' => __( 'Event format. Choose one of the allowed values.', 'extrachill-events' ),
		);
		if ( class_exists( '\\DataMachineEvents\\Core\\Event_Type_Taxonomy' ) ) {
			$schema['enum'] = \DataMachineEvents\Core\Event_Type_Taxonomy::get_vocabulary_names();
		}
		return $schema;
	}
}
