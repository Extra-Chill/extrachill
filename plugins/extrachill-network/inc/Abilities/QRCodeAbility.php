<?php
/**
 * QR code generation ability.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and executes the network-owned QR code primitive.
 */
class QRCodeAbility {

	private const MAX_URL_LENGTH = 2048;

	/**
	 * Stage registration after the retiring Admin Tools owner.
	 */
	public function __construct() {
		// Admin Tools registers at the default priority during the rollout overlap.
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 20 );
		// phpcs:ignore WordPress.NamingConventions.ValidHookName -- Data Machine's slash-separated hook namespace.
		add_filter( 'datamachine/image_generation/qr_image_path', array( $this, 'provide_poster_qr_image' ), 10, 3 );
	}

	/**
	 * Supply QR images to Data Machine's generic poster template.
	 *
	 * Data Machine resolves `qr_url` through this filter and omits the QR
	 * block when nothing answers. The returned temp PNG is owned and
	 * deleted by the template. Calls this class's own generator directly:
	 * generation is public and shares the same validation and rate limits
	 * as the REST and CLI consumers.
	 *
	 * @param string|null $path Path from an earlier provider, if any.
	 * @param mixed       $url  Unvalidated filter input for the URL to encode.
	 * @param int         $size Drawn size in pixels.
	 * @return string|null Temp PNG path, or the incoming value when unable.
	 */
	public function provide_poster_qr_image( $path, $url, $size = 0 ) {
		if ( is_string( $path ) && '' !== $path ) {
			return $path;
		}
		if ( ! is_string( $url ) || '' === $url ) {
			return $path;
		}

		$result = $this->execute(
			array(
				'url'  => $url,
				'size' => max( 600, (int) $size ),
			)
		);
		if ( ! is_array( $result ) || empty( $result['image'] ) ) {
			return $path;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- this class's own base64 PNG response contract.
		$png  = base64_decode( (string) $result['image'], true );
		$file = wp_tempnam( 'poster-qr' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file handed to the GD renderer.
		if ( false === $png || ! $file || false === file_put_contents( $file, $png ) ) {
			return $path;
		}

		return $file;
	}

	/**
	 * Register the ability unless the retiring owner already provided it.
	 */
	public function register(): void {
		if ( wp_has_ability( 'extrachill/generate-qr-code' ) ) {
			return;
		}

		wp_register_ability(
			'extrachill/generate-qr-code',
			array(
				'label'               => __( 'Generate QR Code', 'extrachill-network' ),
				'description'         => __( 'Generate a print-ready QR code PNG for a URL.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'  => array(
							'type'        => 'string',
							'format'      => 'uri',
							'maxLength'   => self::MAX_URL_LENGTH,
							'description' => __( 'URL to encode in the QR code.', 'extrachill-network' ),
						),
						'size' => array(
							'type'        => 'integer',
							'description' => __( 'QR code size in pixels (default: 1000, clamped to 100-2000).', 'extrachill-network' ),
						),
					),
					'required'   => array( 'url' ),
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'QR code image data (base64 PNG).', 'extrachill-network' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	/**
	 * Stateless generation is public; execution enforces resource limits.
	 */
	public function check_permission(): bool {
		return true;
	}

	/**
	 * Allow 30 requests per hour for both the source IP and signed-in user.
	 *
	 * Only REMOTE_ADDR is trusted, never caller-supplied forwarding headers.
	 * Hash identifiers so transient names do not retain raw IP addresses.
	 *
	 * @return true|\WP_Error
	 */
	private function check_rate_limit() {
		// Operator automation (WP-CLI, Action Scheduler) is trusted and unthrottled.
		if ( ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) || ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed only, never rendered or used as an address.
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$keys = array( 'ec_qr_ip_' . hash( 'sha256', $ip ) );
		$user = get_current_user_id();
		if ( $user ) {
			$keys[] = 'ec_qr_user_' . $user;
		}

		$buckets = array();
		foreach ( $keys as $key ) {
			$bucket = get_transient( $key );
			if ( ! is_array( $bucket ) || $bucket['expires'] <= time() ) {
				$bucket = array(
					'count'   => 0,
					'expires' => time() + HOUR_IN_SECONDS,
				);
			}
			if ( $bucket['count'] >= 30 ) {
				return new \WP_Error(
					'qr_rate_limited',
					__( 'You can generate 30 QR codes per hour. Please try again later.', 'extrachill-network' ),
					array( 'status' => 429 )
				);
			}
			$buckets[ $key ] = $bucket;
		}

		foreach ( $buckets as $key => $bucket ) {
			++$bucket['count'];
			set_transient( $key, $bucket, max( 1, (int) $bucket['expires'] - time() ) );
		}
		return true;
	}

	/**
	 * Generate a base64-encoded PNG using bounded dimensions.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		if ( ! isset( $input['url'] ) || ! is_string( $input['url'] ) ) {
			return new \WP_Error( 'invalid_url', 'Please provide a valid HTTP or HTTPS URL.', array( 'status' => 400 ) );
		}
		if ( strlen( $input['url'] ) > self::MAX_URL_LENGTH ) {
			return new \WP_Error( 'url_too_long', 'URL cannot exceed 2048 characters.', array( 'status' => 400 ) );
		}
		if ( ! filter_var( $input['url'], FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) wp_parse_url( $input['url'], PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'invalid_url', 'Please provide a valid HTTP or HTTPS URL.', array( 'status' => 400 ) );
		}

		$allowed = $this->check_rate_limit();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! class_exists( '\\Endroid\\QrCode\\QrCode' ) ) {
			return new \WP_Error( 'dependency_missing', 'Endroid QR Code library not available.' );
		}

		$size = isset( $input['size'] ) ? absint( $input['size'] ) : 1000;
		$size = max( 100, min( 2000, $size ) );

		$qr_code = new \Endroid\QrCode\QrCode(
			data: $input['url'],
			encoding: new \Endroid\QrCode\Encoding\Encoding( 'UTF-8' ),
			errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::High,
			size: $size,
			margin: 40
		);

		$result = ( new \Endroid\QrCode\Writer\PngWriter() )->write( $qr_code );

		return array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- preserved binary image response contract.
			'image'     => base64_encode( $result->getString() ),
			'mime_type' => $result->getMimeType(),
			'url'       => $input['url'],
			'size'      => $size,
		);
	}
}
