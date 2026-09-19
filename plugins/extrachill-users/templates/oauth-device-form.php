<?php
/**
 * Branded device-code entry screen for Extra Chill.
 *
 * Serves in place of wp-native-auth's generic form via the
 * wp_native_auth_oauth_device_form_template filter (registered in
 * inc/wp-native-bridge.php). This is the screen a person sees when an
 * agent on a server — no shared browser — asks them to approve it: they
 * arrive from any device, type the short code the agent showed them, and
 * continue to consent.
 *
 * Renders inside the theme chrome and uses only classes the extrachill
 * theme defines (see oauth-consent.php for the design-system reference).
 *
 * The form contract is owned by wp-native-auth: the nonce action comes in
 * $args['nonce_action'], the field must be named user_code, and the POST
 * goes back to the current URL. Do not alter those.
 *
 * @package ExtraChill\Users
 *
 * @var array $args {
 *     @type string $user_code    Prefilled code (from verification_uri_complete) or ''.
 *     @type string $error        Validation message to show, or ''.
 *     @type string $nonce_action Nonce action to render via wp_nonce_field().
 * }
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$user_code    = isset( $args['user_code'] ) ? (string) $args['user_code'] : '';
$error        = isset( $args['error'] ) ? (string) $args['error'] : '';
$nonce_action = isset( $args['nonce_action'] ) && '' !== (string) $args['nonce_action']
	? (string) $args['nonce_action']
	: 'wp_native_auth_oauth_device';

get_header();
?>
<div class="card">
	<h1><?php esc_html_e( 'Connect a device to Extra Chill', 'extrachill-users' ); ?></h1>

	<?php if ( '' !== $error ) : ?>
		<div class="notice notice-error"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<p><?php esc_html_e( 'The app you are connecting showed you a short code. Enter it here to continue.', 'extrachill-users' ); ?></p>

	<form method="post" action="">
		<?php wp_nonce_field( $nonce_action ); ?>
		<p>
			<label for="extrachill-device-user-code"><?php esc_html_e( 'Device code', 'extrachill-users' ); ?></label>
			<input
				type="text"
				id="extrachill-device-user-code"
				name="user_code"
				value="<?php echo esc_attr( $user_code ); ?>"
				autocomplete="off"
				autocapitalize="characters"
				autocorrect="off"
				spellcheck="false"
				placeholder="XXXX-XXXX"
				required
			>
		</p>
		<p>
			<button type="submit" class="button-1 button-medium"><?php esc_html_e( 'Continue', 'extrachill-users' ); ?></button>
		</p>
	</form>

	<div class="notice notice-info">
		<?php esc_html_e( 'Only enter a code from an app you started yourself. Approving gives that app access as your account.', 'extrachill-users' ); ?>
	</div>
</div>
<?php
get_footer();
