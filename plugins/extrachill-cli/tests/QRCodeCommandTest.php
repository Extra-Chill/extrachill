<?php
/**
 * Focused behavior tests for the QR code CLI command.
 */

define( 'ABSPATH', __DIR__ . '/' );

class QRCodeCommandTestError extends RuntimeException {}

class WP_CLI {
	public static $messages = array();

	public static function error( $message ) {
		throw new QRCodeCommandTestError( $message );
	}

	public static function success( $message ) {
		self::$messages[] = 'Success: ' . $message;
	}

	public static function log( $message ) {
		self::$messages[] = $message;
	}
}

class QRCodeCommandTestAbility {
	public $inputs = array();

	public function execute( $input ) {
		$this->inputs[] = $input;

		return array(
			'image'     => base64_encode( 'png-bytes' ),
			'mime_type' => 'image/png',
			'url'       => $input['url'],
			'size'      => 1000,
		);
	}
}

class QRCodeCommandTestFilesystem {
	public function put_contents( $path, $contents ) {
		return file_put_contents( $path, $contents );
	}
}

$qr_code_test_ability = null;

function wp_get_ability( $name ) {
	global $qr_code_test_ability;

	if ( 'extrachill/generate-qr-code' !== $name ) {
		throw new RuntimeException( 'Unexpected ability requested: ' . $name );
	}

	return $qr_code_test_ability;
}

function wp_parse_url( $url ) {
	return parse_url( $url );
}

function is_wp_error( $value ) {
	return false;
}

function qr_code_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
		);
	}
}

require_once dirname( __DIR__ ) . '/inc/Commands/Tools/QRCodeCommand.php';

use ExtraChill\CLI\Commands\Tools\QRCodeCommand;

$output = tempnam( sys_get_temp_dir(), 'extrachill-qr-test-' );
if ( false === $output ) {
	throw new RuntimeException( 'Failed to allocate test output file.' );
}

try {
	global $wp_filesystem, $qr_code_test_ability;

	$wp_filesystem        = new QRCodeCommandTestFilesystem();
	$qr_code_test_ability = new QRCodeCommandTestAbility();
	WP_CLI::$messages     = array();

	$command = new QRCodeCommand();
	$command->generate(
		array( 'https://example.com/path' ),
		array( 'output' => $output )
	);

	qr_code_test_assert_same(
		array( array( 'url' => 'https://example.com/path' ) ),
		$qr_code_test_ability->inputs,
		'Command must invoke the canonical QR ability with the existing input contract.'
	);
	qr_code_test_assert_same( 'png-bytes', file_get_contents( $output ), 'Command must decode and write the returned PNG bytes.' );
	qr_code_test_assert_same(
		array(
			'Success: QR code generated: ' . $output . ' (9 bytes)',
			'URL: https://example.com/path',
		),
		WP_CLI::$messages,
		'Command success output must remain compatible.'
	);

	$qr_code_test_ability = null;
	try {
		$command->generate( array( 'https://example.com' ), array( 'output' => $output ) );
		throw new RuntimeException( 'Missing QR ability did not terminate the command.' );
	} catch ( QRCodeCommandTestError $error ) {
		qr_code_test_assert_same(
			'QR code ability is unavailable. Ensure Extra Chill Network is active and abilities are registered.',
			$error->getMessage(),
			'Missing ability error must identify the canonical owner.'
		);
	}
} finally {
	@unlink( $output );
}

fwrite( STDOUT, "QRCodeCommand tests passed.\n" );
