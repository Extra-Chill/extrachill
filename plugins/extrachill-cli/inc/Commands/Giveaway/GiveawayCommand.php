<?php
/**
 * Giveaway CLI Commands
 *
 * Wraps the extrachill/run-giveaway and extrachill/resolve-instagram-media
 * abilities for CLI use.
 *
 * @package ExtraChill\CLI\Commands\Giveaway
 */

namespace ExtraChill\CLI\Commands\Giveaway;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiveawayCommand {

	/**
	 * Run a giveaway — pick random winners from Instagram post comments.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Instagram post URL, shortcode, or numeric media ID.
	 *
	 * [--winners=<count>]
	 * : Number of winners to pick.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--require-tag]
	 * : Only include commenters who tagged a friend.
	 *
	 * [--no-require-tag]
	 * : Include all commenters regardless of tags.
	 *
	 * [--min-tags=<count>]
	 * : Minimum number of tagged friends (default: 1).
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--announce]
	 * : Reply to each winner's comment to announce them.
	 *
	 * [--message=<template>]
	 * : Announcement message template. Use {username} as placeholder.
	 * ---
	 * default: Congratulations @{username}, you won the giveaway! Check your DMs for details.
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Pick 1 winner from an Instagram post
	 *     wp extrachill giveaway run https://www.instagram.com/p/CxYz1234abc/
	 *
	 *     # Pick 3 winners and announce them
	 *     wp extrachill giveaway run https://www.instagram.com/p/CxYz1234abc/ --winners=3 --announce
	 *
	 *     # Include all commenters (no tag requirement)
	 *     wp extrachill giveaway run https://www.instagram.com/p/CxYz1234abc/ --no-require-tag
	 *
	 * @subcommand run
	 * @when after_wp_load
	 */
	public function run( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/run-giveaway' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/run-giveaway ability not available. Ensure extrachill-studio is active.' );
		}

		$require_tag = Utils\get_flag_value( $assoc_args, 'require-tag', true );

		WP_CLI::log( 'Fetching comments and running giveaway…' );

		$result = $ability->execute( array(
			'media_input'  => $args[0],
			'require_tag'  => $require_tag,
			'min_tags'     => absint( $assoc_args['min-tags'] ?? 1 ),
			'winner_count' => absint( $assoc_args['winners'] ?? 1 ),
			'announce'     => ! empty( $assoc_args['announce'] ),
			'message'      => $assoc_args['message'] ?? 'Congratulations @{username}, you won the giveaway! Check your DMs for details.',
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format  = $assoc_args['format'] ?? 'table';
		$stats   = $result['stats'] ?? array();
		$winners = $result['winners'] ?? array();

		// Show stats.
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Media ID: %s', $result['media_id'] ?? 'unknown' ) );
		WP_CLI::log( sprintf( 'Total comments: %d', $stats['total_comments'] ?? 0 ) );
		WP_CLI::log( sprintf( 'Valid entries: %d', $stats['valid_entries'] ?? 0 ) );
		WP_CLI::log( sprintf( 'Filtered out: %d', $stats['filtered_out'] ?? 0 ) );

		if ( ! empty( $result['partial'] ) ) {
			WP_CLI::warning( 'Comment fetch was partial — some pages could not be loaded.' );
		}

		if ( empty( $winners ) ) {
			WP_CLI::warning( 'No winners selected.' );
			return;
		}

		// Format winners for display.
		$rows = array_map( function ( $w ) {
			return array(
				'rank'      => $w['rank'],
				'username'  => '@' . $w['username'],
				'comment'   => mb_substr( $w['comment_text'], 0, 60 ) . ( mb_strlen( $w['comment_text'] ) > 60 ? '…' : '' ),
				'tags'      => implode( ', ', array_map( function ( $m ) { return '@' . $m; }, $w['mentions'] ?? array() ) ),
				'announced' => $w['announced'] ? 'yes' : 'no',
			);
		}, $winners );

		Utils\format_items( $format, $rows, array( 'rank', 'username', 'comment', 'tags', 'announced' ) );

		$announced_count = count( array_filter( $winners, function ( $w ) { return $w['announced']; } ) );
		if ( $announced_count > 0 ) {
			WP_CLI::success( sprintf( '%d winner(s) announced via comment reply.', $announced_count ) );
		}

		WP_CLI::success( sprintf( 'Drew %d winner(s) from %d valid entries.', count( $winners ), $stats['valid_entries'] ?? 0 ) );
	}

	/**
	 * Schedule a giveaway to run at a future time.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Instagram post URL, shortcode, or numeric media ID.
	 *
	 * --run-at=<datetime>
	 * : UTC datetime when the giveaway should run (ISO 8601).
	 *
	 * [--winners=<count>]
	 * : Number of winners to pick.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--require-tag]
	 * : Only include commenters who tagged a friend.
	 *
	 * [--no-require-tag]
	 * : Include all commenters regardless of tags.
	 *
	 * [--announce]
	 * : Reply to each winner's comment to announce them.
	 *
	 * [--message=<template>]
	 * : Announcement message template. Use {username} as placeholder.
	 * ---
	 * default: Congratulations @{username}, you won the giveaway! Check your DMs for details.
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Schedule a giveaway for 7 days from now
	 *     wp extrachill giveaway schedule https://www.instagram.com/p/CxYz1234abc/ --run-at="2026-04-09T03:00:00Z" --announce
	 *
	 * @subcommand schedule
	 * @when after_wp_load
	 */
	public function schedule( $args, $assoc_args ) {
		if ( ! class_exists( 'DataMachine\\Engine\\Tasks\\TaskScheduler' ) ) {
			WP_CLI::error( 'Data Machine Task System not available.' );
		}

		$run_at    = $assoc_args['run-at'] ?? '';
		$timestamp = strtotime( $run_at );

		if ( ! $timestamp ) {
			WP_CLI::error( 'Invalid --run-at datetime. Use ISO 8601 format, e.g. 2026-04-09T03:00:00Z' );
		}

		if ( $timestamp <= time() ) {
			WP_CLI::error( '--run-at must be in the future.' );
		}

		$require_tag = Utils\get_flag_value( $assoc_args, 'require-tag', true );

		$params = array(
			'media_input'  => $args[0],
			'require_tag'  => $require_tag,
			'min_tags'     => absint( $assoc_args['min-tags'] ?? 1 ),
			'winner_count' => absint( $assoc_args['winners'] ?? 1 ),
			'announce'     => ! empty( $assoc_args['announce'] ),
			'message'      => $assoc_args['message'] ?? 'Congratulations @{username}, you won the giveaway! Check your DMs for details.',
		);

		$job_id = \DataMachine\Engine\Tasks\TaskScheduler::schedule(
			'giveaway',
			$params,
			array(
				'user_id'      => get_current_user_id(),
				'origin'       => 'cli',
				'scheduled_at' => $run_at,
			)
		);

		if ( ! $job_id ) {
			WP_CLI::error( 'Failed to schedule giveaway task.' );
		}

		WP_CLI::success( sprintf( 'Giveaway scheduled as job #%d, will run at %s UTC.', $job_id, $run_at ) );
		WP_CLI::log( sprintf( 'Track it: wp datamachine jobs show %d --allow-root', $job_id ) );
	}

	/**
	 * Resolve an Instagram URL to a numeric media ID.
	 *
	 * ## OPTIONS
	 *
	 * <input>
	 * : Instagram post URL, shortcode, or numeric media ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill giveaway resolve https://www.instagram.com/p/CxYz1234abc/
	 *     wp extrachill giveaway resolve CxYz1234abc
	 *
	 * @subcommand resolve
	 * @when after_wp_load
	 */
	public function resolve( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/resolve-instagram-media' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/resolve-instagram-media ability not available.' );
		}

		$result = $ability->execute( array( 'input' => $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( sprintf( 'Platform: %s', $result['platform'] ?? 'unknown' ) );
		WP_CLI::log( sprintf( 'Media ID: %s', $result['media_id'] ?? 'unknown' ) );
		WP_CLI::success( 'Resolved.' );
	}
}
