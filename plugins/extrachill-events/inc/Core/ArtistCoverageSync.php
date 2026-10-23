<?php
/**
 * Artist Coverage Sync
 *
 * Keeps the "Artists We Cover" Ticketmaster flow pointed at the artists the
 * publication actually writes about. Main-site artist terms with enough
 * posts are matched to Ticketmaster attractions by exact name through
 * `data-machine-events/find-ticketmaster-attractions`, and the resulting
 * IDs are written into the flow step's `attraction_ids` through
 * `datamachine/update-flow-step`.
 *
 * Operators steer it by name: add an act we have not written about yet,
 * or exclude a wrong exact-name match (a different act sharing the name).
 *
 * Storage (events site):
 * - CONFIG_OPTION: flow_step_id, min_posts, max_ids, manual, excluded.
 * - MATCHES_OPTION: main-site artist term_id => last lookup result.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Matches covered artists to Ticketmaster and maintains the import flow. */
class ArtistCoverageSync {

	public const CONFIG_OPTION  = 'extrachill_events_artist_coverage';
	public const MATCHES_OPTION = 'extrachill_events_artist_coverage_matches';
	public const SCHEDULE_HOOK  = 'extrachill_events_artist_coverage_sync';
	public const GROUP          = 'extrachill-events';
	public const LOOKUP_ABILITY = 'data-machine-events/find-ticketmaster-attractions';
	public const UPDATE_ABILITY = 'datamachine/update-flow-step';
	public const HANDLER_SLUG   = 'ticketmaster';

	/** Re-check a stored match after this long (upcoming counts drift). */
	public const RECHECK_SECONDS = 7 * DAY_IN_SECONDS;

	/** Lookups per scheduled run; Ticketmaster rate-limits bursts. */
	public const BATCH_SIZE = 40;

	/** Pause between lookups, in microseconds. */
	public const LOOKUP_PAUSE_US = 250000;

	/**
	 * Default configuration.
	 *
	 * @return array{flow_step_id: string, min_posts: int, max_ids: int, manual: array<string,string>, excluded: string[]}
	 */
	public static function defaults(): array {
		return array(
			'flow_step_id' => '',
			'min_posts'    => 3,
			'max_ids'      => 150,
			'manual'       => array(),
			'excluded'     => array(),
		);
	}

	/** Register the daily sync on the events site. */
	public static function register(): void {
		add_action( self::SCHEDULE_HOOK, array( self::class, 'run_scheduled' ) );
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
	}

	/** Schedule the daily sync once a flow step is configured. */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( function_exists( 'ec_get_blog_id' ) && (int) ec_get_blog_id( 'events' ) !== get_current_blog_id() ) {
			return;
		}
		if ( '' === self::config()['flow_step_id'] || as_has_scheduled_action( self::SCHEDULE_HOOK, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::SCHEDULE_HOOK, array(), self::GROUP );
	}

	/** Scheduled entry point: refresh a batch and write the flow as the system. */
	public static function run_scheduled(): void {
		$run    = static fn() => self::sync( false, self::BATCH_SIZE );
		$result = class_exists( '\DataMachine\Abilities\PermissionHelper' )
			? \DataMachine\Abilities\PermissionHelper::run_as_system( $run )
			: $run();

		do_action(
			'datamachine_log',
			is_wp_error( $result ) ? 'warning' : 'info',
			is_wp_error( $result ) ? 'Artist coverage sync failed: ' . $result->get_error_message() : 'Artist coverage sync completed',
			is_wp_error( $result ) ? array() : $result
		);
	}

	/**
	 * Current configuration merged over defaults.
	 *
	 * @return array{flow_step_id: string, min_posts: int, max_ids: int, manual: array<string,string>, excluded: string[]}
	 */
	public static function config(): array {
		$stored = get_option( self::CONFIG_OPTION, array() );
		$config = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		$config['flow_step_id'] = (string) $config['flow_step_id'];
		$config['min_posts']    = max( 1, (int) $config['min_posts'] );
		$config['max_ids']      = max( 1, (int) $config['max_ids'] );
		$config['manual']       = is_array( $config['manual'] ) ? array_map( 'strval', $config['manual'] ) : array();
		$config['excluded']     = array_values( array_map( 'strval', is_array( $config['excluded'] ) ? $config['excluded'] : array() ) );

		return $config;
	}

	/**
	 * Persist configuration changes.
	 *
	 * @param array $changes Keys from defaults().
	 * @return array Updated configuration.
	 */
	public static function update_config( array $changes ): array {
		$config = array_merge( self::config(), array_intersect_key( $changes, self::defaults() ) );
		update_option( self::CONFIG_OPTION, $config, false );

		return self::config();
	}

