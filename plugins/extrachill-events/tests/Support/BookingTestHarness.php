<?php
/**
 * Shared venue booking test harness.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingAttachmentPolicy;
use ExtraChillEvents\Core\BookingCommunicationService;
use ExtraChillEvents\Core\BookingCorrespondenceAutomationService;
use ExtraChillEvents\Core\BookingNotificationService;
use ExtraChillEvents\Core\BookingPrivateFileProvider;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
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
use ExtraChillEvents\Abilities\VenueBookingCommunicationAbilities;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\LocalSupportSchema;

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
		public function add_data( $data ) {
			$this->data = $data; }
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
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $value ) {
		return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $value ) {
		$value = basename( (string) $value );
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $value ); }
}
if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ) {
		$mimes = $mimes ? $mimes : array();
		foreach ( $mimes as $extensions => $mime ) {
			if ( preg_match( '/\.(' . $extensions . ')$/i', $filename, $match ) ) {
				return array(
					'ext'  => strtolower( $match[1] ),
					'type' => $mime,
				);
			}
		}
		return array(
			'ext'  => false,
			'type' => false,
		);
	}
}
if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size() {
		return $GLOBALS['ec_artist_test']['max_upload_size'] ?? 2 * 1024 * 1024;
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return array(
			'basedir' => $GLOBALS['ec_artist_test']['uploads_basedir'] ?? ABSPATH . 'uploads',
		);
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			static $venue_sequence = 0;
			++$venue_sequence;
			return sprintf( '00000000-0000-4000-8000-%012d', $venue_sequence );
		}
		++$GLOBALS['ec_artist_test']['uuid'];
		return sprintf( '123e4567-e89b-42d3-a456-%012d', $GLOBALS['ec_artist_test']['uuid'] );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'booking-test-salt:' . $scheme; }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[aObisdN]:/', $value ) ) {
			return $value;
		}
		return unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test double for trusted fixture values.
	}
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
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			return $GLOBALS['venue_membership_test']['current_blog_id'];
		}
		return $GLOBALS['ec_artist_test']['blog_id']; }
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			$GLOBALS['venue_membership_test']['blog_stack'][]  = $GLOBALS['venue_membership_test']['current_blog_id'];
			$GLOBALS['venue_membership_test']['current_blog_id'] = (int) $blog_id;
			return;
		}
		$GLOBALS['ec_artist_test']['stack'][] = $GLOBALS['ec_artist_test']['blog_id'];
		$GLOBALS['ec_artist_test']['blog_id'] = (int) $blog_id;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			$GLOBALS['venue_membership_test']['current_blog_id'] = array_pop( $GLOBALS['venue_membership_test']['blog_stack'] );
			return;
		}
		$GLOBALS['ec_artist_test']['blog_id'] = array_pop( $GLOBALS['ec_artist_test']['stack'] ); }
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			$term = $GLOBALS['venue_membership_test']['terms'][ $term_id ] ?? null;
			return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
		}
		if ( ! empty( $GLOBALS['ec_artist_test']['throw_get_term'] ) ) {
			throw new RuntimeException( 'term read failed' );
		}
		$state = $GLOBALS['ec_artist_test'];
		$term  = $state['terms'][ $state['blog_id'] ][ $term_id ] ?? null;
		return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args ) {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		$include  = array_map( 'intval', (array) ( $args['include'] ?? array() ) );
		$error_id = empty( $include ) ? 0 : (int) reset( $include );
		if ( ! empty( $GLOBALS['ec_artist_test']['term_query_db_errors'][ $taxonomy ][ $error_id ] ) ) {
			$GLOBALS['wpdb']->last_error = 'simulated empty term query database failure';
			return array();
		}
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			$terms = array_values( $GLOBALS['venue_membership_test']['terms'] ?? array() );
		} else {
			$terms = array_values( $GLOBALS['ec_artist_test']['terms'][ get_current_blog_id() ] ?? array() );
		}
		$terms = array_values(
			array_filter(
				$terms,
				static function ( $term ) use ( $taxonomy, $include ): bool {
					return $taxonomy === $term->taxonomy && ( empty( $include ) || in_array( (int) $term->term_id, $include, true ) );
				}
			)
		);
		return 'ids' === ( $args['fields'] ?? '' ) ? array_map( static fn( $term ): int => (int) $term->term_id, $terms ) : $terms;
	}
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key, $single = false ) {
		unset( $single );
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			return $GLOBALS['venue_membership_test']['term_meta'][ $term_id ][ $key ] ?? '';
		}
		$state = $GLOBALS['ec_artist_test'];
		return $state['meta'][ $state['blog_id'] ][ $term_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
	function data_machine_events_get_venue_data( int $term_id ) {
		if ( array_key_exists( 'venue_projection_result', $GLOBALS['ec_artist_test'] ?? array() ) ) {
			$result = $GLOBALS['ec_artist_test']['venue_projection_result'];
			return is_callable( $result ) ? $result( $term_id ) : $result;
		}
		$meta = $GLOBALS['ec_artist_test']['meta'][ get_current_blog_id() ][ $term_id ] ?? array();
		return array(
			'address'     => $meta['_venue_address'] ?? '',
			'city'        => $meta['_venue_city'] ?? '',
			'state'       => $meta['_venue_state'] ?? '',
			'zip'         => $meta['_venue_zip'] ?? '',
			'country'     => $meta['_venue_country'] ?? '',
			'phone'       => $meta['_venue_phone'] ?? '',
			'website'     => $meta['_venue_website'] ?? '',
			'coordinates' => $meta['_venue_coordinates'] ?? '',
			'capacity'    => $meta['_venue_capacity'] ?? '',
			'timezone'    => $meta['_venue_timezone'] ?? '',
		);
	}
}
if ( ! function_exists( 'data_machine_events_query_venue_interval_overlaps' ) ) {
	function data_machine_events_query_venue_interval_overlaps( array $params ) {
		$GLOBALS['ec_artist_test']['overlap_calls'][] = $params;
		if ( array_key_exists( 'overlap_result', $GLOBALS['ec_artist_test'] ?? array() ) ) {
			$result = $GLOBALS['ec_artist_test']['overlap_result'];
			return is_callable( $result ) ? $result( $params ) : $result;
		}
		$venue_id  = (int) $params['venue_id'];
		$projection = data_machine_events_get_venue_data( $venue_id );
		$timezone  = new DateTimeZone( (string) ( $projection['timezone'] ?? 'UTC' ) );
		$start     = ( new DateTimeImmutable( $params['start'] ) )->setTimezone( $timezone )->format( 'Y-m-d H:i:s' );
		$end       = ( new DateTimeImmutable( $params['end'] ) )->setTimezone( $timezone )->format( 'Y-m-d H:i:s' );
		$excluded  = array_map( 'intval', $params['exclude'] ?? array() );
		$events    = array();
		foreach ( $GLOBALS['wpdb']->event_dates ?? array() as $event ) {
			if ( (int) $event['venue_term_id'] !== $venue_id || in_array( (int) $event['post_id'], $excluded, true ) || 'publish' !== $event['post_status'] || null === $event['end_datetime'] || $event['end_datetime'] <= $event['start_datetime'] ) {
				continue;
			}
			if ( $event['start_datetime'] < $end && $event['end_datetime'] > $start ) {
				$events[] = array(
					'event_id' => (int) $event['post_id'],
					'start'    => DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $event['start_datetime'], $timezone )->format( DATE_RFC3339 ),
					'end'      => DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $event['end_datetime'], $timezone )->format( DATE_RFC3339 ),
					'status'   => 'publish',
				);
				break;
			}
		}
		return array(
			'venue_id' => $venue_id,
			'timezone' => $timezone->getName(),
			'interval' => array(
				'start' => ( new DateTimeImmutable( $params['start'] ) )->setTimezone( $timezone )->format( DATE_RFC3339 ),
				'end'   => ( new DateTimeImmutable( $params['end'] ) )->setTimezone( $timezone )->format( DATE_RFC3339 ),
			),
			'events'   => $events,
			'page'     => 1,
			'per_page' => 1,
			'has_more' => false,
		);
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			$GLOBALS['venue_membership_test']['term_meta'][ $term_id ][ $key ] = $value;
			return 1;
		}
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
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id ) {
		$post = get_post( $post_id );
		return $post->post_type ?? false;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post_id ) {
		$post = get_post( $post_id );
		return $post->post_status ?? false;
	}
}
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $post_id, $unfiltered = false ) {
		unset( $unfiltered );
		return $GLOBALS['ec_artist_test']['attachment_files'][ get_current_blog_id() ][ $post_id ] ?? false;
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $post_id ) {
		return $GLOBALS['ec_artist_test']['attachment_urls'][ get_current_blog_id() ][ $post_id ] ?? false;
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $post_id ) {
		return $GLOBALS['ec_artist_test']['attachment_mimes'][ get_current_blog_id() ][ $post_id ] ?? false;
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
		if ( ! empty( $GLOBALS['ec_artist_test']['event_term_db_errors'][ $taxonomy ][ $post_id ] ) ) {
			$GLOBALS['wpdb']->last_error = 'simulated empty object-term database failure';
			return array();
		}
		if ( ! empty( $GLOBALS['ec_artist_test']['event_term_errors'][ $taxonomy ][ $post_id ] ) ) {
			return new WP_Error( 'simulated_event_terms_failure', 'simulated event taxonomy read failure' );
		}
		if ( ! empty( $GLOBALS['ec_artist_test']['venue_terms_error'] ) ) {
			return new WP_Error( 'venue_terms_read_failed', 'simulated venue taxonomy read failure' );
		}
		if ( 'artist' === $taxonomy ) {
			$ids = $GLOBALS['ec_artist_test']['event_artists'][ get_current_blog_id() ][ $post_id ] ?? array();
			return ( $args['fields'] ?? '' ) === 'ids' ? $ids : array();
		}
		if ( 'venue' !== $taxonomy ) {
			return array();
		}
		$ids = $GLOBALS['ec_artist_test']['event_venues'][ get_current_blog_id() ][ $post_id ] ?? array();
		return ( $args['fields'] ?? '' ) === 'ids' ? $ids : array();
	}
}
if ( ! function_exists( 'extrachill_events_resolve_artist_term' ) ) {
	function extrachill_events_resolve_artist_term( $artist_term_id ) {
		if ( isset( $GLOBALS['ec_artist_test']['artist_mapping_errors'][ (int) $artist_term_id ] ) ) {
			return $GLOBALS['ec_artist_test']['artist_mapping_errors'][ (int) $artist_term_id ];
		}
		$mapped = $GLOBALS['ec_artist_test']['artist_mappings'][ (int) $artist_term_id ] ?? 0;
		return $mapped > 0 ? array(
			'term_id'   => $mapped,
			'term_slug' => 'artist-' . $mapped,
		) : new WP_Error( 'artist_mapping_missing' );
	}
}
if ( ! function_exists( 'extrachill_events_read_artist_mapping_claims' ) ) {
	function extrachill_events_read_artist_mapping_claims( $events_term_id ) {
		$GLOBALS['wpdb']->flush();
		if ( ! empty( $GLOBALS['ec_artist_test']['artist_mapping_claims_error'] ) ) {
			return new WP_Error( 'artist_mapping_claims_read_failed' );
		}
		if ( array_key_exists( (int) $events_term_id, $GLOBALS['ec_artist_test']['artist_mapping_claims'] ?? array() ) ) {
			$claims = $GLOBALS['ec_artist_test']['artist_mapping_claims'][ (int) $events_term_id ];
		} else {
			$claims = array();
			foreach ( (array) ( $GLOBALS['ec_artist_test']['artist_mappings'] ?? array() ) as $canonical_id => $mapped_id ) {
				if ( (int) $mapped_id === (int) $events_term_id ) {
					$claims[] = (int) $canonical_id;
				}
			}
		}
		if ( ! empty( $GLOBALS['ec_artist_test']['artist_mapping_claims_db_error'] ) ) {
			$GLOBALS['wpdb']->last_error = 'simulated empty reverse mapping database failure';
			$claims = array();
		}
		return '' !== (string) $GLOBALS['wpdb']->last_error ? new WP_Error( 'artist_mapping_claims_query_failed' ) : $claims;
	}
}
if ( ! function_exists( 'ec_user_can' ) ) {
	function ec_user_can( $capability, array $context = array() ) {
		return 'manage_artist' === $capability && ! empty( $GLOBALS['ec_artist_test']['artist_managers'][ (int) ( $context['artist_id'] ?? 0 ) ][ (int) ( $context['user_id'] ?? 0 ) ] );
	}
}
if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
	function ec_get_artists_for_user( $user_id = null ) {
		$user_id = (int) $user_id;
		return array_values(
			array_map(
				'intval',
				array_keys(
					array_filter(
						(array) ( $GLOBALS['ec_artist_test']['artist_managers'] ?? array() ),
						static function ( array $managers ) use ( $user_id ): bool {
							return ! empty( $managers[ $user_id ] );
						}
					)
				)
			)
		);
	}
}
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		return $GLOBALS['ec_artist_test']['parsed_blocks'][ $content ] ?? array();
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			return $GLOBALS['venue_membership_test']['options'][ $key ] ?? $default;
		}
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
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			return $GLOBALS['venue_membership_test']['current_user_id'];
		}
		return $GLOBALS['ec_artist_test']['current_user_id'] ?? 12; }
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			return $GLOBALS['venue_membership_test']['users'][ $user_id ] ?? false;
		}
		if ( isset( $GLOBALS['ec_artist_test']['users'][ $user_id ] ) ) {
			return $GLOBALS['ec_artist_test']['users'][ $user_id ];
		}
		return (int) $user_id > 0 ? (object) array( 'ID' => (int) $user_id ) : false; }
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			if ( 'manage_options' === $capability ) {
				return ! empty( $GLOBALS['venue_membership_test']['administrators'][ $user_id ] );
			}
			return VenueAuthorization::ACCESS_CAPABILITY === $capability && ! empty( $GLOBALS['venue_membership_test']['team_access'][ $user_id ] );
		}
		if ( isset( $GLOBALS['ec_artist_test']['user_caps'][ (int) $user_id ] ) && array_key_exists( $capability, $GLOBALS['ec_artist_test']['user_caps'][ (int) $user_id ] ) ) {
			return (bool) $GLOBALS['ec_artist_test']['user_caps'][ (int) $user_id ][ $capability ];
		}
		return (int) $user_id > 0 && 'manage_options' !== $capability; }
}
if ( ! function_exists( 'ec_feature_available' ) ) {
	function ec_feature_available( $feature, $user_id ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			return VenueAuthorization::FEATURE === $feature && ! empty( $GLOBALS['venue_membership_test']['feature_available'] );
		}
		if ( array_key_exists( 'feature_available', $GLOBALS['ec_artist_test'] ?? array() ) ) {
			return 'venue_booking' === $feature && ! empty( $GLOBALS['ec_artist_test']['feature_available'] );
		}
		return 'venue_booking' === $feature && (int) $user_id > 0; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $priority, $accepted_args );
		$GLOBALS['ec_artist_test']['actions'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			$GLOBALS['venue_membership_test']['abilities'][ $name ] = $definition;
			return;
		}
		$GLOBALS['ec_artist_test']['abilities'][ $name ] = $definition; }
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return $GLOBALS['ec_artist_test']['ability_objects'][ $name ] ?? null; }
}
if ( ! function_exists( 'wp_is_uuid' ) ) {
	function wp_is_uuid( $uuid, $version = null ) {
		$pattern = null === $version ? '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i' : '/^[0-9a-f]{8}-[0-9a-f]{4}-' . (int) $version . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
		return 1 === preg_match( $pattern, (string) $uuid );
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return $GLOBALS['ec_artist_test']['permalinks'][ get_current_blog_id() ][ $post_id ] ?? 'https://events.example/event/' . (int) $post_id; }
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term, $taxonomy = '' ) {
		unset( $taxonomy );
		return 'https://events.example/venue/' . ( is_object( $term ) ? $term->slug : (int) $term );
	}
}
if ( ! function_exists( 'ec_events_resolve_booking_console_destination' ) ) {
	function ec_events_resolve_booking_console_destination( array $booking, array $recipient_ids, array $locked_rows ) {
		$venue_id = (int) ( $booking['venue_term_id'] ?? 0 );
		foreach ( $recipient_ids as $recipient_id ) {
			$active = false;
			foreach ( $locked_rows as $row ) {
				$active = $active || (int) ( $row['venue_term_id'] ?? 0 ) === $venue_id && (int) ( $row['user_id'] ?? 0 ) === (int) $recipient_id && 'active' === ( $row['status'] ?? '' );
			}
			if ( ! $active ) {
				return new WP_Error( 'booking_notification_destination_forbidden' );
			}
		}
		return get_term_link( $venue_id, 'venue' );
	}
}
if ( ! function_exists( 'ec_users_notify_with_receipts' ) ) {
	function ec_users_notify_with_receipts( $user_ids, array $payload ): array {
		return is_callable( $GLOBALS['ec_artist_test']['users_receipt'] ?? null )
			? call_user_func( $GLOBALS['ec_artist_test']['users_receipt'], (array) $user_ids, $payload )
			: array( 'recipients' => array() );
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		if ( isset( $GLOBALS['venue_membership_test'] ) ) {
			$GLOBALS['venue_membership_test']['cache_deletes'][] = array( $key, $group );
			return true;
		}
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
	public $prefix                               = 'wp_7_';
	public $terms                                = 'wp_7_terms';
	public $posts                                = 'wp_7_posts';
	public $term_relationships                   = 'wp_7_term_relationships';
	public $term_taxonomy                        = 'wp_7_term_taxonomy';
	public $insert_id                            = 0;
	public $last_error                           = '';
	public $rows                                 = array();
	public $schemas                              = array();
	public $engines                              = array();
	public $schema_omit                          = array();
	public $schema_queries                       = 0;
	public $dropped_indexes                      = array();
	public $fail_reads                           = false;
	public $fail_activity_reads                  = false;
	public $fail_attachment_reference_reads      = false;
	public $fail_inserts                         = false;
	public $fail_updates                         = false;
	public $fail_communication_state_updates     = false;
	public $fail_engine_repair                   = false;
	public $race_activity_insert                 = false;
	public $race_booking_insert                  = false;
	public $race_booking_hash                    = null;
	public $race_activity_read_fail              = false;
	public $race_event_read_fail                 = false;
	public $concurrent_role_migration            = false;
	public $reads_before_failure                 = null;
	public $last_query                           = '';
	public $fail_activity_inserts                = false;
	public $fail_activity_kinds                  = array();
	public $fail_transaction_start               = false;
	public $fail_transaction_boundary            = false;
	public $throw_transaction_boundary           = false;
	public $transaction_boundary_queries         = array();
	public $fail_transaction_commit              = false;
	public $fail_transaction_rollback            = false;
	public $throw_transaction_commit             = false;
	public $throw_transaction_commit_after_success = false;
	public $throw_transaction_rollback           = false;
	public $rollback_queries                     = 0;
	public $after_membership_lock                = null;
	public $after_booking_lock                   = null;
	public $after_venue_lock                     = null;
	public $fail_venue_lock                      = false;
	public $venue_lock_queries                   = 0;
	public $reference_locks                      = array();
	public $transaction_start_reference_lock_counts = array();
	public $lock_sequence                        = array();
	public $reference_lock_queries               = 0;
	public $fail_reference_unlock                = false;
	public $after_reference_lock                 = null;
	public $after_reference_unlock               = null;
	public $transaction_active                   = false;
	public $suppress_errors                       = false;
	public $nested_transaction_starts            = 0;
	public $natural_key_reads_in_transaction     = 0;
	public $get_lock_result                      = 1;
	public $release_lock_result                  = 1;
	public $release_lock_results                 = array();
	public $release_lock_errors                  = array();
	public $lock_names                           = array();
	public $event_dates                          = array();
	public $local_support_candidate_rows          = array();
	public $local_support_candidate_query         = '';
	public $local_support_candidate_queries       = array();
	public $fail_local_support_candidate_reads    = false;
	public $fail_local_support_request_reads      = false;
	public $booking_lock_queries                 = 0;
	public $communication_state_queries          = 0;
	public $communication_attempt_queries        = 0;
	public $communication_public_rows_returned   = 0;
	public $pending_reminder_rows_returned       = 0;
	public $pending_reminder_query_limits        = array();
	public $pending_reminder_query_cursors       = array();
	public $elapse_hold_after_membership_lock    = null;
	public $elapse_hold_before_release_update    = null;
	public $elapse_hold_before_conversion_update = null;
	public $fail_read_after_conversion_update    = false;
	public $fail_clock_reads                     = false;
	public $database_now                         = null;
	public $ready                                = true;
	public $close_calls                          = 0;
	private $transaction_snapshot                = null;
	private $savepoint_snapshot                  = null;

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4'; }

	private function current_database_time(): string {
		return null === $this->database_now ? gmdate( 'Y-m-d H:i:s' ) : $this->database_now;
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function flush() {
		$this->last_error = '';
	}

	public function suppress_errors( $suppress = true ) {
		$previous              = $this->suppress_errors;
		$this->suppress_errors = (bool) $suppress;
		return $previous;
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

	public function get_col( $query ) {
		$this->last_query = $query;
		$this->last_error = '';
		if ( $this->fail_reads ) {
			$this->last_error = 'simulated column read failure';
			return null;
		}
		if ( preg_match( "/SELECT venue_term_id FROM .*ec_venue_members WHERE user_id = (\d+) AND status = 'active'/", $query, $match ) ) {
			$ids = array();
			foreach ( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() as $row ) {
				if ( (int) $row['user_id'] === (int) $match[1] && 'active' === $row['status'] ) {
					$ids[] = (int) $row['venue_term_id'];
				}
			}
			sort( $ids, SORT_NUMERIC );
			return $ids;
		}
		return array();
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
		if ( false !== strpos( $table, 'ec_booking_communication_state' ) && isset( $this->rows[ $table ][ (int) $row['intent_id'] ] ) ) {
			$this->last_error = 'duplicate communication intent state';
			return false;
		}
		if ( false !== strpos( $table, 'ec_booking_attachments' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['booking_id'] === (int) $row['booking_id'] && $existing['idempotency_key'] === $row['idempotency_key'] ) {
					$this->last_error = 'duplicate booking attachment idempotency key';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_sales_reports' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['booking_id'] === (int) $row['booking_id'] && $existing['provider'] === $row['provider'] && $existing['external_report_id_hash'] === $row['external_report_id_hash'] ) {
					$this->last_error = 'duplicate provider report ID';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_ticket_sources' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['booking_id'] === (int) $row['booking_id'] && $existing['provider'] === $row['provider'] && $existing['source_key_hash'] === $row['source_key_hash'] ) {
					$this->last_error = 'duplicate ticket source identity';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_sales_resolutions' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['report_id'] === (int) $row['report_id'] && (int) $existing['version'] === (int) $row['version'] ) {
					$this->last_error = 'duplicate sales resolution version';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_settlements' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['booking_id'] === (int) $row['booking_id'] ) {
					$this->last_error = 'duplicate booking settlement';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_show_settlements' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['booking_id'] === (int) $row['booking_id'] && ( (int) $existing['revision'] === (int) $row['revision'] || $existing['idempotency_key'] === $row['idempotency_key'] ) ) {
					$this->last_error = 'duplicate show settlement revision';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_show_settlement_actions' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['show_settlement_id'] === (int) $row['show_settlement_id'] && (int) $existing['expected_version'] === (int) $row['expected_version'] || (int) $existing['booking_id'] === (int) $row['booking_id'] && $existing['idempotency_key'] === $row['idempotency_key'] ) {
					$this->last_error = 'duplicate show settlement action';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_attachment_deliveries' ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( $existing['correlation_id'] === $row['correlation_id'] ) {
					$this->last_error = 'duplicate attachment delivery correlation';
					return false;
				}
			}
		}
		$this->insert_id = count( $this->rows[ $table ] ?? array() ) + 1;
		$key             = false !== strpos( $table, 'ec_booking_communication_state' ) ? (int) $row['intent_id'] : $this->insert_id;
		if ( ! isset( $row['id'] ) && false === strpos( $table, 'ec_booking_communication_state' ) ) {
			$row['id'] = $this->insert_id;
		}
		$this->rows[ $table ][ $key ] = $row;
		if ( false !== strpos( $table, 'ec_bookings' ) && $this->race_booking_insert ) {
			$this->race_booking_insert                          = false;
			$this->rows[ $table ][ $this->insert_id ]['status'] = 'submitted';
			$this->rows[ $table ][ $this->insert_id ]['admission_owner_token'] = null;
			++$this->rows[ $table ][ $this->insert_id ]['version'];
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
				'idempotency_key' => 'inquiry:' . $row['inquiry_idempotency_key'],
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
		if ( ! $this->ready ) {
			$this->last_error = 'simulated closed database connection';
			return null;
		}
		$this->last_error = '';
		$database_now     = $this->current_database_time();
		if ( preg_match( "/SELECT GET_LOCK\('([^']+)', (\d+)\)/", $query, $match ) ) {
			$name               = stripslashes( $match[1] );
			$this->lock_names[] = array( 'get', $name );
			++$this->reference_lock_queries;
			$result = $this->get_lock_result;
			if ( 1 === (int) $result ) {
				$this->reference_locks[ $name ] = ( $this->reference_locks[ $name ] ?? 0 ) + 1;
				$this->lock_sequence[]          = 'reference';
			}
			if ( 1 === (int) $result && is_callable( $this->after_reference_lock ) ) {
				$callback                   = $this->after_reference_lock;
				$this->after_reference_lock = null;
				$callback();
			}
			return $result;
		}
		if ( preg_match( "/SELECT RELEASE_LOCK\('([^']+)'\)/", $query, $match ) ) {
			$name               = stripslashes( $match[1] );
			$this->lock_names[] = array( 'release', $name );
			if ( $this->fail_reference_unlock ) {
				return 0;
			}
			if ( ! isset( $this->reference_locks[ $name ] ) ) {
				return null;
			}
			$result = empty( $this->release_lock_results ) ? $this->release_lock_result : array_shift( $this->release_lock_results );
			if ( ! empty( $this->release_lock_errors ) ) {
				$this->last_error = (string) array_shift( $this->release_lock_errors );
			}
			if ( 1 !== (int) $result ) {
				return $result;
			}
			--$this->reference_locks[ $name ];
			if ( $this->reference_locks[ $name ] < 1 ) {
				unset( $this->reference_locks[ $name ] );
			}
			if ( is_callable( $this->after_reference_unlock ) ) {
				$callback                     = $this->after_reference_unlock;
				$this->after_reference_unlock = null;
				$callback();
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
		if ( false !== strpos( $query, 'SELECT b.id FROM' ) && false !== strpos( $query, 'INNER JOIN' ) && preg_match( '/b\.venue_term_id = (\d+).*h\.expires_at <= UTC_TIMESTAMP\(\)/', $query, $stale ) ) {
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
		if ( preg_match( "/SELECT COUNT\(\*\) FROM .*ec_booking_activity WHERE communication_intent_id = (\d+) AND kind = '([^']+)'/", $query, $match ) ) {
			++$this->communication_attempt_queries;
			if ( $this->fail_reads || $this->fail_activity_reads ) {
				$this->last_error = 'simulated communication attempt read failure';
				return null;
			}
			$count = 0;
			foreach ( $this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array() as $row ) {
				if ( (int) ( $row['communication_intent_id'] ?? 0 ) === (int) $match[1] && $row['kind'] === stripslashes( $match[2] ) ) {
					++$count;
				}
			}
			return $count;
		}
		if ( preg_match( "/SELECT COUNT\(\*\) FROM .*ec_booking_activity WHERE external_id = '(\d+)' AND kind = 'notification_delivery_attempted'/", $query, $match ) ) {
			$count = 0;
			foreach ( $this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array() as $row ) {
				if ( (string) ( $row['external_id'] ?? '' ) === $match[1] && 'notification_delivery_attempted' === $row['kind'] ) {
					++$count;
				}
			}
			return $count;
		}
		if ( preg_match( "/SELECT MAX\(occurred_at\) FROM .*ec_booking_activity WHERE external_id = '(\d+)' AND kind = 'notification_delivery_attempted'/", $query, $match ) ) {
			$due = null;
			foreach ( $this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array() as $row ) {
				if ( (string) ( $row['external_id'] ?? '' ) === $match[1] && 'notification_delivery_attempted' === $row['kind'] && ( null === $due || $row['occurred_at'] > $due ) ) {
					$due = $row['occurred_at'];
				}
			}
			return $due;
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
		if ( $this->fail_local_support_request_reads && false !== strpos( $query, 'ec_local_support_requests' ) ) {
			$this->last_error = 'simulated local support request read failure';
			return null;
		}
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
		$is_activity    = false !== strpos( $query, 'ec_booking_activity' );
		$is_attachment  = false !== strpos( $query, 'ec_booking_attachments' );
		$is_delivery    = false !== strpos( $query, 'ec_booking_attachment_deliveries' );
		$is_state       = false !== strpos( $query, 'ec_booking_communication_state' );
		$is_hold        = false !== strpos( $query, 'ec_booking_holds' );
		$is_sales       = false !== strpos( $query, 'ec_booking_sales_reports' );
		$is_source      = false !== strpos( $query, 'ec_booking_ticket_sources' );
		$is_resolution  = false !== strpos( $query, 'ec_booking_sales_resolutions' );
		$is_settlement  = false !== strpos( $query, 'ec_booking_settlements' );
		$is_show        = false !== strpos( $query, 'ec_booking_show_settlements' );
		$is_show_action = false !== strpos( $query, 'ec_booking_show_settlement_actions' );
		$is_membership  = false !== strpos( $query, 'ec_venue_members' );
		$database_now   = $this->current_database_time();
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
		$table = $is_show_action ? $this->prefix . 'ec_booking_show_settlement_actions' : ( $is_show ? $this->prefix . 'ec_booking_show_settlements' : ( $is_membership ? $this->prefix . 'ec_venue_members' : ( $is_source ? $this->prefix . 'ec_booking_ticket_sources' : ( $is_resolution ? $this->prefix . 'ec_booking_sales_resolutions' : ( $is_sales ? $this->prefix . 'ec_booking_sales_reports' : ( $is_settlement ? $this->prefix . 'ec_booking_settlements' : ( $is_delivery ? $this->prefix . 'ec_booking_attachment_deliveries' : ( $is_attachment ? $this->prefix . 'ec_booking_attachments' : ( $is_activity ? $this->prefix . 'ec_booking_activity' : ( $is_state ? $this->prefix . 'ec_booking_communication_state' : ( $is_hold ? $this->prefix . 'ec_booking_holds' : $this->prefix . 'ec_bookings' ) ) ) ) ) ) ) ) ) ) );
		if ( $is_show && preg_match( "/WHERE booking_id = (\d+) AND idempotency_key = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $match[1] && $row['idempotency_key'] === stripslashes( $match[2] ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( $is_show && preg_match( '/WHERE booking_id = (\d+) AND revision = (\d+)/', $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $match[1] && (int) $row['revision'] === (int) $match[2] ) {
					return $row;
				}
			}
			return null;
		}
		if ( $is_show && preg_match( '/WHERE booking_id = (\d+) ORDER BY revision DESC/', $query, $match ) ) {
			$rows = array_values( array_filter( $this->rows[ $table ] ?? array(), static fn( array $row ): bool => (int) $row['booking_id'] === (int) $match[1] ) );
			usort( $rows, static fn( array $left, array $right ): int => (int) $right['revision'] <=> (int) $left['revision'] );
			return $rows[0] ?? null;
		}
		if ( $is_show_action && preg_match( "/WHERE booking_id = (\d+) AND idempotency_key = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $match[1] && $row['idempotency_key'] === stripslashes( $match[2] ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( $is_membership && preg_match( '/WHERE venue_term_id = (\d+) AND user_id = (\d+)/', $query, $membership ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $membership[1] && (int) $row['user_id'] === (int) $membership[2] ) {
					return $row;
				}
			}
			return null;
		}
		if ( $is_sales && preg_match( "/WHERE booking_id = (\d+) AND provider = '([^']+)' AND external_report_id_hash = '([^']+)'/", $query, $external ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $external[1] && $row['provider'] === stripslashes( $external[2] ) && $row['external_report_id_hash'] === stripslashes( $external[3] ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( $is_source && preg_match( "/WHERE booking_id = (\d+) AND provider = '([^']+)' AND source_key_hash = '([^']+)'/", $query, $identity ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $identity[1] && $row['provider'] === stripslashes( $identity[2] ) && $row['source_key_hash'] === stripslashes( $identity[3] ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( $is_resolution && preg_match( '/WHERE report_id = (\d+)(?: AND version = (\d+))?/', $query, $target ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $target ) {
						return (int) $row['report_id'] === (int) $target[1] && ( empty( $target[2] ) || (int) $row['version'] === (int) $target[2] );
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $right['version'] <=> $left['version'];
				}
			);
			return $rows[0] ?? null;
		}
		if ( $is_settlement && preg_match( '/WHERE booking_id = (\d+)/', $query, $booking ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $booking[1] ) {
					return $row;
				}
			}
			return null;
		}
		if ( $is_delivery && preg_match( "/WHERE correlation_id = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( stripslashes( $match[1] ) === $row['correlation_id'] ) {
					return $row;
				}
			}
			return null;
		}
		if ( $is_state && preg_match( '/WHERE intent_id = (\d+)/', $query, $match ) ) {
			++$this->communication_state_queries;
			return $this->rows[ $table ][ (int) $match[1] ] ?? null;
		}
		if ( $is_activity && preg_match( '/WHERE communication_intent_id = (\d+) ORDER BY id DESC LIMIT 1/', $query, $match ) ) {
			++$this->communication_state_queries;
			$rows = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (int) ( $row['communication_intent_id'] ?? 0 ) === (int) $match[1];
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $right['id'] <=> $left['id'];
				}
			);
			return $rows[0] ?? null;
		}
		if ( $is_activity && preg_match( "/WHERE external_id = '(\d+)' AND kind IN \('notification_delivered', 'notification_suppressed'\)/", $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (string) ( $row['external_id'] ?? '' ) === $match[1] && in_array( $row['kind'], array( 'notification_delivered', 'notification_suppressed' ), true );
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $right['id'] <=> $left['id'];
				}
			);
			return $rows[0] ?? null;
		}
		if ( $is_activity && preg_match( "/WHERE booking_id = (\d+) AND kind = 'event_sync_started' ORDER BY id DESC LIMIT 1/", $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (int) $row['booking_id'] === (int) $match[1] && 'event_sync_started' === $row['kind'];
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $right['id'] <=> $left['id'];
				}
			);
			return $rows[0] ?? null;
		}
		if ( $is_activity && preg_match( "/WHERE booking_id = (\d+) AND external_id = '(\d+)' AND kind IN \('event_sync_succeeded', 'event_sync_noop', 'event_sync_conflict', 'event_sync_failed'\)/", $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (int) $row['booking_id'] === (int) $match[1] && (string) ( $row['external_id'] ?? '' ) === $match[2] && in_array( $row['kind'], array( 'event_sync_succeeded', 'event_sync_noop', 'event_sync_conflict', 'event_sync_failed' ), true );
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $right['id'] <=> $left['id'];
				}
			);
			return $rows[0] ?? null;
		}
		if ( $is_activity && preg_match( "/WHERE booking_id = (\d+) AND external_id = '(\d+)' AND kind = 'event_sync_retryable'/", $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (int) $row['booking_id'] === (int) $match[1] && (string) ( $row['external_id'] ?? '' ) === $match[2] && 'event_sync_retryable' === $row['kind'];
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $right['id'] <=> $left['id'];
				}
			);
			return $rows[0] ?? null;
		}
		if ( $is_activity && preg_match( "/WHERE booking_id = (\d+) AND kind IN \('event_sync_succeeded', 'event_sync_noop', 'event_converted'\)/", $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (int) $row['booking_id'] === (int) $match[1] && in_array( $row['kind'], array( 'event_sync_succeeded', 'event_sync_noop', 'event_converted' ), true );
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $right['id'] <=> $left['id'];
				}
			);
			return $rows[0] ?? null;
		}
		if ( ! $is_attachment && ! $is_delivery && ! $is_activity && ! $is_state && ! $is_hold && ! $is_sales && ! $is_source && ! $is_resolution && ! $is_settlement && ! $is_show && ! $is_show_action && ! $is_membership && false !== strpos( $query, 'FOR UPDATE' ) ) {
			++$this->booking_lock_queries;
			if ( is_callable( $this->after_booking_lock ) ) {
				$callback                 = $this->after_booking_lock;
				$this->after_booking_lock = null;
				$callback();
			}
		}
		if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			if ( ! $is_attachment && ! $is_delivery && ! $is_activity && ! $is_state && ! $is_hold && ! $is_sales && ! $is_source && ! $is_resolution && ! $is_settlement && ! $is_show && ! $is_show_action && ! $is_membership && false !== strpos( $query, 'FOR UPDATE' ) ) {
				$this->lock_sequence[] = 'booking:' . (int) $match[1];
			}
			$row = $this->rows[ $table ][ (int) $match[1] ] ?? null;
			if ( is_array( $row ) && false !== strpos( $query, "status <> 'admission_pending'" ) && 'admission_pending' === ( $row['status'] ?? '' ) ) {
				return null;
			}
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
				$origin       = ( (int) $match[4] > 0 && (int) $row['id'] === (int) $match[4] )
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
				if ( stripslashes( $match[1] ) === $row['public_id'] && ( false === strpos( $query, "status <> 'admission_pending'" ) || 'admission_pending' !== ( $row['status'] ?? '' ) ) ) {
					return $row; }
			}
		}
		if ( ! $is_activity && preg_match( "/WHERE event_id = (\d+) AND status <> 'admission_pending'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) ( $row['event_id'] ?? 0 ) === (int) $match[1] && 'admission_pending' !== ( $row['status'] ?? '' ) ) {
					return $row;
				}
			}
		}
		if ( $is_activity && preg_match( "/WHERE booking_id = (\d+) AND kind = '([^']+)' AND external_id = '([^']+)'/", $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $table ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (int) $row['booking_id'] === (int) $match[1] && $row['kind'] === stripslashes( $match[2] ) && (string) ( $row['external_id'] ?? '' ) === stripslashes( $match[3] );
					}
				)
			);
			usort( $rows, static fn( array $left, array $right ): int => $right['id'] <=> $left['id'] );
			return $rows[0] ?? null;
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
		if ( preg_match( "/WHERE storage_reference = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( stripslashes( $match[1] ) === $row['storage_reference'] ) {
					return $row;
				}
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
		if ( false !== strpos( $query, 'SELECT DISTINCT p.ID' ) && false !== strpos( $query, 'datamachine_event_dates' ) ) {
			$this->local_support_candidate_query = $query;
			$this->local_support_candidate_queries[] = $query;
			if ( $this->fail_local_support_candidate_reads ) {
				$this->last_error = 'simulated local support candidate read failure';
				return null;
			}
			$venue_ids  = array();
			$artist_ids = array();
			if ( preg_match( "/scope_tt.taxonomy = 'venue' AND scope_tt.term_id IN \(([^)]+)\)/", $query, $match ) ) {
				$venue_ids = array_map( 'intval', explode( ',', $match[1] ) );
			}
			if ( preg_match( "/scope_tt.taxonomy = 'artist' AND scope_tt.term_id IN \(([^)]+)\)/", $query, $match ) ) {
				$artist_ids = array_map( 'intval', explode( ',', $match[1] ) );
			}
			preg_match( "/start_datetime >= '([^']+)'/", $query, $start );
			preg_match( "/start_datetime > '([^']+)' OR \(dates.start_datetime = '([^']+)' AND p.ID > (\d+)\)/", $query, $cursor );
			preg_match( '/AND venue_tt.term_id = (\d+)/', $query, $exact_venue );
			preg_match( '/LIMIT (\d+)/', $query, $limit );
			$rows = array_values(
				array_filter(
					$this->local_support_candidate_rows,
					static function ( array $row ) use ( $venue_ids, $artist_ids, $start, $cursor, $exact_venue ): bool {
						$in_scope = in_array( (int) $row['venue_term_id'], $venue_ids, true )
							|| ! empty( array_intersect( array_map( 'intval', $row['artist_term_ids'] ?? array() ), $artist_ids ) );
						$after_cursor = empty( $cursor[1] )
							|| (string) $row['start_datetime'] > (string) $cursor[1]
							|| ( (string) $row['start_datetime'] === (string) $cursor[2] && (int) $row['ID'] > (int) $cursor[3] );
						return $in_scope
							&& (string) $row['start_datetime'] >= (string) ( $start[1] ?? '' )
							&& $after_cursor
							&& ( empty( $exact_venue[1] ) || (int) $row['venue_term_id'] === (int) $exact_venue[1] );
					}
				)
			);
			usort(
				$rows,
				static function ( array $left, array $right ): int {
					return array( $left['start_datetime'], (int) $left['ID'] ) <=> array( $right['start_datetime'], (int) $right['ID'] );
				}
			);
			$rows = array_slice( $rows, 0, (int) ( $limit[1] ?? 100 ) );
			return array_map(
				static function ( array $row ): array {
					unset( $row['artist_term_ids'] );
					return $row;
				},
				$rows
			);
		}
		if ( false !== strpos( $query, 'SELECT source.* FROM' ) && false !== strpos( $query, "source.kind IN ('inquiry_submitted', 'deal_confirmed')" ) ) {
			preg_match( '/LIMIT (\d+)/', $query, $limit );
			$completed = array();
			foreach ( $this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array() as $row ) {
				if ( 'booking_correspondence_source_completed' === $row['kind'] ) {
					$completed[] = (string) $row['external_id'];
				}
			}
			$rows = array_values(
				array_filter(
					$this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array(),
					static function ( $row ) use ( $completed ) {
						return in_array( $row['kind'], array( 'inquiry_submitted', 'deal_confirmed' ), true ) && ! in_array( (string) $row['id'], $completed, true );
					}
				)
			);
			usort( $rows, static function ( $left, $right ) { return $left['id'] <=> $right['id']; } );
			return array_slice( $rows, 0, (int) ( $limit[1] ?? 25 ) );
		}
		if ( false !== strpos( $query, 'SELECT * FROM' ) && false !== strpos( $query, 'requested_space_key =' ) && preg_match( "/venue_term_id = (\d+) AND requested_space_key = '([^']+)' AND requested_start_at < '([^']+)' AND requested_end_at > '([^']+)'.*id <> (\d+) AND id > (\d+).*LIMIT (\d+)/", $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $this->prefix . 'ec_bookings' ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (int) $row['venue_term_id'] === (int) $match[1]
							&& $row['requested_space_key'] === stripslashes( $match[2] )
							&& $row['requested_start_at'] < $match[3]
							&& $row['requested_end_at'] > $match[4]
							&& (int) $row['id'] !== (int) $match[5]
							&& (int) $row['id'] > (int) $match[6]
							&& ! in_array( $row['status'], array( 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed', 'admission_pending' ), true );
					}
				)
			);
			usort( $rows, static function ( $left, $right ) { return $left['id'] <=> $right['id']; } );
			return array_slice( $rows, 0, (int) $match[7] );
		}
		if ( false !== strpos( $query, 'SELECT source.* FROM' ) && false !== strpos( $query, "source.kind IN ('inquiry_submitted'" ) ) {
			preg_match( '/LIMIT (\d+)/', $query, $limit );
			$requests = array();
			foreach ( $this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array() as $row ) {
				if ( in_array( $row['kind'], array( 'notification_requested', 'notification_source_ignored' ), true ) ) {
					$requests[] = (string) $row['external_id'];
				}
			}
			$rows = array_values(
				array_filter(
					$this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array(),
					static function ( $row ) use ( $requests ) {
						return in_array( $row['kind'], array( 'inquiry_submitted', 'assignment_changed', 'status_changed', 'hold_expired', 'event_conversion_failed', 'artist_correction_requested', 'artist_cancellation_requested', 'artist_withdrawn' ), true ) && ! in_array( (string) $row['id'], $requests, true );
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $left['id'] <=> $right['id'];
				}
			);
			return array_slice( $rows, 0, (int) ( $limit[1] ?? 50 ) );
		}
		if ( false !== strpos( $query, 'SELECT request.* FROM' ) && false !== strpos( $query, "request.kind = 'notification_requested'" ) ) {
			preg_match( '/LIMIT (\d+)/', $query, $limit );
			$terminals = array();
			$deferred  = array();
			foreach ( $this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array() as $row ) {
				if ( in_array( $row['kind'], array( 'notification_delivered', 'notification_suppressed' ), true ) ) {
					$terminals[] = (string) $row['external_id'];
				}
				if ( 'notification_delivery_attempted' === $row['kind'] && $row['occurred_at'] > $this->current_database_time() ) {
					$deferred[] = (string) $row['external_id'];
				}
			}
			$rows = array_values(
				array_filter(
					$this->rows[ $this->prefix . 'ec_booking_activity' ] ?? array(),
					static function ( $row ) use ( $terminals, $deferred ) {
						return 'notification_requested' === $row['kind'] && ! in_array( (string) $row['id'], $terminals, true ) && ! in_array( (string) $row['id'], $deferred, true );
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $left['id'] <=> $right['id'];
				}
			);
			return array_slice( $rows, 0, (int) ( $limit[1] ?? 50 ) );
		}
		if ( false !== strpos( $query, 'ec_booking_communication_state' ) && false !== strpos( $query, 'INNER JOIN' ) && preg_match( "/s\.booking_id = (\d+) AND s\.status = 'scheduled' AND s\.intent_id > (\d+).*LIMIT (\d+)/", $query, $match ) ) {
			++$this->communication_state_queries;
			$this->pending_reminder_query_cursors[] = (int) $match[2];
			$this->pending_reminder_query_limits[]  = (int) $match[3];
			$rows                                   = array();
			foreach ( $this->rows[ $this->prefix . 'ec_booking_communication_state' ] ?? array() as $state ) {
				if ( (int) $state['booking_id'] !== (int) $match[1] || 'scheduled' !== $state['status'] || (int) $state['intent_id'] <= (int) $match[2] ) {
					continue;
				}
				$intent = $this->rows[ $this->prefix . 'ec_booking_activity' ][ (int) $state['intent_id'] ] ?? null;
				if ( is_array( $intent ) ) {
					$rows[] = array_merge(
						$intent,
						array(
							'communication_status'      => $state['status'],
							'communication_claim_stage' => $state['claim_stage'],
							'communication_action_id'   => $state['action_id'],
							'communication_updated_activity_id' => $state['updated_activity_id'],
						)
					);
				}
			}
			usort(
				$rows,
				static function ( $left, $right ) {
					return $left['id'] <=> $right['id'];
				}
			);
			$rows                                  = array_slice( $rows, 0, (int) $match[3] );
			$this->pending_reminder_rows_returned += count( $rows );
			return $rows;
		}
		if ( $this->fail_attachment_reference_reads && false !== strpos( $query, 'ec_booking_attachments' ) && false !== strpos( $query, 'storage_reference =' ) ) {
			$this->last_error = 'simulated attachment reference read failure';
			return null;
		}
		if ( false !== strpos( $query, 'INNER JOIN' ) && false !== strpos( $query, 'ec_booking_holds' ) && preg_match( '/b\.venue_term_id = (\d+).*h\.expires_at <= UTC_TIMESTAMP\(\)/', $query, $stale ) ) {
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
			if ( preg_match( '/venue_term_id = (\d+)/', $query, $venue ) ) {
				$this->lock_sequence[] = 'membership:' . (int) $venue[1];
			}
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
			$rows = array_values( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() );
			if ( isset( $venue[1] ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) ( $row['venue_term_id'] ?? 0 ) === (int) $venue[1] ) );
			}
			if ( false !== strpos( $query, "status = 'active'" ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => 'active' === ( $row['status'] ?? '' ) ) );
			}
			return $rows;
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
		if ( false !== strpos( $query, 'ec_booking_show_settlement_actions' ) && preg_match( '/show_settlement_id = (\d+)/', $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$this->rows[ $this->prefix . 'ec_booking_show_settlement_actions' ] ?? array(),
					static fn( array $row ): bool => (int) $row['show_settlement_id'] === (int) $match[1]
				)
			);
			usort( $rows, static fn( array $left, array $right ): int => (int) $left['expected_version'] <=> (int) $right['expected_version'] ?: (int) $left['id'] <=> (int) $right['id'] );
			return $rows;
		}
		$is_activity   = false !== strpos( $query, 'ec_booking_activity' );
		$is_attachment = false !== strpos( $query, 'ec_booking_attachments' );
		$is_delivery   = false !== strpos( $query, 'ec_booking_attachment_deliveries' );
		$is_state      = false !== strpos( $query, 'ec_booking_communication_state' );
		$is_hold       = false !== strpos( $query, 'ec_booking_holds' );
		$is_sales      = false !== strpos( $query, 'ec_booking_sales_reports' );
		$is_source     = false !== strpos( $query, 'ec_booking_ticket_sources' );
		$is_resolution = false !== strpos( $query, 'ec_booking_sales_resolutions' );
		$table         = $is_source ? $this->prefix . 'ec_booking_ticket_sources' : ( $is_resolution ? $this->prefix . 'ec_booking_sales_resolutions' : ( $is_sales ? $this->prefix . 'ec_booking_sales_reports' : ( $is_delivery ? $this->prefix . 'ec_booking_attachment_deliveries' : ( $is_attachment ? $this->prefix . 'ec_booking_attachments' : ( $is_activity ? $this->prefix . 'ec_booking_activity' : ( $is_state ? $this->prefix . 'ec_booking_communication_state' : ( $is_hold ? $this->prefix . 'ec_booking_holds' : $this->prefix . 'ec_bookings' ) ) ) ) ) ) );
		$rows          = array_values( $this->rows[ $table ] ?? array() );
		if ( false !== strpos( $query, "status <> 'admission_pending'" ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'admission_pending' !== ( $row['status'] ?? '' );
					}
				)
			);
		}
		if ( $is_activity && false !== strpos( $query, 'is_communication = 1' ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 1 === (int) ( $row['is_communication'] ?? 0 );
					}
				)
			);
		}
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
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $prefix ) {
						return in_array( $row['kind'], array( 'event_conversion_started', 'event_conversion_failed', 'event_converted' ), true ) || ( '' !== $prefix && 0 === strpos( (string) $row['idempotency_key'], $prefix ) );
					}
				)
			);
			usort(
				$rows,
				static function ( $left, $right ) {
					return $left['id'] <=> $right['id'];
				}
			);
		}
		if ( $is_hold && false !== strpos( $query, "status = 'converted'" ) ) {
			foreach ( array( 'space_key', 'start_at', 'end_at' ) as $field ) {
				if ( preg_match( "/{$field} = '([^']+)'/", $query, $exact ) ) {
					$rows = array_values(
						array_filter(
							$rows,
							static function ( $row ) use ( $field, $exact ) {
								return $row[ $field ] === stripslashes( $exact[1] );
							}
						)
					);
				}
			}
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return null !== $row['converted_at'];
					}
				)
			);
		}
		$filters = array( 'venue_term_id', 'artist_term_id', 'artist_profile_id', 'booking_id' );
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
		if ( $is_activity && false !== strpos( $query, 'ORDER BY occurred_at DESC, id DESC' ) && preg_match( '/kind IN \(([^)]+)\)/', $query, $kind_filter ) ) {
			preg_match_all( "/'([^']+)'/", $kind_filter[1], $kind_matches );
			$allowed_kinds = array_map( 'stripslashes', $kind_matches[1] );
			$rows          = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $allowed_kinds ) {
						return in_array( $row['kind'], $allowed_kinds, true );
					}
				)
			);
		}
		if ( $is_sales && preg_match( "/currency = '([A-Z]{3})'/", $query, $currency ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $currency ) {
						return $row['currency'] === $currency[1];
					}
				)
			);
		}
		if ( $is_attachment && preg_match( '/id > (\d+)/', $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return (int) $row['id'] > (int) $filter[1];
					}
				)
			);
		}
		if ( $is_hold && false !== strpos( $query, "(status = 'expired' OR (status = 'active'" ) && false !== strpos( $query, 'expires_at <= UTC_TIMESTAMP()' ) ) {
			$now  = $this->current_database_time();
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $now ) {
						return 'expired' === $row['status'] || ( 'active' === $row['status'] && $row['expires_at'] <= $now );
					}
				)
			);
		} elseif ( $is_hold && false !== strpos( $query, "status = 'active' AND expires_at > UTC_TIMESTAMP()" ) ) {
			$now  = $this->current_database_time();
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $now ) {
						return 'active' === $row['status'] && $row['expires_at'] > $now;
					}
				)
			);
		} elseif ( $is_hold && false !== strpos( $query, "(status = 'expired' OR (status = 'active'" ) && preg_match( "/expires_at <= '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return 'expired' === $row['status'] || ( 'active' === $row['status'] && $row['expires_at'] <= $filter[1] );
					}
				)
			);
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
		if ( $is_attachment && preg_match( "/storage_reference = '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return stripslashes( $filter[1] ) === $row['storage_reference'];
					}
				)
			);
		}
		if ( $is_attachment && false !== strpos( $query, "state != 'purged'" ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'purged' !== $row['state'];
					}
				)
			);
		}
		if ( $is_attachment && false !== strpos( $query, "state IN ('replaced', 'deleted', 'abandoned', 'purging')" ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return in_array( $row['state'], array( 'replaced', 'deleted', 'abandoned', 'purging' ), true );
					}
				)
			);
		}
		if ( $is_attachment && false !== strpos( $query, "state = 'active'" ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'active' === $row['state'];
					}
				)
			);
		}
		if ( $is_attachment && false !== strpos( $query, 'replaces_attachment_id IS NOT NULL' ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return null !== $row['replaces_attachment_id'];
					}
				)
			);
		}
		if ( $is_delivery && false !== strpos( $query, "state IN ('issued', 'consumed')" ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return in_array( $row['state'], array( 'issued', 'consumed' ), true );
					}
				)
			);
		}
		if ( $is_delivery && false !== strpos( $query, "state = 'terminal'" ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'terminal' === $row['state'];
					}
				)
			);
		}
		if ( $is_delivery && preg_match( "/updated_at < '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return $row['updated_at'] < $filter[1];
					}
				)
			);
		}
		if ( $is_delivery && preg_match( "/terminal_at < '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return null !== $row['terminal_at'] && $row['terminal_at'] < $filter[1];
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
		if ( false !== strpos( $query, 'ORDER BY id ASC' ) ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					return $a['id'] <=> $b['id'];
				}
			);
		} elseif ( ! $is_delivery ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					$date_order = $b['created_at'] <=> $a['created_at'];
					return 0 !== $date_order ? $date_order : ( $b['id'] <=> $a['id'] );
				}
			);
		}
		if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $page ) ) {
			$rows = array_slice( $rows, (int) $page[2], (int) $page[1] );
		} elseif ( preg_match( '/LIMIT (\d+)/', $query, $page ) ) {
			$rows = array_slice( $rows, 0, (int) $page[1] );
		}
		if ( $is_activity && false !== strpos( $query, 'is_communication = 1' ) ) {
			$this->communication_public_rows_returned += count( $rows );
		}
		return $rows;
	}

	public function update( $table, $data, $where ) {
		$this->last_error = '';
		if ( $this->fail_updates || ( $this->fail_communication_state_updates && false !== strpos( $table, 'ec_booking_communication_state' ) ) ) {
			$this->last_error = 'simulated update failure';
			return false;
		}
		foreach ( $this->rows[ $table ] ?? array() as $key => $row ) {
			foreach ( $where as $field => $value ) {
				if ( (string) ( $row[ $field ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			$this->rows[ $table ][ $key ] = array_merge( $row, $data );
			return 1;
		}
		return 0;
	}

	public function delete( $table, $where ) {
		$this->last_error = '';
		$deleted          = 0;
		foreach ( $this->rows[ $table ] ?? array() as $key => $row ) {
			foreach ( $where as $field => $value ) {
				if ( (string) ( $row[ $field ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			unset( $this->rows[ $table ][ $key ] );
			++$deleted;
		}
		return $deleted;
	}

	public function query( $query ) {
		if ( ! $this->ready ) {
			$this->last_error = 'simulated closed database connection';
			return false;
		}
		$this->last_query = $query;
		$this->last_error = '';
		if ( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' === $query ) {
			$this->transaction_boundary_queries[] = $query;
			if ( $this->throw_transaction_boundary ) {
				throw new RuntimeException( 'simulated transaction boundary throwable' );
			}
			if ( $this->transaction_active || $this->fail_transaction_boundary ) {
				$this->last_error = 'simulated transaction boundary failure';
				return false;
			}
			return 1;
		}
		if ( 'START TRANSACTION' === $query ) {
			$this->transaction_start_reference_lock_counts[] = array_sum( $this->reference_locks );
			if ( $this->transaction_active ) {
				++$this->nested_transaction_starts;
			}
			if ( $this->fail_transaction_start ) {
				$this->last_error = 'simulated transaction start failure';
				return false;
			}
			$this->transaction_snapshot = $this->rows;
			$this->savepoint_snapshot   = null;
			$this->transaction_active   = true;
			return 1;
		}
		if ( 'SAVEPOINT booking_communication_projection' === $query ) {
			$this->savepoint_snapshot = $this->rows;
			return 1;
		}
		if ( 'ROLLBACK TO SAVEPOINT booking_communication_projection' === $query ) {
			if ( ! is_array( $this->savepoint_snapshot ) ) {
				$this->last_error = 'simulated missing savepoint';
				return false;
			}
			$this->rows = $this->savepoint_snapshot;
			return 1;
		}
		if ( 'RELEASE SAVEPOINT booking_communication_projection' === $query ) {
			$this->savepoint_snapshot = null;
			return 1;
		}
		if ( 'COMMIT' === $query ) {
			if ( $this->throw_transaction_commit_after_success ) {
				$this->transaction_snapshot = null;
				$this->savepoint_snapshot   = null;
				$this->transaction_active   = false;
				throw new RuntimeException( 'simulated post-commit throwable' );
			}
			if ( $this->throw_transaction_commit ) {
				throw new RuntimeException( 'simulated transaction commit throwable' );
			}
			if ( $this->fail_transaction_commit ) {
				$this->last_error = 'simulated transaction commit failure';
				return false;
			}
			$this->transaction_snapshot = null;
			$this->savepoint_snapshot   = null;
			$this->transaction_active   = false;
			return 1;
		}
		if ( 'ROLLBACK' === $query ) {
			++$this->rollback_queries;
			if ( $this->throw_transaction_rollback ) {
				throw new RuntimeException( 'simulated transaction rollback throwable' );
			}
			if ( $this->fail_transaction_rollback ) {
				$this->last_error = 'simulated transaction rollback failure';
				return false;
			}
			$this->rows                 = $this->transaction_snapshot;
			$this->transaction_snapshot = null;
			$this->savepoint_snapshot   = null;
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
				$past                                      = gmdate( 'Y-m-d H:i:s' );
				$this->rows[ $table ][ $id ]['expires_at'] = $past;
				if ( is_array( $this->transaction_snapshot ) ) {
					$this->transaction_snapshot[ $table ][ $id ]['expires_at'] = $past;
				}
				$this->elapse_hold_before_conversion_update = null;
			}
			if ( false !== strpos( $query, "status = 'converted'" ) && $this->fail_read_after_conversion_update ) {
				$this->reads_before_failure              = 0;
				$this->fail_read_after_conversion_update = false;
			}
			if ( ! isset( $this->rows[ $table ][ $id ] ) || (int) $this->rows[ $table ][ $id ]['version'] !== $expected || 'active' !== $this->rows[ $table ][ $id ]['status'] || $this->rows[ $table ][ $id ]['expires_at'] <= $this->current_database_time() ) {
				return 0;
			}
		}
		if ( false !== strpos( $query, "SET status = 'expired'" ) && preg_match( '/UPDATE ([^ ]+).*WHERE booking_id = (\d+).*expires_at <= UTC_TIMESTAMP\(\).*id <> (\d+)/', $query, $expired ) ) {
			$count                     = 0;
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
		if ( false !== strpos( $query, "SET status = 'released'" ) && preg_match( '/UPDATE ([^ ]+).*WHERE booking_id = (\d+).*expires_at > UTC_TIMESTAMP\(\).*id <> (\d+)/', $query, $release ) ) {
			$count                     = 0;
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
		if ( false !== strpos( $query, "release_reason = 'artist_withdrawn'" ) && preg_match( '/UPDATE ([^ ]+)/', $query, $release_table ) && preg_match( "/WHERE booking_id = (\d+) AND status = 'active' AND expires_at > UTC_TIMESTAMP\(\)/", $query, $release_booking ) ) {
			$count                            = 0;
			$this->rows[ $release_table[1] ] = $this->rows[ $release_table[1] ] ?? array();
			foreach ( $this->rows[ $release_table[1] ] as &$row ) {
				if ( (int) $row['booking_id'] === (int) $release_booking[1] && 'active' === $row['status'] && ( $row['expires_at'] ?? '' ) > $this->current_database_time() ) {
					$row['status']              = 'released';
					$row['released_by_user_id'] = null;
					$row['release_reason']       = 'artist_withdrawn';
					++$row['version'];
					++$count;
				}
			}
			unset( $row );
			return $count;
		}
		if ( preg_match( "/UPDATE ([^ ]*ec_booking_holds) SET venue_term_id = (\d+), space_key = '([^']+)', start_at = '([^']+)', end_at = '([^']+)', version = version \+ 1, updated_at = '([^']+)' WHERE booking_id = (\d+) AND status = 'converted'/", $query, $hold_update ) ) {
			$count = 0;
			foreach ( $this->rows[ $hold_update[1] ] ?? array() as &$row ) {
				if ( (int) $row['booking_id'] === (int) $hold_update[7] && 'converted' === $row['status'] ) {
					$row['venue_term_id'] = (int) $hold_update[2];
					$row['space_key']     = stripslashes( $hold_update[3] );
					$row['start_at']      = $hold_update[4];
					$row['end_at']        = $hold_update[5];
					$row['updated_at']    = $hold_update[6];
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

	public function close() {
		++$this->close_calls;
		if ( $this->transaction_active && is_array( $this->transaction_snapshot ) ) {
			$this->rows = $this->transaction_snapshot;
		}
		$this->ready                = false;
		$this->transaction_active   = false;
		$this->reference_locks      = array();
		$this->transaction_snapshot = null;
		$this->savepoint_snapshot   = null;
		return true;
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/LocalSupportSchema.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueAuthorization.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/ArtistMappingLock.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/LocalSupportRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/LocalSupportAuthorization.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/LocalSupportService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/LocalSupportWorkspace.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingActivityRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingNotificationService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/LocalSupportNotificationAdapter.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/LocalSupportNotificationService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingCommunicationService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingHoldRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingMutationService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingEventSyncService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingEventConversionService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingMarketingService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingLifecycle.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingPrivateFileProvider.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/LocalBookingPrivateFileProvider.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingPrivateStorageReadiness.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingPrivateFileProviders.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingAttachmentPolicy.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingAttachmentReadiness.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingAttachmentRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingAttachmentDeliveryRepository.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingAttachmentService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueBookingConfig.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingInquiryAdmissionService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/ArtistBookingInquiryService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueBookingEmbed.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingCorrespondenceAutomationService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/TicketReconciliationService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/TicketSettlementService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/ShowSettlementService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/CanonicalEventPublicationGuard.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/LocalSupportAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/BookingAttachmentAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingHoldAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingMutationAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingEventAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingCommunicationAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueBookingMarketingAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/TicketSettlementAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/ShowSettlementAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingReportingService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/BookingReportingAbilities.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingPrivacyService.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/BookingPrivacyAbilities.php';

final class BookingTestAuthorization extends VenueAuthorization {
	public $calls                     = array();
	public $direct_calls              = array();
	public $locked_calls              = array();
	public $allowed                   = array();
	public $require_locked_membership = false;
	public function __construct( array $allowed = array() ) {
		parent::__construct();
		$this->allowed = array_merge( array( '12:55' => true ), $allowed );
	}
	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		$this->calls[]        = array( $user_id, $venue_term_id, $action );
		$this->direct_calls[] = array( $user_id, $venue_term_id, $action );
		return ! empty( $this->allowed[ $user_id . ':' . $venue_term_id ] ) ? true : new WP_Error( 'venue_action_forbidden' );
	}
	public function authorize_locked( int $user_id, int $venue_term_id, string $action, array $locked_memberships ) {
		$this->calls[]        = array( $user_id, $venue_term_id, $action );
		$this->locked_calls[] = array( $user_id, $venue_term_id, $action, $locked_memberships );
		if ( $this->require_locked_membership ) {
			return parent::authorize_locked( $user_id, $venue_term_id, $action, $locked_memberships );
		}
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

final class BookingTestPrivateFileProvider implements BookingPrivateFileProvider {
	public $objects           = array();
	public $contents          = array();
	public $claims            = array();
	public $released          = array();
	public $retired           = array();
	public $fail_release      = false;
	public $fail_retire       = false;
	public $handoffs          = array();
	public $claim_records     = array();
	public $throw_claim       = false;
	public $inspect_uncertain = 0;
	public $inspect_truncated = false;
	public $stage_count       = 0;
	public $fail_stage_at     = 0;
	public $after_stage;
	public $claim_count   = 0;
	public $fail_claim_at = 0;
	public function stage( string $source_path, string $filename, string $purpose ) {
		++$this->stage_count;
		if ( $this->fail_stage_at === $this->stage_count ) {
			return new WP_Error( 'simulated_stage_failure' );
		}
		$reference                    = sprintf( 'private_staged_object_%06d', $this->stage_count );
		$filetype                     = wp_check_filetype( $filename, BookingAttachmentPolicy::allowed_mimes() );
		$this->objects[ $reference ]  = array(
			'filename'     => $filename,
			'mime_type'    => $filetype['type'],
			'byte_size'    => filesize( $source_path ),
			'content_hash' => hash_file( 'sha256', $source_path ),
			'scan_status'  => BookingAttachmentPolicy::requires_malware_scan( $filetype['type'] ) ? 'clean' : 'not_required',
		);
		$this->contents[ $reference ] = file_get_contents( $source_path );
		if ( is_callable( $this->after_stage ) ) {
			call_user_func( $this->after_stage, $reference, $purpose );
		}
		return $reference;
	}
	public function claim( string $storage_reference, string $claim_key, string $purpose = '' ) {
		unset( $purpose );
		if ( $this->throw_claim ) {
			throw new RuntimeException( 'simulated provider crash' );
		}
		++$this->claim_count;
		if ( $this->fail_claim_at === $this->claim_count ) {
			return new WP_Error( 'simulated_claim_failure' );
		}
		$this->claims[] = array( $storage_reference, $claim_key );
		$this->claim_records[ $storage_reference . '|' . $claim_key ] = array(
			'storage_reference' => $storage_reference,
			'claim_key'         => $claim_key,
			'state'             => 'active',
			'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
		);
		return $this->objects[ $storage_reference ] ?? new WP_Error( 'private_object_missing' );
	}
	public function release_claim( string $storage_reference, string $claim_key ) {
		$this->released[] = array( $storage_reference, $claim_key );
		if ( isset( $this->claim_records[ $storage_reference . '|' . $claim_key ] ) ) {
			$this->claim_records[ $storage_reference . '|' . $claim_key ]['state'] = 'abandoned';
		}
		return $this->fail_release ? new WP_Error( 'simulated_claim_release_failure' ) : true;
	}
	public function inspect_claims( ?string $cursor = null ) {
		$candidates = array();
		foreach ( $this->claim_records as $claim ) {
			if ( 'active' !== ( $claim['state'] ?? '' ) ) {
				continue;
			}
			$key = hash( 'sha256', $claim['storage_reference'] . "\0" . $claim['claim_key'] );
			if ( null !== $cursor && strcmp( $key, $cursor ) <= 0 ) {
				continue;
			}
			$claim['cursor'] = $key;
			$candidates[]    = $claim;
		}
		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				return strcmp( $left['cursor'], $right['cursor'] );
			}
		);
		$truncated    = $this->inspect_truncated || count( $candidates ) > 250;
		$claims       = array_slice( $candidates, 0, 250 );
		$continuation = $truncated && ! empty( $claims ) ? $claims[ count( $claims ) - 1 ]['cursor'] : null;
		foreach ( $claims as &$claim ) {
			unset( $claim['cursor'] );
		}
		unset( $claim );
		return array(
			'claims'       => $claims,
			'uncertain'    => $this->inspect_uncertain,
			'truncated'    => $truncated,
			'continuation' => $continuation,
		);
	}
	public function download_descriptor( string $storage_reference, string $attachment_public_id, int $actor_id, string $purpose, string $claim_key, string $correlation_id ) {
		$token                    = bin2hex( random_bytes( 32 ) );
		$this->handoffs[ $token ] = array( $storage_reference, $attachment_public_id, $actor_id, $purpose, $claim_key, $correlation_id );
		return isset( $this->objects[ $storage_reference ] ) ? array(
			'stream_token' => $token,
			'expires_at'   => '2026-08-01T00:05:00Z',
		) : new WP_Error( 'private_object_missing' );
	}
	public function open_stream( string $stream_token, string $attachment_public_id, int $actor_id, string $purpose, string $correlation_id ) {
		$handoff = $this->handoffs[ $stream_token ] ?? null;
		unset( $this->handoffs[ $stream_token ] );
		if ( ! is_array( $handoff ) || $handoff[1] !== $attachment_public_id || $handoff[2] !== $actor_id || $handoff[3] !== $purpose || $handoff[5] !== $correlation_id ) {
			return new WP_Error( 'booking_private_stream_invalid' );
		}
		$stream = fopen( 'php://temp', 'rb+' );
		fwrite( $stream, (string) ( $this->contents[ $handoff[0] ] ?? '' ) );
		rewind( $stream );
		return $stream;
	}
	public function retire( string $storage_reference ) {
		$this->retired[] = $storage_reference;
		if ( $this->fail_retire ) {
			return new WP_Error( 'simulated_retirement_failure' );
		}
		unset( $this->objects[ $storage_reference ] );
		return true;
	}
}

final class BookingTestVenueTaxonomy {
	public static function resolve_venue_identity( string $venue_name, array $venue_data = array() ): array {
		unset( $venue_data );
		foreach ( $GLOBALS['ec_artist_test']['terms'][ get_current_blog_id() ] ?? array() as $term ) {
			if ( 'venue' === $term->taxonomy && 0 === strcasecmp( $term->name, $venue_name ) ) {
				return array(
					'term_id'      => (int) $term->term_id,
					'match_status' => 'matched',
				);
			}
		}
		return array(
			'term_id'      => null,
			'match_status' => 'no_match',
		);
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
