<?php
/**
 * Network-level admin settings for qualify v2 unattended-safe operation.
 *
 * Registers four site options (network-wide via get_site_option) and a
 * minimal Network Admin → Settings → Qualify v2 page so an operator can
 * toggle confirmation, recheck, and the weekly digest without reaching
 * for wp-cli.
 *
 * Options:
 *  - dme_qualify_pause_confirmation  (bool, default true)
 *  - dme_qualify_recheck_enabled     (bool, default true)
 *  - dme_qualify_digest_enabled      (bool, default true)
 *  - dme_qualify_digest_recipient    (string, default '' = admin_email)
 *
 * All four are read via get_site_option() so this file works on both
 * network-activated and single-site activations. The settings UI is
 * registered on `network_admin_menu` and falls back gracefully on
 * single-site installs (in which case the page never renders but the
 * options are still honoured by the runtime code).
 *
 * @package ExtraChillEvents\Admin
 * @since   0.21.0
 */

namespace ExtraChillEvents\Admin;

defined( 'ABSPATH' ) || exit;

class NetworkSettings {

	public const OPTION_PAUSE_CONFIRMATION = 'dme_qualify_pause_confirmation';
	public const OPTION_RECHECK_ENABLED    = 'dme_qualify_recheck_enabled';
	public const OPTION_DIGEST_ENABLED     = 'dme_qualify_digest_enabled';
	public const OPTION_DIGEST_RECIPIENT   = 'dme_qualify_digest_recipient';

	/**
	 * All four options + their defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			self::OPTION_PAUSE_CONFIRMATION => true,
			self::OPTION_RECHECK_ENABLED    => true,
			self::OPTION_DIGEST_ENABLED     => true,
			self::OPTION_DIGEST_RECIPIENT   => '',
		);
	}

	/**
	 * Wire admin hooks. Called once at plugin init.
	 */
	public static function register(): void {
		add_action( 'network_admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_extrachill_save_qualify_v2_settings', array( self::class, 'handle_save' ) );
	}

