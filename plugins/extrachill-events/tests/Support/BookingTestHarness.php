<?php
/**
 * Shared venue booking test harness.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingMutationService;
use ExtraChillEvents\Core\BookingEventConversionService;
use ExtraChillEvents\Core\CanonicalEventPublicationGuard;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Abilities\VenueBookingAbilities;
use ExtraChillEvents\Abilities\VenueBookingHoldAbilities;
use ExtraChillEvents\Abilities\VenueBookingMutationAbilities;
use ExtraChillEvents\Abilities\VenueBookingEventAbilities;
use ExtraChillEvents\Core\VenueAuthorization;

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
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $value ) {
		$sanitized = filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL );
		return false === $sanitized ? '' : $sanitized; }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		++$GLOBALS['ec_artist_test']['uuid'];
		return sprintf( '123e4567-e89b-42d3-a456-%012d', $GLOBALS['ec_artist_test']['uuid'] );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'booking-test-salt:' . $scheme; }
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $site ) {
		return array(
			'main'   => 1,
			'artist' => 4,
			'events' => 7,
		)[ $site ] ?? 0; }
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return $GLOBALS['ec_artist_test']['blog_id']; }
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		$GLOBALS['ec_artist_test']['stack'][] = $GLOBALS['ec_artist_test']['blog_id'];
		$GLOBALS['ec_artist_test']['blog_id'] = (int) $blog_id;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		$GLOBALS['ec_artist_test']['blog_id'] = array_pop( $GLOBALS['ec_artist_test']['stack'] ); }
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		if ( ! empty( $GLOBALS['ec_artist_test']['throw_get_term'] ) ) {
			throw new RuntimeException( 'term read failed' );
		}
		$state = $GLOBALS['ec_artist_test'];
		$term  = $state['terms'][ $state['blog_id'] ][ $term_id ] ?? null;
		return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
	}
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key, $single = false ) {
		unset( $single );
		$state = $GLOBALS['ec_artist_test'];
		return $state['meta'][ $state['blog_id'] ][ $term_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$current = get_term_meta( $term_id, $key, true );
		if ( ! empty( $GLOBALS['ec_artist_test']['fail_term_meta'] ) || $current === $value ) {
			return false;
		}
		$GLOBALS['ec_artist_test']['meta'][ get_current_blog_id() ][ $term_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		if ( ! empty( $GLOBALS['ec_artist_test']['throw_get_post'] ) ) {
			throw new RuntimeException( 'post read failed' );
		}
		$state = $GLOBALS['ec_artist_test'];
		return $state['posts'][ $state['blog_id'] ][ $post_id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		$state = $GLOBALS['ec_artist_test'];
		return $state['post_meta'][ $state['blog_id'] ][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) {
		if ( ! empty( $GLOBALS['ec_artist_test']['venue_terms_error'] ) ) {
			return new WP_Error( 'venue_terms_read_failed', 'simulated venue taxonomy read failure' );
		}
		if ( 'venue' !== $taxonomy ) {
			return array();
		}
		$ids = $GLOBALS['ec_artist_test']['event_venues'][ get_current_blog_id() ][ $post_id ] ?? array();
		return ( $args['fields'] ?? '' ) === 'ids' ? $ids : array();
	}
}
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		return $GLOBALS['ec_artist_test']['parsed_blocks'][ $content ] ?? array();
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['ec_artist_test']['options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['ec_artist_test']['options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['ec_artist_test']['options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 12; }
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return (int) $user_id > 0 ? (object) array( 'ID' => (int) $user_id ) : false; }
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability ) {
		return (int) $user_id > 0 && 'manage_options' !== $capability; }
}
if ( ! function_exists( 'ec_feature_available' ) ) {
	function ec_feature_available( $feature, $user_id ) {
		return 'venue_booking' === $feature && (int) $user_id > 0; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $priority, $accepted_args );
		$GLOBALS['ec_artist_test']['actions'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		$GLOBALS['ec_artist_test']['abilities'][ $name ] = $definition; }
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return $GLOBALS['ec_artist_test']['ability_objects'][ $name ] ?? null; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return $GLOBALS['ec_artist_test']['permalinks'][ get_current_blog_id() ][ $post_id ] ?? 'https://events.example/event/' . (int) $post_id; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['ec_artist_test']['cache_deletes'][] = array( $key, $group );
		return true; }
}
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args, $group, $unique = false ) {
		if ( ! empty( $GLOBALS['ec_artist_test']['throw_scheduler'] ) ) {
			throw new RuntimeException( 'simulated scheduler failure' );
		}
		if ( ! empty( $GLOBALS['ec_artist_test']['scheduler_zero'] ) ) {
			return 0;
		}
		$GLOBALS['ec_artist_test']['scheduled'][] = compact( 'timestamp', 'hook', 'args', 'group', 'unique' );
		return count( $GLOBALS['ec_artist_test']['scheduled'] );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['ec_artist_test']['fired_actions'][ $hook ][] = $args;
	}
}

/** Stateful wpdb fake that enforces the persistence contracts under test. */
final class BookingWpdb {
	public $prefix                            = 'wp_7_';
	public $terms                             = 'wp_7_terms';
	public $posts                             = 'wp_7_posts';
	public $term_relationships                = 'wp_7_term_relationships';
	public $term_taxonomy                     = 'wp_7_term_taxonomy';
	public $insert_id                         = 0;
	public $last_error                        = '';
	public $rows                              = array();
	public $schemas                           = array();
	public $engines                           = array();
	public $schema_omit                       = array();
	public $schema_queries                    = 0;
	public $dropped_indexes                   = array();
	public $fail_reads                        = false;
	public $fail_activity_reads               = false;
	public $fail_inserts                      = false;
	public $fail_updates                      = false;
	public $fail_engine_repair                = false;
	public $race_activity_insert              = false;
	public $race_booking_insert               = false;
	public $race_booking_hash                 = null;
	public $race_activity_read_fail           = false;
	public $race_event_read_fail              = false;
	public $concurrent_role_migration         = false;
	public $reads_before_failure              = null;
	public $last_query                        = '';
	public $fail_activity_inserts             = false;
	public $fail_activity_kinds               = array();
	public $fail_transaction_start            = false;
	public $fail_transaction_commit           = false;
	public $fail_transaction_rollback         = false;
	public $rollback_queries                  = 0;
	public $after_membership_lock             = null;
	public $after_venue_lock                  = null;
	public $fail_venue_lock                   = false;
	public $venue_lock_queries                = 0;
	public $transaction_active                = false;
	public $natural_key_reads_in_transaction  = 0;
	public $get_lock_result                   = 1;
	public $release_lock_result               = 1;
	public $release_lock_results              = array();
	public $release_lock_errors               = array();
	public $lock_names                        = array();
	public $event_dates                       = array();
	public $booking_lock_queries              = 0;
	public $elapse_hold_after_membership_lock = null;
	public $elapse_hold_before_release_update = null;
	public $elapse_hold_before_conversion_update = null;
	public $fail_read_after_conversion_update = false;
	public $fail_clock_reads                  = false;
	public $database_now                     = null;
	private $transaction_snapshot            = null;

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4'; }

