<?php
/**
 * Android app prompt.
 *
 * Chrome has no native install banner for store apps, so Android visitors get
 * a small, dismissible inline notice ("Get the Extra Chill app") rendered in
 * the theme's notice slot. iOS gets Safari's Smart App Banner instead, which
 * extrachill-seo outputs as head meta.
 *
 * Nothing renders until the app is configured in network options:
 * - `extrachill_app_store_id` is the network-wide launch switch;
 * - `extrachill_play_package` (default `com.extrachill.app`) names the Play
 *   listing. Storing it explicitly enables the Android prompt on its own;
 *   storing an empty value disables it.
 *
 * Pages are full-page cached, so the server only decides whether the app is
 * configured. The markup always ships `hidden`; assets/js/app-prompt.js
 * reveals it only for Android user agents that have not dismissed it in the
 * last 30 days, and never on a logged-out visitor's first pageview of an
 * article (no interstitials).
 *
 * @package ExtraChill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default Google Play package for the Extra Chill app.
 */
const EXTRACHILL_APP_DEFAULT_PLAY_PACKAGE = 'com.extrachill.app';

/**
 * Resolve the Google Play package name.
 *
 * @return string Package name, or empty string when disabled/invalid.
 */
function extrachill_get_app_play_package() {
	$package = get_site_option( 'extrachill_play_package', EXTRACHILL_APP_DEFAULT_PLAY_PACKAGE );

	if ( ! is_string( $package ) ) {
		return '';
	}

	$package = trim( $package );

	return preg_match( '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', $package ) ? $package : '';
}

/**
 * Whether the Android app prompt is enabled for this network.
 *
 * @return bool
 */
function extrachill_app_prompt_is_enabled() {
	if ( '' === extrachill_get_app_play_package() ) {
		return false;
	}

	$store_id         = get_site_option( 'extrachill_app_store_id', '' );
	$has_store_id     = is_scalar( $store_id ) && '' !== (string) preg_replace( '/\D+/', '', (string) $store_id );
	$package_explicit = false !== get_site_option( 'extrachill_play_package', false );

	return (bool) apply_filters( 'extrachill_app_prompt_enabled', $has_store_id || $package_explicit );
}

/**
 * Google Play listing URL for the configured package.
 *
 * @return string
 */
function extrachill_get_app_play_url() {
	return 'https://play.google.com/store/apps/details?id=' . rawurlencode( extrachill_get_app_play_package() );
}

/**
 * Enqueue the prompt's progressive enhancement only when it can render.
 */
function extrachill_enqueue_app_prompt() {
	if ( ! extrachill_app_prompt_is_enabled() ) {
		return;
	}

	$script_path = get_template_directory() . '/assets/js/app-prompt.js';
	if ( ! file_exists( $script_path ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-app-prompt',
		get_template_directory_uri() . '/assets/js/app-prompt.js',
		array(),
		(string) filemtime( $script_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_app_prompt' );

/**
 * Render the (initially hidden) Android app prompt in the notice slot.
 */
function extrachill_render_app_prompt() {
	if ( ! extrachill_app_prompt_is_enabled() ) {
		return;
	}

	$cookie_domain = defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '';
	?>
	<div class="notice notice-info" data-ec-app-prompt data-article="<?php echo is_singular( 'post' ) ? '1' : '0'; ?>" data-cookie-domain="<?php echo esc_attr( $cookie_domain ); ?>" hidden>
		<p><?php esc_html_e( 'Get the Extra Chill app for music news, shows and community on the go.', 'extrachill' ); ?></p>
		<p>
			<a class="button-1 button-small" href="<?php echo esc_url( extrachill_get_app_play_url() ); ?>" rel="noopener"><?php esc_html_e( 'Get the Extra Chill app', 'extrachill' ); ?></a>
			<button type="button" class="button-3 button-small" data-ec-app-prompt-dismiss><?php esc_html_e( 'Not now', 'extrachill' ); ?></button>
		</p>
	</div>
	<?php
}
add_action( 'extrachill_notices', 'extrachill_render_app_prompt', 20 );
