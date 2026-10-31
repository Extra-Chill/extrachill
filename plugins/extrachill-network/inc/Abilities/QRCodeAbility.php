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
	 * drawing a QR into an image is not a privileged operation, so it
	 * does not go through the REST permission gate.
	 *
	 * @param string|null $path Path from an earlier provider, if any.
	 * @param string      $url  URL to encode.
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
	 * Preserve Admin Tools' automation and network-admin access contract.
	 */
	public function check_permission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) ) {
			return true;
		}

		return current_user_can( 'manage_network_options' );
	}

	/**
	 * Generate a base64-encoded PNG using bounded dimensions.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		if ( ! class_exists( '\\Endroid\\QrCode\\QrCode' ) ) {
			return new \WP_Error( 'dependency_missing', 'Endroid QR Code library not available.' );
		}

		if ( strlen( $input['url'] ) > self::MAX_URL_LENGTH ) {
			return new \WP_Error( 'url_too_long', 'URL cannot exceed 2048 characters.' );
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