	private function current_database_time(): string {
		return null === $this->database_now ? gmdate( 'Y-m-d H:i:s' ) : $this->database_now;
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0]; }
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

	public function apply_schema( $sql ) {
		if ( ! preg_match( '/CREATE TABLE ([^\s(]+)/', $sql, $match ) ) {
			return; }
		$table                   = $match[1];
		$this->engines[ $table ] = $this->engines[ $table ] ?? ( preg_match( '/ENGINE=([a-zA-Z0-9_]+)/', $sql, $engine ) ? $engine[1] : '' );
		$this->schemas[ $table ] = $this->schemas[ $table ] ?? array(
			'columns' => array(),
			'indexes' => array(),
		);
		foreach ( preg_split( '/\R/', $sql ) as $line ) {
			$line = trim( rtrim( $line, ',' ) );
			if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\s*(?:([a-zA-Z0-9_]+))?\s*\(([^)]+)\)/i', $line, $index ) ) {
				$name    = 'PRIMARY KEY' === strtoupper( $index[1] ) ? 'PRIMARY' : $index[2];
				$unique  = 'KEY' !== strtoupper( $index[1] );
				$columns = array_map( 'trim', explode( ',', str_replace( '`', '', $index[3] ) ) );
				if ( ! isset( $this->schemas[ $table ]['indexes'][ $name ] ) && ! in_array( $name, $this->schema_omit[ $table ]['indexes'] ?? array(), true ) ) {
					$this->schemas[ $table ]['indexes'][ $name ] = array(
						'unique'  => $unique,
						'columns' => $columns,
					);
				}
			} elseif ( preg_match( '/^([a-z_]+)\s+(.+)$/', $line, $column ) && ! in_array( $column[1], $this->schema_omit[ $table ]['columns'] ?? array(), true ) ) {
				$definition = $column[2];
				preg_match( '/^(.+?)(?:\s+NOT NULL|\s+NULL|\s+DEFAULT|\s+AUTO_INCREMENT|$)/i', $definition, $type );
				preg_match( "/\sDEFAULT\s+(?:'([^']*)'|([^\s]+))/i", $definition, $default );
				if ( ! isset( $this->schemas[ $table ]['columns'][ $column[1] ] ) ) {
					$this->schemas[ $table ]['columns'][ $column[1] ] = array(
						'Type'    => strtolower( trim( $type[1] ) ),
						'Null'    => false !== stripos( $definition, 'NOT NULL' ) ? 'NO' : 'YES',
						'Default' => isset( $default[1] ) && '' !== $default[1] ? $default[1] : ( $default[2] ?? null ),
						'Extra'   => false !== stripos( $definition, 'AUTO_INCREMENT' ) ? 'auto_increment' : '',
					);
				}
			}
		}
	}

	public function insert( $table, $row ) {
		$this->last_error = '';
		if ( $this->fail_inserts || ( false !== strpos( $table, 'ec_booking_activity' ) && ( $this->fail_activity_inserts || in_array( $row['kind'] ?? '', $this->fail_activity_kinds, true ) ) ) ) {
			$this->last_error = 'simulated insert failure';
			return false;
		}
		if ( false !== strpos( $table, 'ec_bookings' ) && null !== $row['inquiry_idempotency_key'] ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['venue_term_id'] === (int) $row['venue_term_id'] && $existing['inquiry_idempotency_key'] === $row['inquiry_idempotency_key'] ) {
					$this->last_error = 'duplicate venue inquiry idempotency key';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_activity' ) && null !== $row['idempotency_key'] ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['booking_id'] === (int) $row['booking_id'] && $existing['idempotency_key'] === $row['idempotency_key'] ) {
					$this->last_error = 'duplicate booking idempotency key';
					return false;
				}
			}
		}
		$this->insert_id                          = count( $this->rows[ $table ] ?? array() ) + 1;
		$row['id']                                = $this->insert_id;
		$this->rows[ $table ][ $this->insert_id ] = $row;
		if ( false !== strpos( $table, 'ec_bookings' ) && $this->race_booking_insert ) {
			$this->race_booking_insert = false;
			if ( null !== $this->race_booking_hash ) {
				$this->rows[ $table ][ $this->insert_id ]['inquiry_request_hash'] = $this->race_booking_hash;
				$this->race_booking_hash = null;
			}
			$activity_table                                = $this->prefix . 'ec_booking_activity';
			$activity_id                                   = count( $this->rows[ $activity_table ] ?? array() ) + 1;
			$this->rows[ $activity_table ][ $activity_id ] = array(
				'id'              => $activity_id,
				'booking_id'      => $this->insert_id,
				'kind'            => 'inquiry_submitted',
				'actor_type'      => 'anonymous',
				'actor_id'        => null,
				'direction'       => null,
				'channel'         => null,
				'payload'         => '{"version":1,"data":{"status":"submitted"}}',
				'external_id'     => null,
				'idempotency_key' => 'race-winner',
				'occurred_at'     => gmdate( 'Y-m-d H:i:s' ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			);
			$this->transaction_snapshot                    = $this->rows;
			$this->last_error                              = 'simulated concurrent duplicate';
			return false;
		}
		if ( false !== strpos( $table, 'ec_booking_activity' ) && $this->race_activity_insert ) {
			$this->race_activity_insert = false;
			$this->last_error           = 'simulated concurrent duplicate';
			if ( $this->race_activity_read_fail ) {
				$this->fail_activity_reads = true;
			}
			return false;
		}
		return 1;
	}

	/** Make a simulated second-connection commit survive this connection's rollback. */
	public function simulate_external_commit( callable $write ): void {
		$write();
		if ( is_array( $this->transaction_snapshot ) ) {
			$this->transaction_snapshot = $this->rows;
		}
	}

	public function get_var( $query ) {
		$this->last_error = '';
		$database_now = $this->current_database_time();
		if ( preg_match( "/SELECT GET_LOCK\('([^']+)', (\d+)\)/", $query, $match ) ) {
			$this->lock_names[] = array( 'get', stripslashes( $match[1] ) );
			return $this->get_lock_result;
		}
		if ( preg_match( "/SELECT RELEASE_LOCK\('([^']+)'\)/", $query, $match ) ) {
			$this->lock_names[] = array( 'release', stripslashes( $match[1] ) );
			$result = empty( $this->release_lock_results ) ? $this->release_lock_result : array_shift( $this->release_lock_results );
			if ( ! empty( $this->release_lock_errors ) ) {
				$this->last_error = (string) array_shift( $this->release_lock_errors );
			}
			return $result;
		}
		if ( preg_match( "/SELECT id FROM .*ec_booking_holds WHERE id = (\d+) AND status = 'active' AND expires_at <= UTC_TIMESTAMP\(\)/", $query, $match ) ) {
			$row = $this->rows[ $this->prefix . 'ec_booking_holds' ][ (int) $match[1] ] ?? null;
			return is_array( $row ) && 'active' === $row['status'] && $row['expires_at'] <= $database_now ? $row['id'] : null;
		}
		if ( preg_match( "/SELECT id FROM .*ec_bookings WHERE public_id = '([^']+)' AND status = 'confirmed'/", $query, $match ) ) {
			foreach ( $this->rows[ $this->prefix . 'ec_bookings' ] ?? array() as $row ) {
				if ( $row['public_id'] === stripslashes( $match[1] ) && 'confirmed' === $row['status'] ) {
					return $row['id'];
				}
			}
			return null;
		}
		if ( preg_match( "/SELECT id FROM .*ec_booking_holds WHERE booking_id = (\d+) AND venue_term_id = (\d+) AND space_key = '([^']+)' AND start_at = '([^']+)' AND end_at = '([^']+)'.*expires_at > UTC_TIMESTAMP\(\).*id <> (\d+)/", $query, $match ) ) {
			foreach ( $this->rows[ $this->prefix . 'ec_booking_holds' ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $match[1] && (int) $row['venue_term_id'] === (int) $match[2] && $row['space_key'] === stripslashes( $match[3] ) && $row['start_at'] === $match[4] && $row['end_at'] === $match[5] && 'active' === $row['status'] && $row['expires_at'] > $database_now && (int) $row['id'] !== (int) $match[6] ) {
					return $row['id'];
				}
			}
			return null;
		}
		if ( false !== strpos( $query, 'SELECT b.id FROM' ) && false !== strpos( $query, 'INNER JOIN' ) && preg_match( "/b\.venue_term_id = (\d+).*h\.expires_at <= UTC_TIMESTAMP\(\)/", $query, $stale ) ) {
			foreach ( $this->rows[ $this->prefix . 'ec_bookings' ] ?? array() as $booking ) {
				if ( (int) $booking['venue_term_id'] !== (int) $stale[1] || 'held' !== $booking['status'] ) {
					continue;
				}
				foreach ( $this->rows[ $this->prefix . 'ec_booking_holds' ] ?? array() as $hold ) {
					if ( (int) $hold['booking_id'] === (int) $booking['id'] && (int) $hold['venue_term_id'] === (int) $booking['venue_term_id'] && $hold['space_key'] === $booking['space_key'] && $hold['start_at'] === $booking['performance_start_at'] && $hold['end_at'] === $booking['performance_end_at'] && 'active' === $hold['status'] && $hold['expires_at'] <= $database_now ) {
						return $booking['id'];
					}
				}
			}
			return null;
		}
		if ( preg_match( '/SELECT term_id FROM .* WHERE term_id = (\d+) FOR UPDATE/', $query, $match ) ) {
			++$this->venue_lock_queries;
			if ( $this->fail_venue_lock ) {
				$this->last_error = 'simulated venue lock failure';
				return null;
			}
			if ( is_callable( $this->after_venue_lock ) ) {
				$callback               = $this->after_venue_lock;
				$this->after_venue_lock = null;
				$callback();
			}
			return isset( $GLOBALS['ec_artist_test']['terms'][7][ (int) $match[1] ] ) ? (int) $match[1] : null;
		}
		if ( preg_match( "/SELECT id FROM .*ec_bookings WHERE venue_term_id = (\d+) AND inquiry_idempotency_key = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $this->prefix . 'ec_bookings' ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $match[1] && $row['inquiry_idempotency_key'] === stripslashes( $match[2] ) ) {
					return $row['id'];
				}
			}
			return null;
		}
		++$this->schema_queries;
		if ( $this->fail_reads ) {
			$this->last_error = 'simulated schema read failure';
			return null; }
		if ( preg_match( "/LIKE '([^']+)'/", $query, $match ) && isset( $this->schemas[ stripslashes( $match[1] ) ] ) ) {
			return stripslashes( $match[1] ); }
		return null;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		$this->last_query = $query;
		$this->last_error = '';
		if ( false !== strpos( $query, 'DATE_ADD(UTC_TIMESTAMP()' ) ) {
			if ( $this->fail_reads || $this->fail_clock_reads ) {
				$this->last_error = 'simulated clock read failure';
				return null;
			}
			preg_match( '/INTERVAL (\d+) MINUTE/', $query, $ttl );
			$now = $this->current_database_time();
			return array(
				'current_at' => $now,
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + ( (int) $ttl[1] * MINUTE_IN_SECONDS ) ),
			);
		}
		if ( preg_match( "/SHOW TABLE STATUS WHERE Name = '([^']+)'/", $query, $match ) ) {
			$table = stripslashes( $match[1] );
			return isset( $this->engines[ $table ] ) ? array( 'Engine' => $this->engines[ $table ] ) : null;
		}
		$is_activity = false !== strpos( $query, 'ec_booking_activity' );
		$is_hold     = false !== strpos( $query, 'ec_booking_holds' );
		$database_now = $this->current_database_time();
		if ( null !== $this->reads_before_failure ) {
			if ( 0 === $this->reads_before_failure ) {
				$this->last_error = 'simulated delayed row read failure';
				return null;
			}
			--$this->reads_before_failure;
		}
		if ( $this->fail_reads || ( $is_activity && $this->fail_activity_reads ) ) {
			$this->last_error = 'simulated row read failure';
			return null;
		}
		$table = $is_activity ? $this->prefix . 'ec_booking_activity' : ( $is_hold ? $this->prefix . 'ec_booking_holds' : $this->prefix . 'ec_bookings' );
		if ( ! $is_activity && ! $is_hold && false !== strpos( $query, 'FOR UPDATE' ) ) {
			++$this->booking_lock_queries;
		}
		if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			$row = $this->rows[ $table ][ (int) $match[1] ] ?? null;
			if ( is_array( $row ) && false !== strpos( $query, 'AS database_now' ) ) {
				$row['database_now'] = $this->current_database_time();
			}
			return $row; }
		if ( ! $is_hold && false !== strpos( $query, "performance_end_at, 'confirmed_booking' AS conflict_type" ) && preg_match( '/event_id = (\d+)/', $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) ( $row['event_id'] ?? 0 ) === (int) $match[1] && 'confirmed' === $row['status'] ) {
					return array_merge( $row, array( 'conflict_type' => 'confirmed_booking' ) );
				}
			}
			return null;
		}
		if ( $is_hold && false !== strpos( $query, "space_key, 'hold' AS conflict_type" ) ) {
			preg_match( "/venue_term_id = (\d+).*start_at < '([^']+)'.*end_at > '([^']+)'.*booking_id = (\d+)/", $query, $match );
			preg_match_all( "/\(start_at = '([^']+)' AND end_at = '([^']+)'\)/", $query, $exact_matches, PREG_SET_ORDER );
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				$exact_exempt = (int) $row['booking_id'] === (int) $match[4] && array_filter( $exact_matches, static fn( array $exact ): bool => $row['start_at'] === $exact[1] && $row['end_at'] === $exact[2] );
				if ( (int) $row['venue_term_id'] === (int) $match[1] && 'active' === $row['status'] && $row['expires_at'] > $database_now && $row['start_at'] < $match[2] && $row['end_at'] > $match[3] && ! $exact_exempt ) {
					return array(
						'id'            => $row['id'],
						'booking_id'    => $row['booking_id'],
						'space_key'     => $row['space_key'],
						'conflict_type' => 'hold',
					);
				}
			}
			return null;
		}
		if ( ! $is_hold && false !== strpos( $query, "space_key, 'confirmed_booking' AS conflict_type" ) ) {
			preg_match( "/venue_term_id = (\d+).*performance_start_at < '([^']+)'.*performance_end_at > '([^']+)'.*id = (\d+) OR event_id = (\d+)/", $query, $match );
			preg_match_all( "/\(performance_start_at = '([^']+)' AND performance_end_at = '([^']+)'\)/", $query, $exact_matches, PREG_SET_ORDER );
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				$origin = ( (int) $match[4] > 0 && (int) $row['id'] === (int) $match[4] )
					|| ( (int) $match[5] > 0 && (int) ( $row['event_id'] ?? 0 ) === (int) $match[5] );
				$exact_exempt = $origin && array_filter( $exact_matches, static fn( array $exact ): bool => $row['performance_start_at'] === $exact[1] && $row['performance_end_at'] === $exact[2] );
				if ( (int) $row['venue_term_id'] === (int) $match[1] && 'confirmed' === $row['status'] && $row['performance_start_at'] < $match[2] && $row['performance_end_at'] > $match[3] && ! $exact_exempt ) {
					return array(
						'id'            => $row['id'],
						'space_key'     => $row['space_key'],
						'conflict_type' => 'confirmed_booking',
					);
				}
			}
			return null;
		}
		if ( $is_hold && false !== strpos( $query, 'booking_id =' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( preg_match( '/booking_id = (\d+)/', $query, $match ) && (int) $row['booking_id'] !== (int) $match[1] ) {
					continue;
				}
				if ( preg_match( "/venue_term_id = (\d+).*space_key = '([^']+)'.*start_at = '([^']+)'.*end_at = '([^']+)'.*expires_at > '([^']+)'/", $query, $match ) && ( (int) $row['venue_term_id'] !== (int) $match[1] || $row['space_key'] !== stripslashes( $match[2] ) || $row['start_at'] !== $match[3] || $row['end_at'] !== $match[4] || $row['expires_at'] <= $match[5] ) ) {
					continue;
				}
				if ( preg_match( "/venue_term_id = (\d+).*space_key = '([^']+)'.*start_at = '([^']+)'.*end_at = '([^']+)'.*expires_at <= '([^']+)'/", $query, $match ) && ( (int) $row['venue_term_id'] !== (int) $match[1] || $row['space_key'] !== stripslashes( $match[2] ) || $row['start_at'] !== $match[3] || $row['end_at'] !== $match[4] || $row['expires_at'] > $match[5] ) ) {
					continue;
				}
				if ( false !== strpos( $query, 'expires_at > UTC_TIMESTAMP()' ) && $row['expires_at'] <= $database_now ) {
					continue;
				}
				if ( false !== strpos( $query, 'expires_at <= UTC_TIMESTAMP()' ) && $row['expires_at'] > $database_now ) {
					continue;
				}
				if ( false !== strpos( $query, "status = 'active'" ) && 'active' !== $row['status'] ) {
					continue;
				}
				if ( false !== strpos( $query, 'AS database_now' ) ) {
					$row['database_now'] = $this->current_database_time();
				}
				return $row;
			}
			return null;
		}
		if ( $is_hold && false !== strpos( $query, "'hold' AS conflict_type" ) ) {
			preg_match( "/venue_term_id = (\d+) AND space_key = '([^']+)' AND status = 'active' AND expires_at > UTC_TIMESTAMP\(\) AND start_at < '([^']+)' AND end_at > '([^']+)' AND booking_id <> (\d+) AND id <> (\d+)/", $query, $match );
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $match[1] && $row['space_key'] === stripslashes( $match[2] ) && 'active' === $row['status'] && $row['expires_at'] > $database_now && $row['start_at'] < $match[3] && $row['end_at'] > $match[4] && (int) $row['booking_id'] !== (int) $match[5] && (int) $row['id'] !== (int) $match[6] ) {
					return array(
						'id'            => $row['id'],
						'booking_id'    => $row['booking_id'],
						'conflict_type' => 'hold',
					);
				}
			}
			return null;
		}
		if ( ! $is_hold && false !== strpos( $query, "'confirmed_booking' AS conflict_type" ) ) {
			preg_match( "/venue_term_id = (\d+) AND space_key = '([^']+)'.*performance_start_at < '([^']+)' AND performance_end_at > '([^']+)' AND id <> (\d+)/", $query, $match );
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $match[1] && $row['space_key'] === stripslashes( $match[2] ) && 'confirmed' === $row['status'] && $row['performance_start_at'] < $match[3] && $row['performance_end_at'] > $match[4] && (int) $row['id'] !== (int) $match[5] ) {
					return array(
						'id'            => $row['id'],
						'conflict_type' => 'confirmed_booking',
					);
				}
			}
			return null;
		}
		if ( false !== strpos( $query, "'canonical_event' AS conflict_type" ) ) {
			preg_match( "/tt.term_id = (\d+) AND p.ID <> (\d+) AND ed.start_datetime < '([^']+)'.*ed.end_datetime > '([^']+)'.*ed.start_datetime >= '([^']+)'/", $query, $match );
			foreach ( $this->event_dates as $event ) {
				if ( (int) $event['venue_term_id'] !== (int) $match[1] || (int) $event['post_id'] === (int) $match[2] || 'publish' !== $event['post_status'] ) {
					continue;
				}
				if ( $event['start_datetime'] < $match[3] && ( ( null !== $event['end_datetime'] && $event['end_datetime'] > $match[4] ) || ( null === $event['end_datetime'] && $event['start_datetime'] >= $match[5] ) ) ) {
					return array(
						'id'            => $event['post_id'],
						'conflict_type' => 'canonical_event',
					);
				}
			}
			return null;
		}
		if ( preg_match( "/WHERE public_id = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( stripslashes( $match[1] ) === $row['public_id'] ) {
					return $row; }
			}
		}
		if ( preg_match( "/WHERE venue_term_id = (\d+) AND inquiry_idempotency_key = '([^']+)'/", $query, $match ) ) {
			if ( $this->transaction_active ) {
				++$this->natural_key_reads_in_transaction;
			}
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $match[1] && stripslashes( $match[2] ) === $row['inquiry_idempotency_key'] ) {
					return $row;
				}
			}
		}
		if ( preg_match( "/WHERE booking_id = (\d+) AND idempotency_key = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $match[1] && stripslashes( $match[2] ) === $row['idempotency_key'] ) {
					return $row; }
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		$this->last_query = $query;
		$this->last_error = '';
		if ( $this->fail_reads ) {
			$this->last_error = 'simulated result read failure';
			return null; }
		if ( false !== strpos( $query, 'INNER JOIN' ) && false !== strpos( $query, 'ec_booking_holds' ) && preg_match( "/b\.venue_term_id = (\d+).*h\.expires_at <= UTC_TIMESTAMP\(\)/", $query, $stale ) ) {
			$rows = array();
			foreach ( $this->rows[ $this->prefix . 'ec_bookings' ] ?? array() as $booking ) {
				if ( (int) $booking['venue_term_id'] !== (int) $stale[1] || 'held' !== $booking['status'] ) {
					continue;
				}
				foreach ( $this->rows[ $this->prefix . 'ec_booking_holds' ] ?? array() as $hold ) {
					if ( (int) $hold['booking_id'] === (int) $booking['id'] && (int) $hold['venue_term_id'] === (int) $booking['venue_term_id'] && $hold['space_key'] === $booking['space_key'] && $hold['start_at'] === $booking['performance_start_at'] && $hold['end_at'] === $booking['performance_end_at'] && 'active' === $hold['status'] && $hold['expires_at'] <= $this->current_database_time() ) {
						$rows[] = $booking;
						break;
					}
				}
			}
			usort(
				$rows,
				static function ( $left, $right ) {
					return $left['id'] <=> $right['id'];
				}
			);
			return array_slice( $rows, 0, 100 );
		}
		if ( preg_match( '/SHOW COLUMNS FROM `([^`]+)`/', $query, $match ) ) {
			++$this->schema_queries;
			$rows = array();
			foreach ( $this->schemas[ $match[1] ]['columns'] ?? array() as $name => $metadata ) {
				$rows[] = array_merge( array( 'Field' => $name ), $metadata );
			}
			return $rows;
		}
		if ( false !== strpos( $query, 'ec_venue_members' ) && false !== strpos( $query, 'FOR UPDATE' ) ) {
			if ( is_callable( $this->after_membership_lock ) ) {
				$callback                    = $this->after_membership_lock;
				$this->after_membership_lock = null;
				$callback();
			}
			if ( null !== $this->elapse_hold_after_membership_lock ) {
				$hold_id = (int) $this->elapse_hold_after_membership_lock;
				$past    = gmdate( 'Y-m-d H:i:s' );
				$table   = $this->prefix . 'ec_booking_holds';
				$this->rows[ $table ][ $hold_id ]['expires_at'] = $past;
				if ( is_array( $this->transaction_snapshot ) ) {
					$this->transaction_snapshot[ $table ][ $hold_id ]['expires_at'] = $past;
				}
				$this->elapse_hold_after_membership_lock = null;
			}
			return array_values( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() );
		}
		if ( preg_match( '/SHOW INDEX FROM `([^`]+)`/', $query, $match ) ) {
			++$this->schema_queries;
			$rows = array();
			foreach ( $this->schemas[ $match[1] ]['indexes'] ?? array() as $name => $index ) {
				foreach ( $index['columns'] as $position => $column ) {
					$rows[] = array(
						'Key_name'     => $name,
						'Non_unique'   => $index['unique'] ? 0 : 1,
						'Seq_in_index' => $position + 1,
						'Column_name'  => $column,
					);
				}
			}
			return $rows;
		}
		$is_activity = false !== strpos( $query, 'ec_booking_activity' );
		$is_hold     = false !== strpos( $query, 'ec_booking_holds' );
		$table       = $is_activity ? $this->prefix . 'ec_booking_activity' : ( $is_hold ? $this->prefix . 'ec_booking_holds' : $this->prefix . 'ec_bookings' );
		$rows        = array_values( $this->rows[ $table ] ?? array() );
		if ( false !== strpos( $query, 'AS database_now' ) ) {
			$database_now = $this->current_database_time();
			foreach ( $rows as &$row ) {
				$row['database_now'] = $database_now;
			}
			unset( $row );
		}
		if ( $is_activity && false !== strpos( $query, "kind IN ('event_conversion_started', 'event_conversion_failed', 'event_converted')" ) ) {
			preg_match( "/idempotency_key LIKE '([^']+)%'/", $query, $key_prefix );
			$prefix = isset( $key_prefix[1] ) ? stripslashes( $key_prefix[1] ) : '';
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $prefix ) {
						return in_array( $row['kind'], array( 'event_conversion_started', 'event_conversion_failed', 'event_converted' ), true ) || ( '' !== $prefix && 0 === strpos( (string) $row['idempotency_key'], $prefix ) );
					}
				)
			);
			usort( $rows, static function ( $left, $right ) { return $left['id'] <=> $right['id']; } );
		}
		if ( $is_hold && false !== strpos( $query, "status = 'converted'" ) ) {
			foreach ( array( 'space_key', 'start_at', 'end_at' ) as $field ) {
				if ( preg_match( "/{$field} = '([^']+)'/", $query, $exact ) ) {
					$rows = array_values( array_filter( $rows, static function ( $row ) use ( $field, $exact ) { return $row[ $field ] === stripslashes( $exact[1] ); } ) );
				}
			}
			$rows = array_values( array_filter( $rows, static function ( $row ) { return null !== $row['converted_at']; } ) );
		}
		$filters     = array( 'venue_term_id', 'artist_term_id', 'artist_profile_id', 'assignee_user_id', 'booking_id' );
		foreach ( $filters as $field ) {
			if ( preg_match( "/{$field} = (\\d+)/", $query, $filter ) ) {
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $field, $filter ) {
							return (int) ( $row[ $field ] ?? 0 ) === (int) $filter[1];
						}
					)
				);
			}
		}
		if ( $is_hold && false !== strpos( $query, "(status = 'expired' OR (status = 'active'" ) && false !== strpos( $query, 'expires_at <= UTC_TIMESTAMP()' ) ) {
			$now  = $this->current_database_time();
			$rows = array_values( array_filter( $rows, static function ( $row ) use ( $now ) { return 'expired' === $row['status'] || ( 'active' === $row['status'] && $row['expires_at'] <= $now ); } ) );
		} elseif ( $is_hold && false !== strpos( $query, "status = 'active' AND expires_at > UTC_TIMESTAMP()" ) ) {
			$now  = $this->current_database_time();
			$rows = array_values( array_filter( $rows, static function ( $row ) use ( $now ) { return 'active' === $row['status'] && $row['expires_at'] > $now; } ) );
		} elseif ( $is_hold && false !== strpos( $query, "(status = 'expired' OR (status = 'active'" ) && preg_match( "/expires_at <= '([^']+)'/", $query, $filter ) ) {
			$rows = array_values( array_filter( $rows, static function ( $row ) use ( $filter ) { return 'expired' === $row['status'] || ( 'active' === $row['status'] && $row['expires_at'] <= $filter[1] ); } ) );
		} elseif ( $is_hold && false !== strpos( $query, "status = 'active' AND expires_at >" ) && preg_match( "/expires_at > '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return 'active' === $row['status'] && $row['expires_at'] > $filter[1];
					}
				)
			);
		} elseif ( preg_match( "/status = '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return stripslashes( $filter[1] ) === $row['status'];
					}
				)
			);
		}
		if ( preg_match( "/requested_start_at >= '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return null !== $row['requested_start_at'] && $row['requested_start_at'] >= stripslashes( $filter[1] );
					}
				)
			);
		}
		if ( preg_match( "/requested_end_at <= '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return null !== $row['requested_end_at'] && $row['requested_end_at'] <= stripslashes( $filter[1] );
					}
				)
			);
		}
		if ( $is_hold && preg_match( "/end_at > '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return $row['end_at'] > stripslashes( $filter[1] );
					}
				)
			);
		}
		if ( $is_hold && preg_match( "/start_at < '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return $row['start_at'] < stripslashes( $filter[1] );
					}
				)
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$date_order = $b['created_at'] <=> $a['created_at'];
				return 0 !== $date_order ? $date_order : ( $b['id'] <=> $a['id'] );
			}
		);
		if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $page ) ) {
			$rows = array_slice( $rows, (int) $page[2], (int) $page[1] );
		}
		return $rows;
	}

	public function query( $query ) {
		$this->last_query = $query;
		$this->last_error = '';
		if ( 'START TRANSACTION' === $query ) {
			if ( $this->fail_transaction_start ) {
				$this->last_error = 'simulated transaction start failure';
				return false;
			}
			$this->transaction_snapshot = $this->rows;
			$this->transaction_active   = true;
			return 1;
		}
		if ( 'COMMIT' === $query ) {
			if ( $this->fail_transaction_commit ) {
				$this->last_error = 'simulated transaction commit failure';
				return false;
			}
			$this->transaction_snapshot = null;
			$this->transaction_active   = false;
			return 1;
		}
		if ( 'ROLLBACK' === $query ) {
			++$this->rollback_queries;
			if ( $this->fail_transaction_rollback ) {
				$this->last_error = 'simulated transaction rollback failure';
				return false;
			}
			$this->rows                 = $this->transaction_snapshot;
			$this->transaction_snapshot = null;
			$this->transaction_active   = false;
			return 1;
		}
		if ( preg_match( "/UPDATE `([^`]+)` SET is_owner = IF\(role = 'owner', 1, 0\)/", $query, $migration ) ) {
			$this->rows[ $migration[1] ] = $this->rows[ $migration[1] ] ?? array();
			foreach ( $this->rows[ $migration[1] ] as &$row ) {
				$row['is_owner'] = 'owner' === ( $row['role'] ?? '' ) ? 1 : 0;
				if ( $this->concurrent_role_migration ) {
					unset( $row['role'] );
				}
			}
			unset( $row );
			if ( $this->concurrent_role_migration ) {
				$this->concurrent_role_migration = false;
				unset( $this->schemas[ $migration[1] ]['columns']['role'], $this->schemas[ $migration[1] ]['indexes']['venue_status_role'] );
				$this->last_error = 'simulated concurrent migration';
				return false;
			}
			return 1;
		}
		if ( preg_match( '/ALTER TABLE `([^`]+)` ENGINE=([a-zA-Z0-9_]+)/', $query, $engine ) ) {
			if ( $this->fail_engine_repair ) {
				$this->last_error = 'simulated engine conversion failure';
				return false;
			}
			$this->engines[ $engine[1] ] = $engine[2];
			return 1;
		}
		if ( preg_match( '/ALTER TABLE `([^`]+)` DROP COLUMN `([^`]+)`/', $query, $drop_column ) ) {
			unset( $this->schemas[ $drop_column[1] ]['columns'][ $drop_column[2] ] );
			$this->rows[ $drop_column[1] ] = $this->rows[ $drop_column[1] ] ?? array();
			foreach ( $this->rows[ $drop_column[1] ] as &$row ) {
				unset( $row[ $drop_column[2] ] );
			}
			unset( $row );
			return 1;
		}
		if ( preg_match( '/ALTER TABLE `([^`]+)` DROP (?:INDEX `([^`]+)`|PRIMARY KEY)/', $query, $drop ) ) {
			$name = ! empty( $drop[2] ) ? $drop[2] : 'PRIMARY';
			unset( $this->schemas[ $drop[1] ]['indexes'][ $name ] );
			if ( 'PRIMARY' === $name && preg_match( '/ADD PRIMARY KEY \(([^)]+)\)/', $query, $primary ) ) {
				$this->schemas[ $drop[1] ]['indexes']['PRIMARY'] = array(
					'unique'  => true,
					'columns' => array_map( 'trim', explode( ',', str_replace( '`', '', $primary[1] ) ) ),
				);
			}
			$this->dropped_indexes[] = array(
				'table' => $drop[1],
				'index' => $name,
			);
			return 1;
		}
		if ( $this->fail_updates ) {
			$this->last_error = 'simulated update failure';
			return false; }
		if ( false !== strpos( $query, 'expires_at > UTC_TIMESTAMP()' ) && preg_match( '/UPDATE ([^ ]+).*WHERE id = (\d+) AND version = (\d+)/', $query, $release_hold ) ) {
			$table    = $release_hold[1];
			$id       = (int) $release_hold[2];
			$expected = (int) $release_hold[3];
			if ( (int) $this->elapse_hold_before_release_update === $id ) {
				$past                                      = gmdate( 'Y-m-d H:i:s' );
				$this->rows[ $table ][ $id ]['expires_at'] = $past;
				if ( is_array( $this->transaction_snapshot ) ) {
					$this->transaction_snapshot[ $table ][ $id ]['expires_at'] = $past;
				}
				$this->elapse_hold_before_release_update = null;
			}
			if ( false !== strpos( $query, "status = 'converted'" ) && (int) $this->elapse_hold_before_conversion_update === $id ) {
				$past = gmdate( 'Y-m-d H:i:s' );
				$this->rows[ $table ][ $id ]['expires_at'] = $past;
				if ( is_array( $this->transaction_snapshot ) ) {
					$this->transaction_snapshot[ $table ][ $id ]['expires_at'] = $past;
				}
				$this->elapse_hold_before_conversion_update = null;
			}
			if ( false !== strpos( $query, "status = 'converted'" ) && $this->fail_read_after_conversion_update ) {
				$this->reads_before_failure = 0;
				$this->fail_read_after_conversion_update = false;
			}
			if ( ! isset( $this->rows[ $table ][ $id ] ) || (int) $this->rows[ $table ][ $id ]['version'] !== $expected || 'active' !== $this->rows[ $table ][ $id ]['status'] || $this->rows[ $table ][ $id ]['expires_at'] <= $this->current_database_time() ) {
				return 0;
			}
		}
		if ( false !== strpos( $query, "SET status = 'expired'" ) && preg_match( "/UPDATE ([^ ]+).*WHERE booking_id = (\d+).*expires_at <= UTC_TIMESTAMP\(\).*id <> (\d+)/", $query, $expired ) ) {
			$count = 0;
			$this->rows[ $expired[1] ] = $this->rows[ $expired[1] ] ?? array();
			foreach ( $this->rows[ $expired[1] ] as &$row ) {
				if ( (int) $row['booking_id'] === (int) $expired[2] && 'active' === $row['status'] && $row['expires_at'] <= $this->current_database_time() && (int) $row['id'] !== (int) $expired[3] ) {
					$row['status']     = 'expired';
					$row['expired_at'] = gmdate( 'Y-m-d H:i:s' );
					++$row['version'];
					++$count;
				}
			}
			unset( $row );
			return $count;
		}
		if ( false !== strpos( $query, "SET status = 'released'" ) && preg_match( "/UPDATE ([^ ]+).*WHERE booking_id = (\d+).*expires_at > UTC_TIMESTAMP\(\).*id <> (\d+)/", $query, $release ) ) {
			$count = 0;
			$this->rows[ $release[1] ] = $this->rows[ $release[1] ] ?? array();
			foreach ( $this->rows[ $release[1] ] as &$row ) {
				if ( (int) $row['booking_id'] === (int) $release[2] && 'active' === $row['status'] && $row['expires_at'] > $this->current_database_time() && (int) $row['id'] !== (int) $release[3] ) {
					$row['status'] = 'released';
					++$row['version'];
					++$count;
				}
			}
			unset( $row );
			return $count;
		}
		if ( ! preg_match( '/UPDATE ([^ ]+) SET .*WHERE id = (\d+) AND version = (\d+)/', $query, $match ) ) {
			return false; }
		$table    = $match[1];
		$id       = (int) $match[2];
		$expected = (int) $match[3];
		if ( ! isset( $this->rows[ $table ][ $id ] ) || (int) $this->rows[ $table ][ $id ]['version'] !== $expected ) {
			if ( $this->race_event_read_fail ) {
				$this->reads_before_failure = 1;
			}
			return 0; }
		if ( false !== strpos( $query, 'event_id IS NULL' ) && null !== $this->rows[ $table ][ $id ]['event_id'] ) {
			return 0; }
		$set = substr( $query, strpos( $query, ' SET ' ) + 5, strpos( $query, ' WHERE ' ) - strpos( $query, ' SET ' ) - 5 );
		preg_match_all( "/([a-z_]+) = (version \\+ 1|NULL|\\d+|[a-z_]+|'(?:\\\\.|[^'])*')(?=, [a-z_]+ = |$)/", $set, $assignments, PREG_SET_ORDER );
		foreach ( $assignments as $assignment ) {
			$column = $assignment[1];
			$value  = $assignment[2];
			if ( 'version + 1' === $value ) {
				++$this->rows[ $table ][ $id ]['version'];
			} elseif ( 'NULL' === $value ) {
				$this->rows[ $table ][ $id ][ $column ] = null;
			} elseif ( "'" === substr( $value, 0, 1 ) ) {
				$this->rows[ $table ][ $id ][ $column ] = stripslashes( substr( $value, 1, -1 ) );
			} elseif ( preg_match( '/^[a-z_]+$/', $value ) ) {
				$this->rows[ $table ][ $id ][ $column ] = $this->rows[ $table ][ $id ][ $value ] ?? null;
			} else {
				$this->rows[ $table ][ $id ][ $column ] = (int) $value;
			}
		}
		return 1;
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueAuthorization.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingActivityRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingHoldRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingMutationService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingEventConversionService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingLifecycle.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueBookingConfig.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/CanonicalEventPublicationGuard.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingHoldAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingMutationAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingEventAbilities.php';

final class BookingTestAuthorization extends VenueAuthorization {
	public $calls   = array();
	public $allowed = array();
	public $require_locked_membership = false;
	public function __construct( array $allowed = array() ) {
		parent::__construct();
		$this->allowed = array_merge( array( '12:55' => true ), $allowed );
	}
	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		$this->calls[] = array( $user_id, $venue_term_id, $action );
		return ! empty( $this->allowed[ $user_id . ':' . $venue_term_id ] ) ? true : new WP_Error( 'venue_action_forbidden' );
	}
	public function authorize_locked( int $user_id, int $venue_term_id, string $action, array $locked_memberships ) {
		if ( $this->require_locked_membership ) {
			return parent::authorize_locked( $user_id, $venue_term_id, $action, $locked_memberships );
		}
		return $this->authorize( $user_id, $venue_term_id, $action );
	}
}

final class BookingTestConfig extends VenueBookingConfig {
	public function get( int $venue_term_id ) {
		unset( $venue_term_id );
		$GLOBALS['wpdb']->last_error = 'simulated config read failure';
		return array( 'enabled' => true );
	}
}

final class BookingTestVenueTaxonomy {
	public static function resolve_venue_identity( string $venue_name, array $venue_data = array() ): array {
		unset( $venue_data );
		foreach ( $GLOBALS['ec_artist_test']['terms'][ get_current_blog_id() ] ?? array() as $term ) {
			if ( 'venue' === $term->taxonomy && 0 === strcasecmp( $term->name, $venue_name ) ) {
				return array( 'term_id' => (int) $term->term_id, 'match_status' => 'matched' );
			}
		}
		return array( 'term_id' => null, 'match_status' => 'no_match' );
	}
}

if ( ! class_exists( '\\DataMachineEvents\\Core\\Venue_Taxonomy' ) ) {
	class_alias( BookingTestVenueTaxonomy::class, '\\DataMachineEvents\\Core\\Venue_Taxonomy' );
}

final class BookingTestRestRequest {
	private $params;
	public function __construct( array $params ) {
		$this->params = $params;
	}
	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
}