	/**
	 * Register the Network Admin menu entry.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'Qualify v2 Settings', 'extrachill-events' ),
			__( 'Qualify v2', 'extrachill-events' ),
			'manage_network_options',
			'extrachill-qualify-v2',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Register settings (used on single-site activations for UI parity).
	 */
	public static function register_settings(): void {
		register_setting(
			'extrachill_qualify_v2',
			self::OPTION_PAUSE_CONFIRMATION,
			array(
				'type'    => 'boolean',
				'default' => true,
			)
		);
		register_setting(
			'extrachill_qualify_v2',
			self::OPTION_RECHECK_ENABLED,
			array(
				'type'    => 'boolean',
				'default' => true,
			)
		);
		register_setting(
			'extrachill_qualify_v2',
			self::OPTION_DIGEST_ENABLED,
			array(
				'type'    => 'boolean',
				'default' => true,
			)
		);
		register_setting(
			'extrachill_qualify_v2',
			self::OPTION_DIGEST_RECIPIENT,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_recipient' ),
			)
		);
	}

	/**
	 * Sanitize the digest recipient field — accept an email or an empty
	 * string. Anything else is rejected back to the previous value.
	 *
	 * @param string $input Raw input.
	 * @return string
	 */
	public static function sanitize_recipient( $input ): string {
		$input = is_string( $input ) ? trim( $input ) : '';
		if ( '' === $input ) {
			return '';
		}
		return is_email( $input ) ? sanitize_email( $input ) : (string) get_site_option( self::OPTION_DIGEST_RECIPIENT, '' );
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-events' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own admin-post redirect; the mutating save is nonce-protected and this branch is behind a current_user_can( 'manage_network_options' ) check above.
		$saved = isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) );

		$pause_confirmation = (bool) get_site_option( self::OPTION_PAUSE_CONFIRMATION, true );
		$recheck_enabled    = (bool) get_site_option( self::OPTION_RECHECK_ENABLED, true );
		$digest_enabled     = (bool) get_site_option( self::OPTION_DIGEST_ENABLED, true );
		$digest_recipient   = (string) get_site_option( self::OPTION_DIGEST_RECIPIENT, '' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Qualify v2 Settings', 'extrachill-events' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'extrachill-events' ); ?></p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Controls for the unattended-safe pause / recheck / digest loop introduced by qualify v2 issue #79.', 'extrachill-events' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="extrachill_save_qualify_v2_settings" />
				<?php wp_nonce_field( 'extrachill_save_qualify_v2_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_PAUSE_CONFIRMATION ); ?>"><?php esc_html_e( 'Pause confirmation', 'extrachill-events' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="<?php echo esc_attr( self::OPTION_PAUSE_CONFIRMATION ); ?>" name="<?php echo esc_attr( self::OPTION_PAUSE_CONFIRMATION ); ?>" value="1" <?php checked( $pause_confirmation ); ?> />
								<?php esc_html_e( 'Require multi-verdict confirmation before auto-pause', 'extrachill-events' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When off, unqualifiable-flows --auto-pause pauses on the first failing verdict (pre-v0.21 behavior).', 'extrachill-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_RECHECK_ENABLED ); ?>"><?php esc_html_e( 'Recheck paused flows', 'extrachill-events' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="<?php echo esc_attr( self::OPTION_RECHECK_ENABLED ); ?>" name="<?php echo esc_attr( self::OPTION_RECHECK_ENABLED ); ?>" value="1" <?php checked( $recheck_enabled ); ?> />
								<?php esc_html_e( 'Enable automatic recheck of paused flows', 'extrachill-events' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When off, paused flows stay paused until manual operator action.', 'extrachill-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_DIGEST_ENABLED ); ?>"><?php esc_html_e( 'Weekly digest email', 'extrachill-events' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="<?php echo esc_attr( self::OPTION_DIGEST_ENABLED ); ?>" name="<?php echo esc_attr( self::OPTION_DIGEST_ENABLED ); ?>" value="1" <?php checked( $digest_enabled ); ?> />
								<?php esc_html_e( 'Send the weekly qualify v2 activity digest', 'extrachill-events' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Default schedule: Mondays 09:00 UTC. Filterable via dme_qualify_digest_schedule.', 'extrachill-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_DIGEST_RECIPIENT ); ?>"><?php esc_html_e( 'Digest recipient', 'extrachill-events' ); ?></label></th>
						<td>
							<input type="email" id="<?php echo esc_attr( self::OPTION_DIGEST_RECIPIENT ); ?>" name="<?php echo esc_attr( self::OPTION_DIGEST_RECIPIENT ); ?>" value="<?php echo esc_attr( $digest_recipient ); ?>" class="regular-text" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Override recipient email. Empty falls back to the site\'s admin_email.', 'extrachill-events' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'extrachill-events' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the settings via update_site_option so they take effect
	 * network-wide.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'extrachill-events' ) );
		}
		check_admin_referer( 'extrachill_save_qualify_v2_settings' );

		update_site_option( self::OPTION_PAUSE_CONFIRMATION, ! empty( $_POST[ self::OPTION_PAUSE_CONFIRMATION ] ) );
		update_site_option( self::OPTION_RECHECK_ENABLED, ! empty( $_POST[ self::OPTION_RECHECK_ENABLED ] ) );
		update_site_option( self::OPTION_DIGEST_ENABLED, ! empty( $_POST[ self::OPTION_DIGEST_ENABLED ] ) );
		update_site_option(
			self::OPTION_DIGEST_RECIPIENT,
			self::sanitize_recipient( isset( $_POST[ self::OPTION_DIGEST_RECIPIENT ] ) ? wp_unslash( (string) $_POST[ self::OPTION_DIGEST_RECIPIENT ] ) : '' )
		);

		$redirect = network_admin_url( 'settings.php?page=extrachill-qualify-v2&updated=1' );
		wp_safe_redirect( $redirect );
		exit;
	}
}
