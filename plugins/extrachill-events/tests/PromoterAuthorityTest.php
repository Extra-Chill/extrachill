<?php
/**
 * Promoter authority domain tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\PromoterAuthorityAbilities;
use ExtraChillEvents\Abilities\PromoterVenueGrantAbilities;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\PromoterAuthorityRepository;
use ExtraChillEvents\Core\PromoterAuthoritySchema;
use ExtraChillEvents\Core\PromoterAuthorityService;
use ExtraChillEvents\Core\PromoterVenueAuthorization;
use ExtraChillEvents\Core\PromoterVenueGrantRepository;
use ExtraChillEvents\Core\PromoterVenueGrantService;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/tests/fixtures/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WordPress error double. */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;
		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;
		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		private $data;
		public function __construct( $code, $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code; }
		public function get_error_message() {
			return $this->message; }
		public function get_error_data() {
			return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default_value = false ) {
		return $GLOBALS['promoter_test']['options'][ $key ] ?? $default_value; }
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		$term = $GLOBALS['promoter_test']['terms'][ $term_id ] ?? null;
		return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return $GLOBALS['promoter_test']['users'][ $user_id ] ?? false; }
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability ) {
		if ( 'manage_options' === $capability ) {
			return ! empty( $GLOBALS['promoter_test']['administrators'][ $user_id ] );
		}
		return VenueAuthorization::ACCESS_CAPABILITY === $capability && ! empty( $GLOBALS['promoter_test']['team_access'][ $user_id ] ); }
}
if ( ! function_exists( 'ec_feature_available' ) ) {
	function ec_feature_available( $feature, $user_id = null ) {
		return VenueAuthorization::FEATURE === $feature && ! empty( $GLOBALS['promoter_test']['feature_access'][ $user_id ] );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['promoter_test']['current_user_id']; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback ) {
		$GLOBALS['promoter_test']['actions'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		$GLOBALS['promoter_test']['abilities'][ $name ] = $definition; }
}

require_once dirname( __DIR__ ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueAuthorization.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthoritySchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthorityRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthorization.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthorityService.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterVenueGrantRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterVenueAuthorization.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterVenueGrantService.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/PromoterAuthorityAbilities.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/PromoterVenueGrantAbilities.php';

/** Minimal transaction-aware database double for promoter authority. */
final class PromoterAuthorityWpdb {
	/**
	 * Current site table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_7_';
	/**
	 * Last database error.
	 *
	 * @var string
	 */
	public $last_error = '';
	/**
	 * Last inserted identifier.
	 *
	 * @var int
	 */
	public $insert_id = 0;
	/**
	 * Rows grouped by table.
	 *
	 * @var array
	 */
	public $rows = array();
	/**
	 * Whether activity writes should fail.
	 *
	 * @var bool
	 */
	public $fail_activity = false;
	/**
	 * Whether an external organization insert should win a simulated race.
	 *
	 * @var bool
	 */
	public $race_organization_insert = false;
	/**
	 * Whether organization insertion should fail without a winner.
	 *
	 * @var bool
	 */
	public $fail_organization_insert = false;
	/**
	 * Whether an external grant insert should win a simulated race.
	 *
	 * @var bool
	 */
	public $race_grant_insert = false;
	/**
	 * Whether grant insertion should fail without a winner.
	 *
	 * @var bool
	 */
	public $fail_grant_insert = false;
	/**
	 * Transaction snapshot.
	 *
	 * @var array|null
	 */
	private $snapshot;
	/**
	 * Simulated externally committed organization winner.
	 *
	 * @var array|null
	 */
	private $external_winner;
	/**
	 * Simulated externally committed grant winner.
	 *
	 * @var array|null
	 */
	private $external_grant_winner;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $placeholder ) use ( &$args, &$i ) {
				$value = $args[ $i++ ];
				return '%d' === $placeholder[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function insert( $table, $row ) {
		$this->last_error = '';
		if ( PromoterAuthoritySchema::venue_grants_table() === $table && $this->race_grant_insert ) {
			$this->race_grant_insert     = false;
			$this->last_error            = 'Duplicate grant natural key';
			$row['id']                   = ++$this->insert_id;
			$this->external_grant_winner = $row;
			return false;
		}
		if ( PromoterAuthoritySchema::venue_grants_table() === $table && $this->fail_grant_insert ) {
			$this->last_error = 'Grant storage unavailable';
			return false;
		}
		if ( PromoterAuthoritySchema::organizations_table() === $table && $this->race_organization_insert ) {
			$this->race_organization_insert = false;
			$this->last_error               = 'Duplicate entry for promoter term';
			$row['id']                      = ++$this->insert_id;
			$this->external_winner          = $row;
			return false;
		}
		if ( PromoterAuthoritySchema::organizations_table() === $table && $this->fail_organization_insert ) {
			$this->last_error = 'Storage engine unavailable';
			return false;
		}
		if ( $this->fail_activity && PromoterAuthoritySchema::activity_table() === $table ) {
			$this->last_error = 'simulated audit failure';
			return false;
		}
		foreach ( $this->rows[ $table ] ?? array() as $existing ) {
			if ( isset( $row['venue_term_id'], $row['action'] ) && (int) $existing['promoter_term_id'] === (int) $row['promoter_term_id'] && (int) $existing['venue_term_id'] === (int) $row['venue_term_id'] && $existing['action'] === $row['action'] ) {
				$this->last_error = 'duplicate promoter venue action';
				return false;
			}
			if ( isset( $row['promoter_term_id'] ) && isset( $row['user_id'] ) && (int) $existing['promoter_term_id'] === (int) $row['promoter_term_id'] && (int) ( $existing['user_id'] ?? 0 ) === (int) $row['user_id'] ) {
				$this->last_error = 'duplicate promoter user';
				return false;
			}
			if ( isset( $row['promoter_term_id'] ) && ! isset( $row['user_id'] ) && ! isset( $row['venue_term_id'] ) && ! isset( $row['event'] ) && (int) $existing['promoter_term_id'] === (int) $row['promoter_term_id'] ) {
				$this->last_error = 'duplicate promoter';
				return false;
			}
		}
		$row['id']              = ++$this->insert_id;
		$this->rows[ $table ][] = $row;
		return 1;
	}

	public function query( $query ) {
		$this->last_error = '';
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = $this->rows;
			return 1;
		}
		if ( 'ROLLBACK' === $query ) {
			$this->rows = $this->snapshot;
			if ( $this->external_winner ) {
				$this->rows[ PromoterAuthoritySchema::organizations_table() ][] = $this->external_winner;
				$this->external_winner = null;
			}
			if ( $this->external_grant_winner ) {
				$this->rows[ PromoterAuthoritySchema::venue_grants_table() ][] = $this->external_grant_winner;
				$this->external_grant_winner                                   = null;
			}
			return 1;
		}
		if ( 'COMMIT' === $query ) {
			$this->snapshot = null;
			return 1;
		}
		if ( preg_match( "/UPDATE (\\S+) SET (.+) WHERE promoter_term_id = (\\d+) AND venue_term_id = (\\d+) AND action = '([^']+)' AND version = (\\d+)/", $query, $grant_match ) ) {
			$table = $grant_match[1];
			foreach ( $this->rows[ $table ] ?? array() as $index => $row ) {
				if ( (int) $row['promoter_term_id'] !== (int) $grant_match[3] || (int) $row['venue_term_id'] !== (int) $grant_match[4] || stripslashes( $grant_match[5] ) !== $row['action'] || (int) $row['version'] !== (int) $grant_match[6] ) {
					continue;
				}
				++$row['version'];
				preg_match( "/status = '([^']+)'/", $grant_match[2], $status );
				$row['status'] = $status[1];
				preg_match( '/updated_by_user_id = (\d+)/', $grant_match[2], $updater );
				$row['updated_by_user_id'] = (int) $updater[1];
				$row['updated_at']         = gmdate( 'Y-m-d H:i:s' );
				if ( false !== strpos( $grant_match[2], 'revoked_by_user_id = NULL' ) ) {
					$row['revoked_by_user_id'] = null;
					$row['revoked_at']         = null;
				} else {
					preg_match( '/revoked_by_user_id = (\d+)/', $grant_match[2], $revoker );
					$row['revoked_by_user_id'] = (int) $revoker[1];
					$row['revoked_at']         = gmdate( 'Y-m-d H:i:s' );
				}
				$this->rows[ $table ][ $index ] = $row;
				return 1;
			}
			return 0;
		}
		if ( preg_match( '/UPDATE (\S+) SET (.+) WHERE promoter_term_id = (\d+)(?: AND user_id = (\d+))? AND version = (\d+)/', $query, $match ) ) {
			$table = $match[1];
			if ( ! isset( $this->rows[ $table ] ) ) {
				return 0;
			}
			foreach ( $this->rows[ $table ] as &$row ) {
				if ( (int) $row['promoter_term_id'] !== (int) $match[3] || ( ! empty( $match[4] ) && (int) $row['user_id'] !== (int) $match[4] ) || (int) $row['version'] !== (int) $match[5] ) {
					continue;
				}
				++$row['version'];
				if ( preg_match( "/status = '([^']+)'/", $match[2], $status ) ) {
					$row['status'] = $status[1]; }
				if ( preg_match( '/is_owner = (\d+)/', $match[2], $owner ) ) {
					$row['is_owner'] = (int) $owner[1]; }
				if ( preg_match( '/revoked_by_user_id = (\d+)/', $match[2], $revoker ) ) {
					$row['revoked_by_user_id'] = (int) $revoker[1]; }
				if ( preg_match_all( "/'([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9:]+)'/", $match[2], $dates ) ) {
					$row['updated_at'] = $dates[1][0];
					if ( false !== strpos( $match[2], 'revoked_at' ) ) {
						$row['revoked_at'] = end( $dates[1] ); }
				}
				unset( $row );
				return 1;
			}
			unset( $row );
			return 0;
		}
		return false;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		$this->last_error = '';
		if ( preg_match( "/FROM (\\S+) WHERE promoter_term_id = (\\d+) AND venue_term_id = (\\d+) AND action = '([^']+)'/", $query, $grant_match ) ) {
			foreach ( $this->rows[ $grant_match[1] ] ?? array() as $row ) {
				if ( (int) $row['promoter_term_id'] === (int) $grant_match[2] && (int) $row['venue_term_id'] === (int) $grant_match[3] && stripslashes( $grant_match[4] ) === $row['action'] ) {
					return $row;
				}
			}
			return null;
		}
		if ( preg_match( '/FROM (\S+) WHERE venue_term_id = (\d+) AND user_id = (\d+)/', $query, $venue_match ) ) {
			foreach ( $this->rows[ $venue_match[1] ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $venue_match[2] && (int) $row['user_id'] === (int) $venue_match[3] ) {
					return $row;
				}
			}
			return null;
		}
		if ( ! preg_match( '/FROM (\S+) WHERE promoter_term_id = (\d+)(?: AND user_id = (\d+))?/', $query, $match ) ) {
			return null;
		}
		foreach ( $this->rows[ $match[1] ] ?? array() as $row ) {
			if ( (int) $row['promoter_term_id'] === (int) $match[2] && ( empty( $match[3] ) || (int) $row['user_id'] === (int) $match[3] ) ) {
				return $row;
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		$this->last_error = '';
		if ( preg_match( '/FROM (\S+) WHERE venue_term_id = (\d+)/', $query, $venue_match ) ) {
			return array_values(
				array_filter(
					$this->rows[ $venue_match[1] ] ?? array(),
					static function ( $row ) use ( $venue_match ) {
						return (int) $row['venue_term_id'] === (int) $venue_match[2];
					}
				)
			);
		}
		preg_match( '/FROM (\S+) WHERE promoter_term_id = (\d+)/', $query, $match );
		$venue_term_id = preg_match( '/AND venue_term_id = (\d+)/', $query, $pair_match ) ? (int) $pair_match[1] : 0;
		return array_values(
			array_filter(
				$this->rows[ $match[1] ] ?? array(),
				static function ( $row ) use ( $match, $venue_term_id ) {
					return (int) $row['promoter_term_id'] === (int) $match[2] && ( 0 === $venue_term_id || (int) $row['venue_term_id'] === $venue_term_id );
				}
			)
		);
	}

	public function get_col( $query ) {
		$this->last_error = '';
		preg_match( "/FROM (\\S+) WHERE promoter_term_id = (\\d+) AND action = '([^']+)' AND status = '([^']+)'/", $query, $match );
		$ids = array();
		foreach ( $this->rows[ $match[1] ] ?? array() as $row ) {
			if ( (int) $row['promoter_term_id'] === (int) $match[2] && stripslashes( $match[3] ) === $row['action'] && $match[4] === $row['status'] ) {
				$ids[] = $row['venue_term_id'];
			}
		}
		sort( $ids );
		return $ids;
	}
}

/** Covers verified promoter organization authority. */
final class PromoterAuthorityTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['promoter_test']         = array(
			'options'         => array(
				PromoterAuthoritySchema::VERSION_OPTION => PromoterAuthoritySchema::SCHEMA_VERSION,
				BookingSchema::VERSION_OPTION           => BookingSchema::SCHEMA_VERSION,
			),
			'terms'           => array(
				100 => (object) array(
					'term_id'  => 100,
					'taxonomy' => 'promoter',
				),
				101 => (object) array(
					'term_id'  => 101,
					'taxonomy' => 'venue',
				),
				102 => (object) array(
					'term_id'  => 102,
					'taxonomy' => 'promoter',
				),
				200 => (object) array(
					'term_id'  => 200,
					'taxonomy' => 'venue',
				),
				201 => (object) array(
					'term_id'  => 201,
					'taxonomy' => 'venue',
				),
			),
			'users'           => array(
				1 => (object) array( 'ID' => 1 ),
				2 => (object) array( 'ID' => 2 ),
				3 => (object) array( 'ID' => 3 ),
				4 => (object) array( 'ID' => 4 ),
				5 => (object) array( 'ID' => 5 ),
				6 => (object) array( 'ID' => 6 ),
			),
			'administrators'  => array( 1 => true ),
			'team_access'     => array(
				2 => true,
				3 => true,
				4 => true,
				5 => true,
				6 => true,
			),
			'feature_access'  => array(
				2 => true,
				3 => true,
				4 => true,
				5 => true,
				6 => true,
			),
			'current_user_id' => 1,
			'actions'         => array(),
			'abilities'       => array(),
			'database_errors' => array(),
		);
		$GLOBALS['venue_membership_test'] = array(
			'options'           => array(
				PromoterAuthoritySchema::VERSION_OPTION => PromoterAuthoritySchema::SCHEMA_VERSION,
				BookingSchema::VERSION_OPTION           => BookingSchema::SCHEMA_VERSION,
			),
			'terms'             => $GLOBALS['promoter_test']['terms'],
			'users'             => $GLOBALS['promoter_test']['users'],
			'administrators'    => $GLOBALS['promoter_test']['administrators'],
			'team_access'       => $GLOBALS['promoter_test']['team_access'],
			'feature_available' => true,
			'current_user_id'   => 1,
			'abilities'         => array(),
		);
		$GLOBALS['ec_test_filters']       = array();
		add_action(
			'extrachill_events_promoter_authority_database_error',
			static function ( array $context ): void {
				$GLOBALS['promoter_test']['database_errors'][] = $context;
			}
		);
		$GLOBALS['wpdb'] = new PromoterAuthorityWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test double.
	}

	public function test_schema_table_names_are_site_scoped(): void {
		$this->assertSame( 'wp_7_ec_promoter_organizations', PromoterAuthoritySchema::organizations_table() );
		$this->assertSame( 'wp_7_ec_promoter_members', PromoterAuthoritySchema::memberships_table() );
		$this->assertSame( 'wp_7_ec_promoter_authority_activity', PromoterAuthoritySchema::activity_table() );
		$this->assertSame( 'wp_7_ec_promoter_venue_grants', PromoterAuthoritySchema::venue_grants_table() );
	}

	public function test_verification_requires_exact_term_and_users_and_does_not_enroll_admin(): void {
		$service      = new PromoterAuthorityService();
		$invalid_term = $service->verify( 1, 101, 2 );
		$this->assertSame( 'invalid_promoter_authority_term', $invalid_term->get_error_code() );
		$invalid_user = $service->verify( 1, 100, 99 );
		$this->assertSame( 'invalid_promoter_authority_user', $invalid_user->get_error_code() );

		$result = $service->verify( 1, 100, 2 );
		$this->assertSame( 100, $result['organization']['promoter_term_id'] );
		$this->assertTrue( $result['membership']['is_owner'] );
		$this->assertSame( 2, $result['membership']['user_id'] );
		$this->assertNull( ( new PromoterAuthorityRepository() )->get_membership( 100, 1 ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ PromoterAuthoritySchema::activity_table() ] );
		$this->assertSame( 'promoter_organization_exists', $service->verify( 1, 100, 3 )->get_error_code() );
	}

	public function test_audit_failure_is_explicit_and_rolls_back_bootstrap(): void {
		$GLOBALS['wpdb']->fail_activity = true;

		$error = ( new PromoterAuthorityService() )->verify( 1, 100, 2 );
		$this->assertSame( 'promoter_authority_audit_failed', $error->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $error->get_error_data() );
		$this->assertSame( 'simulated audit failure', $GLOBALS['promoter_test']['database_errors'][0]['database_error'] );
		$this->assertNull( ( new PromoterAuthorityRepository() )->get_organization( 100 ) );
		$this->assertNull( ( new PromoterAuthorityRepository() )->get_membership( 100, 2 ) );
	}

	public function test_concurrent_verification_loser_returns_exact_winner_conflict(): void {
		$GLOBALS['wpdb']->race_organization_insert = true;

		$error = ( new PromoterAuthorityService() )->verify( 1, 100, 2 );
		$this->assertSame( 'promoter_organization_exists', $error->get_error_code() );
		$this->assertSame(
			array(
				'status'          => 409,
				'current_version' => 1,
			),
			$error->get_error_data()
		);
		$this->assertSame( 'promoter_organization_create_race_lost', $GLOBALS['promoter_test']['database_errors'][0]['code'] );
		$this->assertSame( 'Duplicate entry for promoter term', $GLOBALS['promoter_test']['database_errors'][0]['database_error'] );
	}

	public function test_unrelated_organization_insert_failure_remains_safe_500(): void {
		$GLOBALS['wpdb']->fail_organization_insert = true;

		$error = ( new PromoterAuthorityService() )->verify( 1, 100, 2 );
		$this->assertSame( 'promoter_organization_create_failed', $error->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $error->get_error_data() );
		$this->assertStringNotContainsString( 'Storage engine unavailable', wp_json_encode( $error->get_error_data() ) );
		$this->assertSame( 'Storage engine unavailable', $GLOBALS['promoter_test']['database_errors'][0]['database_error'] );
	}

	public function test_only_owner_manages_members_with_versions_and_final_owner_protection(): void {
		$service = new PromoterAuthorityService();
		$service->verify( 1, 100, 2 );
		$created = $service->create_membership( 2, 100, 3, false );
		$this->assertFalse( $created['is_owner'] );
		$denied = $service->create_membership( 3, 100, 4, false );
		$this->assertSame( 'promoter_authority_forbidden', $denied->get_error_code() );

		$promoted = $service->update_membership( 2, 100, 3, true, 1 );
		$this->assertSame( 2, $promoted['version'] );
		$conflict = $service->update_membership( 2, 100, 3, false, 1 );
		$this->assertSame( 'promoter_membership_version_conflict', $conflict->get_error_code() );
		$this->assertSame( 2, $conflict->get_error_data()['current_version'] );

		$demoted = $service->update_membership( 2, 100, 2, false, 1 );
		$this->assertFalse( $demoted['is_owner'] );
		$last_owner = $service->revoke_membership( 3, 100, 3, 2 );
		$this->assertSame( 'promoter_membership_last_owner', $last_owner->get_error_code() );
	}

	public function test_revocation_is_preserved_and_corrupt_values_fail_closed(): void {
		$service = new PromoterAuthorityService();
		$service->verify( 1, 100, 2 );
		$service->create_membership( 2, 100, 4, false );
		$revoked = $service->revoke_membership( 2, 100, 4, 1 );
		$this->assertSame( PromoterAuthorityRepository::STATUS_REVOKED, $revoked['status'] );
		$this->assertSame( 2, $revoked['revoked_by_user_id'] );
		$this->assertCount( 2, $service->list_memberships( 2, 100 ) );

		$repository = new PromoterAuthorityRepository();
		$bad_status = $repository->hydrate_membership(
			array(
				'status'   => 'trusted',
				'is_owner' => 1,
			)
		);
		$this->assertSame( 'promoter_membership_corrupt_status', $bad_status->get_error_code() );
		$bad_owner = $repository->hydrate_membership(
			array(
				'status'   => 'active',
				'is_owner' => 2,
			)
		);
		$this->assertSame( 'promoter_membership_corrupt_owner', $bad_owner->get_error_code() );
		$bad_org = $repository->hydrate_organization( array( 'status' => 'verified' ) );
		$this->assertSame( 'promoter_organization_corrupt_status', $bad_org->get_error_code() );
	}

	public function test_membership_listing_fails_when_hard_organization_cap_is_exceeded(): void {
		$service = new PromoterAuthorityService();
		$service->verify( 1, 100, 2 );
		$table = PromoterAuthoritySchema::memberships_table();
		for ( $user_id = 10; $user_id < 110; ++$user_id ) {
			$GLOBALS['wpdb']->rows[ $table ][] = array(
				'id'                 => $user_id,
				'promoter_term_id'   => 100,
				'user_id'            => $user_id,
				'is_owner'           => 0,
				'status'             => 'active',
				'version'            => 1,
				'created_by_user_id' => 2,
				'created_at'         => '2026-01-01 00:00:00',
				'updated_at'         => '2026-01-01 00:00:00',
				'revoked_by_user_id' => null,
				'revoked_at'         => null,
			);
		}
		$error = $service->list_memberships( 2, 100 );
		$this->assertSame( 'promoter_membership_limit_exceeded', $error->get_error_code() );
	}

	public function test_administrator_revokes_organization_without_membership_authority(): void {
		$service = new PromoterAuthorityService();
		$service->verify( 1, 100, 2 );
		$this->assertSame( 'promoter_authority_forbidden', $service->create_membership( 1, 100, 3, false )->get_error_code() );
		$organization = $service->revoke_organization( 1, 100, 1 );
		$this->assertSame( PromoterAuthorityRepository::STATUS_REVOKED, $organization['status'] );
		$this->assertSame( 2, $organization['version'] );
		$this->assertSame( 'promoter_authority_forbidden', $service->list_memberships( 2, 100 )->get_error_code() );
	}

	public function test_multiple_promoters_share_one_venue_without_direct_membership_widening(): void {
		$this->bootstrap_promoter_grant_fixtures();
		$grants = new PromoterVenueGrantService();
		$action = PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT;

		$this->assertIsArray( $grants->create( 4, 100, 200, $action ) );
		$this->assertIsArray( $grants->create( 4, 102, 200, $action ) );
		$authorization = new PromoterVenueAuthorization();
		$this->assertTrue( $authorization->authorize( 3, 100, 200, $action ) );
		$this->assertTrue( $authorization->authorize( 6, 102, 200, $action ) );
		$this->assertSame( 'promoter_venue_action_forbidden', $authorization->authorize( 1, 100, 200, $action )->get_error_code() );
		$this->assertSame( 'promoter_venue_action_forbidden', $authorization->authorize( 3, 102, 200, $action )->get_error_code() );
		$this->assertSame( 'promoter_venue_action_forbidden', $authorization->authorize( 3, 100, 201, $action )->get_error_code() );
		$this->assertSame( array( 200 ), $authorization->effective_venue_ids( 3, 100, $action ) );
		unset( $GLOBALS['promoter_test']['team_access'][3], $GLOBALS['venue_membership_test']['team_access'][3] );
		$this->assertSame( 'promoter_venue_action_forbidden', $authorization->authorize( 3, 100, 200, $action )->get_error_code() );

		$venues = new VenueAuthorization();
		foreach ( array( VenueAuthorization::ACTION_ACCESS_VENUE, VenueAuthorization::ACTION_MANAGE_MEMBERS, VenueAuthorization::ACTION_MANAGE_FINANCES ) as $venue_action ) {
			$this->assertSame( 'venue_action_forbidden', $venues->authorize( 3, 200, $venue_action )->get_error_code() );
		}
		$this->assertNull( ( new ExtraChillEvents\Core\VenueMembershipRepository() )->get( 200, 3 ) );
	}

	public function test_direct_owner_issues_promoter_owner_relinquishes_and_only_direct_owner_reactivates(): void {
		$this->bootstrap_promoter_grant_fixtures();
		$grants = new PromoterVenueGrantService();
		$action = PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT;

		$this->assertSame( 'promoter_venue_action_forbidden', $grants->create( 1, 100, 200, $action )->get_error_code() );
		$this->assertSame( 'promoter_venue_action_forbidden', $grants->create( 2, 100, 200, $action )->get_error_code() );
		$created = $grants->create( 4, 100, 200, $action );
		$this->assertSame( 1, $created['version'] );
		$revoked = $grants->revoke( 2, 100, 200, $action, 1 );
		$this->assertSame( 'revoked', $revoked['status'] );
		$this->assertSame( 2, $revoked['version'] );
		$this->assertSame( 'promoter_venue_action_forbidden', $grants->reactivate( 2, 100, 200, $action, 2 )->get_error_code() );
		$reactivated = $grants->reactivate( 4, 100, 200, $action, 2 );
		$this->assertSame( 'active', $reactivated['status'] );
		$this->assertSame( 3, $reactivated['version'] );
		$this->assertSame( 'promoter_venue_grant_version_conflict', $grants->revoke( 4, 100, 200, $action, 2 )->get_error_code() );
	}

	public function test_effective_authorization_fails_for_revoked_member_organization_or_grant(): void {
		$this->bootstrap_promoter_grant_fixtures();
		$action        = PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT;
		$grants        = new PromoterVenueGrantService();
		$authorization = new PromoterVenueAuthorization();
		$grants->create( 4, 100, 200, $action );
		$grants->create( 5, 100, 201, $action );
		$this->assertTrue( $authorization->authorize( 3, 100, 200, $action ) );

		$grants->revoke( 4, 100, 200, $action, 1 );
		$this->assertSame( 'promoter_venue_action_forbidden', $authorization->authorize( 3, 100, 200, $action )->get_error_code() );
		$this->assertTrue( $authorization->authorize( 3, 100, 201, $action ) );
		$grants->reactivate( 4, 100, 200, $action, 2 );
		( new PromoterAuthorityService() )->revoke_membership( 2, 100, 3, 1 );
		$this->assertSame( 'promoter_venue_action_forbidden', $authorization->authorize( 3, 100, 200, $action )->get_error_code() );

		( new PromoterAuthorityService() )->revoke_organization( 1, 100, 1 );
		$this->assertSame( 'promoter_venue_action_forbidden', $authorization->authorize( 2, 100, 200, $action )->get_error_code() );
	}

	public function test_grants_fail_closed_for_unsupported_or_corrupt_values(): void {
		$this->bootstrap_promoter_grant_fixtures();
		$repository = new PromoterVenueGrantRepository();
		$this->assertSame( 'invalid_promoter_venue_grant_action', $repository->create( 100, 200, 'manage_finances', 4 )->get_error_code() );
		$this->assertSame(
			'promoter_venue_grant_corrupt_status',
			$repository->hydrate(
				array(
					'status' => 'trusted',
					'action' => PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT,
				)
			)->get_error_code()
		);
		$this->assertSame(
			'promoter_venue_grant_corrupt_action',
			$repository->hydrate(
				array(
					'status' => 'active',
					'action' => 'access_venue',
				)
			)->get_error_code()
		);
	}

	public function test_grant_creation_race_has_deterministic_safe_winner_or_500(): void {
		$this->bootstrap_promoter_grant_fixtures();
		$action                             = PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT;
		$GLOBALS['wpdb']->race_grant_insert = true;
		$error                              = ( new PromoterVenueGrantService() )->create( 4, 100, 200, $action );
		$this->assertSame( 'promoter_venue_grant_exists', $error->get_error_code() );
		$this->assertSame(
			array(
				'status'          => 409,
				'current_version' => 1,
			),
			$error->get_error_data()
		);

		$this->setUp();
		$this->bootstrap_promoter_grant_fixtures();
		$GLOBALS['wpdb']->fail_grant_insert = true;
		$error                              = ( new PromoterVenueGrantService() )->create( 4, 100, 200, $action );
		$this->assertSame( 'promoter_venue_grant_create_failed', $error->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $error->get_error_data() );
		$this->assertSame( 'Grant storage unavailable', $GLOBALS['promoter_test']['database_errors'][0]['database_error'] );
	}

	public function test_lock_context_rejects_promoter_membership_overflow(): void {
		$this->bootstrap_promoter_grant_fixtures();
		$table = PromoterAuthoritySchema::memberships_table();
		for ( $index = 0; $index < 99; ++$index ) {
			$GLOBALS['wpdb']->rows[ $table ][] = $this->promoter_membership_row( 2000 + $index, 100, 1000 + $index );
		}
		$error = ( new PromoterVenueGrantService() )->create( 4, 100, 200, PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT );
		$this->assertSame( 'promoter_venue_grant_promoter_membership_limit_exceeded', $error->get_error_code() );
		$this->assertSame(
			array(
				'status'  => 409,
				'maximum' => 100,
			),
			$error->get_error_data()
		);
	}

	public function test_lock_context_rejects_venue_membership_overflow(): void {
		$this->bootstrap_promoter_grant_fixtures();
		$table = BookingSchema::memberships_table();
		for ( $index = 0; $index < 100; ++$index ) {
			$GLOBALS['wpdb']->rows[ $table ][] = $this->venue_membership_row( 3000 + $index, 200, 1000 + $index );
		}
		$error = ( new PromoterVenueGrantService() )->create( 4, 100, 200, PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT );
		$this->assertSame( 'promoter_venue_grant_venue_membership_limit_exceeded', $error->get_error_code() );
		$this->assertSame(
			array(
				'status'  => 409,
				'maximum' => 100,
			),
			$error->get_error_data()
		);
	}

	public function test_ability_permission_cannot_bypass_execute_time_reauthorization(): void {
		$this->bootstrap_promoter_grant_fixtures();
		$GLOBALS['promoter_test']['current_user_id']         = 4;
		$GLOBALS['venue_membership_test']['current_user_id'] = 4;
		$input     = array(
			'promoter_term_id' => 100,
			'venue_term_id'    => 200,
			'action'           => PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT,
		);
		$abilities = new PromoterVenueGrantAbilities();
		$this->assertTrue( $abilities->can_issue( $input ) );

		$table                                        = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->rows[ $table ][0]['status'] = VenueAuthorization::STATUS_REVOKED;
		$this->assertSame( 'promoter_venue_action_forbidden', $abilities->create( $input )->get_error_code() );
		$this->assertNull( ( new PromoterVenueGrantRepository() )->get( 100, 200, PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT ) );
	}

	public function test_effective_venue_ids_propagates_repository_errors(): void {
		$repository = new class() extends PromoterAuthorityRepository {
			public function get_organization( int $promoter_term_id ) {
				unset( $promoter_term_id );
				return new WP_Error( 'promoter_storage_failed', 'Storage unavailable.', array( 'status' => 500 ) );
			}
		};
		$error      = ( new PromoterVenueAuthorization( $repository ) )->effective_venue_ids( 2, 100, PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT );
		$this->assertSame( 'promoter_storage_failed', $error->get_error_code() );
	}

	public function test_grant_ability_schemas_are_closed(): void {
		$abilities = new PromoterVenueGrantAbilities();
		$abilities->register();
		$registered = $this->registered_abilities();
		$this->assertCount( 4, $registered );
		foreach ( $registered as $definition ) {
			$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			$this->assertClosedSchema( $definition['output_schema'] );
		}
	}

	public function test_ability_object_schemas_are_closed(): void {
		$abilities = new PromoterAuthorityAbilities();
		$abilities->register();
		$registered = $this->registered_abilities();
		$this->assertCount( 6, $registered );
		foreach ( $registered as $definition ) {
			$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			$this->assertClosedSchema( $definition['output_schema'] );
		}
		$this->assertSame( PromoterAuthorityRepository::MAX_MEMBERS, $registered['extrachill/list-promoter-memberships']['output_schema']['maxItems'] );
		$provider = file_get_contents( dirname( __DIR__ ) . '/inc/Providers/AbilitiesProvider.php' );
		$this->assertStringContainsString( 'BookingSchema::is_ready()', $provider );
		$this->assertStringContainsString( 'PromoterAuthoritySchema::is_ready()', $provider );
		$this->assertStringNotContainsString( 'BookingSchema::is_ready() && \\ExtraChillEvents\\Core\\PromoterAuthoritySchema::is_ready()', $provider );
	}

	private function registered_abilities(): array {
		if ( ! empty( $GLOBALS['promoter_test']['abilities'] ) ) {
			return $GLOBALS['promoter_test']['abilities'];
		}
		return $GLOBALS['venue_membership_test']['abilities'] ?? array();
	}

	private function bootstrap_promoter_grant_fixtures(): void {
		$promoters = new PromoterAuthorityService();
		$promoters->verify( 1, 100, 2 );
		$promoters->create_membership( 2, 100, 3, false );
		$promoters->verify( 1, 102, 6 );
		$this->seed_venue_owner( 200, 4 );
		$this->seed_venue_owner( 201, 5 );
	}

	private function seed_venue_owner( int $venue_term_id, int $user_id ): void {
		$table                             = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->rows[ $table ][] = $this->venue_membership_row( 1000 + $venue_term_id, $venue_term_id, $user_id );
	}

	private function venue_membership_row( int $id, int $venue_term_id, int $user_id ): array {
		return array(
			'id'                 => $id,
			'venue_term_id'      => $venue_term_id,
			'user_id'            => $user_id,
			'is_owner'           => 1,
			'status'             => VenueAuthorization::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => $user_id,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_at'         => null,
		);
	}

	private function promoter_membership_row( int $id, int $promoter_term_id, int $user_id ): array {
		return array(
			'id'                 => $id,
			'promoter_term_id'   => $promoter_term_id,
			'user_id'            => $user_id,
			'is_owner'           => 0,
			'status'             => PromoterAuthorityRepository::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => 2,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_by_user_id' => null,
			'revoked_at'         => null,
		);
	}

	private function assertClosedSchema( array $schema ): void {
		if ( 'array' === ( $schema['type'] ?? '' ) ) {
			$this->assertClosedSchema( $schema['items'] );
			return;
		}
		$this->assertSame( 'object', $schema['type'] );
		$this->assertFalse( $schema['additionalProperties'] );
		foreach ( $schema['properties'] as $property ) {
			if ( 'object' === ( $property['type'] ?? '' ) ) {
				$this->assertClosedSchema( $property );
			}
		}
	}
}
