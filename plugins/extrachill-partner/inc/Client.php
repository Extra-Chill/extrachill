<?php
namespace ExtraChill\Partner;

defined( 'ABSPATH' ) || exit;

/** Isolated HTTP client; no network plugin dependencies. */
final class Client {
	const KEY_OPTION = 'extrachill_partner_key';

	public static function limit( $value ) {
		return max( 1, min( 100, (int) $value ) );
	}

	public static function request( $key, $limit ) {
		$url = add_query_arg( 'limit', self::limit( $limit ), rtrim( apply_filters( 'extrachill_partner_api_base', 'https://events.extrachill.com/wp-json/extrachill/v1/partner' ), '/' ) . '/events' );
		$response = wp_remote_get( $url, array( 'timeout' => 5, 'redirection' => 0, 'headers' => array( 'X-Extrachill-Partner-Key' => $key ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new \WP_Error( 401 === $status ? 'extrachill_partner_key_invalid' : 'extrachill_partner_unavailable', __( 'Extra Chill could not connect. Please check your API key or try again later.', 'extrachill-partner' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['venue']['id'], $data['venue']['name'], $data['events'] ) || ! is_array( $data['events'] ) || ! is_string( $data['venue']['name'] ) ) {
			return new \WP_Error( 'extrachill_partner_response', __( 'Extra Chill returned an invalid response.', 'extrachill-partner' ) );
		}
		return $data;
	}

	/** Cached failures also back off, so an outage does not block every visitor. */
	public static function get( $limit = 10 ) {
		$key = get_option( self::KEY_OPTION, '' );
		if ( ! $key ) {
			return array( 'error' => true );
		}
		$id = 'ec_partner_' . hash( 'sha256', $key . wp_json_encode( array( 'limit' => self::limit( $limit ) ) ) );
		$cached = get_transient( $id );
		if ( false !== $cached ) {
			return $cached;
		}
		$data = self::request( $key, $limit );
		if ( is_wp_error( $data ) ) {
			$data = get_option( $id . '_good', array() );
			$data['error'] = true;
			set_transient( $id, $data, 60 );
			return $data;
		}
		update_option( $id . '_good', $data, false );
		set_transient( $id, $data, max( 1, (int) apply_filters( 'extrachill_partner_cache_ttl', 15 * MINUTE_IN_SECONDS ) ) );
		return $data;
	}
}
