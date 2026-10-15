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

		if ( 0 === $result['failed'] && empty( $result['dry_run'] ) ) {
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
		unset( $assoc_args );

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
	 * Send or schedule a newsletter post to Sendy lists.
	 *
	 * Always prints a preview first (subject, sender, lists, audience size).
	 * Nothing is sent unless --yes is passed. A post that was already sent or
	 * scheduled is refused unless --force is also passed.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Newsletter post ID.
	 *
	 * [--lists=<ids>]
	 * : Comma-separated Sendy list IDs (encrypted hashes). Use this or --all-lists.
	 *
	 * [--all-lists]
	 * : Send to every Sendy list.
	 *
	 * [--exclude=<ids>]
	 * : Comma-separated Sendy list IDs to drop from the target set.
	 *
	 * [--schedule=<datetime>]
	 * : Send later instead of now, as "Y-m-d H:i" (minutes in multiples of 5).
	 *
	 * [--timezone=<timezone>]
	 * : IANA timezone for --schedule. Defaults to America/New_York.
	 *
	 * [--yes]
	 * : Actually send or schedule. Without it, this is a preview.
	 *
	 * [--force]
	 * : Allow sending a post that was already sent or scheduled.
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
	 *     # Preview sending to all lists except one.
	 *     wp extrachill newsletter send_campaign 69 --all-lists --exclude=abc123 --url=newsletter.extrachill.com --user=1
	 *
	 *     # Schedule for Sunday 9am Eastern.
	 *     wp extrachill newsletter send_campaign 69 --all-lists --schedule="2026-10-18 09:00" --yes --url=newsletter.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function send_campaign( $args, $assoc_args ) {
		$post_id = absint( $args[0] ?? 0 );
		if ( ! $post_id ) {
			WP_CLI::error( 'Post ID is required.' );
		}

		$all_lists = ! empty( $assoc_args['all-lists'] );
		$lists     = $this->parse_id_list( $assoc_args['lists'] ?? '' );
		if ( $all_lists === ! empty( $lists ) ) {
			WP_CLI::error( 'Pass either --lists or --all-lists.' );
		}

		$ability = wp_get_ability( 'extrachill/send-campaign' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/send-campaign ability not available. Ensure extrachill-newsletter plugin is activated.' );
		}

		$input = array(
			'post_id' => $post_id,
			'dry_run' => true,
		);
		if ( $all_lists ) {
			$input['all_lists'] = true;
		} else {
			$input['list_ids'] = $lists;
		}
		$exclude = $this->parse_id_list( $assoc_args['exclude'] ?? '' );
		if ( $exclude ) {
			$input['exclude_list_ids'] = $exclude;
		}
		if ( ! empty( $assoc_args['schedule'] ) ) {
			$input['schedule_date_time'] = (string) $assoc_args['schedule'];
		}
		if ( ! empty( $assoc_args['timezone'] ) ) {
			$input['schedule_timezone'] = (string) $assoc_args['timezone'];
		}
		if ( ! empty( $assoc_args['force'] ) ) {
			$input['force'] = true;
		}

		$format  = $assoc_args['format'] ?? 'table';
		$preview = $ability->execute( $input );
		if ( is_wp_error( $preview ) ) {
			WP_CLI::error( $preview->get_error_message() );
		}

		if ( empty( $assoc_args['yes'] ) ) {
			$this->render_send_result( $preview, $format );
			if ( 'json' !== $format ) {
				WP_CLI::log( 'Preview only. Nothing was sent. Re-run with --yes to send.' );
			}
			return;
		}

		$input['dry_run'] = false;
		$result           = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$this->render_send_result( $result, $format );
		if ( 'json' !== $format ) {
			WP_CLI::success( $result['message'] ?? 'Sent.' );
		}
	}

	/**
	 * Split a comma-separated ID option into a clean list.
	 *
	 * @param string $value Raw option value.
	 * @return string[]
	 */
	private function parse_id_list( $value ) {
		$ids = array_map( 'trim', explode( ',', (string) $value ) );
		return array_values( array_unique( array_filter( $ids, 'strlen' ) ) );
	}

	/**
	 * Print a send-campaign preview or result.
	 *
	 * @param array  $result Ability result.
	 * @param string $format table|json.
	 */
	private function render_send_result( array $result, $format ) {
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$schedule = $result['schedule'] ?? null;
		WP_CLI::log( sprintf( 'Post:     %d', $result['post_id'] ?? 0 ) );
		WP_CLI::log( sprintf( 'Subject:  %s', $result['subject'] ?? '' ) );
		WP_CLI::log( sprintf( 'From:     %s <%s>', $result['from_name'] ?? '', $result['from_email'] ?? '' ) );
		WP_CLI::log( sprintf( 'When:     %s', $schedule ? $schedule['date_time'] . ' ' . $schedule['timezone'] : 'now' ) );

		$rows = array();
		foreach ( $result['lists'] ?? array() as $list ) {
			$rows[] = array(
				'ID'     => $list['id'],
				'Name'   => $list['name'],
				'Active' => $list['active'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Name', 'Active' ) );

		foreach ( $result['excluded'] ?? array() as $list ) {
			WP_CLI::log( sprintf( 'Excluded: %s (%d)', $list['name'], $list['active'] ) );
		}
		WP_CLI::log( sprintf( 'Audience: %d active (summed per list; Sendy dedupes addresses on several lists)', $result['total_active'] ?? 0 ) );

		$previous = $result['previous'] ?? null;
		if ( $previous && ! empty( $result['dry_run'] ) ) {
			WP_CLI::warning(
				sprintf(
					'Already %s. A real send needs --force.',
					! empty( $previous['sent_at'] ) ? 'sent at ' . $previous['sent_at'] : 'scheduled for ' . $previous['scheduled_for']
				)
			);
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
		$result = $this->call_dependency( 'extrachill_newsletter_ability_get_settings', array() );

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
					'Label'      => $integration['label'],
					'List ID'    => $integration['list_id'] ? $integration['list_id'] : '(not set)',
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
			WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * List Sendy campaigns.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status (sent, draft, scheduled).
	 *
	 * [--per-page=<per-page>]
	 * : Number of results. Default 20.
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
	 *     wp extrachill newsletter campaigns
	 *     wp extrachill newsletter campaigns --status=sent
	 *     wp extrachill newsletter campaigns --format=json
	 *
	 * @when after_wp_load
	 */
	public function campaigns( $args, $assoc_args ) {
		$result = $this->call_dependency(
			'extrachill_newsletter_ability_list_campaigns',
			array(
				'per_page' => (int) ( $assoc_args['per-page'] ?? 20 ),
				'status'   => $assoc_args['status'] ?? '',
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'table' === $format ) {
			$rows = array();
			foreach ( $result['campaigns'] as $c ) {
				$rows[] = array(
					'ID'         => $c['id'],
					'Title'      => $c['title'],
					'Status'     => $c['status'],
					'Sent'       => $c['sent_date'] ?? '-',
					'To Send'    => $c['to_send'],
					'Recipients' => $c['recipients'],
				);
			}
			WP_CLI::line( sprintf( 'Showing %d of %d campaigns', count( $rows ), $result['total'] ) );
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Title', 'Status', 'Sent', 'To Send', 'Recipients' ) );
		} else {
			WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Get details for a single Sendy campaign.
	 *
	 * ## OPTIONS
	 *
	 * <campaign_id>
	 * : Sendy campaign ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill newsletter campaign 185
	 *
	 * @when after_wp_load
	 */
	public function campaign( $args, $assoc_args ) {
		unset( $assoc_args );

		$campaign_id = absint( $args[0] ?? 0 );

		if ( ! $campaign_id ) {
			WP_CLI::error( 'Campaign ID is required.' );
		}

		$result = $this->call_dependency( 'extrachill_newsletter_ability_get_campaign', array( 'campaign_id' => $campaign_id ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Delete a Sendy campaign (drafts only).
	 *
	 * ## OPTIONS
	 *
	 * <campaign_id>
	 * : Sendy campaign ID to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill newsletter delete-campaign 184
	 *
	 * @when after_wp_load
	 */
	public function delete_campaign( $args, $assoc_args ) {
		unset( $assoc_args );

		$campaign_id = absint( $args[0] ?? 0 );

		if ( ! $campaign_id ) {
			WP_CLI::error( 'Campaign ID is required.' );
		}

		$result = $this->call_dependency( 'extrachill_newsletter_ability_delete_campaign', array( 'campaign_id' => $campaign_id ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Check a subscriber's status in a Sendy list.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Email address to check.
	 *
	 * --list-id=<list-id>
	 * : Sendy list ID (encrypted).
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill newsletter subscriber-status user@example.com --list-id=abc123
	 *
	 * @when after_wp_load
	 */
	public function subscriber_status( $args, $assoc_args ) {
		$email   = $args[0] ?? '';
		$list_id = $assoc_args['list-id'] ?? '';

		if ( empty( $email ) || ! is_email( $email ) ) {
			WP_CLI::error( 'Valid email address is required.' );
		}

		if ( empty( $list_id ) ) {
			WP_CLI::error( '--list-id is required.' );
		}

		$result = $this->call_dependency(
			'extrachill_newsletter_ability_subscriber_status',
			array(
				'email'   => $email,
				'list_id' => $list_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::line( sprintf( '%s: %s', $result['email'], $result['status'] ) );
	}

	/**
	 * Get total active subscribers across all Sendy lists.
	 *
	 * Returns the network-wide count plus a per-list breakdown.
	 * Cached for 1 hour; pass --refresh to bypass.
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Bypass the cache and query fresh.
	 *
	 * [--source=<source>]
	 * : Where to read counts from.
	 * ---
	 * default: auto
	 * options:
	 *   - auto
	 *   - db
	 *   - api
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill newsletter subscribers
	 *     wp extrachill newsletter subscribers --refresh
	 *     wp extrachill newsletter subscribers --format=count
	 *     wp extrachill newsletter subscribers --source=api --format=json
	 *
	 * @when after_wp_load
	 */
	public function subscribers( $args, $assoc_args ) {
		if ( ! function_exists( 'extrachill_newsletter_ability_subscriber_stats' ) ) {
			WP_CLI::error( 'extrachill/newsletter-subscriber-stats ability not available. Ensure extrachill-newsletter plugin is activated.' );
		}

		$result = $this->call_dependency(
			'extrachill_newsletter_ability_subscriber_stats',
			array(
				'force_refresh' => ! empty( $assoc_args['refresh'] ),
				'source'        => $assoc_args['source'] ?? 'auto',
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'count' === $format ) {
			WP_CLI::line( (string) $result['total_active'] );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::line(
			sprintf(
				'Total active subscribers: %d (across %d list%s, source: %s%s)',
				$result['total_active'],
				$result['list_count'],
				1 === $result['list_count'] ? '' : 's',
				$result['source'],
				! empty( $result['cached'] ) ? ', cached' : ''
			)
		);
		WP_CLI::line( '' );

		$rows = array();
		foreach ( $result['lists'] as $list ) {
			$rows[] = array(
				'List ID' => $list['id'],
				'Name'    => $list['name'],
				'Active'  => $list['active'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'List ID', 'Name', 'Active' ) );
	}

	/**
	 * Invoke a function supplied by the Newsletter runtime.
	 *
	 * @param string $callback Function name.
	 * @param mixed  ...$args Function arguments.
	 * @return mixed
	 */
	private function call_dependency( $callback, ...$args ) {
		if ( ! is_callable( $callback ) ) {
			WP_CLI::error( sprintf( 'Newsletter function %s is not available.', $callback ) );
			return null;
		}

		return $callback( ...$args );
	}
}
