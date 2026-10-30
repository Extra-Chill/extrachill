<?php
/**
 * Delegated booking marketing contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VenueBookingMarketingAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingMarketingService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use DataMachineSocials\Operations\DelegatedCrossPostAction;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';
$owner_root = dirname( __DIR__, 2 );
require_once $owner_root . '/data-machine-socials/inc/Operations/DelegatedCrossPostAction.php';
require_once $owner_root . '/extrachill-newsletter/inc/core/delegated-campaign.php';

final class BookingMarketingPendingStoreFake {
	public static $rows = array();
	public static function get( string $action_id, bool $include_resolved = false ) {
		unset( $include_resolved );
		return self::$rows[ $action_id ] ?? null;
	}
	public function store( $action ): bool {
		self::$rows[ $action->get_action_id() ] = $action;
		return true;
	}
}

final class BookingMarketingDelegatedBackendFake {
	public $calls      = array();
	public $operations = array();
	public $next       = array();
	public $user_id    = 12;
	public $on_submit;
	public int $owner_operation_creations = 0;

	public function execute( string $verb, array $input ): array {
		$this->calls[] = array( $verb, $input, $this->user_id );
		if ( 'submit' === $verb ) {
			$key         = $input['action'] . ':' . $input['operation_id'];
			$fingerprint = hash( 'sha256', wp_json_encode( array( $input['input'], $input['timestamp'] ) ) );
			if ( isset( $this->operations[ $key ] ) && $this->operations[ $key ]['fingerprint'] !== $fingerprint ) {
				return array(
					'success'    => false,
					'error_code' => 'delegated_operation_conflict',
				);
			}
			if ( ! isset( $this->operations[ $key ] ) ) {
				++$this->owner_operation_creations;
				$this->operations[ $key ] = array(
					'fingerprint'   => $fingerprint,
					'operation_ref' => 'dop_' . hash( 'sha256', $key ),
					'status'        => 'submitted',
					'projection'    => array(),
					'input'         => $input,
				);
			}
			$operation = &$this->operations[ $key ];
			if ( isset( $this->next['submit'] ) ) {
				$next = $this->next['submit'];
				unset( $this->next['submit'] );
				if ( false === ( $next['success'] ?? true ) ) {
					return $next;
				}
				$operation = array_merge( $operation, $next );
			}
			if ( is_callable( $this->on_submit ) ) {
				$authorized = ( $this->on_submit )( $input, $operation );
				if ( is_wp_error( $authorized ) ) {
					$data = $authorized->get_error_data();
					return array(
						'success'    => false,
						'error_code' => $authorized->get_error_code(),
						'retryable'  => is_array( $data ) && true === ( $data['retryable'] ?? false ),
					);
				}
			}
			return array(
				'success'       => true,
				'operation_ref' => $operation['operation_ref'],
				'status'        => $operation['status'],
				'replayed'      => count( array_filter( $this->calls, static fn( array $call ): bool => 'submit' === $call[0] && $call[1]['operation_id'] === $input['operation_id'] ) ) > 1,
				'projection'    => $operation['projection'],
			);
		}

		foreach ( $this->operations as &$operation ) {
			if ( $operation['operation_ref'] !== ( $input['operation_ref'] ?? '' ) ) {
				continue;
			}
			if ( isset( $this->next[ $verb ] ) ) {
				$next = $this->next[ $verb ];
				unset( $this->next[ $verb ] );
				if ( false === ( $next['success'] ?? true ) ) {
					return $next;
				}
				$operation = array_merge( $operation, $next );
			} elseif ( 'retry' === $verb && 'failed' === $operation['status'] ) {
				$operation['status'] = 'executing';
			} elseif ( 'cancel' === $verb && 'submitted' === $operation['status'] ) {
				$operation['status'] = 'cancelled';
			} elseif ( 'cancel' === $verb ) {
				return array(
					'success'    => false,
					'error_code' => 'delegated_operation_not_cancellable',
				);
			}
			return array(
				'success'       => true,
				'operation_ref' => $operation['operation_ref'],
				'status'        => $operation['status'],
				'replayed'      => true,
				'projection'    => $operation['projection'],
			);
		}
		return array(
			'success'    => false,
			'error_code' => 'delegated_operation_not_found',
		);
	}
}

final class BookingMarketingDelegatedAbilityFake {
	private $backend;
	private $verb;
	public function __construct( BookingMarketingDelegatedBackendFake $backend, string $verb ) {
		$this->backend = $backend;
		$this->verb    = $verb;
	}
	public function execute( array $input ): array {
		return $this->backend->execute( $this->verb, $input );
	}
}

final class BookingMarketingActivityRepositoryFake extends BookingActivityRepository {
	public string $fail_kind = '';
	public int $failures_remaining = 0;
	public $next_conversion_state_error;

	public function append( array $data ) {
		if ( $this->failures_remaining > 0 && $this->fail_kind === ( $data['kind'] ?? '' ) ) {
			--$this->failures_remaining;
			return new WP_Error( 'booking_activity_write_failed', 'simulated write failure' );
		}
		return parent::append( $data );
	}

	public function event_conversion_state( int $booking_id, string $public_id ) {
		if ( is_wp_error( $this->next_conversion_state_error ) ) {
			$error                             = $this->next_conversion_state_error;
			$this->next_conversion_state_error = null;
			return $error;
		}
		return parent::event_conversion_state( $booking_id, $public_id );
	}
}

final class BookingMarketingRepositoryFake extends BookingRepository {
	public $next_error;

	public function get( $identifier, bool $include_reservations = false ) {
		if ( is_wp_error( $this->next_error ) ) {
			$error            = $this->next_error;
			$this->next_error = null;
			return $error;
		}
		return parent::get( $identifier, $include_reservations );
	}
}

final class BookingMarketingPendingActionFake {
	private $id;
	private $input;
	private $data;
	public function __construct( $id, array $input = array() ) {
		$this->data  = is_array( $id ) ? $id : array(
			'action_id'   => $id,
			'apply_input' => $input,
			'kind'        => 'extrachill_run_booking_marketing',
			'status'      => 'pending',
			'preview'     => array(),
		);
		$this->id    = $this->data['action_id'];
		$this->input = $this->data['apply_input'];
	}
	public static function from_array( array $data ): self {
		return new self( $data );
	}
	public function get_kind(): string {
		return (string) ( $this->data['kind'] ?? 'extrachill_run_booking_marketing' ); }
	public function get_apply_input(): array {
		return $this->input; }
	public function get_action_id(): string {
		return $this->id; }
	public function get_status(): string {
		return (string) ( $this->data['status'] ?? 'pending' ); }
	public function get_preview(): array {
		return (array) ( $this->data['preview'] ?? array() ); }
}

final class BookingMarketingDecisionFake {
	public function is_rejected(): bool {
		return true; }
}

if ( ! class_exists( '\AgentsAPI\AI\Approvals\WP_Agent_Pending_Action' ) ) {
	class_alias( BookingMarketingPendingActionFake::class, '\AgentsAPI\AI\Approvals\WP_Agent_Pending_Action' );
}

final class BookingMarketingTest extends BookingTestCase {
	private BookingMarketingDelegatedBackendFake $backend;
	private $original_wpdb;
	private array $asset_files = array();
	private $log_callback;

	protected function setUp(): void {
		foreach ( array( 301, 302, 303, 304 ) as $attachment_id ) {
			$file = tempnam( sys_get_temp_dir(), 'ec-booking-marketing-' );
			file_put_contents( $file, 'approved-asset-' . $attachment_id );
			$this->asset_files[ $attachment_id ] = $file;
		}
		$this->original_wpdb       = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'          => 7,
			'stack'            => array(),
			'uuid'             => 0,
			'options'          => array(),
			'dbdelta'          => array(),
			'abilities'        => array(),
			'ability_objects'  => array(),
			'actions'          => array(),
			'fired_actions'    => array(),
			'scheduled'        => array(),
			'cache_deletes'    => array(),
			'permalinks'       => array( 7 => array( 901 => 'https://events.example/show' ) ),
			'attachment_urls'  => array( 7 => array( 301 => 'https://events.example/uploads/event.jpg' ) ),
			'attachment_mimes' => array(
				7 => array(
					301 => 'image/jpeg',
					302 => 'image/png',
					303 => 'video/mp4',
					304 => 'image/jpeg',
				),
			),
			'attachment_files' => array( 7 => $this->asset_files ),
			'terms'            => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
				),
			),
			'meta'             => array( 7 => array( 55 => array() ) ),
			'posts'            => array(
				7 => array(
					901 => (object) array(
						'ID'                => 901,
						'post_type'         => 'data_machine_events',
						'post_status'       => 'publish',
						'post_title'        => 'Test Band at The Room',
						'post_content'      => 'Canonical event details.',
						'post_excerpt'      => '',
						'post_modified_gmt' => '2030-01-01 00:00:00',
					),
					301 => (object) array(
						'ID'                => 301,
						'post_type'         => 'attachment',
						'post_status'       => 'inherit',
						'post_modified_gmt' => '2030-01-01 00:00:00',
					),
					302 => (object) array(
						'ID'                => 302,
						'post_type'         => 'attachment',
						'post_status'       => 'inherit',
						'post_modified_gmt' => '2030-01-01 00:00:00',
					),
					303 => (object) array(
						'ID'                => 303,
						'post_type'         => 'attachment',
						'post_status'       => 'inherit',
						'post_modified_gmt' => '2030-01-01 00:00:00',
					),
					304 => (object) array(
						'ID'                => 304,
						'post_type'         => 'attachment',
						'post_status'       => 'inherit',
						'post_modified_gmt' => '2030-01-01 00:00:00',
					),
				),
			),
			'post_meta'        => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][1] = array(
			'id'                 => 1,
			'venue_term_id'      => 55,
			'user_id'            => 12,
			'is_owner'           => 1,
			'status'             => 'active',
			'version'            => 1,
			'created_by_user_id' => 12,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_at'         => null,
		);
		BookingMarketingPendingStoreFake::$rows                         = array();
		$store = new BookingMarketingPendingStoreFake();
		add_filter( 'wp_agent_pending_action_store', static fn() => $store );
		$this->backend = new BookingMarketingDelegatedBackendFake();
		foreach ( array( 'submit', 'get', 'retry', 'cancel' ) as $verb ) {
			$GLOBALS['ec_artist_test']['ability_objects'][ 'datamachine/' . $verb . '-delegated-operation' ] = new BookingMarketingDelegatedAbilityFake( $this->backend, $verb );
		}
		$GLOBALS['ec_test_ability_resolver'] = static fn( string $name ) => $GLOBALS['ec_artist_test']['ability_objects'][ $name ] ?? null;
		if ( ! defined( 'EC_TEST_DO_ACTION_RECORDS_FIXTURES' ) ) {
			$this->log_callback = static function ( $level, $message, $context ): void {
				$GLOBALS['ec_artist_test']['fired_actions']['datamachine_log'][] = array( $level, $message, $context );
			};
			add_action( 'datamachine_log', $this->log_callback, 10, 3 );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		if ( $this->log_callback ) {
			remove_action( 'datamachine_log', $this->log_callback, 10 );
		}
		unset( $GLOBALS['ec_test_ability_resolver'] );
		foreach ( $this->asset_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->asset_files = array();
	}

	private function booking(): array {
		$booking         = ( new BookingRepository() )->create(
			array(
				'venue_term_id'        => 55,
				'artist_name'          => 'Test Band',
				'intake'               => array(),
				'performance_start_at' => '2030-03-10 00:00:00',
				'performance_end_at'   => '2030-03-10 03:00:00',
			)
		);
		$row             = &$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$row['status']   = 'confirmed';
		$row['event_id'] = 901;
		return ( new BookingRepository() )->get( $booking['id'] );
	}

	private function social( string $key = 'social', string $approval = 'direct', int $delay = 0, string $media_kind = 'image', array $asset_refs = array( 301 ), array $channels = array( 'instagram', 'twitter' ) ): array {
		return array(
			'key'           => $key,
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'approval'      => $approval,
			'delay_seconds' => $delay,
			'social'        => array(
				'channels'   => $channels,
				'caption'    => 'Approved event caption',
				'media_kind' => $media_kind,
				'asset_refs' => $asset_refs,
			),
			'newsletter'    => null,
		);
	}

	private function newsletter( string $key = 'newsletter', string $approval = 'required', int $delay = 0 ): array {
		return array(
			'key'           => $key,
			'action'        => BookingMarketingService::NEWSLETTER_ACTION,
			'approval'      => $approval,
			'delay_seconds' => $delay,
			'social'        => null,
			'newsletter'    => array( 'policy' => 'canonical-post-draft' ),
		);
	}

	private function configure( array $channels, int $revision = 4 ): void {
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ] = array(
			'version'            => VenueBookingConfig::VERSION,
			'revision'           => $revision,
			'enabled'            => true,
			'attachment_policy'  => $this->disabled_attachment_policy(),
			'marketing_channels' => array_column( $channels, 'key' ),
			'marketing_triggers' => array(
				array(
					'key'      => 'announcement',
					'event'    => 'event_converted',
					'channels' => $channels,
				),
			),
		);
	}

	private function disabled_attachment_policy(): array {
		return array(
			'version'  => VenueBookingConfig::ATTACHMENT_POLICY_VERSION,
			'enabled'  => false,
			'purposes' => array(),
		);
	}

	private function service( ?BookingTestAuthorization $authorization = null, ?BookingActivityRepository $activity = null, ?BookingRepository $bookings = null ): BookingMarketingService {
		return new BookingMarketingService( $bookings, $activity, null, $authorization ?: new BookingTestAuthorization() );
	}

	private function accept_pending( string $approval_id ): void {
		$pending = BookingMarketingPendingStoreFake::$rows[ $approval_id ];
		BookingMarketingPendingStoreFake::$rows[ $approval_id ] = BookingMarketingPendingActionFake::from_array(
			array(
				'action_id'   => $approval_id,
				'apply_input' => $pending->get_apply_input(),
				'kind'        => $pending->get_kind(),
				'status'      => 'accepted',
				'preview'     => $pending->get_preview(),
			)
		);
	}

	public function test_submit_uses_exact_public_ability_and_owner_input_with_delay(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social( 'social', 'direct', 3600 ) ) );
		$result = $this->service()->trigger( $booking['id'], 12 );

		$this->assertSame( 'submitted', $result['channels']['social']['status'] );
		$this->assertSame( 'submit', $this->backend->calls[0][0] );
		$input = $this->backend->calls[0][1];
		$this->assertSame( BookingMarketingService::SOCIAL_ACTION, $input['action'] );
		$this->assertSame( 901, $input['input']['post_id'] );
		$this->assertSame( hash( 'sha256', 'Approved event caption' ), $input['input']['content_hash'] );
		$this->assertSame( array( array( 'attachment_id' => 301, 'role' => 'image' ) ), $input['input']['asset_refs'] );
		$this->assertEqualsWithDelta( 3600, $input['timestamp'] - time(), 2.0 );
		$this->assertArrayNotHasKey( 'workflow', $input );
		$this->assertArrayNotHasKey( 'task_type', $input );
	}

	public function test_effect_authorization_uses_frozen_binding_before_submit_receipt_lands(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$authorized = null;
		$this->backend->on_submit = static function ( array $request, array $operation ) use ( &$authorized ): void {
			$submit_context = array(
				'phase'         => 'submit',
				'action'        => BookingMarketingService::SOCIAL_ACTION,
				'operation_id'  => $request['operation_id'],
				'operation_ref' => $operation['operation_ref'],
				'actor'         => array( 'user_id' => 12 ),
				'input'         => $request['input'],
			);
			$authorized = BookingMarketingService::authorize_social_operation( false, $submit_context );
			if ( true !== $authorized ) {
				return;
			}
			$authorized = BookingMarketingService::authorize_social_operation(
				false,
				array(
					'phase'         => 'effect',
					'action'        => BookingMarketingService::SOCIAL_ACTION,
					'operation_ref' => $operation['operation_ref'],
					'actor'         => array( 'user_id' => 12 ),
					'input'         => $request['input'],
				)
			);
		};

		$this->service()->trigger( $booking['id'], 12 );

		$this->assertTrue( $authorized );
	}

	public function test_newsletter_execute_authorization_uses_frozen_binding_before_submit_receipt_lands(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->newsletter( 'newsletter', 'direct' ) ) );
		$authorized = null;
		$this->backend->on_submit = static function ( array $request, array $operation ) use ( &$authorized ): void {
			$submit_context = array(
				'phase'         => 'submit',
				'action'        => BookingMarketingService::NEWSLETTER_ACTION,
				'operation_id'  => $request['operation_id'],
				'operation_ref' => $operation['operation_ref'],
				'actor'         => array( 'user_id' => 12 ),
				'input'         => $request['input'],
			);
			$authorized = BookingMarketingService::authorize_newsletter_operation( false, $request['input']['source'], $submit_context );
			if ( true !== $authorized ) {
				return;
			}
			$authorized = BookingMarketingService::authorize_newsletter_operation(
				false,
				$request['input']['source'],
				array(
					'phase'         => 'execute',
					'action'        => BookingMarketingService::NEWSLETTER_ACTION,
					'operation_ref' => $operation['operation_ref'],
					'actor'         => array( 'user_id' => 12 ),
					'input'         => $request['input'],
				)
			);
		};

		$this->service()->trigger( $booking['id'], 12 );

		$this->assertTrue( $authorized );
	}

	public function test_identical_pre_receipt_channels_bind_distinct_trusted_operation_refs(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure(
			array(
				$this->social( 'first', 'required' ),
				$this->social( 'second', 'required' ),
			)
		);
		$pending = $this->service()->trigger( $booking['id'], 12 )['channels'];
		$bindings = array();
		foreach ( array( 'first', 'second' ) as $key ) {
			$action                = BookingMarketingPendingStoreFake::$rows[ $pending[ $key ]['approval_id'] ];
			$bindings[ $key ]      = $action->get_preview();
			$bindings[ $key ]['approval_id'] = $pending[ $key ]['approval_id'];
			( new BookingActivityRepository() )->append(
				array(
					'booking_id'      => $booking['id'],
					'kind'            => 'marketing_operation_frozen',
					'actor_type'      => 'user',
					'actor_id'        => 12,
					'channel'         => $key,
					'external_id'     => $pending[ $key ]['approval_id'],
					'idempotency_key' => $bindings[ $key ]['operation_id'] . ':frozen',
					'payload'         => $bindings[ $key ],
				)
			);
		}
		$input = array(
			'post_id'      => 901,
			'source_url'   => 'https://events.example/show',
			'caption'      => 'Approved event caption',
			'content_hash' => hash( 'sha256', 'Approved event caption' ),
			'channels'     => array( 'instagram', 'twitter' ),
			'media_kind'   => 'image',
			'asset_refs'   => array( array( 'attachment_id' => 301, 'role' => 'image' ) ),
		);
		$refs = array(
			'first'  => 'dop_' . hash( 'sha256', 'first-operation' ),
			'second' => 'dop_' . hash( 'sha256', 'second-operation' ),
		);
		$this->assertNotSame( $bindings['first']['operation_id'], $bindings['second']['operation_id'] );
		foreach ( array( 'first', 'second' ) as $key ) {
			$submit_context = array(
				'phase'         => 'submit',
				'action'        => BookingMarketingService::SOCIAL_ACTION,
				'operation_id'  => $bindings[ $key ]['operation_id'],
				'operation_ref' => $refs[ $key ],
				'actor'         => array( 'user_id' => 12 ),
				'input'         => $input,
			);
			$this->assertTrue( BookingMarketingService::authorize_social_operation( false, $submit_context ) );
			unset( $submit_context['operation_id'] );
			$submit_context['phase'] = 'effect';
			$this->assertNull( ( new BookingActivityRepository() )->find_by_external_id( $booking['id'], 'marketing_operation_submitted', $refs[ $key ] ) );
			$this->assertTrue( BookingMarketingService::authorize_social_operation( false, $submit_context ) );
		}

		$substituted = array(
			'phase'         => 'submit',
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'operation_id'  => $bindings['second']['operation_id'],
			'operation_ref' => $refs['first'],
			'actor'         => array( 'user_id' => 12 ),
			'input'         => $input,
		);
		$this->assertSame( 'booking_marketing_owner_forbidden', BookingMarketingService::authorize_social_operation( false, $substituted )->get_error_code() );
		$substituted['phase']         = 'effect';
		$substituted['operation_ref'] = 'dop_' . hash( 'sha256', 'unknown-operation' );
		unset( $substituted['operation_id'] );
		$this->assertSame( 'booking_marketing_binding_missing', BookingMarketingService::authorize_social_operation( false, $substituted )->get_error_code() );
	}

	public function test_generated_inputs_compose_with_concrete_owner_normalizers(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$this->service()->trigger( $booking['id'], 12 );
		$social_input = $this->backend->calls[0][1]['input'];
		$first        = DelegatedCrossPostAction::normalize_input( $social_input, array( 'actor' => array( 'user_id' => 12 ) ) );
		$second       = DelegatedCrossPostAction::normalize_input( $social_input, array( 'actor' => array( 'user_id' => 13 ) ) );
		$this->assertFalse( is_wp_error( $first ) );
		$this->assertSame( $first, $second );
		$this->assertSame( array( array( 'attachment_id' => 301, 'role' => 'image' ) ), $first['asset_refs'] );
		$owner = static fn() => array(
			'user_id'  => 7,
			'agent_id' => 9,
		);
		add_filter( 'datamachine_socials_delegated_cross_post_execution_owner', $owner, 10, 3 );
		$first_prepared  = DelegatedCrossPostAction::prepare( $first, array( 'operation_ref' => 'dop_' . str_repeat( 'a', 64 ), 'actor' => array( 'user_id' => 12 ) ) );
		$second_prepared = DelegatedCrossPostAction::prepare( $second, array( 'operation_ref' => 'dop_' . str_repeat( 'a', 64 ), 'actor' => array( 'user_id' => 13 ) ) );
		remove_filter( 'datamachine_socials_delegated_cross_post_execution_owner', $owner, 10 );
		$this->assertSame( $first_prepared, $second_prepared );
		$this->assertStringNotContainsString( 'delegated_actor', wp_json_encode( $first_prepared ) );

		$this->configure( array( $this->newsletter( 'newsletter', 'direct' ) ), 5 );
		$this->service()->trigger( $booking['id'], 12 );
		$newsletter_input = $this->backend->calls[1][1]['input'];
		$this->assertSame( $newsletter_input, extrachill_newsletter_normalize_delegated_campaign_input( $newsletter_input ) );
	}

	public function test_social_asset_roles_match_concrete_v2_media_contract(): void {
		$cases = array(
			'image'    => array( array( 301 ), array( 'instagram', 'twitter' ), array( array( 'attachment_id' => 301, 'role' => 'image' ) ) ),
			'carousel' => array( array( 301, 302 ), array( 'instagram', 'twitter' ), array( array( 'attachment_id' => 301, 'role' => 'image' ), array( 'attachment_id' => 302, 'role' => 'image' ) ) ),
			'reel'     => array( array( 303, 304 ), array( 'instagram' ), array( array( 'attachment_id' => 303, 'role' => 'video' ), array( 'attachment_id' => 304, 'role' => 'cover' ) ) ),
			'story'    => array( array( 303 ), array( 'instagram' ), array( array( 'attachment_id' => 303, 'role' => 'video' ) ) ),
		);
		$GLOBALS['ec_artist_test']['attachment_urls'][7] += array(
			302 => 'https://events.example/uploads/carousel.png',
			303 => 'https://events.example/uploads/reel.mp4',
			304 => 'https://events.example/uploads/cover.jpg',
		);
		foreach ( $cases as $media_kind => $case ) {
			$booking = $this->booking();
			$channel = $this->social( $media_kind, 'direct', 0, $media_kind, $case[0], $case[1] );
			$this->configure( array( $channel ) );
			$result = $this->service()->trigger( $booking['id'], 12 )['channels'][ $media_kind ];
			$this->assertFalse( is_wp_error( $result ), $media_kind );
			$input      = $this->backend->calls[ count( $this->backend->calls ) - 1 ][1]['input'];
			$normalized = DelegatedCrossPostAction::normalize_input( $input, array() );
			$this->assertFalse( is_wp_error( $normalized ), $media_kind );
			$this->assertSame( $case[2], $normalized['asset_refs'], $media_kind );
		}
	}

	public function test_required_approval_binds_hashes_and_rejects_stale_policy_content_event_and_booking(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame( 'pending', $pending['status'] );
		$this->assertCount( 0, $this->backend->calls );
		$stored      = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$apply_input = $stored->get_apply_input();
		$preview     = $stored->get_preview();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $apply_input['binding_hash'] );
		$this->assertArrayHasKey( 'policy_hash', $preview );
		$this->assertArrayHasKey( 'content_hash', $preview );
		$this->assertArrayHasKey( 'assets_hash', $preview );

		$GLOBALS['ec_artist_test']['posts'][7][901]->post_modified_gmt = '2030-01-02 00:00:00';
		$this->assertSame( 'booking_marketing_binding_stale', $this->service()->apply( $apply_input, 12 )->get_error_code() );
		$GLOBALS['ec_artist_test']['posts'][7][901]->post_modified_gmt = '2030-01-01 00:00:00';
		++$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['version'];
		$this->assertSame( 'booking_marketing_binding_stale', $this->service()->apply( $apply_input, 12 )->get_error_code() );
	}

	public function test_approved_newsletter_submission_contains_only_owner_contract_fields(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$input   = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ]->get_apply_input();
		$result  = $this->service()->apply( $input, 12 );

		$this->assertSame( 'submitted', $result['status'] );
		$request = $this->backend->calls[0][1];
		$this->assertSame( BookingMarketingService::NEWSLETTER_ACTION, $request['action'] );
		$this->assertSame(
			array(
				'source' => array(
					'site_id' => 7,
					'post_id' => 901,
				),
				'policy' => 'canonical-post-draft',
			),
			$request['input']
		);
		$this->assertArrayNotHasKey( 'recipients', $request['input'] );
		$this->assertArrayNotHasKey( 'content', $request['input'] );
	}

	public function test_retryable_approved_apply_recovers_one_created_operation_without_a_duplicate_approval(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$service  = $this->service();
		$pending  = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$approval = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$this->backend->next['submit'] = array(
			'success'    => false,
			'error_code' => 'delegated_enqueue_failed',
			'retryable'  => true,
		);

		$first = $service->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'recovery-pending', $first['status'] );
		$this->assertTrue( $first['retryable'] );
		$this->assertArrayNotHasKey( 'operation_ref', $first );
		$this->assertCount( 1, $this->backend->operations );

		$this->accept_pending( $pending['approval_id'] );
		$recovered = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$replayed  = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame( 'submitted', $recovered['status'] );
		$this->assertSame( $recovered['operation_ref'], $replayed['operation_ref'] );
		$this->assertCount( 1, BookingMarketingPendingStoreFake::$rows );
		$this->assertCount( 1, $this->backend->operations );
		$this->assertSame( 1, $this->backend->owner_operation_creations );
	}

	public function test_approved_apply_recovers_after_context_lookup_failure(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending  = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$approval = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$bookings = new BookingMarketingRepositoryFake();
		$bookings->next_error = new WP_Error( 'booking_read_failed', 'simulated transient read failure' );
		$service              = $this->service( null, null, $bookings );

		$first = $service->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'recovery-pending', $first['status'] );
		$this->assertArrayNotHasKey( 'operation_id', $first );
		$this->assertCount( 0, $this->backend->calls );

		$this->accept_pending( $pending['approval_id'] );
		$recovered = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$replayed  = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame( 'submitted', $recovered['status'] );
		$this->assertSame( $recovered['operation_ref'], $replayed['operation_ref'] );
		$this->assertSame( 1, $this->backend->owner_operation_creations );
	}

	public function test_approved_apply_recovers_after_operation_lookup_failure(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$activity = new BookingMarketingActivityRepositoryFake();
		$service  = $this->service( null, $activity );
		$pending  = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$approval = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$activity->next_conversion_state_error = new WP_Error( 'booking_event_conversion_state_read_failed', 'simulated transient state read failure' );

		$first = $service->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'recovery-pending', $first['status'] );
		$this->assertCount( 0, $this->backend->calls );

		$this->accept_pending( $pending['approval_id'] );
		$recovered = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame( 'submitted', $recovered['status'] );
		$this->assertSame( 1, $this->backend->owner_operation_creations );
	}

	public function test_approved_apply_recovers_after_frozen_binding_write_failure(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social( 'social', 'required' ) ) );
		$activity = new BookingMarketingActivityRepositoryFake();
		$service  = $this->service( null, $activity );
		$pending  = $service->trigger( $booking['id'], 12 )['channels']['social'];
		$approval = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$activity->fail_kind          = 'marketing_operation_frozen';
		$activity->failures_remaining = 1;

		$first = $service->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'recovery-pending', $first['status'] );
		$this->assertCount( 0, $this->backend->calls );

		$this->accept_pending( $pending['approval_id'] );
		$recovered = $service->trigger( $booking['id'], 12 )['channels']['social'];
		$this->assertSame( 'submitted', $recovered['status'] );
		$this->assertSame( 1, $this->backend->owner_operation_creations );
	}

	public function test_approved_apply_recovers_after_authorization_mapping_write_failure(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social( 'social', 'required' ) ) );
		$service  = $this->service();
		$pending  = $service->trigger( $booking['id'], 12 )['channels']['social'];
		$approval = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$GLOBALS['wpdb']->fail_activity_kinds = array( 'marketing_operation_authorized' );
		$this->backend->on_submit = static function ( array $request, array $operation ) {
			$result = BookingMarketingService::authorize_social_operation(
				false,
				array(
					'phase'         => 'submit',
					'action'        => BookingMarketingService::SOCIAL_ACTION,
					'operation_id'  => $request['operation_id'],
					'operation_ref' => $operation['operation_ref'],
					'actor'         => array( 'user_id' => 12 ),
					'input'         => $request['input'],
				)
			);
			$GLOBALS['wpdb']->fail_activity_kinds = array();
			return $result;
		};

		$first = $service->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'recovery-pending', $first['status'] );
		$this->assertCount( 1, $this->backend->operations );

		$this->accept_pending( $pending['approval_id'] );
		$recovered = $service->trigger( $booking['id'], 12 )['channels']['social'];
		$replayed  = $service->trigger( $booking['id'], 12 )['channels']['social'];
		$this->assertSame( 'submitted', $recovered['status'] );
		$this->assertSame( $recovered['operation_ref'], $replayed['operation_ref'] );
		$this->assertSame( 1, $this->backend->owner_operation_creations );
	}

	public function test_approved_apply_recovers_after_created_operation_receipt_persistence_failure(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social( 'social', 'required' ) ) );
		$activity                    = new BookingMarketingActivityRepositoryFake();
		$activity->fail_kind         = 'marketing_operation_submitted';
		$activity->failures_remaining = 1;
		$service                     = $this->service( null, $activity );
		$pending                     = $service->trigger( $booking['id'], 12 )['channels']['social'];
		$approval                    = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];

		$first = $service->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'recovery-pending', $first['status'] );
		$this->assertCount( 1, $this->backend->operations );
		$this->assertNull( $activity->find_by_external_id( $booking['id'], 'marketing_operation_submitted', reset( $this->backend->operations )['operation_ref'] ) );

		$this->accept_pending( $pending['approval_id'] );
		$recovered = $service->trigger( $booking['id'], 12 )['channels']['social'];
		$this->assertSame( 'submitted', $recovered['status'] );
		$this->assertCount( 1, $this->backend->operations );
		$this->assertSame( 1, $this->backend->owner_operation_creations );
		$this->assertIsArray( $activity->find_by_external_id( $booking['id'], 'marketing_operation_submitted', $recovered['operation_ref'] ) );
	}

	public function test_permanent_approved_apply_failure_blocks_restaging_and_resubmission(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$service  = $this->service();
		$pending  = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$approval = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$this->backend->next['submit'] = array(
			'success'    => false,
			'error_code' => 'delegated_operation_conflict',
		);

		$failed = $service->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'delegated_operation_conflict', $failed->get_error_code() );
		$this->assertFalse( $failed->get_error_data()['retryable'] );
		$again = $service->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame( 'delegated_operation_conflict', $again->get_error_code() );
		$this->assertFalse( $again->get_error_data()['retryable'] );
		$this->assertCount( 1, $this->backend->calls );
		$this->assertCount( 1, $this->backend->operations );
	}

	public function test_permanent_pre_effect_failures_remain_terminal(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending  = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$approval = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];

		$denied = $this->service( new BookingTestAuthorization( array( '12:55' => false ) ) )->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
		$this->assertFalse( (bool) ( $denied->get_error_data()['retryable'] ?? false ) );

		$activity = new BookingMarketingActivityRepositoryFake();
		$activity->next_conversion_state_error = new WP_Error( 'booking_event_conversion_state_invalid', 'simulated permanent state corruption' );
		$invalid = $this->service( null, $activity )->apply( $approval->get_apply_input(), 12 );
		$this->assertSame( 'booking_event_conversion_state_invalid', $invalid->get_error_code() );
		$this->assertFalse( (bool) ( $invalid->get_error_data()['retryable'] ?? false ) );
		$this->assertCount( 0, $this->backend->calls );
	}

	public function test_duplicate_submission_is_cross_user_stable_and_changed_request_conflicts(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$first                  = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$this->backend->user_id = 13;
		$second                 = $this->service( new BookingTestAuthorization( array( '13:55' => true ) ) )->trigger( $booking['id'], 13 )['channels']['social'];
		$this->assertSame( $first['operation_ref'], $second['operation_ref'] );
		$this->assertCount( 1, $this->backend->operations );

		$changed                      = $this->social();
		$changed['social']['caption'] = 'Changed after first submission';
		$this->configure( array( $changed ), 5 );
		$conflict = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$this->assertSame( 'booking_marketing_operation_conflict', $conflict->get_error_code() );
	}

	public function test_partial_social_failure_and_owner_refs_are_preserved_without_diagnostics(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$this->backend->next['submit'] = array(
			'status'     => 'failed',
			'projection' => array(
				'classification' => 'partial',
				'effect_count'   => 1,
				'share_refs'     => array(
					array(
						'channel'          => 'instagram',
						'platform_post_id' => 'ig-123',
						'url'              => 'https://provider.invalid/private',
					),
				),
				'error_codes'    => array(
					array(
						'channel'    => 'twitter',
						'code'       => 'undelivered',
						'diagnostic' => 'token=secret',
					),
				),
				'credentials'    => 'secret',
			),
		);
		$result                        = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$this->assertSame( 'failed', $result['status'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( 'partial', $result['projection']['classification'] );
		$this->assertSame( 'ig-123', $result['projection']['share_refs'][0]['platform_post_id'] );
		$this->assertStringNotContainsString( 'secret', serialize( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ) ) );
		$this->assertStringNotContainsString( 'provider.invalid', serialize( $result ) );
	}

	public function test_explicit_data_machine_failure_is_the_only_retryable_evidence(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$this->backend->next['submit'] = array(
			'success'    => false,
			'error_code' => 'delegated_enqueue_failed',
			'retryable'  => true,
			'diagnostic' => 'private scheduler state',
		);
		$result = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];

		$this->assertSame( 'delegated_enqueue_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertStringNotContainsString( 'private scheduler state', serialize( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ) ) );
	}

	public function test_indeterminate_and_unsupported_failures_do_not_guess_retry_availability(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter( 'newsletter', 'direct' ) ) );
		$this->backend->next['submit'] = array(
			'status'     => 'failed',
			'projection' => array(
				'classification' => 'failed',
				'error_code'     => 'newsletter_campaign_reconciliation_required',
			),
		);
		$result = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];

		$this->assertSame( 'failed', $result['status'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( 'newsletter_campaign_reconciliation_required', $result['projection']['error_code'] );

		$this->backend->next['retry'] = array(
			'success'    => false,
			'error_code' => 'delegated_operation_retry_unsupported',
		);
		$unsupported = $this->service()->manage( 'retry', $booking['id'], $result['operation_ref'], 12 );
		$this->assertSame( 'delegated_operation_retry_unsupported', $unsupported->get_error_code() );
		$this->assertFalse( $unsupported->get_error_data()['retryable'] );
	}

	public function test_no_op_and_zero_result_remain_truthful(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter( 'newsletter', 'direct' ) ) );
		$this->backend->next['submit'] = array(
			'status'     => 'no-op',
			'projection' => array(
				'classification' => 'no-op',
				'effect_count'   => 0,
				'error_code'     => 'newsletter_campaign_empty_source',
			),
		);
		$result                        = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame( 'no-op', $result['status'] );
		$this->assertSame( 0, $result['projection']['effect_count'] );
		$this->assertSame( 'no-op', $result['projection']['classification'] );
	}

	public function test_failed_operation_is_exposed_and_owner_approved_retry_reuses_reference(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$this->backend->next['submit'] = array(
			'status'     => 'failed',
			'projection' => array(
				'classification' => 'failure',
				'effect_count'   => 0,
				'error_codes'    => array(
					array(
						'channel' => 'twitter',
						'code'    => 'undelivered',
					),
				),
			),
		);
		$failed                        = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$retry                         = $this->service()->manage( 'retry', $booking['id'], $failed['operation_ref'], 12 );
		$this->assertSame( 'executing', $retry['status'] );
		$this->assertSame( $failed['operation_ref'], $retry['operation_ref'] );
		$this->assertSame( 'retry', $this->backend->calls[1][0] );
	}

	public function test_retry_rejection_preserves_failed_operation_truth(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$this->backend->next['submit'] = array(
			'status'     => 'failed',
			'projection' => array(
				'classification' => 'failure',
				'effect_count'   => 0,
				'error_codes'    => array( array( 'channel' => 'twitter', 'code' => 'delivery_unknown' ) ),
			),
		);
		$failed = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$this->backend->next['retry'] = array(
			'success'    => false,
			'error_code' => 'social_cross_post_retry_unsafe',
		);

		$rejected = $this->service()->manage( 'retry', $booking['id'], $failed['operation_ref'], 12 );
		$current  = $this->service()->manage( 'get', $booking['id'], $failed['operation_ref'], 12 );

		$this->assertSame( 'social_cross_post_retry_unsafe', $rejected->get_error_code() );
		$this->assertFalse( $rejected->get_error_data()['retryable'] );
		$this->assertSame( 'failed', $current['status'] );
		$this->assertFalse( $current['retryable'] );
		$this->assertSame( 'delivery_unknown', $current['projection']['error_codes'][0]['code'] );
		$kinds = array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' );
		$this->assertContains( 'marketing_operation_retry_rejected', $kinds );
		$this->assertNotContains( 'marketing_operation_failed', $kinds );
	}

	public function test_cancellation_succeeds_only_before_execution(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$cancelled = $this->service()->manage( 'cancel', $booking['id'], $submitted['operation_ref'], 12 );
		$this->assertSame( 'cancelled', $cancelled['status'] );

		$this->backend->next['cancel'] = array( 'status' => 'executing' );
		$denied                        = $this->service()->manage( 'cancel', $booking['id'], $submitted['operation_ref'], 12 );
		$this->assertSame( 'executing', $denied['status'] );
	}

	public function test_delayed_operation_can_be_cancelled_after_booking_and_event_become_stale(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$request   = $this->backend->calls[0][1];
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'cancelled';
		$GLOBALS['ec_artist_test']['posts'][7][901]->post_status                              = 'draft';
		$cancel_context = array(
			'phase'         => 'cancel',
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'operation_id'  => $request['operation_id'],
			'operation_ref' => $submitted['operation_ref'],
			'actor'         => array( 'user_id' => 12 ),
			'input'         => $request['input'],
		);
		$this->assertTrue( BookingMarketingService::authorize_social_operation( false, $cancel_context ) );
		$mismatched_context                 = $cancel_context;
		$mismatched_context['operation_id'] = 'booking-marketing:' . str_repeat( '0', 64 );
		$this->assertSame( 'booking_marketing_owner_forbidden', BookingMarketingService::authorize_social_operation( false, $mismatched_context )->get_error_code() );
		$this->assertSame( 'cancelled', $this->service()->manage( 'cancel', $booking['id'], $submitted['operation_ref'], 12 )['status'] );
	}

	public function test_owner_action_rechecks_exact_actor_resource_and_frozen_binding(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted              = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$request                = $this->backend->calls[0][1];
		$this->assertTrue(
			BookingMarketingService::authorize_social_operation(
				false,
				array(
					'phase'         => 'submit',
					'action'        => BookingMarketingService::SOCIAL_ACTION,
					'operation_id'  => $request['operation_id'],
					'operation_ref' => $submitted['operation_ref'],
					'actor'         => array( 'user_id' => 12 ),
					'input'         => $request['input'],
				)
			)
		);
		$normalized_owner_input = array_merge(
			$request['input'],
			array(
				'images'        => array( 'owner-private-normalized-data' ),
				'video_url'     => null,
				'cover_url'     => null,
				'share_to_feed' => true,
			)
		);
		$context                = array(
			'phase'         => 'effect',
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'operation_ref' => $submitted['operation_ref'],
			'actor'         => array( 'user_id' => 12 ),
			'input'         => $normalized_owner_input,
		);
		$this->assertSame( $booking['id'], ( new BookingRepository() )->get_by_event( 901 )['id'] );
		$this->assertTrue( BookingMarketingService::authorize_social_operation( false, $context ) );
		$context['actor']['user_id'] = 99;
		$this->assertSame( 'booking_marketing_owner_forbidden', BookingMarketingService::authorize_social_operation( false, $context )->get_error_code() );
		$context['actor']['user_id']                            = 12;
		$GLOBALS['ec_artist_test']['posts'][7][901]->post_title = 'Changed event';
		$this->assertSame( 'booking_marketing_binding_stale', BookingMarketingService::authorize_social_operation( false, $context )->get_error_code() );
	}

	public function test_owner_action_rejects_same_attachment_id_after_asset_replacement(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$request   = $this->backend->calls[0][1];
		$context   = array(
			'phase'         => 'effect',
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'operation_ref' => $submitted['operation_ref'],
			'actor'         => array( 'user_id' => 12 ),
			'input'         => $request['input'],
		);
		$GLOBALS['ec_artist_test']['attachment_urls'][7][301] = 'https://events.example/uploads/replaced.jpg';
		$this->assertSame( 'booking_marketing_binding_stale', BookingMarketingService::authorize_social_operation( false, $context )->get_error_code() );
	}

	public function test_owner_action_rejects_same_attachment_url_after_file_bytes_change(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$request   = $this->backend->calls[0][1];
		$context   = array(
			'phase'         => 'effect',
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'operation_ref' => $submitted['operation_ref'],
			'actor'         => array( 'user_id' => 12 ),
			'input'         => $request['input'],
		);
		file_put_contents( $this->asset_files[301], 'replacement-bytes' );
		$this->assertSame( 'booking_marketing_binding_stale', BookingMarketingService::authorize_social_operation( false, $context )->get_error_code() );
	}

	public function test_owner_newsletter_record_is_bounded_and_preserved(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter( 'newsletter', 'direct' ) ) );
		$this->backend->next['submit'] = array(
			'status'     => 'executed',
			'projection' => array(
				'classification' => 'executed',
				'effect_count'   => 1,
				'record'         => array(
					'newsletter_post_id' => 77,
					'campaign_id'        => 'campaign-42',
					'state'              => 'provider-private',
				),
			),
		);
		$result                        = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame(
			array(
				'newsletter_post_id' => 77,
				'campaign_id'        => 'campaign-42',
			),
			$result['projection']['record']
		);
		$this->assertArrayNotHasKey( 'state', $result['projection']['record'] );
	}

	public function test_ability_registration_exposes_recovery_lifecycle_without_generic_names(): void {
		$booking   = $this->booking();
		$abilities = new VenueBookingMarketingAbilities( $this->service(), null, new BookingTestAuthorization() );
		$abilities->register();
		$this->assertArrayHasKey( 'extrachill/get-booking-marketing-operation', $GLOBALS['ec_artist_test']['abilities'] );
		$this->assertArrayHasKey( 'extrachill/retry-booking-marketing-operation', $GLOBALS['ec_artist_test']['abilities'] );
		$this->assertArrayHasKey( 'extrachill/cancel-booking-marketing-operation', $GLOBALS['ec_artist_test']['abilities'] );
		$this->assertTrue( $abilities->can_access( array( 'booking_id' => $booking['id'] ) ) );
		$this->assertArrayNotHasKey( 'task_type', $GLOBALS['ec_artist_test']['abilities']['extrachill/trigger-booking-marketing']['input_schema']['properties'] );
	}

	public function test_denial_records_only_bounded_approval_reference(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$input   = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ]->get_apply_input();
		BookingMarketingService::on_pending_action_resolved( new BookingMarketingPendingActionFake( $pending['approval_id'], $input ), new BookingMarketingDecisionFake(), 'user:12' );
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$denied     = array_values( array_filter( $activities, static fn( array $activity ): bool => 'marketing_operation_denied' === $activity['kind'] ) );
		$this->assertCount( 1, $denied );
		$this->assertSame( array( 'approval_id' => $pending['approval_id'] ), $denied[0]['payload']['data'] );
	}

	public function test_config_rejects_arbitrary_actions_handlers_and_mixed_owner_policy(): void {
		$channel              = $this->social();
		$channel['task_type'] = 'arbitrary_handler';
		$config               = new VenueBookingConfig();
		$result               = $config->normalize(
			array(
				'version'            => VenueBookingConfig::VERSION,
				'attachment_policy'  => $this->disabled_attachment_policy(),
				'marketing_channels' => array( 'social' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'announcement',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_trigger_channel', $result->get_error_code() );

		$channel           = $this->social();
		$channel['action'] = 'attacker/arbitrary-action';
		$result            = $config->normalize(
			array(
				'version'            => VenueBookingConfig::VERSION,
				'attachment_policy'  => $this->disabled_attachment_policy(),
				'marketing_channels' => array( 'social' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'announcement',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_trigger_channel', $result->get_error_code() );

		$channel               = $this->social();
		$channel['newsletter'] = array( 'policy' => 'canonical-post-draft' );
		$result                = $config->normalize(
			array(
				'version'            => VenueBookingConfig::VERSION,
				'attachment_policy'  => $this->disabled_attachment_policy(),
				'marketing_channels' => array( 'social' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'announcement',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_social', $result->get_error_code() );
	}

	public function test_existing_accepted_approval_must_match_current_frozen_binding(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending               = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$stored                = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$input                 = $stored->get_apply_input();
		$input['binding_hash'] = str_repeat( '0', 64 );
		BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ] = BookingMarketingPendingActionFake::from_array(
			array(
				'action_id'   => $pending['approval_id'],
				'kind'        => 'extrachill_run_booking_marketing',
				'status'      => 'accepted',
				'apply_input' => $input,
				'preview'     => array(),
			)
		);
		$this->assertSame( 'booking_marketing_approval_conflict', $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter']->get_error_code() );
		$this->assertCount( 0, $this->backend->calls );
	}

	public function test_automatic_trigger_records_and_logs_each_channel_failure(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		unset( $GLOBALS['ec_artist_test']['attachment_urls'][7][301] );
		BookingMarketingService::on_event_converted( array( 'booking_id' => $booking['id'] ), 12 );
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertContains( 'marketing_operation_trigger_failed', array_column( $activities, 'kind' ) );
		$this->assertNotEmpty( $GLOBALS['ec_artist_test']['fired_actions']['datamachine_log'] ?? array() );
	}

	public function test_config_rejects_duplicate_channel_keys_across_triggers(): void {
		$channel = $this->social();
		$result  = ( new VenueBookingConfig() )->normalize(
			array(
				'version'            => VenueBookingConfig::VERSION,
				'attachment_policy'  => $this->disabled_attachment_policy(),
				'marketing_channels' => array( 'social' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'first',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
					array(
						'key'      => 'second',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_trigger_channel', $result->get_error_code() );
	}

	public function test_version_two_configs_migrate_without_implicit_marketing_triggers(): void {
		$config            = ( new VenueBookingConfig() )->defaults();
		$config['version'] = VenueBookingConfig::PREVIOUS_VERSION;
		unset( $config['consent'], $config['marketing_triggers'], $config['embed'], $config['attachment_policy'] );
		$normalized = ( new VenueBookingConfig() )->normalize( $config );
		$this->assertSame( VenueBookingConfig::VERSION, $normalized['version'] );
		$this->assertSame( array(), $normalized['marketing_triggers'] );
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ] = $config;
		$persisted = ( new VenueBookingConfig() )->get( 55 );
		$this->assertSame( VenueBookingConfig::VERSION, $persisted['version'] );
		$this->assertSame( array(), $persisted['marketing_triggers'] );

		$config['marketing_triggers'] = array();
		$this->assertSame( 'booking_config_version_field_invalid', ( new VenueBookingConfig() )->normalize( $config )->get_error_code() );

		$missing = ( new VenueBookingConfig() )->defaults();
		unset( $missing['marketing_triggers'] );
		$this->assertSame( 'booking_config_version_field_invalid', ( new VenueBookingConfig() )->normalize( $missing )->get_error_code() );
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ] = $missing;
		$this->assertSame( 'booking_config_version_field_invalid', ( new VenueBookingConfig() )->get( 55 )->get_error_code() );
	}
}
