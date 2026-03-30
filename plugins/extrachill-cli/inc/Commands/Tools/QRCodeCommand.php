<?php
/**
 * QR Code CLI Commands
 *
 * Delegates to the existing `extrachill/generate-qr-code` ability provided by
 * extrachill-admin-tools. This keeps QR generation primitive logic in its
 * existing home while exposing a CLI entrypoint under `wp extrachill`.
 *
 * @package ExtraChill\CLI\Commands\Tools
 */

namespace ExtraChill\CLI\Commands\Tools;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QRCodeCommand {

	/**
	 * Generate a QR code PNG for a URL.
	 *
	 * Uses the existing `extrachill/generate-qr-code` ability and writes the
	 * decoded PNG to disk.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL to encode in the QR code.
	 *
	 * [--output=<path>]
	 * : Output file path for PNG.
	 * ---
	 * default: ./qr-code.png
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill tools qr generate https://example.com
	 *     wp extrachill tools qr generate https://example.com --output=/tmp/example-qr.png
	 *
	 * @subcommand generate
	 */
	public function generate( $args, $assoc_args ) {
		global $wp_filesystem;
		$this->ensure_qr_ability();

		$url        = $args[0];
		$output     = $assoc_args['output'] ?? './qr-code.png';
		$parsed_url = wp_parse_url( $url );

		if ( false === $parsed_url || empty( $parsed_url['scheme'] ) || empty( $parsed_url['host'] ) ) {
			WP_CLI::error( 'Please provide a valid absolute URL (including protocol), e.g. https://example.com' );
		}

		$ability = wp_get_ability( 'extrachill/generate-qr-code' );
		if ( ! $ability ) {
			WP_CLI::error( 'Ability extrachill/generate-qr-code is not registered.' );
		}

		$result = $ability->execute(
			array(
				'url' => $url,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['image'] ) ) {
			WP_CLI::error( 'QR generation returned no image data.' );
		}

		$binary = base64_decode( $result['image'], true );
		if ( false === $binary ) {
			WP_CLI::error( 'Failed to decode QR image data.' );
		}

		$directory = dirname( $output );
		if ( ! is_dir( $directory ) ) {
			WP_CLI::error( sprintf( 'Output directory does not exist: %s', $directory ) );
		}

		$bytes_written = $wp_filesystem->put_contents( $output, $binary );
		if ( false === $bytes_written ) {
			WP_CLI::error( sprintf( 'Failed to write QR code file: %s', $output ) );
		}

		WP_CLI::success( sprintf( 'QR code generated: %s (%d bytes)', $output, $bytes_written ) );
		WP_CLI::log( sprintf( 'URL: %s', $url ) );
	}

	/**
	 * Ensure the QR ability is available.
	 */
	private function ensure_qr_ability() {
		if ( ! wp_get_ability( 'extrachill/generate-qr-code' ) ) {
			WP_CLI::error( 'QR code ability is unavailable. Ensure extrachill-admin-tools is active and abilities are registered.' );
		}
	}
}
