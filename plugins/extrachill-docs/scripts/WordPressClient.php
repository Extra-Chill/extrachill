<?php

/**
 * Class WordPressClient
 * 
 * Handles low-level HTTP communication with the WordPress REST API.
 * Encapsulates authentication and cURL logic.
 */
class WordPressClient {

	private string $site_url;
	private string $auth_header;

	public function __construct() {
		$site_url = getenv( 'WP_SITE_URL' );
		$username = getenv( 'WP_USERNAME' );
		$password = getenv( 'WP_SYNC_PASSWORD' );

		if ( ! $site_url || ! $username || ! $password ) {
			throw new Exception( 'Missing required environment variables: WP_SITE_URL, WP_USERNAME, WP_SYNC_PASSWORD' );
		}

		$this->site_url    = rtrim( $site_url, '/' );
		$this->auth_header = 'Authorization: Basic ' . base64_encode( $username . ':' . $password );
	}

	/**
	 * Make an authenticated request to the WordPress API.
	 *
	 * @param string $method HTTP method (GET, POST, DELETE, etc.)
	 * @param string $endpoint API endpoint (relative to site URL, e.g., '/wp-json/extrachill/v1/...')
	 * @param array|null $data Optional data to send as JSON body
	 * @return array Decoded JSON response
	 * @throws Exception On cURL error or HTTP error status
	 */
	public function request( string $method, string $endpoint, ?array $data = null ): array {
		$url = $this->site_url . $endpoint;
		$ch  = curl_init();

		$headers = [
			$this->auth_header,
			'Accept: application/json',
			'Content-Type: application/json',
		];

		$json_data = null;
		if ( $data !== null ) {
			$json_data = json_encode( $data );
			if ( $json_data === false ) {
				throw new Exception( 'Failed to encode JSON data: ' . json_last_error_msg() );
			}
			$headers[] = 'Content-Length: ' . strlen( $json_data );
		}

		curl_setopt_array(
			$ch,
			[
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_TIMEOUT        => 30,
			]
		);

		if ( $json_data !== null ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $json_data );
		}

		$response  = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			throw new Exception( "cURL error: {$error}" );
		}

		if ( $http_code >= 400 ) {
			$error_data    = json_decode( $response, true );
			$error_message = $error_data['message'] ?? "HTTP {$http_code}";
			throw new Exception( "API error: {$error_message} (HTTP {$http_code})" );
		}

		$decoded = json_decode( $response, true );
		if ( $decoded === null && json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( "Invalid JSON response. HTTP: {$http_code}" );
		}

		return $decoded;
	}
}
