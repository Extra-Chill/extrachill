<?php
/**
 * Local Scene member prompt.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return eligible settings, or false when this request should not show the prompt.
 *
 * @return array|false
 */
function extrachill_community_local_scene_prompt_settings() {
	static $settings;

	if ( null !== $settings ) {
		return $settings;
	}

	$settings          = false;
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : 0;
	if ( ! $community_blog_id || (int) get_current_blog_id() !== (int) $community_blog_id || ! is_user_logged_in() || is_admin() ) {
		return false;
	}

	if ( is_page( array( 'onboarding', 'settings' ) ) || extrachill_community_is_local_scene_archive() ) {
		return false;
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-user-settings' ) : null;
	if ( ! $ability ) {
		return false;
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		return false;
	}

	if ( empty( $result['onboarding_completed'] ) || ! empty( $result['local_scene'] ) || ! empty( $result['local_scene_prompt_dismissed'] ) ) {
		return false;
	}

	$settings = $result;
	return $settings;
}

/**
 * Enqueue prompt assets only for eligible requests.
 */
function extrachill_community_enqueue_local_scene_prompt_assets() {
	if ( ! extrachill_community_local_scene_prompt_settings() ) {
		return;
	}

	$css_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/local-scene-member-prompt.css';
	$js_path  = EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/local-scene-member-prompt.js';

	wp_enqueue_style(
		'extrachill-local-scene-member-prompt',
		EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/local-scene-member-prompt.css',
		array( 'extrachill-community-global' ),
		filemtime( $css_path )
	);

	wp_enqueue_script(
		'extrachill-local-scene-member-prompt',
		EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/local-scene-member-prompt.js',
		array(),
		filemtime( $js_path ),
		true
	);

	wp_localize_script(
		'extrachill-local-scene-member-prompt',
		'extrachillLocalScenePrompt',
		array(
			'abilitiesUrl' => rest_url( 'wp-abilities/v1/abilities/' ),
			'restNonce'    => wp_create_nonce( 'wp_rest' ),
			'analyticsUrl' => admin_url( 'admin-ajax.php' ),
			'analyticsNonce' => wp_create_nonce( 'extrachill_local_scene_prompt_analytics' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_community_enqueue_local_scene_prompt_assets', 30 );

/**
 * Emit a privacy-safe prompt analytics event.
 *
 * @param string $event_type Analytics-owned event constant.
 * @param array  $event_data Bounded event payload.
 */
function extrachill_community_emit_local_scene_prompt_event( $event_type, $event_data ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/track-analytics-event' ) : null;
	if ( $ability ) {
		$ability->execute(
			array(
				'event_type' => $event_type,
				'event_data' => $event_data,
			)
		);
	}
}

/**
 * Render the prompt through the theme notice area.
 */
function extrachill_community_render_local_scene_prompt() {
	$settings = extrachill_community_local_scene_prompt_settings();
	if ( ! $settings ) {
		return;
	}

	extrachill_community_emit_local_scene_prompt_event(
		EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_VIEWED,
		array(
			'user_id'         => (int) $settings['user_id'],
			'visibility'      => 'public' === ( $settings['local_scene_visibility'] ?? 'public' ),
			'has_local_scene' => false,
		)
	);
	?>
	<section class="notice notice-info ec-local-scene-prompt" id="ec-local-scene-prompt" aria-labelledby="ec-local-scene-prompt-title">
		<div class="ec-local-scene-prompt__copy">
			<p id="ec-local-scene-prompt-title"><strong><?php esc_html_e( 'Find your Local Scene', 'extra-chill-community' ); ?></strong></p>
			<p><?php esc_html_e( 'Connect with people and discover live music near you.', 'extra-chill-community' ); ?></p>
		</div>
		<form class="ec-local-scene-prompt__form">
			<div class="ec-local-scene-prompt__picker">
				<label class="screen-reader-text" for="ec-local-scene-search"><?php esc_html_e( 'Search Local Scenes', 'extra-chill-community' ); ?></label>
				<input type="search" id="ec-local-scene-search" placeholder="<?php esc_attr_e( 'Search cities and regions', 'extra-chill-community' ); ?>" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ec-local-scene-results" required>
				<input type="hidden" id="ec-local-scene-slug" value="">
				<div id="ec-local-scene-results" class="ec-local-scene-prompt__results" role="listbox" hidden></div>
			</div>
			<label class="ec-checkbox-row ec-local-scene-prompt__visibility">
				<input type="checkbox" id="ec-local-scene-public" checked>
				<span><?php esc_html_e( 'Show publicly', 'extra-chill-community' ); ?></span>
			</label>
			<div class="ec-local-scene-prompt__actions">
				<button type="submit" class="button-1 button-small"><?php esc_html_e( 'Save', 'extra-chill-community' ); ?></button>
				<button type="button" class="button-3 button-small ec-local-scene-prompt__dismiss"><?php esc_html_e( 'Dismiss', 'extra-chill-community' ); ?></button>
			</div>
			<p class="ec-local-scene-prompt__status" role="status" aria-live="polite"></p>
		</form>
	</section>
	<?php
}
add_action( 'extrachill_notices', 'extrachill_community_render_local_scene_prompt', 15 );

/**
 * Record a client-side prompt outcome through the Analytics-owned Ability.
 */
function extrachill_community_local_scene_prompt_analytics() {
	check_ajax_referer( 'extrachill_local_scene_prompt_analytics', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( null, 403 );
	}

	$outcome = isset( $_POST['outcome'] ) ? sanitize_key( wp_unslash( $_POST['outcome'] ) ) : '';
	$events  = array(
		'dismissed' => EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_DISMISSED,
		'completed' => EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_COMPLETED,
	);
	if ( ! isset( $events[ $outcome ] ) ) {
		wp_send_json_error( null, 400 );
	}

	extrachill_community_emit_local_scene_prompt_event(
		$events[ $outcome ],
		array(
			'user_id'         => get_current_user_id(),
			'visibility'      => ! empty( $_POST['visibility'] ),
			'has_local_scene' => 'completed' === $outcome,
		)
	);

	wp_send_json_success();
}
add_action( 'wp_ajax_extrachill_local_scene_prompt_analytics', 'extrachill_community_local_scene_prompt_analytics' );
