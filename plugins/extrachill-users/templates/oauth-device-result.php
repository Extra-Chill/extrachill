<?php
/**
 * Branded device-flow terminal screen for Extra Chill.
 *
 * Serves in place of wp-native-auth's generic result page via the
 * wp_native_auth_oauth_device_result_template filter (registered in
 * inc/wp-native-bridge.php). Shown once after the person approves or
 * denies a device; the connecting app learns the outcome by polling, so
 * this page only needs to tell the person they are done.
 *
 * Renders inside the theme chrome and uses only classes the extrachill
 * theme defines (see oauth-consent.php for the design-system reference).
 *
 * @package ExtraChill\Users
 *
 * @var array $args {
 *     @type bool $approved Whether the person approved the device.
 * }
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$approved = ! empty( $args['approved'] );

get_header();
?>
<div class="card">
	<?php if ( $approved ) : ?>
		<h1><?php esc_html_e( 'Device connected', 'extrachill-users' ); ?></h1>
		<div class="notice notice-success">
			<?php esc_html_e( 'The app has been connected to your Extra Chill account. You can close this window and go back to it.', 'extrachill-users' ); ?>
		</div>
		<p><?php esc_html_e( 'You can disconnect it at any time from your settings, under Security → Connected Apps.', 'extrachill-users' ); ?></p>
	<?php else : ?>
		<h1><?php esc_html_e( 'Request denied', 'extrachill-users' ); ?></h1>
		<div class="notice notice-info">
			<?php esc_html_e( 'No access was granted. You can close this window.', 'extrachill-users' ); ?>
		</div>
	<?php endif; ?>
</div>
<?php
get_footer();
