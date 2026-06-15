<?php
/**
 * phish.net import adapter.
 *
 * API docs: https://docs.phish.net/
 *  - GET /v5/attendance/username/{username}.json?apikey=KEY
 *  - Returns ALL of a user's attended shows in a single response (no paging).
 *  - Auth: apikey query param (platform-wide key).
 *  - Rate limits: phish.net does not publish a strict per-day cap; their TOS
 *    asks consumers to "be reasonable" and to identify the application via
 *    User-Agent. We conservatively self-throttle at 1 req/sec and cap at
 *    300 req/day per platform-wide policy — well under practical limits but
 *    enough headroom for many concurrent imports.
 *
 * Because the response is not paginated, our orchestrator only makes one
 * request per run. The framework still drives this through page=1 / total_pages=1.
 *
 * Platform-wide API key is held in Data Machine's encrypted auth envelope
 * under the `ec_concert_import_phish_net` provider slug. Manage it via:
 *
 *   wp datamachine auth config ec_concert_import_phish_net --api_key=...
 *
 * Credentials are encrypted at rest (AES-256-GCM) and discoverable through
 * `wp datamachine auth status`.
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.13.0
 */

namespace ExtraChill\Users\Concert_Import\Sources;

use ExtraChill\Users\Concert_Import\ExternalEvent;
use ExtraChill\Users\Concert_Import\ImportSource;

defined( 'ABSPATH' ) || exit;

final class PhishNetImportSource implements ImportSource {

	public const SLUG     = 'phish-net';
	public const API_BASE = 'https://api.phish.net/v5';

	public function slug(): string {
		return self::SLUG;
	}

	public function label(): string {
		return 'phish.net';
	}

	public function rate_limit(): array {
		return array(
			'requests_per_second' => 1.0,
			'requests_per_day'    => 300,
		);
	}

	public function is_configured(): bool {
		return '' !== $this->get_api_key();
	}

	public function preview( string $username ) {
		$username = trim( $username );
		if ( '' === $username ) {
			return new \WP_Error( 'missing_username', 'Username is required.' );
		}

		$result = $this->fetch_page( $username, 1 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'total'    => (int) ( $result['total'] ?? 0 ),
			'username' => $username,
		);
	}

	public function fetch_page( string $username, int $page ) {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error(
				'source_not_configured',
				'phish.net API key is not configured.',
				array( 'status' => 503 )
			);
		}

		// phish.net returns all attendances in one response — we only hit
		// the network on page 1 and treat subsequent calls as a no-op.
		if ( $page > 1 ) {
			return array(
				'events'      => array(),
				'total_pages' => 1,
				'total'       => 0,
				'page'        => $page,
			);
		}

		$url = self::API_BASE . '/attendance/username/' . rawurlencode( $username ) . '.json?apikey=' . rawurlencode( $api_key );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'ExtraChill-Concert-Import/1.0 (+https://extrachill.com)',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 429 === $code ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return new \WP_Error(
				'rate_limit',
				'phish.net rate limit hit.',
				array( 'retry_after' => $retry > 0 ? $retry : 60 )
			);
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'http_error',
				sprintf( 'phish.net API returned HTTP %d.', $code ),
				array(
					'status' => $code,
					'body'   => $body,
				)
			);
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'invalid_json', 'phish.net returned malformed JSON.' );
		}

		// phish.net v5 envelope: { error: false, error_message: "", data: [...] }
		if ( ! empty( $payload['error'] ) ) {
			$msg = isset( $payload['error_message'] ) ? (string) $payload['error_message'] : 'phish.net API error.';
			return new \WP_Error( 'phishnet_error', $msg );
		}

		$rows   = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$events = array();

		foreach ( $rows as $row ) {
			$event = $this->row_to_external_event( $row );
			if ( $event ) {
				$events[] = $event;
			}
		}

		return array(
			'events'      => $events,
			'total_pages' => 1,
			'total'       => count( $events ),
			'page'        => 1,
		);
	}

	/**
	 * Map a phish.net attendance row to an ExternalEvent.
	 *
	 * Expected fields per the v5 schema:
	 *   - showdate: YYYY-MM-DD
	 *   - venue: venue name
	 *   - city, state, country: location parts
	 *   - artist_name: typically "Phish" (or Trey/etc. for related projects)
	 *   - showid: stable show identifier
	 */
	private function row_to_external_event( array $row ): ?ExternalEvent {
		$date = isset( $row['showdate'] ) ? (string) $row['showdate'] : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		return new ExternalEvent(
			array(
				'date'       => $date,
				'venue_name' => isset( $row['venue'] ) ? (string) $row['venue'] : '',
				'city'       => isset( $row['city'] ) ? (string) $row['city'] : '',
				'state'      => isset( $row['state'] ) ? (string) $row['state'] : '',
				'country'    => isset( $row['country'] ) ? (string) $row['country'] : '',
				'headliner'  => isset( $row['artist_name'] ) ? (string) $row['artist_name'] : 'Phish',
				'source_id'  => isset( $row['showid'] ) ? (string) $row['showid'] : '',
				'raw'        => $row,
			)
		);
	}

	/**
	 * Read the platform-wide phish.net API key from the Data Machine auth
	 * provider. Returns empty string when no credential has been provisioned.
	 */
	private function get_api_key(): string {
		// PhishNetAuthProvider extends Data Machine's BaseAuthProvider, which is
		// only loaded (by bootstrap.php) once Data Machine's autoloader has
		// declared the parent. Guard so a DM-absent fetch cannot fatal — it
		// simply yields no credential, which the caller treats as "unprovisioned".
		if ( ! class_exists( PhishNetAuthProvider::class ) ) {
			return '';
		}
		$provider = new PhishNetAuthProvider();
		return $provider->get_api_key();
	}
}
