<?php
/**
 * Newsletter CLI Commands
 *
 * Wraps newsletter abilities from extrachill-newsletter.
 *
 * @package ExtraChill\CLI\Commands\Newsletter
 */

namespace ExtraChill\CLI\Commands\Newsletter;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NewsletterCommand {

	/**
	 * Subscribe one or more email addresses.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Email address to subscribe.
	 *
	 * [--integration=<integration>]
	 * : Integration context (homepage, navigation, content, archive, contact).
	 *
	 * [--list-id=<list-id>]
	 * : Direct Sendy list ID (admin only; skips context lookup).
	 *
	 * [--name=<name>]
	 * : Subscriber name.
	 *
	 * [--source-url=<source-url>]
	 * : URL where the subscription originated.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill newsletter subscribe user@example.com --integration=homepage
	 *     wp extrachill newsletter subscribe user@example.com --list-id=abc123
	 *
	 * @when after_wp_load
	 */
	public function subscribe( $args, $assoc_args ) {
		$email       = $args[0] ?? '';
		$integration = $assoc_args['integration'] ?? '';
		$list_id     = $assoc_args['list-id'] ?? '';
		$name        = $assoc_args['name'] ?? '';
		$source_url  = $assoc_args['source-url'] ?? '';

		if ( empty( $email ) || ! is_email( $email ) ) {
			WP_CLI::error( 'Valid email address is required.' );
		}

		if ( empty( $integration ) && empty( $list_id ) ) {
			WP_CLI::error( 'Either --integration or --list-id is required.' );
		}

		$ability = wp_get_ability( 'extrachill/subscribe' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/subscribe ability not available. Ensure extrachill-newsletter plugin is activated.' );
		}

		$input = array(
			'email'      => $email,
			'name'       => $name,
			'source_url' => $source_url,
		);

		if ( ! empty( $list_id ) ) {
			$input['list_id'] = $list_id;
		}
		if ( ! empty( $integration ) ) {
			$input['context'] = $integration;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['success'] ) ) {
			WP_CLI::success( $result['message'] );
		} else {
			WP_CLI::warning( $result['message'] . ' (status: ' . $result['status'] . ')' );
		}
	}

	/**
	 * Bulk sync subscribers to a Sendy list.
	 *
	 * ## OPTIONS
	 *
	 * --integration=<integration>
	 * : Integration context to determine target list.
	 *
	 * [--emails=<emails>]
	 * : Comma-separated list of email addresses.
	 *
	 * [--since=<date>]
	 * : ISO date string. Sync users registered after this date.
	 *
	 * [--dry-run]
	 * : Preview results without actually subscribing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill newsletter sync --integration=homepage --since=2025-01-01
	 *     wp extrachill newsletter sync --integration=homepage --emails=a@b.com,c@d.com
	 *     wp extrachill newsletter sync --integration=homepage --since=2025-01-01 --dry-run
	 *
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ) {
		$integration = $assoc_args['integration'] ?? '';

		if ( empty( $integration ) ) {
			WP_CLI::error( '--integration is required.' );
		}

		$ability = wp_get_ability( 'extrachill/sync-subscribers' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/sync-subscribers ability not available. Ensure extrachill-newsletter plugin is activated.' );
		}

		$input = array(
			'context' => $integration,
		);

		if ( ! empty( $assoc_args['emails'] ) ) {
			$input['emails'] = array_map( 'trim', explode( ',', $assoc_args['emails'] ) );
		}

		if ( ! empty( $assoc_args['since'] ) ) {
			$input['since'] = $assoc_args['since'];
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$input['dry_run'] = true;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['dry_run'] ) ) {
			WP_CLI::line( 'DRY RUN — no subscriptions were processed.' );
		}

		WP_CLI::line( sprintf(
			'Total: %d | Synced: %d | Already subscribed: %d | Failed: %d',
			$result['total'],
			$result['synced'],
			$result['already_subscribed'],
			$result['failed']
		) );

		if ( ! empty( $result['errors'] ) ) {
			WP_CLI::warning( 'Errors:' );
			foreach ( $result['errors'] as $error ) {
				WP_CLI::line( '  - ' . $error );
			}
		}

		if ( $result['failed'] === 0 && empty( $result['dry_run'] ) ) {
			WP_CLI::success( 'Sync complete.' );
		}
	}

	/**
	 * Push a newsletter post to Sendy as an email campaign.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Newsletter post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill newsletter push-campaign 123
	 *
	 * @when after_wp_load
	 */
	public function push_campaign( $args, $assoc_args ) {
		$post_id = absint( $args[0] ?? 0 );

		if ( ! $post_id ) {
			WP_CLI::error( 'Post ID is required.' );
		}

		$ability = wp_get_ability( 'extrachill/push-campaign' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/push-campaign ability not available. Ensure extrachill-newsletter plugin is activated.' );
		}

		$result = $ability->execute( array( 'post_id' => $post_id ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['success'] ) ) {
			WP_CLI::success( sprintf( '%s (campaign_id: %s)', $result['message'], $result['campaign_id'] ?? 'N/A' ) );
		} else {
			WP_CLI::warning( $result['message'] );
		}
	}

	/**
	 * Get newsletter settings with integration validation.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill newsletter settings
	 *     wp extrachill newsletter settings --format=table
	 *
	 * @when after_wp_load
	 */
	public function settings( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-newsletter-settings' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-newsletter-settings ability not available. Ensure extrachill-newsletter plugin is activated.' );
		}

		// Call the execute callback directly (bypasses permission check for CLI).
		$result = extrachill_newsletter_ability_get_settings( array() );

		$format = $assoc_args['format'] ?? 'json';

		if ( 'table' === $format ) {
			// Core settings table.
			$rows = array();
			foreach ( $result['settings'] as $key => $value ) {
				$rows[] = array(
					'Field' => $key,
					'Value' => $value,
				);
			}
			WP_CLI::line( '=== Core Settings ===' );
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );

			// Integrations table.
			$int_rows = array();
			foreach ( $result['integrations'] as $context => $integration ) {
				$int_rows[] = array(
					'Context'    => $context,
					'Label'     => $integration['label'],
					'List ID'   => $integration['list_id'] ?: '(not set)',
					'Configured' => $integration['list_id_set'] ? 'Yes' : 'No',
				);
			}
			WP_CLI::line( '=== Integrations ===' );
			\WP_CLI\Utils\format_items( 'table', $int_rows, array( 'Context', 'Label', 'List ID', 'Configured' ) );

			// Warnings.
			if ( ! empty( $result['warnings'] ) ) {
				WP_CLI::line( '' );
				WP_CLI::warning( 'Warnings:' );
				foreach ( $result['warnings'] as $warning ) {
					WP_CLI::line( '  ⚠ ' . $warning );
				}
			}
		} else {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}
}
