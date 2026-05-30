<?php
/**
 * setlist.fm import adapter.
 *
 * API docs: https://api.setlist.fm/docs/1.0/index.html
 *  - GET /1.0/user/{userId}/attended?p={page}
 *  - 20 setlists per page.
 *  - Auth: x-api-key header (platform-wide key).
 *  - Rate limits: 2 req/sec, 1440 req/day (free tier).
 *  - Accept: application/json.
 *
 * Platform-wide API key is held in Data Machine's encrypted auth envelope
 * under the `ec_concert_import_setlist_fm` provider slug. Manage it via:
 *
 *   wp datamachine auth config ec_concert_import_setlist_fm --api_key=...
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

final class SetlistFmImportSource implements ImportSource {

	public const SLUG     = 'setlist-fm';
	public const API_BASE = 'https://api.setlist.fm/rest/1.0';

	public function slug(): string {
		return self::SLUG;
	}

	public function label(): string {
		return 'setlist.fm';
	}

	public function rate_limit(): array {
		return array(
			'requests_per_second' => 2.0,
			'requests_per_day'    => 1440,
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
				'setlist.fm API key is not configured.',
				array( 'status' => 503 )
			);
		}

		$page = max( 1, $page );
		$url  = self::API_BASE . '/user/' . rawurlencode( $username ) . '/attended?p=' . $page;

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'    => 'application/json',
					'x-api-key' => $api_key,
					'User-Agent' => 'ExtraChill-Concert-Import/1.0 (+https://extrachill.com)',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 404 === $code ) {
			return new \WP_Error(
				'username_not_found',
				sprintf( 'No setlist.fm user named "%s" was found.', $username ),
				array( 'status' => 404 )
			);
		}
		if ( 429 === $code ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return new \WP_Error(
				'rate_limit',
				'setlist.fm rate limit hit.',
				array( 'retry_after' => $retry > 0 ? $retry : 30 )
			);
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'http_error',
				sprintf( 'setlist.fm API returned HTTP %d.', $code ),
				array(
					'status' => $code,
					'body'   => $body,
				)
			);
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'invalid_json', 'setlist.fm returned malformed JSON.' );
		}

		$setlists = isset( $payload['setlist'] ) && is_array( $payload['setlist'] ) ? $payload['setlist'] : array();
		$events   = array();

		foreach ( $setlists as $setlist ) {
			$event = $this->setlist_to_external_event( $setlist );
			if ( $event ) {
				$events[] = $event;
			}
		}

		$total     = isset( $payload['total'] ) ? (int) $payload['total'] : count( $events );
		$per_page  = isset( $payload['itemsPerPage'] ) ? max( 1, (int) $payload['itemsPerPage'] ) : 20;
		$total_pgs = (int) max( 1, ceil( $total / $per_page ) );

		return array(
			'events'      => $events,
			'total_pages' => $total_pgs,
			'total'       => $total,
			'page'        => isset( $payload['page'] ) ? (int) $payload['page'] : $page,
		);
	}

	/**
	 * Convert a setlist.fm setlist object to ExternalEvent.
	 *
	 * setlist.fm format reference:
	 *   {
	 *     "id": "...",
	 *     "eventDate": "DD-MM-YYYY",
	 *     "artist": { "name": "..." },
	 *     "venue": {
	 *       "name": "...",
	 *       "city": { "name": "...", "state": "...", "country": { "code": "US", "name": "..." } }
	 *     }
	 *   }
	 */
	private function setlist_to_external_event( array $setlist ): ?ExternalEvent {
		$event_date = isset( $setlist['eventDate'] ) ? $this->normalize_date( (string) $setlist['eventDate'] ) : '';
		if ( '' === $event_date ) {
			return null;
		}

		$venue   = isset( $setlist['venue'] ) && is_array( $setlist['venue'] ) ? $setlist['venue'] : array();
		$city_a  = isset( $venue['city'] ) && is_array( $venue['city'] ) ? $venue['city'] : array();
		$country = isset( $city_a['country'] ) && is_array( $city_a['country'] ) ? $city_a['country'] : array();
		$artist  = isset( $setlist['artist'] ) && is_array( $setlist['artist'] ) ? $setlist['artist'] : array();

		return new ExternalEvent(
			array(
				'date'       => $event_date,
				'venue_name' => isset( $venue['name'] ) ? (string) $venue['name'] : '',
				'city'       => isset( $city_a['name'] ) ? (string) $city_a['name'] : '',
				'state'      => isset( $city_a['state'] ) ? (string) $city_a['state'] : '',
				'country'    => isset( $country['name'] ) ? (string) $country['name'] : '',
				'headliner'  => isset( $artist['name'] ) ? (string) $artist['name'] : '',
				'source_id'  => isset( $setlist['id'] ) ? (string) $setlist['id'] : '',
				'raw'        => $setlist,
			)
		);
	}

	/**
	 * Convert setlist.fm's DD-MM-YYYY date format to YYYY-MM-DD.
	 */
	private function normalize_date( string $date ): string {
		if ( preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $date, $m ) ) {
			return $m[3] . '-' . $m[2] . '-' . $m[1];
		}
		// Some payloads may already be ISO; pass through if so.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		return '';
	}

	/**
	 * Read the platform-wide setlist.fm API key from the Data Machine auth
	 * provider. Returns empty string when no credential has been provisioned.
	 */
	private function get_api_key(): string {
		$provider = new SetlistFmAuthProvider();
		return $provider->get_api_key();
	}
}
