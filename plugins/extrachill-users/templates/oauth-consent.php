<?php
/**
 * Branded OAuth consent screen for Extra Chill.
 *
 * Serves in place of wp-native-auth's generic consent form via the
 * wp_native_auth_oauth_consent_template filter (registered in
 * inc/wp-native-bridge.php). Renders inside the theme chrome
 * (get_header()/get_footer()) and uses only design-system classes
 * (.card, .btn, .notice) — this plugin ships no CSS for this screen.
 *
 * The form contract below is owned by wp-native-auth's decision
 * handler. The nonce action, the hidden field names, and the decision
 * button names/values must not be altered. The signed bundle is bound
 * to the current user by an HMAC signature: render it verbatim —
 * never rebuild, reorder, or trim it.
 *
 * @package ExtraChill\Users
 *
 * @var array $args {
 *     @type string $client_name      Display name (or raw client id when unnamed).
 *     @type string $client_uri       Optional client homepage.
 *     @type string $client_id        Raw client identifier.
 *     @type string $scope            Declared scope (single scope by design).
 *     @type string $resource         RFC 8707 resource audience, if requested.
 *     @type string $bundle           Signed request bundle — render verbatim.
 *     @type string $signature        Bundle signature — render verbatim.
 *     @type string $authorize_action Consent nonce (rendered via wp_nonce_field()).
 *     @type string $nonce_action     Nonce action to render. Differs per grant:
 *                                    'wp_native_auth_oauth_consent' for the
 *                                    authorization code flow,
 *                                    'wp_native_auth_oauth_device' for the
 *                                    RFC 8628 device flow. Optional; defaults
 *                                    to the consent action.
 *     @type bool   $is_device_flow   True when rendering for the device grant.
 * }
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$client_name = isset( $args['client_name'] ) ? (string) $args['client_name'] : '';
$client_uri  = isset( $args['client_uri'] ) ? (string) $args['client_uri'] : '';
$client_id   = isset( $args['client_id'] ) ? (string) $args['client_id'] : '';
$resource    = isset( $args['resource'] ) ? (string) $args['resource'] : '';

/*
 * One screen serves both grants, and they verify different nonce actions.
 * The device grant passes 'wp_native_auth_oauth_device'; the authorization
 * code grant passes 'wp_native_auth_oauth_consent' and is the fallback for
 * any caller that predates the argument. Hardcoding the consent action here
 * made every device approval fail its nonce check with a 403.
 */
$nonce_action = isset( $args['nonce_action'] ) && '' !== (string) $args['nonce_action']
	? (string) $args['nonce_action']
	: 'wp_native_auth_oauth_consent';

get_header();
?>
<div class="card">
	<h1>
		<?php
		if ( '' !== $client_uri ) {
			printf(
				/* translators: %s: application name. */
				esc_html__( 'Connect %s to your Extra Chill account', 'extrachill-users' ),
				'<a href="' . esc_url( $client_uri ) . '" rel="noopener noreferrer">' . esc_html( $client_name ) . '</a>'
			);
		} else {
			printf(
				/* translators: %s: application name. */
				esc_html__( 'Connect %s to your Extra Chill account', 'extrachill-users' ),
				'<strong>' . esc_html( $client_name ) . '</strong>'
			);
		}
		?>
	</h1>

	<p>
		<?php
		printf(
			/* translators: %s: application name. */
			esc_html__( '%s is asking for permission to act as you on Extra Chill.', 'extrachill-users' ),
			'<strong>' . esc_html( $client_name ) . '</strong>'
		);
		?>
		<?php esc_html_e( 'Approving gives it access as your account, with the permissions you already have and no more.', 'extrachill-users' ); ?>
		<?php esc_html_e( 'There are no partial permissions to choose from — you either approve or deny the whole connection.', 'extrachill-users' ); ?>
	</p>

	<?php if ( '' !== $resource ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: resource URI. */
				esc_html__( 'This connection is scoped to the resource %s.', 'extrachill-users' ),
				'<code>' . esc_html( $resource ) . '</code>'
			);
			?>
		</p>
	<?php endif; ?>

	<p>
		<?php esc_html_e( 'Application ID:', 'extrachill-users' ); ?>
		<code><?php echo esc_html( $client_id ); ?></code>
	</p>

	<div class="notice notice--info">
		<?php esc_html_e( 'You can revoke this access at any time.', 'extrachill-users' ); ?>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( $nonce_action ); ?>
		<input type="hidden" name="oauth_request" value="<?php echo esc_attr( $args['bundle'] ); ?>">
		<input type="hidden" name="oauth_signature" value="<?php echo esc_attr( $args['signature'] ); ?>">
		<p>
			<button type="submit" class="btn btn--primary" name="oauth_decision" value="approve"><?php esc_html_e( 'Approve', 'extrachill-users' ); ?></button>
			<button type="submit" class="btn btn--danger" name="oauth_decision" value="deny"><?php esc_html_e( 'Deny', 'extrachill-users' ); ?></button>
		</p>
	</form>
</div>
<?php
get_footer();
