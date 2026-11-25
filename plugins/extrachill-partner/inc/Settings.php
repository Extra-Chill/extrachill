<?php
namespace ExtraChill\Partner;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public static function register() {
		add_action( 'admin_menu', function () {
			add_options_page( __( 'Extra Chill', 'extrachill-partner' ), __( 'Extra Chill', 'extrachill-partner' ), 'manage_options', 'extrachill-partner', array( self::class, 'page' ) );
		} );
		add_action( 'admin_init', function () {
			add_option( Client::KEY_OPTION, '', '', false );
			register_setting( 'extrachill_partner', Client::KEY_OPTION, array( 'sanitize_callback' => array( self::class, 'save' ), 'show_in_rest' => false ) );
		} );
	}

	/** Empty input keeps the existing secret; invalid keys never replace it. */
	public static function save( $value ) {
		$old = get_option( Client::KEY_OPTION, '' );
		if ( ! current_user_can( 'manage_options' ) || ! is_string( $value ) || '' === trim( $value ) ) {
			return $old;
		}
		$key = sanitize_text_field( $value );
		$data = Client::request( $key, 1 );
		if ( is_wp_error( $data ) ) {
			add_settings_error( 'extrachill_partner', 'connection', __( 'Could not validate the API key. The previous key has been kept.', 'extrachill-partner' ) );
			return $old;
		}
		update_option( 'extrachill_partner_venue_name', $data['venue']['name'], false );
		add_settings_error( 'extrachill_partner', 'connected', sprintf( __( 'Connected to %s.', 'extrachill-partner' ), $data['venue']['name'] ), 'success' );
		return $key;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Extra Chill', 'extrachill-partner' ) . '</h1>';
		settings_errors( 'extrachill_partner' );
		$name = get_option( 'extrachill_partner_venue_name', '' );
		if ( $name ) {
			echo '<p>' . esc_html( sprintf( __( 'Connected to %s.', 'extrachill-partner' ), $name ) ) . '</p>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'extrachill_partner' );
		echo '<p><label for="extrachill-partner-key">' . esc_html__( 'API key', 'extrachill-partner' ) . '</label></p>';
		echo '<input id="extrachill-partner-key" name="' . esc_attr( Client::KEY_OPTION ) . '" type="password" value="" autocomplete="new-password" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key. Your key is never displayed.', 'extrachill-partner' ) . '</p>';
		submit_button();
		echo '</form></div>';
	}
}
