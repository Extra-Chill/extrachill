<?php
/**
 * One-shot migration: legacy Music Fan Details → user bio (description).
 *
 * The free-text `favorite_artists` / `top_concerts` usermeta fields are dead:
 * the edit-profile and settings blocks carry no write path for them, so the
 * data has been frozen since the old PHP profile form era (~33 users hold
 * values; `top_venues` is empty platform-wide). This command APPENDS that
 * text to each user's `description` so it renders in the profile About card,
 * then deletes the legacy meta. Existing bios are never overwritten — the
 * legacy text is added below whatever is already there.
 *
 * Dry-run by default; pass --apply to write. Idempotent: users whose legacy
 * meta is gone are skipped on re-run.
 *
 * Usage:
 *   wp extrachill-community migrate-music-fan-details [--apply] [--url=community.extrachill.com]
 *
 * Delete this file (and its require) once the migration has run in
 * production — it is intentionally single-purpose.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Build the bio addendum from legacy meta values.
 *
 * @param string $favorite_artists Legacy favorite_artists text.
 * @param string $top_concerts     Legacy top_concerts text.
 * @param string $top_venues       Legacy top_venues text.
 * @return string Addendum text, '' when all fields are empty.
 */
function extrachill_community_build_music_fan_addendum( $favorite_artists, $top_concerts, $top_venues ) {
	$sections = array();

	if ( '' !== trim( (string) $favorite_artists ) ) {
		$sections[] = "Favorite artists: " . trim( (string) $favorite_artists );
	}
	if ( '' !== trim( (string) $top_concerts ) ) {
		$sections[] = "Top concerts: " . trim( (string) $top_concerts );
	}
	if ( '' !== trim( (string) $top_venues ) ) {
		$sections[] = "Top venues: " . trim( (string) $top_venues );
	}

	return implode( "\n\n", $sections );
}

WP_CLI::add_command(
	'extrachill-community migrate-music-fan-details',
	function ( $args, $assoc_args ) {
		$apply = isset( $assoc_args['apply'] );

		$user_ids = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-shot migration over ~33 users.
					'relation' => 'OR',
					array(
						'key'     => 'favorite_artists',
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'key'     => 'top_concerts',
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'key'     => 'top_venues',
						'value'   => '',
						'compare' => '!=',
					),
				),
				'fields'     => 'ID',
			)
		);

		if ( empty( $user_ids ) ) {
			WP_CLI::success( 'No users with legacy Music Fan Details meta — nothing to migrate.' );
			return;
		}

		WP_CLI::log( sprintf( '%d user(s) with legacy Music Fan Details meta.%s', count( $user_ids ), $apply ? '' : ' (dry run — pass --apply to write)' ) );

		$migrated = 0;

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;

			$addendum = extrachill_community_build_music_fan_addendum(
				get_user_meta( $user_id, 'favorite_artists', true ),
				get_user_meta( $user_id, 'top_concerts', true ),
				get_user_meta( $user_id, 'top_venues', true )
			);

			if ( '' === $addendum ) {
				continue;
			}

			$existing_bio = trim( (string) get_user_meta( $user_id, 'description', true ) );

			// APPEND, never overwrite: existing bio stays first, legacy text
			// follows after a blank line.
			$new_bio = '' === $existing_bio ? $addendum : $existing_bio . "\n\n" . $addendum;

			$user = get_userdata( $user_id );
			WP_CLI::log( sprintf( '- #%d %s: bio %d chars -> %d chars', $user_id, $user ? $user->user_login : '?', strlen( $existing_bio ), strlen( $new_bio ) ) );

			if ( $apply ) {
				update_user_meta( $user_id, 'description', $new_bio );
				delete_user_meta( $user_id, 'favorite_artists' );
				delete_user_meta( $user_id, 'top_concerts' );
				delete_user_meta( $user_id, 'top_venues' );
			}

			++$migrated;
		}

		if ( $apply ) {
			WP_CLI::success( sprintf( 'Migrated %d user(s): legacy text appended to bio, legacy meta deleted.', $migrated ) );
		} else {
			WP_CLI::success( sprintf( 'Dry run complete: %d user(s) would be migrated. Re-run with --apply.', $migrated ) );
		}
	}
);
