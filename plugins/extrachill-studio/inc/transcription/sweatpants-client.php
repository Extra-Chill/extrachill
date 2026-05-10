<?php
/**
 * Sweatpants HTTP Client
 *
 * Thin wrapper around wp_remote_* for the sweatpants worker API on chubes.net.
 * Reads base URL + bearer token from network options (sweatpants_base_url,
 * sweatpants_api_token). All methods return parsed array on 2xx, WP_Error
 * otherwise with a stable error code for callers to branch on.
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.10.0
 */

namespace ExtraChillStudio\Transcription;

defined( 'ABSPATH' ) || exit;

/**
 * SweatpantsClient
 *
 * @since 0.10.0
 */
class SweatpantsClient {

	/**
	 * Base URL of the sweatpants API (no trailing slash).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Bearer token for the sweatpants API.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Constructor. Resolves base URL + token from network options.
	 *
	 * @since 0.10.0
	 */
	public function __construct() {
		$this->base_url = untrailingslashit( (string) get_site_option( 'sweatpants_base_url', '' ) );
		$this->token    = (string) get_site_option( 'sweatpants_api_token', '' );
	}

	/**
	 * Submit a new job to sweatpants.
	 *
	 * @since 0.10.0
	 *
	 * @param string $module_id Sweatpants module slug (e.g. 'audio-transcription').
	 * @param array  $inputs    Module-specific inputs (e.g. audio_url, output_dir).
	 * @param array  $settings  Optional module-specific settings.
	 * @return array|\WP_Error Parsed response on 2xx, WP_Error otherwise.
	 */
	public function submit_job( string $module_id, array $inputs, array $settings = array() ): array|\WP_Error {
		$body = array(
			'module_id' => $module_id,
			'inputs'    => $inputs,
		);
		if ( ! empty( $settings ) ) {
			$body['settings'] = $settings;
		}

		return $this->request( 'POST', '/jobs', $body, 60 );
	}

	/**
	 * Fetch a job's current state.
	 *
	 * @since 0.10.0
	 *
	 * @param string $job_id Sweatpants job UUID.
	 * @return array|\WP_Error Parsed response on 2xx, WP_Error otherwise.
	 */
	public function get_job( string $job_id ): array|\WP_Error {
		return $this->request( 'GET', '/jobs/' . rawurlencode( $job_id ), null, 10 );
	}

	/**
	 * Fetch a completed job's results envelope.
	 *
	 * @since 0.10.0
	 *
	 * @param string $job_id Sweatpants job UUID.
	 * @return array|\WP_Error Parsed response on 2xx, WP_Error otherwise.
	 */
	public function get_job_results( string $job_id ): array|\WP_Error {
		return $this->request( 'GET', '/jobs/' . rawurlencode( $job_id ) . '/results', null, 10 );
	}

	/**
	 * Internal request dispatcher.
	 *
	 * @since 0.10.0
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $path     Path beginning with '/'.
	 * @param array|null $body     Optional JSON body.
	 * @param int        $timeout  Request timeout in seconds.
	 * @return array|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body, int $timeout ): array|\WP_Error {
		if ( '' === $this->base_url || '' === $this->token ) {
			return new \WP_Error(
				'sweatpants_unauthorized',
				__( 'Sweatpants base URL or API token is not configured.', 'extrachill-studio' ),
				array( 'status' => 500 )
			);
		}

		$url  = $this->base_url . $path;
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			error_log( '[ExtraChillStudio][Transcription] sweatpants unreachable ' . $method . ' ' . $path . ' — ' . $msg );
			return new \WP_Error( 'sweatpants_unreachable', $msg, array( 'status' => 502 ) );
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( $raw_body, true );
			if ( ! is_array( $data ) ) {
				error_log( '[ExtraChillStudio][Transcription] sweatpants non-JSON ' . $method . ' ' . $path . ' status=' . $code . ' body=' . substr( $raw_body, 0, 500 ) );
				return new \WP_Error( 'sweatpants_bad_response', __( 'Sweatpants returned a non-JSON response.', 'extrachill-studio' ), array( 'status' => 502 ) );
			}
			return $data;
		}

		$truncated = substr( $raw_body, 0, 500 );
		error_log( '[ExtraChillStudio][Transcription] sweatpants error ' . $method . ' ' . $path . ' status=' . $code . ' body=' . $truncated );

		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error( 'sweatpants_unauthorized', __( 'Sweatpants rejected the bearer token.', 'extrachill-studio' ), array( 'status' => 502 ) );
		}
		if ( 404 === $code ) {
			return new \WP_Error( 'sweatpants_job_not_found', __( 'Sweatpants job not found.', 'extrachill-studio' ), array( 'status' => 404 ) );
		}
		if ( $code >= 500 ) {
			return new \WP_Error( 'sweatpants_server_error', sprintf( __( 'Sweatpants server error (HTTP %d).', 'extrachill-studio' ), $code ), array( 'status' => 502 ) );
		}

		return new \WP_Error( 'sweatpants_bad_response', sprintf( __( 'Sweatpants returned HTTP %d.', 'extrachill-studio' ), $code ), array( 'status' => 502 ) );
	}
}
