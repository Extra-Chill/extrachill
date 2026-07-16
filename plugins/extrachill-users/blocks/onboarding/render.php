<?php
/**
 * Onboarding Block - Server-Side Render
 *
 * Handles redirects for edge cases and provides data attributes for the JS app.
 *
 * @package ExtraChillUsers
 */

if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
	echo '<div class="onboarding-container"><p>' . esc_html__( 'Onboarding form (editor preview)', 'extrachill-users' ) . '</p></div>';
	return;
}

if ( ! is_user_logged_in() ) {
	$login_url = function_exists( 'ec_get_site_url' )
		? ec_get_site_url( 'community' ) . '/login/'
		: home_url( '/login/' );
	wp_safe_redirect( $login_url );
	exit;
}

if ( function_exists( 'ec_is_onboarding_complete' ) && ec_is_onboarding_complete( get_current_user_id() ) ) {
	$home_url = function_exists( 'ec_get_site_url' )
		? ec_get_site_url( 'community' )
		: home_url();
	wp_safe_redirect( $home_url );
	exit;
}

$user_id           = get_current_user_id();
$stored_redirect   = get_user_meta( $user_id, 'onboarding_redirect_url', true );
$default_url       = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url();
$redirect_url      = $stored_redirect ? $stored_redirect : $default_url;
$from_join         = get_user_meta( $user_id, 'onboarding_from_join', true ) === '1';
$user              = get_userdata( $user_id );
$current_username  = $user ? $user->user_login : '';
$onboarding_notice = EC_Redirect_Handler::get_message( 'ec_onboarding' );
ec_users_emit_onboarding_viewed_once( $user_id );
?>

<div class="onboarding-container">
	<div class="onboarding-header">
		<h1><?php esc_html_e( 'Welcome to Extra Chill!', 'extrachill-users' ); ?></h1>
		<p><?php esc_html_e( 'Let\'s set up your profile.', 'extrachill-users' ); ?></p>
	</div>

	<div 
		id="extrachill-onboarding-form"
		data-redirect-url="<?php echo esc_attr( $redirect_url ); ?>"
		data-from-join="<?php echo $from_join ? 'true' : 'false'; ?>"
		data-rest-url="<?php echo esc_url( rest_url( 'extrachill/v1/' ) ); ?>"
		data-abilities-url="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		data-analytics-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-analytics-nonce="<?php echo esc_attr( wp_create_nonce( 'extrachill_onboarding_analytics' ) ); ?>"
		data-username="<?php echo esc_attr( $current_username ); ?>"
	>
		<?php if ( $onboarding_notice ) : ?>
			<div class="ec-auth-notice ec-auth-notice--<?php echo esc_attr( $onboarding_notice['type'] ); ?>" data-ec-auth-notice="1">
				<p><?php echo esc_html( $onboarding_notice['text'] ); ?></p>
			</div>
		<?php endif; ?>

		<form id="onboarding-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="extrachill_complete_onboarding">
			<?php wp_nonce_field( 'extrachill_complete_onboarding', 'onboarding_nonce' ); ?>
			<?php EC_Redirect_Handler::render_hidden_fields(); ?>
			<div class="onboarding-field">
				<label for="onboarding-username"><?php esc_html_e( 'Choose your username', 'extrachill-users' ); ?></label>
				<input 
					type="text" 
					id="onboarding-username" 
					name="username" 
					value="<?php echo esc_attr( $current_username ); ?>" 
					required 
					minlength="3" 
					maxlength="60"
					pattern="[a-zA-Z0-9_-]+"
					placeholder="<?php esc_attr_e( 'Your username', 'extrachill-users' ); ?>"
				>
				<small class="onboarding-field-hint"><?php esc_html_e( 'Letters, numbers, hyphens, and underscores only. 3-60 characters.', 'extrachill-users' ); ?></small>
			</div>

			<div class="onboarding-field onboarding-local-scene">
				<label for="onboarding-local-scene"><?php esc_html_e( 'Local Scene (optional)', 'extrachill-users' ); ?></label>
				<input type="search" id="onboarding-local-scene" autocomplete="off" placeholder="<?php esc_attr_e( 'Search cities and regions', 'extrachill-users' ); ?>" aria-autocomplete="list" aria-controls="onboarding-local-scene-results" disabled>
				<input type="hidden" id="onboarding-local-scene-slug" name="local_scene" value="">
				<div id="onboarding-local-scene-results" class="onboarding-local-scene-results" role="listbox" hidden></div>
				<small class="onboarding-field-hint"><?php esc_html_e( 'Find people and live music near you. You can change this anytime.', 'extrachill-users' ); ?></small>
				<noscript><small class="onboarding-field-hint"><?php esc_html_e( 'You can add a Local Scene from Settings after setup.', 'extrachill-users' ); ?></small></noscript>
				<fieldset class="onboarding-visibility">
					<legend><?php esc_html_e( 'Who can see your Local Scene?', 'extrachill-users' ); ?></legend>
					<label><input type="radio" name="local_scene_visibility" value="public" checked> <?php esc_html_e( 'Public', 'extrachill-users' ); ?></label>
					<label><input type="radio" name="local_scene_visibility" value="private"> <?php esc_html_e( 'Private', 'extrachill-users' ); ?></label>
				</fieldset>
			</div>

			<div class="onboarding-field onboarding-checkboxes">
				<label class="onboarding-checkbox-label">
					<input type="checkbox" id="user_is_fan" checked disabled>
					<span><?php esc_html_e( 'I love music', 'extrachill-users' ); ?></span>
				</label>
				<label class="onboarding-checkbox-label">
					<input type="checkbox" id="user_is_artist" name="user_is_artist" value="1">
					<span><?php esc_html_e( 'I am a musician', 'extrachill-users' ); ?></span>
				</label>
				<label class="onboarding-checkbox-label">
					<input type="checkbox" id="user_is_professional" name="user_is_professional" value="1">
					<span><?php esc_html_e( 'I work in the music industry', 'extrachill-users' ); ?></span>
				</label>
			</div>

			<?php if ( $from_join ) : ?>
				<p class="onboarding-join-notice">
					<?php esc_html_e( 'To create your artist profile, please select "I am a musician" or "I work in the music industry".', 'extrachill-users' ); ?>
				</p>
			<?php endif; ?>

			<div class="onboarding-error" id="onboarding-error" style="display: none;"></div>

			<div class="onboarding-actions">
				<button type="submit" class="button-1 button-large" id="onboarding-submit">
					<?php esc_html_e( 'Complete Setup', 'extrachill-users' ); ?>
				</button>
			</div>
		</form>
	</div>
</div>
