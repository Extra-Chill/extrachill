<?php
/**
 * Qualify Digest Abilities
 *
 * `extrachill/qualify-digest` — weekly summary of qualify v2 activity:
 * paused flows, auto-resumed flows, newly-qualified venues, standing
 * inventory by interval/paused_reason, and the top extraction_gap
 * fingerprints.
 *
 * The digest is informational only. It surfaces what changed and what
 * the operator may want to look at next — it never auto-decides anything
 * for them. Silence is meaningful: no email means nothing changed.
 *
 * @package ExtraChillEvents\Abilities
 * @since   0.21.0
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\QualifyVerdictsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QualifyDigestAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		$this->register();
		self::$registered = true;
	}

	private function register(): void {
		$callback = function () {
			wp_register_ability(
				'extrachill/qualify-digest',
				array(
					'label'               => __( 'Qualify Digest', 'extrachill-events' ),
					'description'         => __( 'Render and (optionally) send the weekly qualify v2 activity digest email.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'since'     => array(
								'type'        => 'string',
								'default'     => '1 week ago',
								'description' => __( 'Window start; any strtotime-parseable string.', 'extrachill-events' ),
							),
							'recipient' => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Override recipient email. Defaults to dme_qualify_digest_recipient site option or admin_email.', 'extrachill-events' ),
							),
							'format'    => array(
								'type'        => 'string',
								'enum'        => array( 'html', 'text' ),
								'default'     => 'html',
								'description' => __( 'Render format.', 'extrachill-events' ),
							),
							'dry_run'   => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Render but do not send.', 'extrachill-events' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'window_start' => array( 'type' => 'string' ),
							'window_end'   => array( 'type' => 'string' ),
							'recipient'    => array( 'type' => 'string' ),
							'sent'         => array( 'type' => 'boolean' ),
							'dry_run'      => array( 'type' => 'boolean' ),
							'counts'       => array( 'type' => 'object' ),
							'body'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => '__return_true',
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		if ( function_exists( 'doing_action' ) && doing_action( 'wp_abilities_api_init' ) ) {
			$callback();
		} elseif ( function_exists( 'did_action' ) && ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $callback );
		} else {
			$callback();
		}
	}

	/**
	 * Execute the digest.
	 *
	 * @param array $input { since, recipient, format, dry_run }
	 * @return array Summary payload.
	 */
	public function execute( array $input ): array {
		$since   = (string) ( $input['since'] ?? '1 week ago' );
		$format  = 'text' === ( $input['format'] ?? 'html' ) ? 'text' : 'html';
		$dry_run = ! empty( $input['dry_run'] );

		$window_start_ts = strtotime( $since );
		if ( false === $window_start_ts ) {
			$window_start_ts = time() - WEEK_IN_SECONDS;
		}
		$window_end_ts = time();

		$recipient = (string) ( $input['recipient'] ?? '' );
		if ( '' === $recipient ) {
			$recipient = (string) get_site_option( 'dme_qualify_digest_recipient', '' );
		}
		if ( '' === $recipient ) {
			$recipient = (string) get_option( 'admin_email', '' );
		}

		$data = $this->gather_data( $window_start_ts, $window_end_ts );

		$body = 'text' === $format
			? $this->render_text( $data, $window_start_ts, $window_end_ts )
			: $this->render_html( $data, $window_start_ts, $window_end_ts );

		$sent = false;
		if ( ! $dry_run && '' !== $recipient && function_exists( 'wp_get_ability' ) ) {
			$send_ability = wp_get_ability( 'datamachine/send-email' );
			if ( $send_ability ) {
				$subject = sprintf(
					'Event Calendar Qualify Digest — Week of %s–%s',
					gmdate( 'M j', $window_start_ts ),
					gmdate( 'M j', $window_end_ts )
				);
				$result  = $send_ability->execute(
					array(
						'to'           => $recipient,
						'subject'      => $subject,
						'body'         => $body,
						'content_type' => 'text' === $format ? 'text/plain' : 'text/html',
					)
				);
				$sent    = is_array( $result ) ? (bool) ( $result['success'] ?? false ) : false;

				do_action(
					'datamachine_log',
					$sent ? 'info' : 'warning',
					$sent
						? sprintf( 'QualifyDigest: sent to %s', $recipient )
						: sprintf( 'QualifyDigest: send failed for %s', $recipient ),
					array(
						'recipient' => $recipient,
						'counts'    => $data['counts'],
						'window'    => array(
							'start' => gmdate( 'c', $window_start_ts ),
							'end'   => gmdate( 'c', $window_end_ts ),
						),
					)
				);
			}
		}

		return array(
			'window_start' => gmdate( 'c', $window_start_ts ),
			'window_end'   => gmdate( 'c', $window_end_ts ),
			'recipient'    => $recipient,
			'sent'         => $sent,
			'dry_run'      => $dry_run,
			'counts'       => $data['counts'],
			'body'         => $body,
		);
	}

	/**
	 * Gather the data for the digest. Public so tests can call it
	 * directly when validating queries.
	 *
	 * @param int $start_ts Window start (inclusive).
	 * @param int $end_ts   Window end (exclusive).
	 * @return array Structured data: counts + breakdowns.
	 */
	public function gather_data( int $start_ts, int $end_ts ): array {
		global $wpdb;

		$start = gmdate( 'Y-m-d H:i:s', $start_ts );
		$end   = gmdate( 'Y-m-d H:i:s', $end_ts );

		$verdicts_table = QualifyVerdictsTable::table_name();
		$flows_table    = $wpdb->prefix . 'datamachine_flows';

		// Paused-this-week — read scheduling_config from datamachine_flows
		// rows whose paused_at falls in the window. paused_at is stashed as
		// JSON inside scheduling_config; we use LIKE-then-decode in PHP.
		$paused_by_verdict = array();
		$resumed_count     = 0;
		$stale_flows       = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT flow_id, flow_name, scheduling_config FROM {$flows_table} WHERE scheduling_config LIKE %s OR scheduling_config LIKE %s",
				'%paused_reason%',
				'%resumed_at%'
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$cfg = json_decode( (string) ( $row['scheduling_config'] ?? '' ), true );
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$paused_at_str = (string) ( $cfg['paused_at'] ?? '' );
			$paused_at_ts  = '' !== $paused_at_str ? strtotime( $paused_at_str . ' UTC' ) : false;
			if ( $paused_at_ts && $paused_at_ts >= $start_ts && $paused_at_ts <= $end_ts ) {
				$verdict                       = (string) ( $cfg['paused_reason'] ?? 'unknown' );
				$paused_by_verdict[ $verdict ] = ( $paused_by_verdict[ $verdict ] ?? 0 ) + 1;
			}

			$resumed_at_str = (string) ( $cfg['resumed_at'] ?? '' );
			$resumed_at_ts  = '' !== $resumed_at_str ? strtotime( $resumed_at_str . ' UTC' ) : false;
			if ( $resumed_at_ts && $resumed_at_ts >= $start_ts && $resumed_at_ts <= $end_ts && ! empty( $cfg['resumed_by_qualify'] ) ) {
				++$resumed_count;
			}

			if ( ! empty( $cfg['stale_flag'] ) && is_array( $cfg['stale_flag'] ) ) {
				$stale_flows[] = array(
					'flow_id'              => (int) $row['flow_id'],
					'flow_name'            => (string) $row['flow_name'],
					'paused_reason'        => (string) ( $cfg['paused_reason'] ?? '' ),
					'consecutive_failures' => (int) ( $cfg['stale_flag']['consecutive_failures'] ?? 0 ),
				);
			}
		}

		// Standing inventory — current count by interval / paused_reason.
		$standing_inventory = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inv_rows = (array) $wpdb->get_results(
			"SELECT scheduling_config FROM {$flows_table} WHERE flow_config LIKE '%universal_web_scraper%'",
			ARRAY_A
		);
		foreach ( $inv_rows as $row ) {
			$cfg = json_decode( (string) ( $row['scheduling_config'] ?? '' ), true );
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$interval = (string) ( $cfg['interval'] ?? '' );
			if ( 'manual' === $interval ) {
				$reason = (string) ( $cfg['paused_reason'] ?? 'unknown' );
				$key    = 'paused:' . $reason;
			} else {
				$key = 'active:' . ( '' === $interval ? 'unknown' : $interval );
			}
			$standing_inventory[ $key ] = ( $standing_inventory[ $key ] ?? 0 ) + 1;
		}

		// Newly-qualified venues this week — count of QUALIFIED_STRUCTURED
		// verdict rows in the window.
		$new_qualified = 0;
		if ( $wpdb->get_var( "SHOW TABLES LIKE '" . $verdicts_table . "'" ) === $verdicts_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$new_qualified = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$verdicts_table} WHERE verdict = %s AND qualified_at >= %s AND qualified_at <= %s",
					QualifyVerdict::QUALIFIED_STRUCTURED,
					$start,
					$end
				)
			);
		}

		// Top 3 fingerprints in extraction_gap. The fingerprint is a JSON
		// blob; we group by `improvement_hint` as a coarse proxy for the
		// platform / shape signature.
		$top_extraction_gap = array();
		if ( $wpdb->get_var( "SHOW TABLES LIKE '" . $verdicts_table . "'" ) === $verdicts_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$gap_rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT improvement_hint, COUNT(*) AS c FROM {$verdicts_table}
					 WHERE verdict = %s AND qualified_at >= %s AND qualified_at <= %s
					 GROUP BY improvement_hint
					 ORDER BY c DESC LIMIT 3",
					QualifyVerdict::EXTRACTION_GAP,
					$start,
					$end
				),
				ARRAY_A
			);
			foreach ( $gap_rows as $g ) {
				$top_extraction_gap[] = array(
					'hint'  => (string) ( $g['improvement_hint'] ?? '' ),
					'count' => (int) ( $g['c'] ?? 0 ),
				);
			}
		}

		$counts = array(
			'paused_total'        => array_sum( $paused_by_verdict ),
			'resumed_total'       => $resumed_count,
			'new_qualified_total' => $new_qualified,
			'stale_total'         => count( $stale_flows ),
		);

		return array(
			'counts'             => $counts,
			'paused_by_verdict'  => $paused_by_verdict,
			'standing_inventory' => $standing_inventory,
			'top_extraction_gap' => $top_extraction_gap,
			'stale_flows'        => $stale_flows,
		);
	}

	/**
	 * Render the digest as HTML.
	 *
	 * @param array $data     Output of gather_data().
	 * @param int   $start_ts Window start.
	 * @param int   $end_ts   Window end.
	 * @return string
	 */
	public function render_html( array $data, int $start_ts, int $end_ts ): string {
		$range = sprintf( '%s – %s UTC', gmdate( 'M j', $start_ts ), gmdate( 'M j, Y', $end_ts ) );

		$h  = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Qualify Digest</title>';
		$h .= '<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#222;max-width:680px;margin:24px auto;padding:0 16px}h1{font-size:20px;margin-bottom:4px}h2{font-size:15px;border-bottom:1px solid #eee;padding-bottom:4px;margin-top:24px}.muted{color:#666;font-size:13px}table{border-collapse:collapse;width:100%;margin:8px 0}td,th{text-align:left;padding:6px 8px;border-bottom:1px solid #eee;font-size:14px}.count{font-variant-numeric:tabular-nums;text-align:right}.empty{color:#999;font-style:italic;font-size:13px}</style>';
		$h .= '</head><body>';
		$h .= '<h1>Event Calendar Qualify Digest</h1>';
		$h .= '<div class="muted">Week of ' . esc_html( $range ) . '</div>';

		// Summary card.
		$h .= '<h2>Summary</h2><table>';
		$h .= '<tr><td>Flows paused this week</td><td class="count">' . (int) $data['counts']['paused_total'] . '</td></tr>';
		$h .= '<tr><td>Flows auto-resumed this week</td><td class="count">' . (int) $data['counts']['resumed_total'] . '</td></tr>';
		$h .= '<tr><td>New venues qualified</td><td class="count">' . (int) $data['counts']['new_qualified_total'] . '</td></tr>';
		$h .= '<tr><td>Stale paused flows (need review)</td><td class="count">' . (int) $data['counts']['stale_total'] . '</td></tr>';
		$h .= '</table>';

		// Paused by verdict.
		$h .= '<h2>Paused this week — by verdict</h2>';
		if ( empty( $data['paused_by_verdict'] ) ) {
			$h .= '<p class="empty">Nothing paused this week.</p>';
		} else {
			$h .= '<table>';
			foreach ( $data['paused_by_verdict'] as $verdict => $count ) {
				$h .= '<tr><td>' . esc_html( (string) $verdict ) . '</td><td class="count">' . (int) $count . '</td></tr>';
			}
			$h .= '</table>';
		}

		// Standing inventory.
		$h .= '<h2>Standing inventory</h2>';
		if ( empty( $data['standing_inventory'] ) ) {
			$h .= '<p class="empty">No universal_web_scraper flows registered.</p>';
		} else {
			ksort( $data['standing_inventory'] );
			$h .= '<table>';
			foreach ( $data['standing_inventory'] as $key => $count ) {
				$h .= '<tr><td>' . esc_html( (string) $key ) . '</td><td class="count">' . (int) $count . '</td></tr>';
			}
			$h .= '</table>';
		}

		// Top extraction_gap.
		$h .= '<h2>Top extraction_gap fingerprints</h2>';
		if ( empty( $data['top_extraction_gap'] ) ) {
			$h .= '<p class="empty">No extraction_gap verdicts this week.</p>';
		} else {
			$h .= '<table><tr><th>Hint</th><th class="count">Count</th></tr>';
			foreach ( $data['top_extraction_gap'] as $row ) {
				$hint = '' === $row['hint'] ? '(no hint)' : $row['hint'];
				$h   .= '<tr><td>' . esc_html( $hint ) . '</td><td class="count">' . (int) $row['count'] . '</td></tr>';
			}
			$h .= '</table>';
		}

		// Stale flows.
		if ( ! empty( $data['stale_flows'] ) ) {
			$h .= '<h2>Stale paused flows — manual review recommended</h2>';
			$h .= '<table><tr><th>Flow</th><th>Verdict</th><th class="count">Failures</th></tr>';
			foreach ( $data['stale_flows'] as $f ) {
				$h .= '<tr><td>#' . (int) $f['flow_id'] . ' ' . esc_html( (string) $f['flow_name'] ) . '</td>';
				$h .= '<td>' . esc_html( (string) $f['paused_reason'] ) . '</td>';
				$h .= '<td class="count">' . (int) $f['consecutive_failures'] . '</td></tr>';
			}
			$h .= '</table>';
		}

		$h .= '<p class="muted" style="margin-top:32px">Run <code>wp extrachill venues qualify-stats</code> for the full breakdown.</p>';
		$h .= '</body></html>';

		return $h;
	}

	/**
	 * Render the digest as plain text.
	 *
	 * @param array $data     Output of gather_data().
	 * @param int   $start_ts Window start.
	 * @param int   $end_ts   Window end.
	 * @return string
	 */
	public function render_text( array $data, int $start_ts, int $end_ts ): string {
		$range   = sprintf( '%s – %s UTC', gmdate( 'M j', $start_ts ), gmdate( 'M j, Y', $end_ts ) );
		$lines   = array();
		$lines[] = 'EVENT CALENDAR QUALIFY DIGEST';
		$lines[] = 'Week of ' . $range;
		$lines[] = '';
		$lines[] = sprintf( 'Paused this week:        %d flows', (int) $data['counts']['paused_total'] );
		$lines[] = sprintf( 'Auto-resumed this week:  %d flows', (int) $data['counts']['resumed_total'] );
		$lines[] = sprintf( 'New venues qualified:    %d', (int) $data['counts']['new_qualified_total'] );
		$lines[] = sprintf( 'Stale paused flows:      %d', (int) $data['counts']['stale_total'] );
		$lines[] = '';

		if ( ! empty( $data['paused_by_verdict'] ) ) {
			$lines[] = 'Paused this week by verdict:';
			foreach ( $data['paused_by_verdict'] as $verdict => $count ) {
				$lines[] = sprintf( '  %-25s %d', $verdict, (int) $count );
			}
			$lines[] = '';
		}

		if ( ! empty( $data['standing_inventory'] ) ) {
			$lines[] = 'Standing inventory:';
			ksort( $data['standing_inventory'] );
			foreach ( $data['standing_inventory'] as $key => $count ) {
				$lines[] = sprintf( '  %-30s %d', $key, (int) $count );
			}
			$lines[] = '';
		}

		if ( ! empty( $data['top_extraction_gap'] ) ) {
			$lines[] = 'Top 3 extraction_gap fingerprints:';
			$i       = 1;
			foreach ( $data['top_extraction_gap'] as $row ) {
				$hint    = '' === $row['hint'] ? '(no hint)' : $row['hint'];
				$lines[] = sprintf( '  %d. %s — %d', $i++, $hint, (int) $row['count'] );
			}
			$lines[] = '';
		}

		if ( ! empty( $data['stale_flows'] ) ) {
			$lines[] = 'Stale paused flows — manual review recommended:';
			foreach ( $data['stale_flows'] as $f ) {
				$lines[] = sprintf(
					'  #%d %s — %s (%d failures)',
					(int) $f['flow_id'],
					(string) $f['flow_name'],
					(string) $f['paused_reason'],
					(int) $f['consecutive_failures']
				);
			}
			$lines[] = '';
		}

		$lines[] = 'Run: wp extrachill venues qualify-stats   for the full breakdown.';

		return implode( "\n", $lines );
	}
}
