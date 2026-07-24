<?php
/**
 * Venue membership and authorization tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VenueMembershipAbilities;
use ExtraChillEvents\Abilities\VenueBookingConfigAbilities;
use ExtraChillEvents\Abilities\VenueProfileAbilities;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Core\VenueMembershipRepository;
use ExtraChillEvents\Core\VenueMembershipService;
use ExtraChillEvents\Core\VenueInvitationDeliveryWorker;
use ExtraChillEvents\Core\VenueOnboardingRepository;
use ExtraChillEvents\Core\VenueOnboardingService;
use ExtraChillEvents\Core\VenueProfile;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
		}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
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
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID;
		public $user_email;
		public $user_login;
		public $display_name;
		public function __construct( $id, $email = '' ) {
			$this->ID           = (int) $id;
			$this->user_email   = $email;
			$this->user_login   = 'user' . $id;
			$this->display_name = 'User ' . $id;
		}
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
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $value ) {
		return filter_var( trim( (string) $value ), FILTER_SANITIZE_EMAIL ); }
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $value ) {
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ); }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12 ) {
		return str_repeat( 'x', $length ); }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		static $sequence = 0;
		++$sequence;
		return sprintf( '00000000-0000-4000-8000-%012d', $sequence );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'venue-test-' . $scheme;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $value ) {
		return strip_tags( (string) $value, '<p><br><strong><em><a>' ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value, $protocols = null ) {
		unset( $protocols );
		return filter_var( trim( (string) $value ), FILTER_VALIDATE_URL ) ? trim( (string) $value ) : ''; }
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return $GLOBALS['venue_membership_test']['current_blog_id']; }
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $site ) {
		return 'events' === $site ? 7 : 0; }
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		$term = $GLOBALS['venue_membership_test']['terms'][ $term_id ] ?? null;
		return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return $GLOBALS['venue_membership_test']['users'][ $user_id ] ?? false;
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		if ( 'email' !== $field ) {
			return false;
		}
		foreach ( $GLOBALS['venue_membership_test']['users'] as $user ) {
			if ( strtolower( $user->user_email ) === strtolower( $value ) ) {
				return $user;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['venue_membership_test']['user_meta'][ $user_id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		if ( 'ec_venue_invitation_account' === $key && ! empty( $GLOBALS['venue_membership_test']['fail_provenance'] ) ) {
			return false;
		}
		$GLOBALS['venue_membership_test']['user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return $GLOBALS['venue_membership_test']['ability_objects'][ $name ] ?? null; }
}
if ( ! function_exists( 'get_password_reset_key' ) ) {
	function get_password_reset_key( $user ) {
		++$GLOBALS['venue_membership_test']['reset_key_calls'];
		if ( ! empty( $GLOBALS['venue_membership_test']['fail_reset_key'] ) ) {
			return new WP_Error( 'reset_key_failed' );
		}
		return 'reset-' . $user->ID; }
}
if ( ! function_exists( 'ec_get_site_url' ) ) {
	function ec_get_site_url( $site ) {
		return 'https://' . $site . '.extrachill.test'; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/' ) . '/'; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://events.extrachill.test/wp-admin/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		return $url . '?' . http_build_query( $args ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $value ) {
		return esc_html( $value ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return (string) $value; }
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook ) {
		return $hook === ( $GLOBALS['venue_membership_test']['doing_action'] ?? '' );
	}
}
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( $hook, array $args = array(), $group = '', $unique = false ) {
		$GLOBALS['venue_membership_test']['scheduled_actions'][] = compact( 'hook', 'args', 'group', 'unique' );
		return empty( $GLOBALS['venue_membership_test']['fail_schedule'] ) ? count( $GLOBALS['venue_membership_test']['scheduled_actions'] ) : 0;
	}
}
if ( ! function_exists( 'ec_send_email' ) ) {
	function ec_send_email( array $args ) {
		$GLOBALS['venue_membership_test']['sent_emails'][] = $args;
		if ( ! doing_action( 'action_scheduler_run_queue' ) ) {
			$GLOBALS['venue_membership_test']['permission_boundary_failures'][] = $args;
			return array( 'success' => false );
		}
		if ( ! empty( $GLOBALS['venue_membership_test']['fail_delivery'] ) ) {
			return array( 'success' => false );
		}
		return array( 'success' => true );
	}
}
if ( ! function_exists( 'extrachill_users_rollback_created_user' ) ) {
	function extrachill_users_rollback_created_user( $user_id ) {
		$GLOBALS['venue_membership_test']['rollback_attempts'][] = (int) $user_id;
		if ( ! empty( $GLOBALS['venue_membership_test']['fail_account_rollback'] ) ) {
			return false;
		}
		unset( $GLOBALS['venue_membership_test']['users'][ $user_id ], $GLOBALS['venue_membership_test']['user_meta'][ $user_id ] );
		return true;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability ) {
		if ( 'manage_options' === $capability ) {
			return ! empty( $GLOBALS['venue_membership_test']['administrators'][ $user_id ] );
		}
		return VenueAuthorization::ACCESS_CAPABILITY === $capability && ! empty( $GLOBALS['venue_membership_test']['team_access'][ $user_id ] );
	}
}
if ( ! function_exists( 'ec_feature_available' ) ) {
	function ec_feature_available( $feature, $user_id = null ) {
		unset( $user_id );
		return VenueAuthorization::FEATURE === $feature && ! empty( $GLOBALS['venue_membership_test']['feature_available'] );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['venue_membership_test']['current_user_id']; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback ) {
		$GLOBALS['venue_membership_test']['actions'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		$GLOBALS['venue_membership_test']['abilities'][ $name ] = $definition; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['venue_membership_test']['options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['venue_membership_test']['term_meta'][ $term_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'data_machine_events_get_venue_profile' ) ) {
	function data_machine_events_get_venue_profile( $term_id ) {
		unset( $term_id );
		return $GLOBALS['venue_membership_test']['dme_profile'];
	}
}
if ( ! function_exists( 'data_machine_events_update_venue_profile' ) ) {
	function data_machine_events_update_venue_profile( $term_id, $changes, $expected_revision ) {
		$callback = $GLOBALS['venue_membership_test']['dme_update'];
		return $callback( $term_id, $changes, $expected_revision );
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['venue_membership_test']['term_meta'][ $term_id ][ $key ] = $value;
		return 1;
	}
}
if ( ! function_exists( 'delete_term_meta' ) ) {
	function delete_term_meta( $term_id, $key ) {
		if ( ! isset( $GLOBALS['venue_membership_test']['term_meta'][ $term_id ][ $key ] ) ) {
			return false;
		}
		unset( $GLOBALS['venue_membership_test']['term_meta'][ $term_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'add_term_meta' ) ) {
	function add_term_meta( $term_id, $key, $value, $unique = false ) {
		unset( $unique );
		if ( VenueProfile::HISTORY_META_KEY === $key && ! empty( $GLOBALS['venue_membership_test']['fail_profile_audit'] ) ) {
			return false;
		}
		$GLOBALS['venue_membership_test']['term_history'][ $term_id ][ $key ][] = $value;
		return count( $GLOBALS['venue_membership_test']['term_history'][ $term_id ][ $key ] );
	}
}
if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( $term_id, $taxonomy, $updates ) {
		$term = $GLOBALS['venue_membership_test']['terms'][ $term_id ] ?? null;
		if ( ! $term || $taxonomy !== $term->taxonomy ) {
			return new WP_Error( 'invalid_term' );
		}
		foreach ( array( 'name', 'description' ) as $field ) {
			if ( array_key_exists( $field, $updates ) ) {
				$term->{$field} = $updates[ $field ];
			}
		}
		return array( 'term_id' => $term_id );
	}
}
if ( ! function_exists( 'clean_term_cache' ) ) {
	function clean_term_cache( $term_id, $taxonomy = '' ) {
		$GLOBALS['venue_membership_test']['term_cache_deletes'][] = array( $term_id, $taxonomy );
	}
}
if ( ! function_exists( 'extrachill_events_invalidate_location_venue_cache' ) ) {
	function extrachill_events_invalidate_location_venue_cache( int $term_id ) {
		$GLOBALS['venue_membership_test']['location_cache_deletes'][] = $term_id;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['venue_membership_test']['fired_actions'][ $hook ][] = $args;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['venue_membership_test']['cache_deletes'][] = array( $key, $group );
		return true;
	}
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $value ) {
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
	}
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[aObis]:/', $value ) ) {
			return $value;
		}
		return unserialize( $value );
	}
}

/** Minimal membership wpdb fake with transaction and optimistic update support. */
final class VenueMembershipWpdb {
	public $prefix                = 'wp_7_';
	public $users                 = 'wp_users';
	public $terms                 = 'wp_7_terms';
	public $term_taxonomy         = 'wp_7_term_taxonomy';
	public $termmeta              = 'wp_7_termmeta';
	public $insert_id             = 0;
	public $last_error            = '';
	public $rows                  = array();
	public $race_insert           = false;
	public $fail_invitation_insert = false;
	public $after_start           = null;
	public $before_list           = null;
	private $snapshot             = null;
	private $meta_snapshot        = null;
	private $history_snapshot     = null;
	private $terms_snapshot       = null;
	private $meta_values          = array();
	private $meta_values_snapshot = array();
	private $meta_records         = array();
	private $meta_records_snapshot = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $match ) use ( &$args, &$i ) {
				$value = $args[ $i++ ];
				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function insert( $table, $row ) {
		$this->last_error = '';
		if ( $this->fail_invitation_insert && 'wp_7_ec_venue_invitations' === $table ) {
			$this->last_error = 'simulated invitation write failure';
			return false;
		}
		if ( $this->termmeta === $table ) {
			if ( VenueBookingConfig::META_KEY === $row['meta_key'] && ! empty( $GLOBALS['venue_membership_test']['fail_config_save'] ) ) {
				$this->last_error = 'simulated config write failure';
				return false;
			}
			if ( VenueBookingConfig::HISTORY_META_KEY === $row['meta_key'] && ! empty( $GLOBALS['venue_membership_test']['fail_config_audit'] ) ) {
				$this->last_error = 'simulated config audit failure';
				return false;
			}
			if ( VenueProfile::HISTORY_META_KEY === $row['meta_key'] && ! empty( $GLOBALS['venue_membership_test']['fail_profile_audit'] ) ) {
				$this->last_error = 'simulated profile audit failure';
				return false;
			}
			$this->insert_id                       = max( 101, $this->insert_id + 1 );
			$this->meta_values[ $this->insert_id ] = $row['meta_value'];
			$this->meta_records[ $this->insert_id ] = array(
				'term_id'  => (int) $row['term_id'],
				'meta_key' => $row['meta_key'],
			);
			$value                                 = maybe_unserialize( $row['meta_value'] );
			if ( in_array( $row['meta_key'], array( VenueBookingConfig::HISTORY_META_KEY, VenueProfile::HISTORY_META_KEY ), true ) ) {
				$GLOBALS['venue_membership_test']['term_history'][ $row['term_id'] ][ $row['meta_key'] ][] = $value;
			} else {
				$GLOBALS['venue_membership_test']['term_meta'][ $row['term_id'] ][ $row['meta_key'] ] = $value;
			}
			return 1;
		}
		foreach ( $this->rows[ $table ] ?? array() as $existing ) {
			$user_field = isset( $row['claimant_user_id'] ) ? 'claimant_user_id' : ( isset( $row['user_id'] ) ? 'user_id' : '' );
			if ( $user_field && (int) $existing['venue_term_id'] === (int) $row['venue_term_id'] && (int) $existing[ $user_field ] === (int) $row[ $user_field ] ) {
				$this->last_error = 'duplicate venue user';
				return false;
			}
		}
		if ( $this->race_insert ) {
			$this->race_insert = false;
			$this->store( $table, array_merge( $row, array( 'is_owner' => 0 ) ) );
			$this->snapshot   = $this->rows;
			$this->last_error = 'simulated concurrent duplicate';
			return false;
		}
		$this->store( $table, $row );
		return 1;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		$this->last_error = '';
		if ( preg_match( "/SELECT meta_id, meta_value FROM .*term_id = (\d+) AND meta_key = '([^']+)'/", $query, $meta_match ) ) {
			$value = $GLOBALS['venue_membership_test']['term_meta'][ (int) $meta_match[1] ][ stripslashes( $meta_match[2] ) ] ?? null;
			if ( null === $value ) {
				return null;
			}
			$this->meta_values[100] = maybe_serialize( $value );
			$this->meta_records[100] = array(
				'term_id'  => (int) $meta_match[1],
				'meta_key' => stripslashes( $meta_match[2] ),
			);
			return array(
				'meta_id'    => 100,
				'meta_value' => $this->meta_values[100],
			);
		}
		if ( false !== strpos( $query, 'ec_venue_claims' ) ) {
			return $this->find_onboarding_row( 'wp_7_ec_venue_claims', $query );
		}
		if ( false !== strpos( $query, 'ec_venue_invitations' ) ) {
			return $this->find_onboarding_row( 'wp_7_ec_venue_invitations', $query );
		}
		if ( ! preg_match( '/venue_term_id = (\d+) AND user_id = (\d+)/', $query, $match ) ) {
			return null;
		}
		foreach ( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() as $row ) {
			if ( (int) $row['venue_term_id'] === (int) $match[1] && (int) $row['user_id'] === (int) $match[2] ) {
				return $row;
			}
		}
		return null;
	}

	public function get_var( $query ) {
		$this->last_error = '';
		if ( preg_match( '/SELECT ID FROM .* WHERE ID = (\d+) FOR UPDATE/', $query, $user_match ) ) {
			return isset( $GLOBALS['venue_membership_test']['users'][ (int) $user_match[1] ] ) ? (int) $user_match[1] : null;
		}
		if ( preg_match( '/SELECT COUNT\(\*\) FROM .*user_id = (\d+) AND NOT \(venue_term_id = (\d+) AND status = \'([^\']+)\'\)/', $query, $count_match ) ) {
			$count = 0;
			foreach ( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() as $row ) {
				if ( (int) $row['user_id'] === (int) $count_match[1] && ( (int) $row['venue_term_id'] !== (int) $count_match[2] || $row['status'] !== stripslashes( $count_match[3] ) ) ) {
					++$count;
				}
			}
			return $count;
		}
		if ( preg_match( '/SELECT meta_value FROM .*meta_id = (\d+) FOR UPDATE/', $query, $meta_match ) ) {
			return $this->meta_values[ (int) $meta_match[1] ] ?? null;
		}
		if ( preg_match( '/SELECT id FROM .*venue_term_id = (\d+) AND user_id = (\d+) FOR UPDATE/', $query, $match ) ) {
			foreach ( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $match[1] && (int) $row['user_id'] === (int) $match[2] ) {
					return $row['id'];
				}
			}
			return null;
		}
		if ( preg_match( '/SELECT term_id FROM .*term_id = (\d+) FOR UPDATE/', $query, $match ) ) {
			return isset( $GLOBALS['venue_membership_test']['terms'][ (int) $match[1] ] ) ? (int) $match[1] : null;
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		$this->last_error = '';
		if ( false !== strpos( $query, 'ec_venue_invitations' ) ) {
			$rows = array_values( $this->rows['wp_7_ec_venue_invitations'] ?? array() );
			if ( preg_match( '/user_id = (\d+)/', $query, $user_match ) ) {
				$rows = $this->filter_rows( $rows, 'user_id', (int) $user_match[1] );
			}
			if ( preg_match( '/venue_term_id = (\d+)/', $query, $match ) ) {
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $match ) {
							return (int) $row['venue_term_id'] === (int) $match[1];
						}
					)
				);
			}
			return $rows;
		}
		if ( false !== strpos( $query, 'ec_venue_claims' ) ) {
			$rows = array_values( $this->rows['wp_7_ec_venue_claims'] ?? array() );
			if ( preg_match( '/claimant_user_id = (\d+)/', $query, $claimant ) ) {
				$rows = $this->filter_rows( $rows, 'claimant_user_id', (int) $claimant[1] );
			}
			return $rows;
		}
		if ( false !== strpos( $query, 'SELECT member.*' ) && is_callable( $this->before_list ) ) {
			$callback          = $this->before_list;
			$this->before_list = null;
			$callback( $this );
		}
		$rows = array_values( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() );
		if ( preg_match( '/WHERE user_id = (\d+)/', $query, $user_match ) ) {
			$rows = $this->filter_rows( $rows, 'user_id', (int) $user_match[1] );
		}
		if ( preg_match( '/AS actor .*actor.user_id = (\d+)/', $query, $actor ) ) {
			$authorized = false;
			preg_match( '/member\.venue_term_id = (\d+)/', $query, $requested_venue );
			foreach ( $rows as $row ) {
				if ( (int) $row['user_id'] === (int) $actor[1] && (int) $row['venue_term_id'] === (int) ( $requested_venue[1] ?? 0 ) && ! empty( $row['is_owner'] ) && VenueAuthorization::STATUS_ACTIVE === $row['status'] ) {
					$authorized = true;
					break;
				}
			}
			if ( ! $authorized ) {
				return array();
			}
		}
		if ( preg_match( '/venue_term_id = (\d+)/', $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $match ) {
						return (int) $row['venue_term_id'] === (int) $match[1];
					}
				)
			);
		}
		foreach ( array( 'status' ) as $field ) {
			if ( preg_match( "/member\.{$field} = '([^']+)'/", $query, $match ) ) {
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $field, $match ) {
							return $row[ $field ] === stripslashes( $match[1] );
						}
					)
				);
			}
		}
		if ( preg_match( '/member\.is_owner = ([01])/', $query, $owner_match ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $owner_match ) {
						return (int) $row['is_owner'] === (int) $owner_match[1];
					}
				)
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['id'] <=> $b['id'];
			}
		);
		if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $page ) ) {
			$rows = array_slice( $rows, (int) $page[2], (int) $page[1] );
		}
		return $rows;
	}

	public function query( $query ) {
		$this->last_error = '';
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot             = $this->rows;
			$this->meta_snapshot        = $GLOBALS['venue_membership_test']['term_meta'];
			$this->history_snapshot     = $GLOBALS['venue_membership_test']['term_history'];
			$this->terms_snapshot       = unserialize( serialize( $GLOBALS['venue_membership_test']['terms'] ) );
			$this->meta_values_snapshot = $this->meta_values;
			$this->meta_records_snapshot = $this->meta_records;
			if ( is_callable( $this->after_start ) ) {
				$callback          = $this->after_start;
				$this->after_start = null;
				$callback( $this );
				$this->snapshot             = $this->rows;
				$this->meta_snapshot        = $GLOBALS['venue_membership_test']['term_meta'];
				$this->history_snapshot     = $GLOBALS['venue_membership_test']['term_history'];
				$this->terms_snapshot       = unserialize( serialize( $GLOBALS['venue_membership_test']['terms'] ) );
				$this->meta_values_snapshot = $this->meta_values;
				$this->meta_records_snapshot = $this->meta_records;
			}
			return 1;
		}
		if ( 'ROLLBACK' === $query ) {
			$this->rows                                       = $this->snapshot;
			$GLOBALS['venue_membership_test']['term_meta']    = $this->meta_snapshot;
			$GLOBALS['venue_membership_test']['term_history'] = $this->history_snapshot;
			$GLOBALS['venue_membership_test']['terms']        = $this->terms_snapshot;
			$this->meta_values                                = $this->meta_values_snapshot;
			$this->meta_records                               = $this->meta_records_snapshot;
			$this->snapshot                                   = null;
			$this->meta_snapshot                              = null;
			$this->history_snapshot                           = null;
			$this->terms_snapshot                             = null;
			$this->meta_values_snapshot                       = array();
			$this->meta_records_snapshot                      = array();
			return 1;
		}
		if ( 'COMMIT' === $query ) {
			$this->snapshot             = null;
			$this->meta_snapshot        = null;
			$this->history_snapshot     = null;
			$this->terms_snapshot       = null;
			$this->meta_values_snapshot = array();
			$this->meta_records_snapshot = array();
			return 1;
		}
		if ( preg_match( '/UPDATE (wp_7_ec_venue_(?:claims|invitations)) SET .* WHERE id = (\d+) AND version = (\d+)/', $query, $onboarding ) ) {
			$table = $onboarding[1];
			$id    = (int) $onboarding[2];
			if ( ! isset( $this->rows[ $table ][ $id ] ) || (int) $this->rows[ $table ][ $id ]['version'] !== (int) $onboarding[3] ) {
				return 0;
			}
			$this->apply_assignments( $table, $id, $query );
			return 1;
		}
		if ( ! preg_match( '/WHERE venue_term_id = (\d+) AND user_id = (\d+) AND version = (\d+)/', $query, $match ) ) {
			return false;
		}
		$table = $this->prefix . 'ec_venue_members';
		foreach ( $this->rows[ $table ] ?? array() as $id => $row ) {
			if ( (int) $row['venue_term_id'] !== (int) $match[1] || (int) $row['user_id'] !== (int) $match[2] || (int) $row['version'] !== (int) $match[3] ) {
				continue;
			}
			$this->apply_assignments( $table, $id, $query );
			return 1;
		}
		return 0;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $format, $where_format );
		$this->last_error = '';
		if ( $this->prefix . 'ec_venue_members' === $table ) {
			foreach ( $this->rows[ $table ] ?? array() as $id => $row ) {
				if ( (int) $row['venue_term_id'] === (int) $where['venue_term_id'] && (int) $row['user_id'] === (int) $where['user_id'] && (int) $row['version'] === (int) $where['version'] ) {
					$this->rows[ $table ][ $id ] = array_merge( $row, $data );
					return 1;
				}
			}
			return 0;
		}
		if ( $this->terms === $table && isset( $where['term_id'], $data['name'] ) ) {
			$GLOBALS['venue_membership_test']['terms'][ (int) $where['term_id'] ]->name = $data['name'];
			return 1;
		}
		if ( $this->term_taxonomy === $table && isset( $where['term_id'], $data['description'] ) ) {
			$GLOBALS['venue_membership_test']['terms'][ (int) $where['term_id'] ]->description = $data['description'];
			return 1;
		}
		if ( $this->termmeta !== $table || ! isset( $where['meta_id'], $data['meta_value'] ) ) {
			return false;
		}
		$meta_id = (int) $where['meta_id'];
		$record  = $this->meta_records[ $meta_id ] ?? null;
		if ( ! is_array( $record ) ) {
			return false;
		}
		if ( ! empty( $GLOBALS['venue_membership_test']['fail_config_save'] ) ) {
			$this->last_error = 'simulated config write failure';
			return false;
		}
		$this->meta_values[ $meta_id ] = $data['meta_value'];
		$GLOBALS['venue_membership_test']['term_meta'][ $record['term_id'] ][ $record['meta_key'] ] = maybe_unserialize( $data['meta_value'] );
		return 1;
	}

	public function delete( $table, $where, $where_format = null ) {
		unset( $where_format );
		$this->last_error = '';
		$meta_id          = (int) ( $where['meta_id'] ?? 0 );
		$record           = $this->meta_records[ $meta_id ] ?? null;
		if ( $this->termmeta !== $table || ! is_array( $record ) ) {
			return false;
		}
		unset( $GLOBALS['venue_membership_test']['term_meta'][ $record['term_id'] ][ $record['meta_key'] ] );
		unset( $this->meta_values[ $meta_id ], $this->meta_records[ $meta_id ] );
		return 1;
	}

	private function store( $table, $row ): void {
		$this->insert_id                          = count( $this->rows[ $table ] ?? array() ) + 1;
		$row['id']                                = $this->insert_id;
		$this->rows[ $table ][ $this->insert_id ] = $row;
	}

	private function filter_rows( array $rows, string $field, int $value ): array {
		return array_values( array_filter( $rows, static function ( $row ) use ( $field, $value ) {
			return (int) $row[ $field ] === $value;
		} ) );
	}

	private function find_onboarding_row( string $table, string $query ) {
		foreach ( $this->rows[ $table ] ?? array() as $row ) {
			if ( preg_match( '/WHERE id = (\d+)/', $query, $id ) && (int) $row['id'] === (int) $id[1] ) {
				return $row;
			}
			if ( preg_match( "/WHERE public_id = '([^']+)'/", $query, $public ) && $row['public_id'] === stripslashes( $public[1] ) ) {
				return $row;
			}
			if ( preg_match( "/WHERE delivery_id = '([^']+)'/", $query, $delivery ) && ( $row['delivery_id'] ?? '' ) === stripslashes( $delivery[1] ) ) {
				return $row;
			}
			if ( preg_match( '/venue_term_id = (\d+) AND claimant_user_id = (\d+)/', $query, $claim ) && (int) $row['venue_term_id'] === (int) $claim[1] && (int) $row['claimant_user_id'] === (int) $claim[2] ) {
				return $row;
			}
			if ( preg_match( '/venue_term_id = (\d+) AND user_id = (\d+)/', $query, $invitation ) && (int) $row['venue_term_id'] === (int) $invitation[1] && (int) $row['user_id'] === (int) $invitation[2] ) {
				return $row;
			}
		}
		return null;
	}

	private function apply_assignments( string $table, int $id, string $query ): void {
		$set = substr( $query, strpos( $query, ' SET ' ) + 5, strpos( $query, ' WHERE ' ) - strpos( $query, ' SET ' ) - 5 );
		preg_match_all( "/([a-z_]+) = (version \\+ 1|NULL|[01]|'(?:\\\\.|[^'])*')(?=, [a-z_]+ = |$)/", $set, $assignments, PREG_SET_ORDER );
		foreach ( $assignments as $assignment ) {
			if ( 'version + 1' === $assignment[2] ) {
				++$this->rows[ $table ][ $id ]['version'];
			} elseif ( 'NULL' === $assignment[2] ) {
				$this->rows[ $table ][ $id ][ $assignment[1] ] = null;
			} elseif ( "'" === $assignment[2][0] ) {
				$this->rows[ $table ][ $id ][ $assignment[1] ] = stripslashes( substr( $assignment[2], 1, -1 ) );
			} else {
				$this->rows[ $table ][ $id ][ $assignment[1] ] = (int) $assignment[2];
			}
		}
		if ( false !== strpos( $set, 'delivery_attempts = delivery_attempts + 1' ) ) {
			++$this->rows[ $table ][ $id ]['delivery_attempts'];
		}
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueAuthorization.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueMembershipService.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueInvitationToken.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueOnboardingRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueOnboardingService.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueInvitationDeliveryWorker.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueBookingConfig.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueProfile.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VenueMembershipAbilities.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VenueBookingConfigAbilities.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VenueProfileAbilities.php';

/**
 * Venue membership and profile composition coverage uses isolated WP doubles.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class VenueMembershipAuthorizationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['venue_membership_test'] = array(
			'terms'             => array(
				55 => (object) array(
					'term_id'     => 55,
					'taxonomy'    => 'venue',
					'name'        => 'The Royal American',
					'description' => 'Neighborhood venue.',
				),
				56 => (object) array(
					'term_id'     => 56,
					'taxonomy'    => 'venue',
					'name'        => 'Music Farm',
					'description' => '',
				),
				57 => (object) array(
					'term_id'  => 57,
					'taxonomy' => 'artist',
				),
			),
			'users'             => array(
				1 => new WP_User( 1, 'admin@example.com' ),
				2 => new WP_User( 2, 'owner@example.com' ),
				3 => new WP_User( 3, 'member3@example.com' ),
				4 => new WP_User( 4, 'member4@example.com' ),
				5 => new WP_User( 5, 'member5@example.com' ),
				6 => new WP_User( 6, 'member6@example.com' ),
				7 => new WP_User( 7, 'existing@example.com' ),
			),
			'administrators'    => array( 1 => true ),
			'team_access'       => array(
				2 => true,
				3 => true,
				4 => true,
				5 => true,
				6 => true,
				7 => true,
			),
			'feature_available' => true,
			'current_user_id'   => 1,
			'current_blog_id'   => 7,
			'actions'           => array(),
			'abilities'         => array(),
			'options'           => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
			'term_meta'         => array(),
			'term_history'      => array(),
			'fired_actions'     => array(),
			'cache_deletes'     => array(),
			'term_cache_deletes' => array(),
			'location_cache_deletes' => array(),
			'fail_config_save'  => false,
			'fail_config_audit' => false,
			'user_meta'         => array(),
			'ability_objects'   => array(),
			'scheduled_actions' => array(),
			'sent_emails'       => array(),
			'permission_boundary_failures' => array(),
			'doing_action'      => '',
			'rollback_attempts' => array(),
			'reset_key_calls'   => 0,
			'fail_reset_key'    => false,
			'fail_delivery'     => false,
			'fail_schedule'     => false,
			'fail_provenance'   => false,
			'fail_account_rollback' => false,
			'fail_profile_state' => false,
			'fail_profile_audit' => false,
			'dme_profile'        => array(
				'term_id'     => 55,
				'name'        => 'The Royal American',
				'description' => 'Neighborhood venue.',
				'address'     => '970 Morrison Drive',
				'city'        => 'Charleston',
				'state'       => 'SC',
				'zip'         => '29403',
				'country'     => 'US',
				'phone'       => '843-555-1212',
				'website'     => 'https://theroyalamerican.com',
				'capacity'    => '300',
				'revision'    => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			),
			'dme_update'         => static function () {
				return new WP_Error( 'unexpected_dme_update' );
			},
		);
		$GLOBALS['wpdb']                  = new VenueMembershipWpdb();
	}

	private function create_member( int $venue, int $user, bool $is_owner, string $status = VenueAuthorization::STATUS_ACTIVE, int $creator = 1 ) {
		return ( new VenueMembershipRepository() )->create(
			array(
				'venue_term_id'      => $venue,
				'user_id'            => $user,
				'is_owner'           => $is_owner,
				'status'             => $status,
				'created_by_user_id' => $creator,
			)
		);
	}

	private function email_hash( string $email ): string {
		return hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
	}

	private function create_prepared_invitation( VenueOnboardingRepository $repository, int $actor, int $venue, int $user, bool $is_owner, string $email, bool $account_created = false ): array {
		$created = $repository->create_invitation( $actor, $venue, $user, $is_owner, $this->email_hash( $email ), $account_created );
		return $repository->prepare_delivery( $created['_delivery_id'], 3600 );
	}

	private function install_account_creator(): void {
		$GLOBALS['venue_membership_test']['account_create_calls'] = 0;
		$GLOBALS['venue_membership_test']['ability_objects']['extrachill/create-user'] = new class() {
			public function execute( array $input ) {
				++$GLOBALS['venue_membership_test']['account_create_calls'];
				$user_id = max( array_keys( $GLOBALS['venue_membership_test']['users'] ) ) + 1;
				$GLOBALS['venue_membership_test']['users'][ $user_id ] = new WP_User( $user_id, $input['email'] );
				$GLOBALS['venue_membership_test']['user_meta'][ $user_id ] = array(
					'ec_unclaimed'       => '1',
					'registration_source' => $input['registration_source'],
				);
				$GLOBALS['venue_membership_test']['created_account_input'] = $input;
				return $user_id;
			}
		};
	}

	public function test_capability_scope_and_inactive_statuses_fail_closed(): void {
		$authorization = new VenueAuthorization();
		$this->create_member( 55, 3, true );
		$this->create_member( 55, 2, false );
		$this->create_member( 56, 4, true, VenueAuthorization::STATUS_INVITED );
		$this->create_member( 56, 5, true, VenueAuthorization::STATUS_REVOKED );
		$this->create_member( 56, 6, true );

		$this->assertTrue( $authorization->can( 2, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertFalse( $authorization->can( 2, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertTrue( $authorization->can( 3, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertFalse( $authorization->can( 4, 56, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertFalse( $authorization->can( 5, 56, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$locked = array_values( $GLOBALS['wpdb']->rows['wp_7_ec_venue_members'] );
		$this->assertSame( 'venue_action_forbidden', $authorization->authorize_locked( 4, 56, VenueAuthorization::ACTION_ACCESS_VENUE, $locked )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', $authorization->authorize_locked( 5, 56, VenueAuthorization::ACTION_ACCESS_VENUE, $locked )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', $authorization->authorize_locked( 4, 56, VenueAuthorization::ACTION_MANAGE_MEMBERS, $locked )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', $authorization->authorize_locked( 5, 56, VenueAuthorization::ACTION_MANAGE_MEMBERS, $locked )->get_error_code() );
		$this->assertTrue( $authorization->authorize_locked( 6, 56, VenueAuthorization::ACTION_MANAGE_MEMBERS, $locked ) );

		unset( $GLOBALS['venue_membership_test']['team_access'][6] );
		$this->assertFalse( $authorization->can( 6, 56, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertTrue( $authorization->can( 6, 56, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$GLOBALS['venue_membership_test']['feature_available'] = false;
		$this->assertFalse( $authorization->can( 3, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertTrue( $authorization->can( 3, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
	}

	public function test_administrator_override_validates_venue_and_cross_venue_access_is_denied(): void {
		$authorization = new VenueAuthorization();
		$this->assertTrue( $authorization->can( 1, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertFalse( $authorization->can( 1, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertSame( 'invalid_venue_membership_venue', $authorization->authorize( 1, 999, VenueAuthorization::ACTION_MANAGE_MEMBERS )->get_error_code() );
		$this->assertSame( 'invalid_venue_membership_venue', $authorization->authorize( 1, 57, VenueAuthorization::ACTION_MANAGE_MEMBERS )->get_error_code() );

		$this->create_member( 55, 2, true );
		$this->assertTrue( $authorization->can( 2, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertFalse( $authorization->can( 2, 56, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
	}

	public function test_admin_bootstraps_owner_and_only_owner_can_manage_exact_venue(): void {
		$service = new VenueMembershipService();
		$this->assertSame( 'venue_membership_owner_required', $service->create( 1, 55, 3, false )->get_error_code() );
		$owner = $service->create( 1, 55, 2, true );
		$this->assertTrue( $owner['is_owner'] );
		$this->assertSame( 1, $owner['created_by_user_id'] );

		$member = $service->create( 2, 55, 3, false );
		$this->assertFalse( $member['is_owner'] );
		$this->assertSame( 'venue_action_forbidden', $service->create( 3, 55, 4, false )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', $service->create( 2, 56, 4, false )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', $service->create( 4, 56, 4, true )->get_error_code() );
	}

	public function test_unique_relationship_and_concurrent_create_conflicts_are_explicit(): void {
		$repository = new VenueMembershipRepository();
		$first      = $this->create_member( 55, 2, true );
		$duplicate  = $this->create_member( 55, 2, false );
		$this->assertSame( 'venue_membership_exists', $duplicate->get_error_code() );
		$this->assertSame( $first['version'], $duplicate->get_error_data()['current_version'] );

		$same_user_other_venue = $this->create_member( 56, 2, true );
		$this->assertSame( 56, $same_user_other_venue['venue_term_id'] );

		$GLOBALS['wpdb']->race_insert = true;
		$race                         = $repository->create(
			array(
				'venue_term_id'      => 55,
				'user_id'            => 3,
				'is_owner'           => false,
				'created_by_user_id' => 1,
			)
		);
		$this->assertSame( 'venue_membership_exists', $race->get_error_code() );
		$this->assertFalse( $repository->get( 55, 3 )['is_owner'] );
	}

	public function test_optimistic_updates_and_last_owner_invariant(): void {
		$repository = new VenueMembershipRepository();
		$owner      = $this->create_member( 55, 2, true );
		$this->assertSame( 'venue_membership_last_owner', $repository->update_owner( 55, 2, false, $owner['version'], 1 )->get_error_code() );
		$this->assertSame( 'venue_membership_last_owner', $repository->revoke( 55, 2, $owner['version'], 1 )->get_error_code() );

		$second = $this->create_member( 55, 3, true );
		$member = $repository->update_owner( 55, 2, false, $owner['version'], 1 );
		$this->assertFalse( $member['is_owner'] );
		$this->assertSame( 2, $member['version'] );
		$this->assertSame( 'venue_membership_version_conflict', $repository->update_owner( 55, 2, true, 1, 1 )->get_error_code() );

		$this->create_member( 55, 4, true );
		$revoked = $repository->revoke( 55, 3, $second['version'], 1 );
		$this->assertSame( VenueAuthorization::STATUS_REVOKED, $revoked['status'] );
		$this->assertNotNull( $revoked['revoked_at'] );
		$this->assertFalse( ( new VenueAuthorization( $repository ) )->can( 3, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
	}

	public function test_actor_authority_is_rechecked_under_the_venue_lock(): void {
		$service = new VenueMembershipService();
		$owner   = $service->create( 1, 55, 2, true );
		$this->assertSame( VenueAuthorization::STATUS_ACTIVE, $owner['status'] );

		$GLOBALS['wpdb']->after_start = static function ( VenueMembershipWpdb $wpdb ): void {
			foreach ( $wpdb->rows['wp_7_ec_venue_members'] as &$row ) {
				if ( 2 === (int) $row['user_id'] ) {
					$row['status'] = VenueAuthorization::STATUS_REVOKED;
				}
			}
			unset( $row );
		};

		$result = $service->create( 2, 55, 3, false );
		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
		$this->assertSame( VenueAuthorization::STATUS_REVOKED, ( new VenueMembershipRepository() )->get( 55, 2 )['status'] );
		$this->assertNull( ( new VenueMembershipRepository() )->get( 55, 3 ) );
	}

	public function test_authorization_fails_closed_until_schema_is_ready(): void {
		unset( $GLOBALS['venue_membership_test']['options'][ BookingSchema::VERSION_OPTION ] );
		$result = ( new VenueAuthorization() )->authorize( 1, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS );
		$this->assertSame( 'venue_membership_schema_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_member_listing_rechecks_actor_in_the_same_query(): void {
		$service = new VenueMembershipService();
		$service->create( 1, 55, 2, true );
		$service->create( 2, 55, 3, false );

		$GLOBALS['wpdb']->before_list = static function ( VenueMembershipWpdb $wpdb ): void {
			foreach ( $wpdb->rows['wp_7_ec_venue_members'] as &$row ) {
				if ( 2 === (int) $row['user_id'] ) {
					$row['status'] = VenueAuthorization::STATUS_REVOKED;
				}
			}
			unset( $row );
		};

		$this->assertSame( array(), $service->list( 2, 55 ) );
	}

	public function test_list_filters_and_corrupt_rows_fail_closed(): void {
		$repository = new VenueMembershipRepository();
		$this->create_member( 55, 2, true );
		$this->create_member( 55, 3, false );
		$this->create_member( 55, 4, false, VenueAuthorization::STATUS_INVITED );
		$this->create_member( 56, 5, true );

		$this->assertCount( 3, $repository->list_for_venue( 55 ) );
		$this->assertCount( 2, $repository->list_for_venue( 55, array( 'status' => VenueAuthorization::STATUS_ACTIVE ) ) );
		$this->assertSame( 2, $repository->list_for_venue( 55, array( 'is_owner' => true ) )[0]['user_id'] );
		$this->assertCount( 2, $repository->list_for_venue( 55, array( 'is_owner' => false ) ) );

		$row             = $repository->get( 55, 2 );
		$row['is_owner'] = 2;
		$this->assertSame( 'venue_membership_corrupt_owner', $repository->hydrate( $row )->get_error_code() );
		$row['is_owner'] = 1;
		$row['status']   = 'paused';
		$this->assertSame( 'venue_membership_corrupt_status', $repository->hydrate( $row )->get_error_code() );
	}

	public function test_abilities_share_authorization_and_execution_rechecks_it(): void {
		$this->create_member( 55, 2, true );
		$GLOBALS['venue_membership_test']['current_user_id'] = 2;
		$abilities = new VenueMembershipAbilities();
		$abilities->register();

		$this->assertSame(
			array(
				'extrachill/create-venue-membership',
				'extrachill/update-venue-membership',
				'extrachill/revoke-venue-membership',
				'extrachill/list-venue-memberships',
			),
			array_keys( $GLOBALS['venue_membership_test']['abilities'] )
		);
		foreach ( $GLOBALS['venue_membership_test']['abilities'] as $definition ) {
			$this->assertSame( 'extrachill-events', $definition['category'] );
			$this->assertTrue( $definition['meta']['show_in_rest'] );
			$this->assertTrue( call_user_func( $definition['permission_callback'], array( 'venue_term_id' => 55 ) ) );
			$this->assertSame( 'venue_action_forbidden', call_user_func( $definition['permission_callback'], array( 'venue_term_id' => 56 ) )->get_error_code() );
		}
		$this->assertStringNotContainsString( '"role"', json_encode( $GLOBALS['venue_membership_test']['abilities'] ) );
		$this->assertTrue( $GLOBALS['venue_membership_test']['abilities']['extrachill/update-venue-membership']['meta']['annotations']['destructive'] );

		$created = call_user_func(
			$GLOBALS['venue_membership_test']['abilities']['extrachill/create-venue-membership']['execute_callback'],
			array(
				'venue_term_id' => 55,
				'user_id'       => 3,
				'is_owner'      => false,
			)
		);
		$this->assertSame( 3, $created['user_id'] );

		$GLOBALS['venue_membership_test']['current_user_id'] = 3;
		$denied = call_user_func(
			$GLOBALS['venue_membership_test']['abilities']['extrachill/create-venue-membership']['execute_callback'],
			array(
				'venue_term_id' => 55,
				'user_id'       => 4,
				'is_owner'      => false,
			)
		);
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
	}

	public function test_claim_submission_is_idempotent_and_approval_bootstraps_exact_owner(): void {
		$onboarding = new VenueOnboardingRepository();
		$first      = $onboarding->submit_claim( 7, 55 );
		$duplicate  = $onboarding->submit_claim( 7, 55 );

		$this->assertSame( $first['id'], $duplicate['id'] );
		$this->assertSame( VenueOnboardingRepository::CLAIM_PENDING, $first['status'] );
		$approved = $onboarding->review_claim( 1, $first['id'], VenueOnboardingRepository::CLAIM_APPROVED, 1 );
		$this->assertSame( VenueOnboardingRepository::CLAIM_APPROVED, $approved['status'] );
		$owner = ( new VenueMembershipRepository() )->get( 55, 7 );
		$this->assertSame( VenueAuthorization::STATUS_ACTIVE, $owner['status'] );
		$this->assertTrue( $owner['is_owner'] );

		$idempotent = $onboarding->review_claim( 1, $first['id'], VenueOnboardingRepository::CLAIM_APPROVED, 1 );
		$this->assertSame( $approved, $idempotent );
	}

	public function test_claim_cancellation_resubmission_and_review_conflicts_are_optimistic(): void {
		$onboarding = new VenueOnboardingRepository();
		$claim      = $onboarding->submit_claim( 7, 55 );
		$cancelled  = $onboarding->cancel_claim( 7, $claim['id'], 1 );

		$this->assertSame( VenueOnboardingRepository::CLAIM_CANCELLED, $cancelled['status'] );
		$this->assertSame( $cancelled, $onboarding->cancel_claim( 7, $claim['id'], 1 ) );
		$resubmitted = $onboarding->submit_claim( 7, 55 );
		$this->assertSame( 3, $resubmitted['version'] );
		$this->assertSame( 'venue_claim_version_conflict', $onboarding->review_claim( 1, $claim['id'], VenueOnboardingRepository::CLAIM_REJECTED, 1 )->get_error_code() );
	}

	public function test_claims_and_invitations_are_reciprocally_exclusive_under_the_venue_lock(): void {
		$onboarding = new VenueOnboardingRepository();
		$this->create_member( 55, 2, true );
		$invite = $onboarding->create_invitation( 2, 55, 7, false, $this->email_hash( 'existing@example.com' ) );

		$this->assertSame( VenueOnboardingRepository::INVITE_PENDING, $invite['status'] );
		$this->assertSame( 'venue_claim_invitation_exists', $onboarding->submit_claim( 7, 55 )->get_error_code() );

		$claim = $onboarding->submit_claim( 6, 55 );
		$this->assertSame( VenueOnboardingRepository::CLAIM_PENDING, $claim['status'] );
		$result = $onboarding->create_invitation( 2, 55, 6, false, $this->email_hash( 'member6@example.com' ) );
		$this->assertSame( 'venue_invitation_claim_exists', $result->get_error_code() );
	}

	public function test_invitation_acceptance_is_single_use_and_exactly_bound(): void {
		$onboarding = new VenueOnboardingRepository();
		$this->create_member( 55, 2, true );
		$invite = $this->create_prepared_invitation( $onboarding, 1, 55, 7, false, 'existing@example.com' );
		$token  = $invite['_delivery_token'];

		$this->assertArrayNotHasKey( 'token_hash', $invite );
		$this->assertArrayNotHasKey( 'email_hash', $invite );
		$this->assertArrayNotHasKey( '_email_hash', $onboarding->public_invitation( $invite ) );
		$this->assertSame( 'invalid_venue_invitation', $onboarding->respond_to_invitation( 7, $invite['public_id'], $token, 56, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED )->get_error_code() );
		$this->assertSame( 'invalid_venue_invitation', $onboarding->respond_to_invitation( 7, $invite['public_id'], $token, 55, true, 2, VenueOnboardingRepository::INVITE_ACCEPTED )->get_error_code() );
		$this->assertSame( 'invalid_venue_invitation', $onboarding->respond_to_invitation( 6, $invite['public_id'], $token, 55, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED )->get_error_code() );

		$accepted = $onboarding->respond_to_invitation( 7, $invite['public_id'], $token, 55, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED );
		$this->assertSame( VenueOnboardingRepository::INVITE_ACCEPTED, $accepted['status'] );
		$this->assertSame( VenueAuthorization::STATUS_ACTIVE, ( new VenueMembershipRepository() )->get( 55, 7 )['status'] );
		$this->assertSame( 'invalid_venue_invitation', $onboarding->respond_to_invitation( 7, $invite['public_id'], $token, 55, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED )->get_error_code() );
	}

	public function test_invitation_acceptance_rechecks_current_account_email_binding(): void {
		$onboarding = new VenueOnboardingRepository();
		$this->create_member( 55, 2, true );
		$invite = $this->create_prepared_invitation( $onboarding, 2, 55, 7, false, 'existing@example.com' );
		$token  = $invite['_delivery_token'];

		$GLOBALS['venue_membership_test']['users'][7]->user_email = 'changed@example.com';
		$result = $onboarding->respond_to_invitation( 7, $invite['public_id'], $token, 55, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED );

		$this->assertSame( 'invalid_venue_invitation', $result->get_error_code() );
		$this->assertSame( VenueAuthorization::STATUS_INVITED, ( new VenueMembershipRepository() )->get( 55, 7 )['status'] );
	}

	public function test_first_active_invitation_must_bootstrap_an_owner(): void {
		$onboarding = new VenueOnboardingRepository();
		$invite     = $this->create_prepared_invitation( $onboarding, 1, 55, 7, false, 'existing@example.com' );
		$token      = $invite['_delivery_token'];

		$result = $onboarding->respond_to_invitation( 7, $invite['public_id'], $token, 55, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED );
		$this->assertSame( 'venue_membership_owner_required', $result->get_error_code() );
		$this->assertSame( VenueAuthorization::STATUS_INVITED, ( new VenueMembershipRepository() )->get( 55, 7 )['status'] );
	}

	public function test_resend_rotates_token_and_revoked_inviter_fails_closed(): void {
		$this->create_member( 55, 2, true );
		$onboarding = new VenueOnboardingRepository();
		$invite     = $this->create_prepared_invitation( $onboarding, 2, 55, 7, true, 'existing@example.com' );
		$old_token  = $invite['_delivery_token'];
		$rotated    = $onboarding->resend_invitation( 2, $invite['id'], 2 );
		$prepared   = $onboarding->prepare_delivery( $rotated['_delivery_id'], 3600 );
		$new_token  = $prepared['_delivery_token'];

		$this->assertSame( 3, $rotated['version'] );
		$this->assertSame( 'invalid_venue_invitation', $onboarding->respond_to_invitation( 7, $invite['public_id'], $old_token, 55, true, 4, VenueOnboardingRepository::INVITE_ACCEPTED )->get_error_code() );
		foreach ( $GLOBALS['wpdb']->rows['wp_7_ec_venue_members'] as &$member ) {
			if ( 2 === (int) $member['user_id'] ) {
				$member['status'] = VenueAuthorization::STATUS_REVOKED;
			}
		}
		unset( $member );
		$this->assertSame( 'venue_invitation_inviter_revoked', $onboarding->respond_to_invitation( 7, $invite['public_id'], $new_token, 55, true, 4, VenueOnboardingRepository::INVITE_ACCEPTED )->get_error_code() );
	}

	public function test_rejection_cancellation_expiry_and_audit_are_private(): void {
		$onboarding = new VenueOnboardingRepository();
		$invite     = $this->create_prepared_invitation( $onboarding, 1, 55, 7, false, 'existing@example.com' );
		$token      = $invite['_delivery_token'];
		$rejected   = $onboarding->respond_to_invitation( 7, $invite['public_id'], $token, 55, false, 2, VenueOnboardingRepository::INVITE_REJECTED );
		$this->assertSame( VenueOnboardingRepository::INVITE_REJECTED, $rejected['status'] );
		$this->assertSame( VenueAuthorization::STATUS_REVOKED, ( new VenueMembershipRepository() )->get( 55, 7 )['status'] );

		$owner     = $this->create_member( 56, 2, true );
		$invite2   = $onboarding->create_invitation( 2, 56, 6, false, $this->email_hash( 'member6@example.com' ) );
		$cancelled = $onboarding->cancel_invitation( 2, $invite2['id'], 1 );
		$this->assertSame( VenueOnboardingRepository::INVITE_CANCELLED, $cancelled['status'] );
		$this->assertSame( $cancelled, $onboarding->cancel_invitation( 2, $invite2['id'], 1 ) );
		$this->assertTrue( $owner['is_owner'] );

		$invite3 = $this->create_prepared_invitation( $onboarding, 2, 56, 5, false, 'member5@example.com' );
		$token3  = $invite3['_delivery_token'];
		$GLOBALS['wpdb']->rows['wp_7_ec_venue_invitations'][ $invite3['id'] ]['expires_at'] = '2000-01-01 00:00:00';
		$expired = $onboarding->respond_to_invitation( 5, $invite3['public_id'], $token3, 56, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED );
		$this->assertSame( 'venue_invitation_expired', $expired->get_error_code() );
		$this->assertSame( VenueAuthorization::STATUS_REVOKED, ( new VenueMembershipRepository() )->get( 56, 5 )['status'] );

		foreach ( $GLOBALS['wpdb']->rows['wp_7_ec_venue_onboarding_audit'] as $audit ) {
			$encoded = json_encode( $audit );
			$this->assertStringNotContainsString( $token, $encoded );
			$this->assertStringNotContainsString( 'private@example.com', $encoded );
			$this->assertStringNotContainsString( 'token', strtolower( $audit['payload'] ) );
			$this->assertStringNotContainsString( 'email', strtolower( $audit['payload'] ) );
		}
	}

	public static function terminal_invitation_status_provider(): array {
		return array( 'cancelled' => array( VenueOnboardingRepository::INVITE_CANCELLED ), 'rejected' => array( VenueOnboardingRepository::INVITE_REJECTED ), 'expired' => array( VenueOnboardingRepository::INVITE_EXPIRED ) );
	}

	/** @dataProvider terminal_invitation_status_provider */
	public function test_terminal_invitation_can_be_reactivated_with_rotated_bindings_and_audit( string $terminal_status ): void {
		$this->create_member( 55, 2, true );
		$repository = new VenueOnboardingRepository();
		$first      = $this->create_prepared_invitation( $repository, 2, 55, 7, false, 'existing@example.com' );
		$old_token  = $first['_delivery_token'];

		if ( VenueOnboardingRepository::INVITE_CANCELLED === $terminal_status ) {
			$terminal = $repository->cancel_invitation( 2, $first['id'], 2 );
		} elseif ( VenueOnboardingRepository::INVITE_REJECTED === $terminal_status ) {
			$terminal = $repository->respond_to_invitation( 7, $first['public_id'], $old_token, 55, false, 2, VenueOnboardingRepository::INVITE_REJECTED );
		} else {
			$GLOBALS['wpdb']->rows['wp_7_ec_venue_invitations'][ $first['id'] ]['expires_at'] = '2000-01-01 00:00:00';
			$expired = $repository->respond_to_invitation( 7, $first['public_id'], $old_token, 55, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED );
			$this->assertSame( 'venue_invitation_expired', $expired->get_error_code() );
			$terminal = $GLOBALS['wpdb']->rows['wp_7_ec_venue_invitations'][ $first['id'] ];
		}
		$this->assertSame( $terminal_status, $terminal['status'] );

		$reactivated = $repository->create_invitation( 2, 55, 7, true, $this->email_hash( 'existing@example.com' ) );
		$prepared    = $repository->prepare_delivery( $reactivated['_delivery_id'], 3600 );
		$this->assertSame( VenueOnboardingRepository::INVITE_PENDING, $prepared['status'] );
		$this->assertSame( VenueAuthorization::STATUS_INVITED, ( new VenueMembershipRepository() )->get( 55, 7 )['status'] );
		$this->assertTrue( $prepared['is_owner'] );
		$this->assertNotSame( $first['public_id'], $prepared['public_id'] );
		$this->assertNotSame( $first['_delivery_id'], $prepared['_delivery_id'] );
		$this->assertNotSame( $old_token, $prepared['_delivery_token'] );
		$this->assertSame( 'invalid_venue_invitation', $repository->respond_to_invitation( 7, $first['public_id'], $old_token, 55, false, 5, VenueOnboardingRepository::INVITE_ACCEPTED )->get_error_code() );
		$events = array_column( $GLOBALS['wpdb']->rows['wp_7_ec_venue_onboarding_audit'], 'event' );
		$this->assertContains( 'invitation_reinvited', $events );
	}

	public function test_cancelled_invitation_is_not_disclosed_to_another_venue_owner(): void {
		$onboarding = new VenueOnboardingRepository();
		$this->create_member( 55, 2, true );
		$this->create_member( 56, 3, true );
		$invite    = $onboarding->create_invitation( 2, 55, 7, false, $this->email_hash( 'existing@example.com' ) );
		$cancelled = $onboarding->cancel_invitation( 2, $invite['id'], 1 );

		$this->assertSame( VenueOnboardingRepository::INVITE_CANCELLED, $cancelled['status'] );
		$this->assertSame( 'venue_action_forbidden', $onboarding->cancel_invitation( 3, $invite['id'], 2 )->get_error_code() );
	}

	public function test_invitation_listing_rechecks_owner_authority_under_the_venue_lock(): void {
		$onboarding = new VenueOnboardingRepository();
		$this->create_member( 55, 2, true );
		$onboarding->create_invitation( 2, 55, 7, false, $this->email_hash( 'existing@example.com' ) );

		$GLOBALS['wpdb']->after_start = static function ( VenueMembershipWpdb $wpdb ): void {
			foreach ( $wpdb->rows['wp_7_ec_venue_members'] as &$row ) {
				if ( 2 === (int) $row['user_id'] ) {
					$row['status'] = VenueAuthorization::STATUS_REVOKED;
				}
			}
			unset( $row );
		};

		$result = $onboarding->list_invitations( 2, 55 );
		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
	}

	public function test_queue_persists_only_opaque_delivery_id_and_worker_sends_synchronously(): void {
		$this->create_member( 55, 2, true );
		$service  = new VenueOnboardingService();
		$existing = $service->invite( 2, 55, 'existing@example.com', false );

		$this->assertFalse( $existing['account_created'] );
		$this->assertTrue( $existing['delivery_queued'] );
		$this->assertSame( 0, $GLOBALS['venue_membership_test']['reset_key_calls'] );
		$this->assertSame( array(), $GLOBALS['venue_membership_test']['sent_emails'] );
		$scheduled = $GLOBALS['venue_membership_test']['scheduled_actions'][0];
		$this->assertSame( VenueInvitationDeliveryWorker::HOOK, $scheduled['hook'] );
		$this->assertSame( VenueInvitationDeliveryWorker::GROUP, $scheduled['group'] );
		$this->assertTrue( $scheduled['unique'] );
		$this->assertCount( 1, $scheduled['args'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $scheduled['args'][0] );
		$this->assertStringNotContainsString( 'token', strtolower( serialize( $scheduled ) ) );
		$this->assertStringNotContainsString( 'existing@example.com', serialize( $scheduled ) );

		$GLOBALS['venue_membership_test']['doing_action'] = 'action_scheduler_run_queue';
		$delivered = ( new VenueInvitationDeliveryWorker() )->deliver( $scheduled['args'][0] );
		$this->assertFalse( is_wp_error( $delivered ) );
		$this->assertSame( 'chubes@extrachill.com', $GLOBALS['venue_membership_test']['sent_emails'][0]['cc'] );
		$this->assertSame( 'Extra Chill Bot', $GLOBALS['venue_membership_test']['sent_emails'][0]['from_name'] );
		$this->assertStringContainsString( 'acting on Chris Huber', $GLOBALS['venue_membership_test']['sent_emails'][0]['context']['body_html'] );
		$this->assertSame( array(), $GLOBALS['venue_membership_test']['permission_boundary_failures'] );

		$this->install_account_creator();
		$new = $service->invite( 1, 56, 'brand-new@example.com', true );
		$this->assertTrue( $new['account_created'] );
		$this->assertTrue( $new['delivery_queued'] );
		$this->assertTrue( $GLOBALS['venue_membership_test']['created_account_input']['unclaimed'] );
		$this->assertSame( 'venue_invitation', $GLOBALS['venue_membership_test']['created_account_input']['registration_source'] );
		$this->assertSame( 0, $GLOBALS['venue_membership_test']['reset_key_calls'] );
		$delivery_id = $GLOBALS['venue_membership_test']['scheduled_actions'][1]['args'][0];
		( new VenueInvitationDeliveryWorker() )->deliver( $delivery_id );
		$this->assertSame( 1, $GLOBALS['venue_membership_test']['reset_key_calls'] );
		$this->assertStringContainsString( 'action=rp', $GLOBALS['venue_membership_test']['sent_emails'][1]['context']['cta_url'] );
	}

	public function test_account_creation_requires_fresh_membership_authority_but_not_booking_access(): void {
		$this->create_member( 55, 2, true );
		unset( $GLOBALS['venue_membership_test']['team_access'][2] );
		$GLOBALS['venue_membership_test']['feature_available'] = false;

		$this->install_account_creator();

		$service = new VenueOnboardingService();

		$allowed = $service->invite( 2, 55, 'authorized-new@example.com', false );
		$this->assertTrue( $allowed['account_created'] );
		$this->assertSame( 1, $GLOBALS['venue_membership_test']['account_create_calls'] );
		$this->assertFalse( ( new VenueAuthorization() )->can( 2, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );

		$denied = $service->invite( 3, 55, 'unauthorized-new@example.com', false );
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
		$this->assertSame( 1, $GLOBALS['venue_membership_test']['account_create_calls'] );
	}

	public function test_failed_existing_unclaimed_invitation_preserves_prior_reset_handoff(): void {
		$this->create_member( 55, 2, true );
		$GLOBALS['venue_membership_test']['user_meta'][7] = array( 'ec_unclaimed' => '1' );
		$GLOBALS['wpdb']->fail_invitation_insert          = true;

		$result = ( new VenueOnboardingService() )->invite( 2, 55, 'existing@example.com', false );

		$this->assertSame( 'venue_invitation_create_failed', $result->get_error_code() );
		$this->assertSame( 0, $GLOBALS['venue_membership_test']['reset_key_calls'] );
		$this->assertSame( array(), $GLOBALS['venue_membership_test']['scheduled_actions'] );
		$this->assertArrayHasKey( 7, $GLOBALS['venue_membership_test']['users'] );
	}

	public function test_reset_key_is_deferred_until_persisted_worker_delivery(): void {
		$this->create_member( 55, 2, true );
		$this->install_account_creator();
		$GLOBALS['venue_membership_test']['fail_reset_key'] = true;

		$result = ( new VenueOnboardingService() )->invite( 2, 55, 'reset-failure@example.com', false );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 0, $GLOBALS['venue_membership_test']['reset_key_calls'] );
		$GLOBALS['venue_membership_test']['doing_action'] = 'action_scheduler_run_queue';
		$delivered = ( new VenueInvitationDeliveryWorker() )->deliver( $GLOBALS['venue_membership_test']['scheduled_actions'][0]['args'][0] );
		$this->assertSame( 'venue_invitation_reset_key_failed', $delivered->get_error_code() );
		$this->assertSame( 1, $GLOBALS['venue_membership_test']['reset_key_calls'] );
		$this->assertArrayHasKey( 8, $GLOBALS['venue_membership_test']['users'] );
		$this->assertSame( array(), $GLOBALS['venue_membership_test']['sent_emails'] );
		$invitation = array_values( $GLOBALS['wpdb']->rows['wp_7_ec_venue_invitations'] )[0];
		$this->assertSame( VenueOnboardingRepository::DELIVERY_FAILED, $invitation['delivery_status'] );
		$this->assertSame( str_repeat( '0', 64 ), $invitation['token_hash'] );
	}

	public function test_authority_race_and_invitation_write_failure_compensate_new_accounts(): void {
		$this->create_member( 55, 2, true );
		$this->install_account_creator();
		$GLOBALS['wpdb']->after_start = static function ( VenueMembershipWpdb $wpdb ): void {
			foreach ( $wpdb->rows['wp_7_ec_venue_members'] as &$row ) {
				if ( 2 === (int) $row['user_id'] ) {
					$row['status'] = VenueAuthorization::STATUS_REVOKED;
				}
			}
			unset( $row );
		};

		$race = ( new VenueOnboardingService() )->invite( 2, 55, 'authority-race@example.com', false );
		$this->assertSame( 'venue_action_forbidden', $race->get_error_code() );
		$this->assertArrayNotHasKey( 8, $GLOBALS['venue_membership_test']['users'] );

		foreach ( $GLOBALS['wpdb']->rows['wp_7_ec_venue_members'] as &$row ) {
			if ( 2 === (int) $row['user_id'] ) {
				$row['status'] = VenueAuthorization::STATUS_ACTIVE;
			}
		}
		unset( $row );
		$this->install_account_creator();
		$GLOBALS['wpdb']->fail_invitation_insert = true;
		$write = ( new VenueOnboardingService() )->invite( 2, 55, 'write-failure@example.com', false );
		$this->assertSame( 'venue_invitation_create_failed', $write->get_error_code() );
		$this->assertArrayNotHasKey( 8, $GLOBALS['venue_membership_test']['users'] );
		$this->assertSame( array( 8, 8 ), $GLOBALS['venue_membership_test']['rollback_attempts'] );
	}

	public function test_compensation_locks_and_preserves_concurrently_adopted_account(): void {
		$this->create_member( 55, 2, true );
		$this->install_account_creator();
		$GLOBALS['venue_membership_test']['fail_schedule'] = true;
		$GLOBALS['wpdb']->after_start = static function ( VenueMembershipWpdb $wpdb ): void {
			$wpdb->insert(
				'wp_7_ec_venue_members',
				array( 'venue_term_id' => 56, 'user_id' => 8, 'is_owner' => 0, 'status' => VenueAuthorization::STATUS_ACTIVE, 'version' => 1, 'created_by_user_id' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'revoked_at' => null )
			);
		};

		$result = ( new VenueOnboardingService() )->invite( 2, 55, 'adopted-race@example.com', false );

		$this->assertSame( 'venue_invitation_delivery_failed', $result->get_error_code() );
		$this->assertArrayHasKey( 8, $GLOBALS['venue_membership_test']['users'] );
		$this->assertSame( array(), $GLOBALS['venue_membership_test']['rollback_attempts'] );
		$this->assertSame( VenueAuthorization::STATUS_ACTIVE, ( new VenueMembershipRepository() )->get( 56, 8 )['status'] );
	}

	public function test_schedule_failure_cancels_state_and_compensates_provenanced_account(): void {
		$this->create_member( 55, 2, true );
		$this->install_account_creator();
		$GLOBALS['venue_membership_test']['fail_schedule'] = true;

		$result = ( new VenueOnboardingService() )->invite( 2, 55, 'delivery-failure@example.com', false );

		$this->assertSame( 'venue_invitation_delivery_failed', $result->get_error_code() );
		$this->assertArrayNotHasKey( 8, $GLOBALS['venue_membership_test']['users'] );
		$invitation = array_values( $GLOBALS['wpdb']->rows['wp_7_ec_venue_invitations'] )[0];
		$this->assertSame( VenueOnboardingRepository::INVITE_CANCELLED, $invitation['status'] );
		$this->assertSame( 1, (int) $invitation['account_created'] );
		$this->assertSame( VenueAuthorization::STATUS_REVOKED, ( new VenueMembershipRepository() )->get( 55, 8 )['status'] );
	}

	public function test_provenance_failure_cancels_invitation_and_rolls_back_created_account(): void {
		$this->create_member( 55, 2, true );
		$this->install_account_creator();
		$GLOBALS['venue_membership_test']['fail_provenance'] = true;

		$result = ( new VenueOnboardingService() )->invite( 2, 55, 'provenance-failure@example.com', false );

		$this->assertSame( 'venue_invitation_provenance_failed', $result->get_error_code() );
		$this->assertArrayNotHasKey( 8, $GLOBALS['venue_membership_test']['users'] );
		$invitation = array_values( $GLOBALS['wpdb']->rows['wp_7_ec_venue_invitations'] )[0];
		$this->assertSame( VenueOnboardingRepository::INVITE_CANCELLED, $invitation['status'] );
		$this->assertSame( array( 8 ), $GLOBALS['venue_membership_test']['rollback_attempts'] );
	}

	public function test_terminal_cleanup_deletes_only_unused_provenanced_accounts(): void {
		$this->create_member( 55, 2, true );
		$this->install_account_creator();
		$service = new VenueOnboardingService();
		$created = $service->invite( 2, 55, 'cancel-created@example.com', false );
		$this->assertSame( VenueOnboardingRepository::INVITE_CANCELLED, $service->cancel_invitation( 2, $created['id'], 1 )['status'] );
		$this->assertArrayNotHasKey( 8, $GLOBALS['venue_membership_test']['users'] );

		$existing = $service->invite( 2, 55, 'existing@example.com', false );
		$this->assertSame( VenueOnboardingRepository::INVITE_CANCELLED, $service->cancel_invitation( 2, $existing['id'], 1 )['status'] );
		$this->assertArrayHasKey( 7, $GLOBALS['venue_membership_test']['users'] );
	}

	public function test_claimed_or_shared_provenanced_accounts_are_preserved_on_terminal_transition(): void {
		$this->create_member( 55, 2, true );
		$this->create_member( 56, 3, true );
		$this->install_account_creator();
		$service = new VenueOnboardingService();
		$claimed = $service->invite( 2, 55, 'claimed-before-cancel@example.com', false );
		unset( $GLOBALS['venue_membership_test']['user_meta'][8]['ec_unclaimed'] );
		$service->cancel_invitation( 2, $claimed['id'], 1 );
		$this->assertArrayHasKey( 8, $GLOBALS['venue_membership_test']['users'] );

		$this->install_account_creator();
		$shared = $service->invite( 3, 56, 'shared-before-reject@example.com', false );
		$this->create_member( 55, 9, false, VenueAuthorization::STATUS_ACTIVE, 2 );
		$service->cancel_invitation( 3, $shared['id'], 1 );
		$this->assertArrayHasKey( 9, $GLOBALS['venue_membership_test']['users'] );
		$this->assertSame( 56, $shared['venue_term_id'] );
	}

	public function test_rejection_and_expiry_cleanup_created_account_provenance(): void {
		$this->create_member( 55, 2, true );
		$this->create_member( 56, 3, true );
		$repository = new VenueOnboardingRepository();
		$service    = new VenueOnboardingService( $repository );

		$GLOBALS['venue_membership_test']['users'][8] = new WP_User( 8, 'reject-created@example.com' );
		$GLOBALS['venue_membership_test']['user_meta'][8] = array(
			'ec_unclaimed'       => '1',
			'registration_source' => 'venue_invitation',
		);
		$rejected     = $this->create_prepared_invitation( $repository, 2, 55, 8, false, 'reject-created@example.com', true );
		$reject_token = $rejected['_delivery_token'];
		update_user_meta( 8, 'ec_venue_invitation_account', $rejected['public_id'] );
		$result = $service->respond( 8, $rejected['public_id'], $reject_token, 55, false, 2, VenueOnboardingRepository::INVITE_REJECTED );
		$this->assertSame( VenueOnboardingRepository::INVITE_REJECTED, $result['status'] );
		$this->assertArrayNotHasKey( 8, $GLOBALS['venue_membership_test']['users'] );

		$GLOBALS['venue_membership_test']['users'][9] = new WP_User( 9, 'expire-created@example.com' );
		$GLOBALS['venue_membership_test']['user_meta'][9] = array(
			'ec_unclaimed'       => '1',
			'registration_source' => 'venue_invitation',
		);
		$expired      = $this->create_prepared_invitation( $repository, 3, 56, 9, false, 'expire-created@example.com', true );
		$expire_token = $expired['_delivery_token'];
		update_user_meta( 9, 'ec_venue_invitation_account', $expired['public_id'] );
		$GLOBALS['wpdb']->rows['wp_7_ec_venue_invitations'][ $expired['id'] ]['expires_at'] = '2000-01-01 00:00:00';
		$result = $service->respond( 9, $expired['public_id'], $expire_token, 56, false, 2, VenueOnboardingRepository::INVITE_ACCEPTED );
		$this->assertSame( 'venue_invitation_expired', $result->get_error_code() );
		$this->assertArrayNotHasKey( 9, $GLOBALS['venue_membership_test']['users'] );
	}

	public function test_config_abilities_round_trip_revision_and_audit(): void {
		$this->create_member( 55, 2, true );
		$this->create_member( 55, 3, false );
		$GLOBALS['venue_membership_test']['current_user_id'] = 3;
		$abilities = new VenueBookingConfigAbilities();
		$abilities->register();

		$this->assertSame(
			array( 'extrachill/get-venue-booking-config', 'extrachill/update-venue-booking-config' ),
			array_keys( $GLOBALS['venue_membership_test']['abilities'] )
		);
		$get    = $GLOBALS['venue_membership_test']['abilities']['extrachill/get-venue-booking-config'];
		$update = $GLOBALS['venue_membership_test']['abilities']['extrachill/update-venue-booking-config'];
		$this->assertTrue( call_user_func( $get['permission_callback'], array( 'venue_term_id' => 55 ) ) );
		$this->assertSame( 'venue_action_forbidden', call_user_func( $get['permission_callback'], array( 'venue_term_id' => 56 ) )->get_error_code() );

		$legacy = ( new VenueBookingConfig() )->defaults();
		unset( $legacy['revision'], $legacy['updated_by_user_id'], $legacy['updated_at'] );
		$GLOBALS['venue_membership_test']['term_meta'][55][ VenueBookingConfig::META_KEY ] = $legacy;
		$current = call_user_func( $get['execute_callback'], array( 'venue_term_id' => 55 ) );
		$this->assertSame( 0, $current['revision'] );
		$this->assertNull( $current['updated_by_user_id'] );
		$settings = $current;
		unset( $settings['revision'], $settings['updated_by_user_id'], $settings['updated_at'] );
		$settings['enabled'] = true;
		$saved               = call_user_func(
			$update['execute_callback'],
			array(
				'venue_term_id'     => 55,
				'expected_revision' => 0,
				'config'            => $settings,
			)
		);
		$this->assertSame( 1, $saved['revision'] );
		$this->assertSame( 3, $saved['updated_by_user_id'] );
		$this->assertTrue( $saved['enabled'] );
		$history = $GLOBALS['venue_membership_test']['term_history'][55][ VenueBookingConfig::HISTORY_META_KEY ];
		$this->assertCount( 1, $history );
		$this->assertSame( array( 'enabled' ), $history[0]['changed_fields'] );
		$this->assertCount( 1, $GLOBALS['venue_membership_test']['fired_actions']['extrachill_events_venue_booking_config_updated'] );
		$this->assertNotEmpty( $GLOBALS['venue_membership_test']['cache_deletes'] );

		$stale = call_user_func(
			$update['execute_callback'],
			array(
				'venue_term_id'     => 55,
				'expected_revision' => 0,
				'config'            => $settings,
			)
		);
		$this->assertSame( 'booking_config_revision_conflict', $stale->get_error_code() );
		$this->assertSame( 1, $stale->get_error_data()['current_revision'] );

		$unchanged = call_user_func(
			$update['execute_callback'],
			array(
				'venue_term_id'     => 55,
				'expected_revision' => 1,
				'config'            => $settings,
			)
		);
		$this->assertSame( 1, $unchanged['revision'] );
		$this->assertCount( 1, $GLOBALS['venue_membership_test']['term_history'][55][ VenueBookingConfig::HISTORY_META_KEY ] );
	}

	public function test_config_update_reauthorizes_and_rolls_back_failed_audit(): void {
		$this->create_member( 55, 2, true );
		$config   = new VenueBookingConfig();
		$settings = $config->defaults();
		unset( $settings['revision'], $settings['updated_by_user_id'], $settings['updated_at'] );
		$settings['enabled'] = true;

		$GLOBALS['wpdb']->after_start = static function ( VenueMembershipWpdb $wpdb ): void {
			foreach ( $wpdb->rows['wp_7_ec_venue_members'] as &$row ) {
				if ( 2 === (int) $row['user_id'] ) {
					$row['status'] = VenueAuthorization::STATUS_REVOKED;
				}
			}
			unset( $row );
		};
		$denied = $config->update( 55, $settings, 0, 2 );
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
		$this->assertSame( array(), $GLOBALS['venue_membership_test']['term_meta'] );

		foreach ( $GLOBALS['wpdb']->rows['wp_7_ec_venue_members'] as &$row ) {
			if ( 2 === (int) $row['user_id'] ) {
				$row['status'] = VenueAuthorization::STATUS_ACTIVE;
			}
		}
		unset( $row );
		$GLOBALS['venue_membership_test']['fail_config_audit'] = true;
		$failed = $config->update( 55, $settings, 0, 2 );
		$this->assertSame( 'booking_config_audit_failed', $failed->get_error_code() );
		$this->assertSame( array(), $GLOBALS['venue_membership_test']['term_meta'] );
		$this->assertSame( array( 55, 'term_meta' ), $GLOBALS['venue_membership_test']['cache_deletes'][0] );
	}

	public function test_profile_abilities_authorize_exact_active_venue_members(): void {
		$this->create_member( 55, 2, true );
		$this->create_member( 55, 3, false );
		$this->create_member( 56, 4, true, VenueAuthorization::STATUS_INVITED );
		$GLOBALS['venue_membership_test']['current_user_id'] = 3;

		$abilities = new VenueProfileAbilities();
		$abilities->register();
		$get    = $GLOBALS['venue_membership_test']['abilities']['extrachill/get-venue-profile'];
		$update = $GLOBALS['venue_membership_test']['abilities']['extrachill/update-venue-profile'];

		$this->assertTrue( call_user_func( $get['permission_callback'], array( 'venue_term_id' => 55 ) ) );
		$this->assertSame( 'venue_action_forbidden', call_user_func( $get['permission_callback'], array( 'venue_term_id' => 56 ) )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', call_user_func( $update['permission_callback'], array( 'venue_term_id' => 56 ) )->get_error_code() );
		$this->assertSame( 'extrachill-events', $get['category'] );
		$this->assertTrue( $get['meta']['annotations']['readonly'] );
		$this->assertTrue( $update['meta']['annotations']['destructive'] );
		$this->assertFalse( $get['input_schema']['additionalProperties'] );
		$this->assertFalse( $update['input_schema']['properties']['profile']['additionalProperties'] );
		$this->assertSame( 'string', $update['input_schema']['properties']['expected_revision']['type'] );

		$GLOBALS['venue_membership_test']['current_user_id'] = 4;
		$this->assertSame( 'venue_action_forbidden', call_user_func( $get['execute_callback'], array( 'venue_term_id' => 56 ) )->get_error_code() );
		$GLOBALS['venue_membership_test']['current_user_id'] = 1;
		$this->assertSame( 'venue_action_forbidden', call_user_func( $get['execute_callback'], array( 'venue_term_id' => 55 ) )->get_error_code() );
	}

	public function test_profile_read_delegates_to_dme_after_authorization(): void {
		$this->create_member( 55, 2, true );
		$result = ( new VenueProfile() )->get( 55, 2 );

		$this->assertSame( $GLOBALS['venue_membership_test']['dme_profile'], $result );
		$this->assertArrayNotHasKey( 55, $GLOBALS['venue_membership_test']['term_history'] );
	}

	public function test_profile_propagates_dme_validation_errors_without_audit(): void {
		$this->create_member( 55, 2, true );
		$GLOBALS['venue_membership_test']['dme_update'] = static function () {
			return new WP_Error( 'venue_meta_update_failed', 'Canonical owner rejected the mutation.' );
		};

		$result = ( new VenueProfile() )->update( 55, array( 'website' => 'invalid' ), str_repeat( 'a', 64 ), 2 );

		$this->assertSame( 'venue_meta_update_failed', $result->get_error_code() );
		$this->assertArrayNotHasKey( 55, $GLOBALS['venue_membership_test']['term_history'] );
	}

	public function test_profile_authorization_denial_prevents_dme_mutation(): void {
		$this->create_member( 55, 2, true, VenueAuthorization::STATUS_REVOKED );
		$called = false;
		$GLOBALS['venue_membership_test']['dme_update'] = static function () use ( &$called ) {
			$called = true;
			return array();
		};

		$result = ( new VenueProfile() )->update( 55, array( 'name' => 'Blocked' ), str_repeat( 'a', 64 ), 2 );

		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
		$this->assertFalse( $called );
	}

	public function test_profile_noop_owner_result_does_not_create_audit(): void {
		$this->create_member( 55, 2, true );
		$GLOBALS['venue_membership_test']['dme_update'] = static function ( $term_id ) {
			return array(
				'success'        => true,
				'term_id'        => $term_id,
				'updated_fields' => array(),
				'revision'       => str_repeat( 'a', 64 ),
				'profile'        => $GLOBALS['venue_membership_test']['dme_profile'],
			);
		};

		$result = ( new VenueProfile() )->update( 55, array( 'name' => 'The Royal American' ), str_repeat( 'a', 64 ), 2 );

		$this->assertSame( array(), $result['updated_fields'] );
		$this->assertArrayNotHasKey( 55, $GLOBALS['venue_membership_test']['term_history'] );
	}

	public function test_profile_composes_dme_contract_and_audits_only_member_fields_across_timezones(): void {
		$this->create_member( 55, 2, true );
		$previous_revision = $GLOBALS['venue_membership_test']['dme_profile']['revision'];
		$next_revision     = str_repeat( 'b', 64 );
		$GLOBALS['venue_membership_test']['dme_update'] = static function ( $term_id, $changes, $expected_revision ) use ( $next_revision ) {
			$GLOBALS['venue_membership_test']['dme_call'] = compact( 'term_id', 'changes', 'expected_revision' );
			$GLOBALS['venue_membership_test']['derived_timezone'] = 'America/Los_Angeles';
			$profile             = $GLOBALS['venue_membership_test']['dme_profile'];
			$profile['address']  = $changes['address'];
			$profile['city']     = $changes['city'];
			$profile['state']    = $changes['state'];
			$profile['revision'] = $next_revision;
			return array(
				'success'        => true,
				'term_id'        => $term_id,
				'updated_fields' => array( 'address', 'city', 'state', 'coordinates', 'timezone' ),
				'revision'       => $next_revision,
				'profile'        => $profile,
			);
		};

		$result = ( new VenueProfile() )->update(
			55,
			array(
				'address' => '800 West Olympic Boulevard',
				'city'    => 'Los Angeles',
				'state'   => 'CA',
			),
			$previous_revision,
			2
		);

		$this->assertSame( $next_revision, $result['revision'] );
		$this->assertSame( $previous_revision, $GLOBALS['venue_membership_test']['dme_call']['expected_revision'] );
		$this->assertSame( 'America/Los_Angeles', $GLOBALS['venue_membership_test']['derived_timezone'] );
		$audit = $GLOBALS['venue_membership_test']['term_history'][55][ VenueProfile::HISTORY_META_KEY ][0];
		$this->assertSame( array( 'address', 'city', 'state' ), $audit['changed_fields'] );
		$this->assertSame( $previous_revision, $audit['previous_revision'] );
		$this->assertSame( $next_revision, $audit['revision'] );
		$this->assertSame( 2, $audit['actor_user_id'] );
	}

	public function test_profile_audit_failure_reports_committed_owner_mutation(): void {
		$this->create_member( 55, 2, true );
		$GLOBALS['venue_membership_test']['fail_profile_audit'] = true;
		$GLOBALS['venue_membership_test']['dme_update'] = static function ( $term_id ) {
			return array(
				'success'        => true,
				'term_id'        => $term_id,
				'updated_fields' => array( 'phone' ),
				'revision'       => str_repeat( 'c', 64 ),
				'profile'        => $GLOBALS['venue_membership_test']['dme_profile'],
			);
		};

		$result = ( new VenueProfile() )->update( 55, array( 'phone' => '843-555-0100' ), str_repeat( 'a', 64 ), 2 );
		$this->assertSame( 'venue_profile_audit_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['mutation_committed'] );
		$this->assertSame( str_repeat( 'c', 64 ), $result->get_error_data()['revision'] );
	}

	public function test_profile_composition_requires_canonical_events_site(): void {
		$this->create_member( 55, 2, true );
		$GLOBALS['venue_membership_test']['current_blog_id'] = 1;
		$result = ( new VenueProfile() )->get( 55, 2 );
		$this->assertSame( 'canonical_events_site_required', $result->get_error_code() );
	}
}
