<?php
/**
 * Login/Register Block Server-Side Render
 *
 * @package ExtraChillUsers
 */

// Show friendly message if user is already logged in
if ( is_user_logged_in() ) {
	$current_user = wp_get_current_user();
	$profile_url = function_exists( 'ec_get_user_profile_url' )
		? ec_get_user_profile_url( $current_user->ID, $current_user->user_email )
		: home_url();

	ob_start();
	?>
	<div class="login-already-logged-in-message">
		<p><strong>You're already logged in as <?php echo esc_html( $current_user->display_name ); ?></strong></p>
		<p>What would you like to do?</p>
		<p>
			<a href="<?php echo esc_url( home_url() ); ?>" class="button-1 button-medium">Go to Homepage</a>
			<a href="<?php echo esc_url( $profile_url ); ?>" class="button-1 button-medium">View Profile</a>
			<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="button-3 button-medium">Log Out</a>
		</p>
	</div>
	<?php
	return ob_get_clean();
}

// Enqueue Cloudflare Turnstile script when block renders
if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

// Enqueue shared tabs when block renders
wp_enqueue_style( 'extrachill-shared-tabs' );
wp_enqueue_script( 'extrachill-shared-tabs' );
?>

<div class="shared-tabs-component">
	<div class="shared-tabs-buttons-container">
		<!-- Login Tab -->
		<div class="shared-tab-item">
			<button type="button" class="shared-tab-button active" data-tab="tab-login">
				Login
				<span class="shared-tab-arrow open"></span>
			</button>
			<div id="tab-login" class="shared-tab-pane active">
				<?php
				if ( function_exists( 'extrachill_login_form' ) ) {
					echo extrachill_login_form();
				}
				?>
			</div>
		</div>

		<!-- Register Tab -->
		<div class="shared-tab-item">
			<button type="button" class="shared-tab-button" data-tab="tab-register">
				Register
				<span class="shared-tab-arrow"></span>
			</button>
			<div id="tab-register" class="shared-tab-pane">
				<?php
				if ( function_exists( 'extrachill_registration_form_shortcode' ) ) {
					echo extrachill_registration_form_shortcode();
				}
				?>
			</div>
		</div>
	</div>

	<!-- Desktop Tab Content Area -->
	<div class="shared-desktop-tab-content-area" style="display: none;"></div>
</div>

<?php
// Allow plugins to add content below (e.g., artist platform join flow modal)
do_action( 'extrachill_below_login_register_form' );
?>
