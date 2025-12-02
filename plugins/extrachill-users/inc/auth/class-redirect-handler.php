<?php
/**
 * Form Redirect Handler
 *
 * Centralized redirect and message handling for authentication forms.
 * Provides consistent error/success messaging via transients and
 * standardized redirect behavior across all auth flows.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

class EC_Redirect_Handler {

	private string $source_url;
	private string $fragment;
	private string $transient_prefix;

	/**
	 * @param string $source_url       URL to redirect back to on error
	 * @param string $fragment         URL fragment (e.g., 'tab-register')
	 * @param string $transient_prefix Prefix for transient keys (e.g., 'ec_registration')
	 */
	public function __construct( string $source_url, string $fragment = '', string $transient_prefix = 'ec_form' ) {
		$this->source_url       = $source_url;
		$this->fragment         = $fragment;
		$this->transient_prefix = $transient_prefix;
	}

	/**
	 * Create instance from POST data.
	 *
	 * @param string $transient_prefix Prefix for transient keys
	 * @return self
	 */
	public static function from_post( string $transient_prefix = 'ec_form' ): self {
		$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : home_url();
		$fragment   = isset( $_POST['source_fragment'] ) ? sanitize_text_field( wp_unslash( $_POST['source_fragment'] ) ) : '';
		return new self( $source_url, $fragment, $transient_prefix );
	}

	/**
	 * Redirect with error message.
	 *
	 * @param string $message    Error message to display
	 * @param array  $query_args Additional query args to preserve (e.g., ['key' => $key, 'login' => $login])
	 */
	public function error( string $message, array $query_args = array() ): void {
		$this->set_message( $message, 'error' );
		$url = $this->source_url;
		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}
		$this->redirect( $url, true );
	}

	/**
	 * Redirect with success message.
	 *
	 * @param string $message      Success message to display
	 * @param string $redirect_url Optional custom redirect URL (defaults to source_url)
	 */
	public function success( string $message, string $redirect_url = '' ): void {
		$this->set_message( $message, 'success' );
		$url = ! empty( $redirect_url ) ? $redirect_url : $this->source_url;
		$this->redirect( $url, false );
	}

	/**
	 * Redirect to custom URL without setting a message.
	 *
	 * @param string $url URL to redirect to
	 */
	public function redirect_to( string $url ): void {
		$this->redirect( $url, false );
	}

	/**
	 * Verify nonce and redirect with error if invalid.
	 *
	 * @param string $nonce_field  POST field name containing nonce
	 * @param string $nonce_action Nonce action name
	 * @return bool True if valid (never returns false - redirects on failure)
	 */
	public function verify_nonce( string $nonce_field, string $nonce_action ): bool {
		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_action ) ) {
			$this->error( __( 'Security verification failed. Please try again.', 'extrachill-users' ) );
		}
		return true;
	}

	/**
	 * Store message in transient.
	 *
	 * @param string $text Message text
	 * @param string $type Message type (error|success)
	 */
	private function set_message( string $text, string $type ): void {
		set_transient(
			$this->transient_prefix . '_message',
			array(
				'text' => $text,
				'type' => $type,
			),
			60
		);
	}

	/**
	 * Get and clear message from transient.
	 *
	 * @param string $transient_prefix Prefix for transient key
	 * @return array|null Message array with 'text' and 'type' keys, or null if no message
	 */
	public static function get_message( string $transient_prefix ): ?array {
		$message = get_transient( $transient_prefix . '_message' );
		if ( $message ) {
			delete_transient( $transient_prefix . '_message' );
			return $message;
		}
		return null;
	}

	/**
	 * Execute redirect with optional fragment.
	 *
	 * @param string $url              URL to redirect to
	 * @param bool   $include_fragment Whether to append fragment to URL
	 */
	private function redirect( string $url, bool $include_fragment ): void {
		if ( $include_fragment && ! empty( $this->fragment ) ) {
			$url .= '#' . $this->fragment;
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render hidden fields for forms.
	 *
	 * @param string $fragment Optional URL fragment for error redirects
	 */
	public static function render_hidden_fields( string $fragment = '' ): void {
		$current_url = set_url_scheme(
			( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . strtok( $_SERVER['REQUEST_URI'], '?' )
		);
		?>
		<input type="hidden" name="source_url" value="<?php echo esc_url( $current_url ); ?>">
		<?php if ( ! empty( $fragment ) ) : ?>
		<input type="hidden" name="source_fragment" value="<?php echo esc_attr( $fragment ); ?>">
		<?php endif;
	}
}
