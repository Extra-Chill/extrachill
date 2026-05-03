<?php
/**
 * Concert Tracking CLI Commands
 *
 * Wraps concert tracking abilities from extrachill-users.
 * Designed for both admin operators and authenticated agents.
 *
 * Commands:
 *   wp extrachill shows mark <event_id> [--user=<user>]
 *   wp extrachill shows unmark <event_id> [--user=<user>]
 *   wp extrachill shows check <event_id> [--user=<user>]
 *   wp extrachill shows list [<user>] [--period=<period>] [--year=<year>] [--format=<format>]
 *   wp extrachill shows stats [<user>] [--year=<year>] [--format=<format>]
 *   wp extrachill shows event <event_id> [--attendees] [--format=<format>]
 *   wp extrachill shows leaderboard [<user>] [--year=<year>] [--type=<type>] [--limit=<n>]
 *   wp extrachill shows import <user> <event_ids> [--dry-run]
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConcertTrackingCommand {

	/**
	 * Mark an event for a user (Going / Check In / I Was There).
	 *
	 * ## OPTIONS
	 *
	 * <event_id>
	 * : Event post ID.
	 *
	 * [--user=<user>]
	 * : User ID, login, or email. Defaults to current CLI user (admin).
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shows mark 4521
	 *     wp extrachill shows mark 4521 --user=chubes
	 *
	 * @when after_wp_load
	 */
	public function mark( $args, $assoc_args ) {
		$event_id = (int) $args[0];
		$user     = $this->resolve_user( $assoc_args );

		$this->validate_event( $event_id );

		$ability = wp_get_ability( 'extrachill/toggle-event-mark' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/toggle-event-mark ability not available. Is extrachill-users active?' );
		}

		$result = $ability->execute( array(
			'user_id'  => (int) $user->ID,
			'event_id' => $event_id,
			'action'   => 'mark',
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['changed'] ) ) {
			$timing = $result['timing'] ?? '';
			WP_CLI::success( sprintf(
				'Marked event %d for %s. (%s)',
				$event_id,
				$user->user_login,
				$this->timing_label( $timing )
			) );
		} else {
			WP_CLI::warning( sprintf( 'Event %d already marked for %s.', $event_id, $user->user_login ) );
		}
	}

	/**
	 * Unmark an event for a user.
	 *
	 * ## OPTIONS
	 *
	 * <event_id>
	 * : Event post ID.
	 *
	 * [--user=<user>]
	 * : User ID, login, or email. Defaults to current CLI user.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shows unmark 4521 --user=chubes
	 *
	 * @when after_wp_load
	 */
	public function unmark( $args, $assoc_args ) {
		$event_id = (int) $args[0];
		$user     = $this->resolve_user( $assoc_args );

		$ability = wp_get_ability( 'extrachill/toggle-event-mark' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/toggle-event-mark ability not available. Is extrachill-users active?' );
		}

		$result = $ability->execute( array(
			'user_id'  => (int) $user->ID,
			'event_id' => $event_id,
			'action'   => 'unmark',
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['changed'] ) ) {
			WP_CLI::success( sprintf( 'Unmarked event %d for %s.', $event_id, $user->user_login ) );
		} else {
			WP_CLI::warning( sprintf( 'Event %d was not marked for %s.', $event_id, $user->user_login ) );
		}
	}

	/**
	 * Check if a user has marked an event.
	 *
	 * ## OPTIONS
	 *
	 * <event_id>
	 * : Event post ID.
	 *
	 * [--user=<user>]
	 * : User ID, login, or email. Defaults to current CLI user.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shows check 4521 --user=chubes
	 *     wp extrachill shows check 4521 --user=chubes --format=json
	 *
	 * @when after_wp_load
	 */
	public function check( $args, $assoc_args ) {
		$event_id = (int) $args[0];
		$user     = $this->resolve_user( $assoc_args );
		$format   = $assoc_args['format'] ?? 'table';

		$ability = wp_get_ability( 'extrachill/get-event-attendance' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-event-attendance ability not available. Is extrachill-users active?' );
		}

		$result = $ability->execute( array(
			'event_id' => $event_id,
			'user_id'  => (int) $user->ID,
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$data = array(
			'event_id' => $event_id,
			'user'     => $user->user_login,
			'marked'   => $result['marked'] ?? false,
			'timing'   => $result['timing'] ?? '',
			'label'    => $this->timing_label( $result['timing'] ?? '' ),
			'count'    => $result['count'] ?? 0,
		);

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
		} else {
			$rows = array();
			foreach ( $data as $key => $value ) {
				$display = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value;
				$rows[]  = array( 'Field' => $key, 'Value' => $display );
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );
		}
	}

	/**
	 * List a user's marked events (concert history).
	 *
	 * ## OPTIONS
	 *
	 * [<user>]
	 * : User ID, login, or email. Defaults to current CLI user.
	 *
	 * [--period=<period>]
	 * : Filter by period.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - upcoming
	 *   - past
	 * ---
	 *
	 * [--year=<year>]
	 * : Filter by year (e.g. 2025).
	 *
	 * [--page=<page>]
	 * : Page number.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--per-page=<per_page>]
	 * : Results per page.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shows list chubes
	 *     wp extrachill shows list chubes --period=upcoming
	 *     wp extrachill shows list chubes --year=2025 --format=json
	 *     wp extrachill shows list --period=past --per-page=50
	 *
	 * @when after_wp_load
	 */
	/**
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		$user   = $this->resolve_user_from_args( $args, $assoc_args );
		$format = $assoc_args['format'] ?? 'table';

		$ability = wp_get_ability( 'extrachill/get-user-shows' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-user-shows ability not available. Is extrachill-users active?' );
		}

		$input = array(
			'user_id'  => (int) $user->ID,
			'period'   => $assoc_args['period'] ?? 'all',
			'year'     => isset( $assoc_args['year'] ) ? (int) $assoc_args['year'] : 0,
			'page'     => isset( $assoc_args['page'] ) ? (int) $assoc_args['page'] : 1,
			'per_page' => isset( $assoc_args['per-page'] ) ? (int) $assoc_args['per-page'] : 20,
		);

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( empty( $result['shows'] ) ) {
			WP_CLI::log( sprintf( 'No shows found for %s.', $user->user_login ) );
			return;
		}

		WP_CLI::log( sprintf( 'Shows for %s — page %d of %d (%d total)', $user->user_login, $result['page'], $result['pages'], $result['total'] ) );

		$rows = array();
		foreach ( $result['shows'] as $show ) {
			$artists = ! empty( $show['artists'] )
				? implode( ', ', array_column( $show['artists'], 'name' ) )
				: '';
			$rows[]  = array(
				'event_id' => $show['event_id'],
				'date'     => $show['event_date'] ?? '',
				'artist'   => mb_substr( $artists ?: $show['title'], 0, 40 ),
				'venue'    => $show['venue']['name'] ?? '',
				'city'     => $show['city']['name'] ?? '',
				'timing'   => $show['timing'] ?? '',
			);
		}

		WP_CLI\Utils\format_items( $format, $rows, array( 'event_id', 'date', 'artist', 'venue', 'city', 'timing' ) );
	}

	/**
	 * Show aggregate concert stats for a user.
	 *
	 * ## OPTIONS
	 *
	 * [<user>]
	 * : User ID, login, or email. Defaults to current CLI user.
	 *
	 * [--year=<year>]
	 * : Filter by year.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shows stats chubes
	 *     wp extrachill shows stats chubes --year=2025
	 *     wp extrachill shows stats --format=json
	 *
	 * @when after_wp_load
	 */
	public function stats( $args, $assoc_args ) {
		$user   = $this->resolve_user_from_args( $args, $assoc_args );
		$format = $assoc_args['format'] ?? 'table';
		$year   = isset( $assoc_args['year'] ) ? (int) $assoc_args['year'] : 0;

		$ability = wp_get_ability( 'extrachill/get-user-concert-stats' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-user-concert-stats ability not available. Is extrachill-users active?' );
		}

		$input = array( 'user_id' => (int) $user->ID );
		if ( $year ) {
			$input['year'] = $year;
		}

		$stats = $ability->execute( $input );

		if ( is_wp_error( $stats ) ) {
			WP_CLI::error( $stats->get_error_message() );
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		$label = $year ? sprintf( 'Concert stats for %s (%d)', $user->user_login, $year ) : sprintf( 'Concert stats for %s (all time)', $user->user_login );
		WP_CLI::log( $label );
		WP_CLI::log( str_repeat( '─', strlen( $label ) ) );

		$rows = array(
			array( 'Metric' => 'Total Shows', 'Value' => $stats['total_shows'] ),
			array( 'Metric' => 'Unique Venues', 'Value' => $stats['unique_venues'] ),
			array( 'Metric' => 'Unique Artists', 'Value' => $stats['unique_artists'] ),
			array( 'Metric' => 'Unique Cities', 'Value' => $stats['unique_cities'] ),
		);

		if ( $stats['first_show'] ) {
			$rows[] = array( 'Metric' => 'First Show', 'Value' => $stats['first_show']['date'] . ' — ' . $stats['first_show']['title'] );
		}
		if ( $stats['latest_show'] ) {
			$rows[] = array( 'Metric' => 'Latest Show', 'Value' => $stats['latest_show']['date'] . ' — ' . $stats['latest_show']['title'] );
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'Metric', 'Value' ) );

		// Top artists.
		if ( ! empty( $stats['top_artists'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Top Artists:' );
			WP_CLI\Utils\format_items( 'table', $stats['top_artists'], array( 'name', 'count' ) );
		}

		// Top venues.
		if ( ! empty( $stats['top_venues'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Top Venues:' );
			WP_CLI\Utils\format_items( 'table', $stats['top_venues'], array( 'name', 'count' ) );
		}

		// Shows by year.
		if ( ! empty( $stats['shows_by_year'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Shows by Year:' );
			$year_rows = array();
			foreach ( $stats['shows_by_year'] as $yr => $count ) {
				$year_rows[] = array( 'Year' => $yr, 'Shows' => $count );
			}
			WP_CLI\Utils\format_items( 'table', $year_rows, array( 'Year', 'Shows' ) );
		}
	}

	/**
	 * Show attendance info for a specific event.
	 *
	 * ## OPTIONS
	 *
	 * <event_id>
	 * : Event post ID.
	 *
	 * [--attendees]
	 * : Include attendee list.
	 *
	 * [--limit=<limit>]
	 * : Max attendees to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shows event 4521
	 *     wp extrachill shows event 4521 --attendees
	 *     wp extrachill shows event 4521 --attendees --format=json
	 *
	 * @when after_wp_load
	 */
	public function event( $args, $assoc_args ) {
		$event_id = (int) $args[0];
		$format   = $assoc_args['format'] ?? 'table';

		$this->validate_event( $event_id );

		$ability = wp_get_ability( 'extrachill/get-event-attendance' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-event-attendance ability not available. Is extrachill-users active?' );
		}

		$input = array( 'event_id' => $event_id );

		$include_attendees = isset( $assoc_args['attendees'] );
		if ( $include_attendees ) {
			$input['include_attendees'] = true;
			$input['attendee_limit']    = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$data = array(
			'event_id' => $event_id,
			'title'    => $result['title'] ?? '(unknown)',
			'timing'   => $result['timing'] ?? '',
			'label'    => $this->timing_label( $result['timing'] ?? '' ),
			'count'    => $result['count'] ?? 0,
		);

		if ( $include_attendees && ! empty( $result['attendees'] ) ) {
			$data['attendees'] = $result['attendees'];
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		$count_label = $result['count_label'] ?? (string) ( $result['count'] ?? 0 );

		$rows = array(
			array( 'Field' => 'Event', 'Value' => $data['title'] ),
			array( 'Field' => 'Timing', 'Value' => $data['timing'] . ' (' . $data['label'] . ')' ),
			array( 'Field' => 'Attendance', 'Value' => $count_label ),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );

		if ( $include_attendees && ! empty( $data['attendees'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Attendees:' );
			WP_CLI\Utils\format_items( 'table', $data['attendees'], array( 'user_id', 'display_name' ) );
		}
	}

	/**
	 * Bulk import event marks for a user (backfill concert history).
	 *
	 * Composite CLI-only utility — no single ability covers the full
	 * import loop (validation, dedup, dry-run). Each mark invokes the
	 * extrachill/toggle-event-mark ability individually.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * <event_ids>
	 * : Comma-separated event post IDs to mark.
	 *
	 * [--dry-run]
	 * : Preview without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shows import chubes 4521,4522,4523
	 *     wp extrachill shows import chubes 4521,4522,4523 --dry-run
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/toggle-event-mark' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/toggle-event-mark ability not available. Is extrachill-users active?' );
		}

		$user      = $this->resolve_user_by_identifier( $args[0] ?? '' );
		$event_ids = array_map( 'intval', array_filter( explode( ',', $args[1] ?? '' ) ) );
		$dry_run   = isset( $assoc_args['dry-run'] );

		if ( empty( $event_ids ) ) {
			WP_CLI::error( 'No event IDs provided.' );
		}

		$marked  = 0;
		$skipped = 0;
		$invalid = 0;

		foreach ( $event_ids as $event_id ) {
			$post = get_post( $event_id );
			if ( ! $post || 'data_machine_events' !== $post->post_type ) {
				WP_CLI::warning( sprintf( 'Event %d: not found or wrong post type. Skipping.', $event_id ) );
				++$invalid;
				continue;
			}

			if ( $dry_run ) {
				// Dry-run: use the ability with action=check to see current state.
				$check = $ability->execute( array(
					'user_id'  => (int) $user->ID,
					'event_id' => $event_id,
					'action'   => 'check',
				) );

				if ( is_wp_error( $check ) ) {
					WP_CLI::warning( sprintf( 'Event %d: %s', $event_id, $check->get_error_message() ) );
					++$invalid;
					continue;
				}

				if ( ! empty( $check['marked'] ) ) {
					WP_CLI::log( sprintf( 'Event %d: already marked. Skipping.', $event_id ) );
					++$skipped;
					continue;
				}

				$timing = $check['timing'] ?? '';
				WP_CLI::log( sprintf( 'Event %d: would mark (%s — %s)', $event_id, $post->post_title, $timing ) );
				++$marked;
			} else {
				$result = $ability->execute( array(
					'user_id'  => (int) $user->ID,
					'event_id' => $event_id,
					'action'   => 'mark',
				) );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( 'Event %d: %s', $event_id, $result->get_error_message() ) );
					++$invalid;
					continue;
				}

				if ( empty( $result['changed'] ) ) {
					WP_CLI::log( sprintf( 'Event %d: already marked. Skipping.', $event_id ) );
					++$skipped;
					continue;
				}

				WP_CLI::log( sprintf( 'Event %d: marked (%s)', $event_id, $post->post_title ) );
				++$marked;
			}
		}

		$verb = $dry_run ? 'Would mark' : 'Marked';
		WP_CLI::success( sprintf( '%s %d events for %s. Skipped: %d. Invalid: %d.', $verb, $marked, $user->user_login, $skipped, $invalid ) );
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	private function get_events_blog_id() {
		return function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	}

	private function validate_event( $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post ) {
			WP_CLI::error( sprintf( 'Event %d not found.', $event_id ) );
		}
	}

	private function timing_label( $timing ) {
		$labels = array(
			'upcoming' => 'Going',
			'ongoing'  => 'Check In',
			'past'     => 'I Was There',
		);
		return $labels[ $timing ] ?? $timing;
	}

	private function resolve_user( $assoc_args ) {
		if ( ! empty( $assoc_args['user'] ) ) {
			$user = $this->resolve_user_by_identifier( $assoc_args['user'] );
		} else {
			$user = wp_get_current_user();
			if ( ! $user || ! $user->ID ) {
				$user = get_user_by( 'id', 1 );
			}
		}

		if ( ! $user || ! $user->ID ) {
			WP_CLI::error( 'User not found.' );
		}

		return $user;
	}

	private function resolve_user_from_args( $args, $assoc_args ) {
		if ( ! empty( $args[0] ) ) {
			return $this->resolve_user_by_identifier( $args[0] );
		}

		return $this->resolve_user( $assoc_args );
	}

	private function resolve_user_by_identifier( $identifier ) {
		if ( is_numeric( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
		} elseif ( is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		} else {
			$user = get_user_by( 'login', $identifier );
		}

		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User "%s" not found.', $identifier ) );
		}

		return $user;
	}
}
