<?php
/**
 * Shared venue booking test harness.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Abilities\VenueBookingAbilities;
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
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback ) {
		$GLOBALS['ec_artist_test']['actions'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		$GLOBALS['ec_artist_test']['abilities'][ $name ] = $definition; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['ec_artist_test']['cache_deletes'][] = array( $key, $group );
		return true; }
}

/** Stateful wpdb fake that enforces the persistence contracts under test. */
final class BookingWpdb {
	public $prefix                    = 'wp_7_';
	public $terms                     = 'wp_7_terms';
	public $insert_id                 = 0;
	public $last_error                = '';
	public $rows                      = array();
	public $schemas                   = array();
	public $engines                   = array();
	public $schema_omit               = array();
	public $schema_queries            = 0;
	public $dropped_indexes           = array();
	public $fail_reads                = false;
	public $fail_activity_reads       = false;
	public $fail_inserts              = false;
	public $fail_updates              = false;
	public $fail_engine_repair        = false;
	public $race_activity_insert      = false;
	public $race_booking_insert       = false;
	public $race_booking_hash         = null;
	public $race_activity_read_fail   = false;
	public $race_event_read_fail      = false;
	public $concurrent_role_migration = false;
	public $reads_before_failure      = null;
	public $last_query                = '';
	public $fail_activity_inserts     = false;
	public $fail_transaction_start    = false;
	public $fail_transaction_commit   = false;
	public $fail_transaction_rollback = false;
	public $rollback_queries          = 0;
	public $after_membership_lock     = null;
	public $after_venue_lock          = null;
	public $fail_venue_lock           = false;
	public $venue_lock_queries        = 0;
	public $transaction_active        = false;
	public $natural_key_reads_in_transaction = 0;
	private $transaction_snapshot     = null;

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4'; }

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
		if ( $this->fail_inserts || ( $this->fail_activity_inserts && false !== strpos( $table, 'ec_booking_activity' ) ) ) {
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
			$activity_table = $this->prefix . 'ec_booking_activity';
			$activity_id    = count( $this->rows[ $activity_table ] ?? array() ) + 1;
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
			$this->transaction_snapshot = $this->rows;
			$this->last_error          = 'simulated concurrent duplicate';
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

	public function get_var( $query ) {
		$this->last_error = '';
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
		if ( preg_match( "/SHOW TABLE STATUS WHERE Name = '([^']+)'/", $query, $match ) ) {
			$table = stripslashes( $match[1] );
			return isset( $this->engines[ $table ] ) ? array( 'Engine' => $this->engines[ $table ] ) : null;
		}
		$is_activity = false !== strpos( $query, 'ec_booking_activity' );
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
		$table = $is_activity ? $this->prefix . 'ec_booking_activity' : $this->prefix . 'ec_bookings';
		if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			return $this->rows[ $table ][ (int) $match[1] ] ?? null; }
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
		$table       = $is_activity ? $this->prefix . 'ec_booking_activity' : $this->prefix . 'ec_bookings';
		$rows        = array_values( $this->rows[ $table ] ?? array() );
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
		if ( preg_match( "/status = '([^']+)'/", $query, $filter ) ) {
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
		if ( ! preg_match( '/WHERE id = (\d+) AND version = (\d+)/', $query, $match ) ) {
			return false; }
		$id       = (int) $match[1];
		$expected = (int) $match[2];
		$table    = $this->prefix . 'ec_bookings';
		if ( ! isset( $this->rows[ $table ][ $id ] ) || (int) $this->rows[ $table ][ $id ]['version'] !== $expected ) {
			if ( $this->race_event_read_fail ) {
				$this->reads_before_failure = 1;
			}
			return 0; }
		if ( false !== strpos( $query, 'event_id IS NULL' ) && null !== $this->rows[ $table ][ $id ]['event_id'] ) {
			return 0; }
		$set = substr( $query, strpos( $query, ' SET ' ) + 5, strpos( $query, ' WHERE ' ) - strpos( $query, ' SET ' ) - 5 );
		preg_match_all( "/([a-z_]+) = (version \\+ 1|NULL|\\d+|'(?:\\\\.|[^'])*')(?=, [a-z_]+ = |$)/", $set, $assignments, PREG_SET_ORDER );
		foreach ( $assignments as $assignment ) {
			$column = $assignment[1];
			$value  = $assignment[2];
			if ( 'version + 1' === $value ) {
				++$this->rows[ $table ][ $id ]['version'];
			} elseif ( 'NULL' === $value ) {
				$this->rows[ $table ][ $id ][ $column ] = null;
			} elseif ( "'" === substr( $value, 0, 1 ) ) {
				$this->rows[ $table ][ $id ][ $column ] = stripslashes( substr( $value, 1, -1 ) );
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
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingLifecycle.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueBookingConfig.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingAbilities.php';

final class BookingTestAuthorization extends VenueAuthorization {
	public $calls = array();
	public $allowed = array();
	public function __construct( array $allowed = array() ) {
		$this->allowed = array_merge( array( '12:55' => true ), $allowed );
	}
	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		$this->calls[] = array( $user_id, $venue_term_id, $action );
		return ! empty( $this->allowed[ $user_id . ':' . $venue_term_id ] ) ? true : new WP_Error( 'venue_action_forbidden' );
	}
}

final class BookingTestConfig extends VenueBookingConfig {
	public function get( int $venue_term_id ) {
		unset( $venue_term_id );
		$GLOBALS['wpdb']->last_error = 'simulated config read failure';
		return array( 'enabled' => true );
	}
}
