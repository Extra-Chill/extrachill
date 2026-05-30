<?php
/**
 * bbPress Edit-Flow Autosave via WP Core /autosaves
 *
 * Issue #37: route topic/reply EDIT autosaves through WordPress core's native
 * `/wp/v2/<cpt>/<id>/autosaves` REST endpoint (per-user isolation, dedupe and
 * snapshot semantics built in) instead of the custom drafts table.
 *
 * The custom drafts table (draft-storage.php / draft-abilities.php) still owns
 * the new-topic / new-reply COMPOSE case, where no parent post exists yet and
 * bbPress validation runs at insert-time. This file only handles the narrow
 * remaining case core can serve cleanly: a logged-in user editing an existing
 * topic or reply they can edit. Scope = ONLY the gap (RULES.md: do not build a
 * unified abstraction that swallows the working compose path).
 *
 * How it works:
 *  1. bbPress `topic` / `reply` CPTs ship with `show_in_rest = false`, so core's
 *     autosaves controller is never registered for them. We flip `show_in_rest`
 *     to true *conditionally* — only for the autosave REST request itself and
 *     for the front-end edit page render — so the collection endpoint is not
 *     broadly exposed network-wide on every page load.
 *  2. On an edit page, we inject `postEntity = { type, id }` into the Blocks
 *     Everywhere editor settings. BE's PostEntityShell then wraps the editor in
 *     core's <EditorProvider> + <AutosaveMonitor>, which fire the standard
 *     `/wp/v2/<cpt>/<id>/autosaves` path with no custom client code.
 *
 * Core's autosaves controller already enforces per-user isolation and the
 * `edit_post` capability (WP_REST_Autosaves_Controller::create_item_permissions_check
 * → parent update_item_permissions_check), so reads/writes stay owner-gated.
 *
 * @package ExtraChillCommunity
 * @subpackage ForumFeatures\Content\Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detect whether the current request is a core autosaves REST request for a
 * bbPress topic or reply.
 *
 * CPT args (including `show_in_rest`) are decided at registration time on the
 * `init` hook, which runs before REST route matching — and before the
 * `REST_REQUEST` constant is defined (that happens on `parse_request`). So we
 * cannot rely on `REST_REQUEST` here; we inspect the raw request URI instead to
 * know whether to expose the autosaves route for this request.
 *
 * Matches both the pretty form `/wp-json/wp/v2/<cpt>/<id>/autosaves[...]` and
 * the `?rest_route=/wp/v2/<cpt>/<id>/autosaves` fallback form.
 *
 * @param string $cpt The bbPress CPT slug (topic or reply post type name).
 * @return bool
 */
function extrachill_community_is_autosaves_rest_request( $cpt ) {
	if ( empty( $cpt ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	$uri = rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) );

	// rest_base defaults to the post type name for bbPress CPTs (no custom rest_base set).
	$pattern = '#/wp/v2/' . preg_quote( $cpt, '#' ) . '/\d+/autosaves#';

	return (bool) preg_match( $pattern, $uri );
}

/**
 * Detect whether the current front-end request is a logged-in user editing an
 * existing topic.
 *
 * @return bool
 */
function extrachill_community_is_frontend_topic_edit() {
	return is_user_logged_in()
		&& function_exists( 'bbp_is_topic_edit' )
		&& bbp_is_topic_edit();
}

/**
 * Detect whether the current front-end request is a logged-in user editing an
 * existing reply.
 *
 * @return bool
 */
function extrachill_community_is_frontend_reply_edit() {
	return is_user_logged_in()
		&& function_exists( 'bbp_is_reply_edit' )
		&& bbp_is_reply_edit();
}

/**
 * Conditionally enable `show_in_rest` for the topic CPT.
 *
 * Only flips on for (a) the autosaves REST request itself, or (b) a front-end
 * topic-edit page render — keeping the collection endpoint off for normal page
 * loads so the blast radius stays minimal.
 *
 * @param array $args register_post_type args for the topic CPT.
 * @return array
 */
function extrachill_community_enable_topic_rest_for_autosave( $args ) {
	if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
		return $args;
	}

	$cpt = bbp_get_topic_post_type();

	if ( extrachill_community_is_autosaves_rest_request( $cpt )
		|| extrachill_community_is_frontend_topic_edit() ) {
		$args['show_in_rest'] = true;
	}

	return $args;
}
add_filter( 'bbp_register_topic_post_type', 'extrachill_community_enable_topic_rest_for_autosave', 20 );

/**
 * Conditionally enable `show_in_rest` for the reply CPT.
 *
 * @param array $args register_post_type args for the reply CPT.
 * @return array
 */
function extrachill_community_enable_reply_rest_for_autosave( $args ) {
	if ( ! function_exists( 'bbp_get_reply_post_type' ) ) {
		return $args;
	}

	$cpt = bbp_get_reply_post_type();

	if ( extrachill_community_is_autosaves_rest_request( $cpt )
		|| extrachill_community_is_frontend_reply_edit() ) {
		$args['show_in_rest'] = true;
	}

	return $args;
}
add_filter( 'bbp_register_reply_post_type', 'extrachill_community_enable_reply_rest_for_autosave', 20 );

/**
 * Inject `postEntity` into the Blocks Everywhere editor settings on edit pages.
 *
 * When present, BE's PostEntityShell wraps the editor in core's
 * <EditorProvider> + <AutosaveMonitor>, activating the native
 * `/wp/v2/<cpt>/<id>/autosaves` path for the post being edited. Absent on
 * compose pages, so the custom drafts table continues to own new-topic /
 * new-reply autosave untouched.
 *
 * Runs at priority 30, after the endpoint configuration filter (priority 20)
 * in inc/core/assets.php, so it composes cleanly with the existing bbpress
 * settings sub-object.
 *
 * @param array $settings Blocks Everywhere editor settings.
 * @return array
 */
function extrachill_community_configure_edit_post_entity( $settings ) {
	if ( extrachill_community_is_frontend_topic_edit()
		&& function_exists( 'bbp_get_topic_id' )
		&& function_exists( 'bbp_get_topic_post_type' ) ) {
		$topic_id = (int) bbp_get_topic_id();
		if ( $topic_id > 0 && current_user_can( 'edit_post', $topic_id ) ) {
			$settings['postEntity'] = array(
				'type' => bbp_get_topic_post_type(),
				'id'   => $topic_id,
			);
		}
		return $settings;
	}

	if ( extrachill_community_is_frontend_reply_edit()
		&& function_exists( 'bbp_get_reply_id' )
		&& function_exists( 'bbp_get_reply_post_type' ) ) {
		$reply_id = (int) bbp_get_reply_id();
		if ( $reply_id > 0 && current_user_can( 'edit_post', $reply_id ) ) {
			$settings['postEntity'] = array(
				'type' => bbp_get_reply_post_type(),
				'id'   => $reply_id,
			);
		}
	}

	return $settings;
}
add_filter( 'blocks_everywhere_editor_settings', 'extrachill_community_configure_edit_post_entity', 30 );
