<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

final class WP_Error {
	/** @var string */
	private $code;
	/** @var string */
	private $message;
	/** @var mixed */
	private $data;

	/** @param mixed $data Error data. */
	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
}

/** @param mixed $value Value to inspect. */
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function add_action(): void {}
function wp_register_ability( string $name, array $definition ): void {
	$GLOBALS['ec_test_abilities'][ $name ] = $definition;
}
function __( string $message ): string {
	return $message;
}
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}
function sanitize_textarea_field( string $value ): string {
	return trim( strip_tags( $value ) );
}
function sanitize_email( string $value ): string {
	return filter_var( $value, FILTER_SANITIZE_EMAIL );
}
function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
}
function is_email( string $value ) {
	return filter_var( $value, FILTER_VALIDATE_EMAIL );
}
function esc_html( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}
function wp_json_encode( $value ): string {
	return (string) json_encode( $value );
}
function get_option( string $name ) {
	return 'admin_email' === $name ? 'admin@example.com' : null;
}
function apply_filters( string $name, $value ) {
	return 'extrachill_bypass_turnstile_verification' === $name ? true : $value;
}
function ec_verify_turnstile_response(): bool {
	return true;
}
function get_transient( string $key ) {
	return $GLOBALS['ec_test_transients'][ $key ] ?? false;
}
function set_transient( string $key, $value ): bool {
	$GLOBALS['ec_test_transients'][ $key ] = $value;
	return true;
}
function wp_cache_add( string $key ): bool {
	if ( isset( $GLOBALS['ec_test_locks'][ $key ] ) ) {
		return false;
	}
	$GLOBALS['ec_test_locks'][ $key ] = true;
	return true;
}
function wp_cache_delete( string $key ): bool {
	unset( $GLOBALS['ec_test_locks'][ $key ] );
	return true;
}
function ec_send_email() {
	++$GLOBALS['ec_test_email_calls'];
	return array_shift( $GLOBALS['ec_test_email_results'] );
}
function extrachill_network_subscribe() {
	++$GLOBALS['ec_test_newsletter_calls'];
	return $GLOBALS['ec_test_newsletter_result'];
}

require_once dirname( __DIR__ ) . '/includes/email-functions.php';
require_once dirname( __DIR__ ) . '/inc/abilities/contact-submit.php';
