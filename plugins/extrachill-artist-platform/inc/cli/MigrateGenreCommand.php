<?php
/**
 * CLI: migrate legacy `_genre` post meta to `genre` taxonomy terms.
 *
 * One-shot migration for Extra-Chill/extrachill-artist-platform#213. Reads the
 * legacy free-text `_genre` meta on artist_profile posts (the only sanctioned
 * read of that key in the plugin), resolves each value through the network
 * genre resolver, and — with --apply — assigns the resolved terms, refreshes
 * the main-site `_genres` mirror, and deletes the legacy meta on resolved
 * rows. Unresolved rows keep `_genre` so the review list can be re-run after
 * the values are corrected by hand.
 *
 * Dry-run is the default. --apply aborts unless the genre resolver from
 * extrachill-network (Extra-Chill/extrachill-network#191) is available.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate legacy `_genre` post meta to `genre` terms + the `_genres` mirror.
 */
class EC_CLI_MigrateGenreCommand {

	/**
	 * Collect the migration report rows.
	 *
	 * Reads `_genre` and resolves it; performs no writes. Safe to call from
	 * dry-run tooling and harnesses.
	 *
	 * @return array[]|WP_Error Rows: profile_id, name, input, resolved, status.
	 */
	public static function collect_rows() {
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		if ( $artist_blog_id <= 0 ) {
			return new WP_Error( 'artist_site_unavailable', __( 'The artist site is unavailable.', 'extrachill-artist-platform' ) );
		}

		$did_switch = get_current_blog_id() !== $artist_blog_id;
		if ( $did_switch ) {
			switch_to_blog( $artist_blog_id );
		}

		$post_ids = get_posts(
			array(
				'post_type'        => 'artist_profile',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The migration target is exactly this legacy key.
				'meta_key'         => '_genre',
			)
		);

		$rows = array();
		foreach ( $post_ids as $post_id ) {
			$input    = trim( (string) get_post_meta( (int) $post_id, '_genre', true ) );
			$resolved = array();
			if ( '' !== $input && function_exists( 'extrachill_network_resolve_genres' ) ) {
				$resolved = array_values( array_unique( array_filter( (array) extrachill_network_resolve_genres( $input, EC_ARTIST_GENRE_MAX ) ) ) );
				$resolved = array_slice( $resolved, 0, EC_ARTIST_GENRE_MAX );
			}
			$rows[] = array(
				'profile_id' => (int) $post_id,
				'name'       => (string) get_the_title( (int) $post_id ),
				'input'      => $input,
				'resolved'   => $resolved,
				'status'     => empty( $resolved ) ? 'unresolved' : 'resolved',
			);
		}

		if ( $did_switch ) {
			restore_current_blog();
		}

		return $rows;
	}

	/**
	 * Run the migration command.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Assign resolved terms, refresh the mirror, and delete `_genre` on
	 * resolved rows. Unresolved rows keep `_genre` for review. Default: dry-run.
	 *
	 * [--format=<format>]
	 * : Render output as table, csv, or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill-artist-platform migrate-genre
	 *     wp extrachill-artist-platform migrate-genre --apply
	 *
	 * @param array            $args       Positional arguments (none).
	 * @param array<string,mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function run( $args, $assoc_args ) {
		$apply  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'apply', false );
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( $apply && ! function_exists( 'extrachill_network_resolve_genres' ) ) {
			WP_CLI::error( 'The genre resolver (extrachill-network#191) is not available; refusing to apply the migration.' );
		}

		$rows = self::collect_rows();
		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}

		$table_rows = array();
		foreach ( $rows as $row ) {
			$table_rows[] = array(
				'profile_id' => $row['profile_id'],
				'name'       => $row['name'],
				'input'      => $row['input'],
				'resolved'   => implode( ', ', $row['resolved'] ),
				'status'     => $row['status'],
			);
		}

		if ( ! empty( $table_rows ) ) {
			\WP_CLI\Utils\format_items( $format, $table_rows, array( 'profile_id', 'name', 'input', 'resolved', 'status' ) );
		}

		$resolved_rows   = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return 'resolved' === $row['status'];
				}
			)
		);
		$unresolved_rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return 'unresolved' === $row['status'];
				}
			)
		);

		WP_CLI::log(
			sprintf(
				'%d profile(s) with legacy _genre meta: %d resolved, %d unresolved.',
				count( $rows ),
				count( $resolved_rows ),
				count( $unresolved_rows )
			)
		);

		if ( ! empty( $unresolved_rows ) ) {
			WP_CLI::log( __( 'Review list (kept as _genre meta; fix the value and re-run):', 'extrachill-artist-platform' ) );
			foreach ( $unresolved_rows as $row ) {
				WP_CLI::log( sprintf( '  - %d "%s": %s', $row['profile_id'], $row['name'], $row['input'] ) );
			}
		}

		if ( ! $apply ) {
			WP_CLI::log( __( 'Dry run only. Re-run with --apply to write terms, refresh the mirror, and delete _genre on resolved rows.', 'extrachill-artist-platform' ) );
			return;
		}

		$applied        = 0;
		$failed         = 0;
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		foreach ( $resolved_rows as $row ) {
			$profile_id = (int) $row['profile_id'];
			$did_switch = get_current_blog_id() !== $artist_blog_id;
			if ( $did_switch ) {
				switch_to_blog( $artist_blog_id );
			}
			$assigned = wp_set_object_terms( $profile_id, $row['resolved'], 'genre', false );
			if ( is_wp_error( $assigned ) ) {
				++$failed;
				WP_CLI::warning( sprintf( 'Profile %d: term assignment failed (%s).', $profile_id, $assigned->get_error_message() ) );
				if ( $did_switch ) {
					restore_current_blog();
				}
				continue;
			}
			ec_artist_mirror_genres_to_term( $profile_id );
			$deleted = delete_post_meta( $profile_id, '_genre' );
			if ( $did_switch ) {
				restore_current_blog();
			}
			if ( ! $deleted ) {
				++$failed;
				WP_CLI::warning( sprintf( 'Profile %d: terms assigned but the legacy _genre meta could not be deleted.', $profile_id ) );
				continue;
			}
			++$applied;
		}

		WP_CLI::success( sprintf( 'Applied %d row(s); %d failure(s). Unresolved rows were left untouched for review.', $applied, $failed ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'extrachill-artist-platform migrate-genre', array( 'EC_CLI_MigrateGenreCommand', 'run' ) );
}