	/**
	 * Stored lookup results keyed by main-site artist term ID.
	 *
	 * @return array<int,array<string,mixed>> Untrusted stored rows: name, id, attraction_name, upcoming, checked.
	 */
	public static function matches(): array {
		$stored = get_option( self::MATCHES_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Main-site artists with at least $min_posts published posts.
	 *
	 * @param int $min_posts Post threshold.
	 * @return array<int,string> term_id => name.
	 */
	public static function covered_artists( int $min_posts ): array {
		$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
		if ( $main_blog_id <= 0 ) {
			return array();
		}

		switch_to_blog( $main_blog_id );
		try {
			$terms = get_terms(
				array(
					'taxonomy'   => 'artist',
					'hide_empty' => true,
				)
			);
		} finally {
			restore_current_blog();
		}

		$artists = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( (int) $term->count >= $min_posts ) {
				$artists[ (int) $term->term_id ] = html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return $artists;
	}

	/**
	 * Artists whose stored match is missing, renamed, or older than RECHECK.
	 *
	 * @param array<int,string> $artists Covered artists.
	 * @param array             $matches Stored matches.
	 * @param int               $now     Current timestamp.
	 * @return array<int,string> Due artists, never-checked first.
	 */
	public static function due_artists( array $artists, array $matches, int $now ): array {
		$due = array();
		foreach ( $artists as $term_id => $name ) {
			$match = $matches[ $term_id ] ?? null;
			if ( ! is_array( $match ) || ( $match['name'] ?? '' ) !== $name ) {
				$due[ $term_id ] = -1;
				continue;
			}
			if ( $now - (int) ( $match['checked'] ?? 0 ) >= self::RECHECK_SECONDS ) {
				$due[ $term_id ] = (int) ( $match['checked'] ?? 0 );
			}
		}
		asort( $due );

		$ordered = array();
		foreach ( array_keys( $due ) as $term_id ) {
			$ordered[ $term_id ] = $artists[ $term_id ];
		}

		return $ordered;
	}

	/**
	 * Choose the attraction IDs to write.
	 *
	 * Manual additions always come first; automatic matches follow, only when
	 * they currently have upcoming events, busiest first. Excluded IDs never
	 * appear. The list is capped so the Discovery request stays a sane size.
	 *
	 * @param array    $matches  Stored matches.
	 * @param string[] $manual   attraction_id => display name.
	 * @param string[] $excluded Excluded attraction IDs.
	 * @param int      $max_ids  Cap.
	 * @return string[]
	 */
	public static function select_attraction_ids( array $matches, array $manual, array $excluded, int $max_ids ): array {
		$excluded = array_flip( $excluded );
		$ids      = array();

		foreach ( array_keys( $manual ) as $id ) {
			$ids[ (string) $id ] = PHP_INT_MAX;
		}

		foreach ( $matches as $match ) {
			$id       = (string) ( $match['id'] ?? '' );
			$upcoming = (int) ( $match['upcoming'] ?? 0 );
			if ( '' === $id || $upcoming <= 0 || isset( $ids[ $id ] ) ) {
				continue;
			}
			$ids[ $id ] = $upcoming;
		}

		$ids = array_diff_key( $ids, $excluded );
		arsort( $ids );

		return array_slice( array_map( 'strval', array_keys( $ids ) ), 0, $max_ids );
	}

	/**
	 * Resolve a name to its best Ticketmaster attraction.
	 *
	 * @param string $name Act name.
	 * @return array|null|\WP_Error best_match row, null when no exact match.
	 */
	public static function lookup( string $name ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( self::LOOKUP_ABILITY ) : null;
		if ( ! $ability ) {
			return new \WP_Error( 'artist_coverage_lookup_unavailable', 'Ticketmaster attraction lookup is unavailable.' );
		}

		$result = $ability->execute( array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return is_array( $result['best_match'] ?? null ) ? $result['best_match'] : null;
	}

	/**
	 * Refresh due matches, then write the flow.
	 *
	 * @param bool $dry_run Report without writing anything.
	 * @param int  $limit   Max lookups this run (0 = none).
	 * @return array|\WP_Error Summary.
	 */
	public static function sync( bool $dry_run, int $limit ) {
		$config = self::config();
		if ( '' === $config['flow_step_id'] ) {
			return new \WP_Error( 'artist_coverage_not_configured', 'No flow step is configured for artist coverage.' );
		}

		$artists = self::covered_artists( $config['min_posts'] );
		$matches = array_intersect_key( self::matches(), $artists );
		$due     = self::due_artists( $artists, $matches, time() );
		$checked = 0;
		$errors  = array();

		foreach ( array_slice( $due, 0, max( 0, $limit ), true ) as $term_id => $name ) {
			if ( $checked > 0 ) {
				usleep( self::LOOKUP_PAUSE_US );
			}
			$best = self::lookup( $name );
			if ( is_wp_error( $best ) ) {
				$errors[] = $name . ': ' . $best->get_error_message();
				if ( 'rate_limited' === $best->get_error_code() ) {
					break;
				}
				continue;
			}

			++$checked;
			$matches[ $term_id ] = array(
				'name'            => $name,
				'id'              => (string) ( $best['id'] ?? '' ),
				'attraction_name' => (string) ( $best['name'] ?? '' ),
				'upcoming'        => (int) ( $best['upcoming'] ?? 0 ),
				'checked'         => time(),
			);
		}

		if ( ! $dry_run ) {
			update_option( self::MATCHES_OPTION, $matches, false );
		}

		$ids     = self::select_attraction_ids( $matches, $config['manual'], $config['excluded'], $config['max_ids'] );
		$written = false;

		if ( ! $dry_run ) {
			$write = self::write_flow( $config['flow_step_id'], $ids );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
			$written = true;
		}

		return array(
			'dry_run'         => $dry_run,
			'covered_artists' => count( $artists ),
			'checked'         => $checked,
			'still_due'       => max( 0, count( $due ) - $checked ),
			'matched'         => count( array_filter( $matches, static fn( $m ) => '' !== ( $m['id'] ?? '' ) && (int) ( $m['upcoming'] ?? 0 ) > 0 ) ),
			'manual'          => count( $config['manual'] ),
			'excluded'        => count( $config['excluded'] ),
			'attraction_ids'  => count( $ids ),
			'written'         => $written,
			'errors'          => $errors,
		);
	}

	/**
	 * Write attraction IDs to the configured flow step.
	 *
	 * @param string   $flow_step_id Flow step ID.
	 * @param string[] $ids          Attraction IDs.
	 * @return true|\WP_Error
	 */
	public static function write_flow( string $flow_step_id, array $ids ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( self::UPDATE_ABILITY ) : null;
		if ( ! $ability ) {
			return new \WP_Error( 'artist_coverage_update_unavailable', 'Flow step update is unavailable.' );
		}
		if ( array() === $ids ) {
			// An empty list would turn artist mode into an unfiltered search.
			return new \WP_Error( 'artist_coverage_empty', 'Refusing to write an empty attraction list.' );
		}

		$result = $ability->execute(
			array(
				'flow_step_id'   => $flow_step_id,
				'handler_slug'   => self::HANDLER_SLUG,
				'handler_config' => array( 'attraction_ids' => implode( ',', $ids ) ),
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Add an act by name (or explicit attraction ID).
	 *
	 * @param string $name          Act name.
	 * @param string $attraction_id Optional explicit ID; skips the lookup.
	 * @return array|\WP_Error The added entry.
	 */
	public static function add( string $name, string $attraction_id = '' ) {
		$name = trim( $name );
		if ( '' === $attraction_id ) {
			$best = self::lookup( $name );
			if ( is_wp_error( $best ) ) {
				return $best;
			}
			if ( null === $best ) {
				return new \WP_Error( 'artist_coverage_no_match', sprintf( 'No exact Ticketmaster match for "%s". Pass an attraction ID to add it anyway.', $name ) );
			}
			$attraction_id = (string) $best['id'];
			$name          = (string) $best['name'];
		}

		$config                             = self::config();
		$config['manual'][ $attraction_id ] = $name;
		$config['excluded']                 = array_values( array_diff( $config['excluded'], array( $attraction_id ) ) );
		self::update_config( $config );

		return array(
			'attraction_id' => $attraction_id,
			'name'          => $name,
		);
	}

	/**
	 * Exclude an act by name or attraction ID.
	 *
	 * @param string $name_or_id Covered artist name, manual name, or attraction ID.
	 * @return array|\WP_Error The excluded entry.
	 */
	public static function exclude( string $name_or_id ) {
		$config = self::config();
		$id     = self::find_known_id( $name_or_id, self::matches(), $config['manual'] );
		if ( '' === $id ) {
			return new \WP_Error( 'artist_coverage_unknown', sprintf( '"%s" is not a covered or added act.', $name_or_id ) );
		}

		unset( $config['manual'][ $id ] );
		$config['excluded'] = array_values( array_unique( array_merge( $config['excluded'], array( $id ) ) ) );
		self::update_config( $config );

		return array( 'attraction_id' => $id );
	}

	/**
	 * Find a known attraction ID by ID, artist name, or attraction name.
	 *
	 * @param string $needle  Name or ID.
	 * @param array  $matches Stored matches.
	 * @param array  $manual  Manual additions.
	 * @return string Attraction ID or ''.
	 */
	public static function find_known_id( string $needle, array $matches, array $manual ): string {
		$needle = trim( $needle );
		$key    = mb_strtolower( $needle );

		if ( isset( $manual[ $needle ] ) ) {
			return $needle;
		}
		foreach ( $manual as $id => $name ) {
			if ( mb_strtolower( (string) $name ) === $key ) {
				return (string) $id;
			}
		}
		foreach ( $matches as $match ) {
			$id = (string) ( $match['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			if ( $id === $needle || mb_strtolower( (string) ( $match['name'] ?? '' ) ) === $key || mb_strtolower( (string) ( $match['attraction_name'] ?? '' ) ) === $key ) {
				return $id;
			}
		}

		return '';
	}
}
