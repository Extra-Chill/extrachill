<?php
/**
 * Artist identity push to the owned Link Page.
 *
 * The artist profile is the source of truth for identity (name and profile
 * image): every editor, upload and admin path writes the profile. The Link
 * Page owns a materialized copy so it renders from its own storage with no
 * owner plugin loaded (extrachill-link-pages#36). This file keeps that copy
 * current by pushing profile identity to the page whenever it changes.
 *
 * The push is one-way. The previous bidirectional sync also copied page
 * values back onto the profile; nothing edits page identity independently
 * any more, so that direction only created ways for the two to fight.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Re-entrancy guard for identity pushes.
 */
class ArtistDataSyncManager {
	/**
	 * Whether a push is in progress.
	 *
	 * @var bool
	 */
	private static $is_syncing = false;

	public static function is_syncing() {
		return self::$is_syncing;
	}

	public static function start_sync() {
		self::$is_syncing = true;
	}

	public static function stop_sync() {
		self::$is_syncing = false;
	}
}

/**
 * Write owned fields to an artist's Link Page from any artist context.
 *
 * Artist abilities hold the page lock in `separate` mode, and the runtime
 * refuses to mix a `generic` save (ec_save_link_page_persistence()) into
 * it. This helper always writes under a `separate` scope: it nests inside
 * an active artist mutation on the same page and acquires the lock itself
 * otherwise, then persists through the locked save API.
 *
 * @param int   $link_page_id Link Page ID.
 * @param array $fields       Fields accepted by the Link Page storage API.
 * @return array|WP_Error Saved persistence or error.
 */
function ec_artist_save_link_page_fields( $link_page_id, $fields ) {
	$link_page_id = absint( $link_page_id );
	if ( ! $link_page_id || ! function_exists( 'ec_with_link_page_storage_blog' ) || ! function_exists( 'ec_with_link_page_lock_scope' ) || ! function_exists( 'ec_save_link_page_persistence_locked' ) ) {
		return new WP_Error( 'link_pages_runtime_unavailable', 'The Link Pages runtime is not loaded.' );
	}
	// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; checked above.)
	return ec_with_link_page_storage_blog(
		static function () use ( $link_page_id, $fields ) {
			// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; checked above.)
			if ( ec_link_page_post_type() !== get_post_type( $link_page_id ) ) {
				return new WP_Error( 'invalid_link_page', 'Invalid Link Page ID.' );
			}
			// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; checked above.)
			return ec_with_link_page_lock_scope(
				$link_page_id,
				static function () use ( $link_page_id, $fields ) {
					// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; checked above.)
					return ec_save_link_page_persistence_locked( $link_page_id, $fields );
				},
				'separate'
			);
		}
	);
}

/**
 * Push an artist's identity (name, profile image, schema entity) onto its Link Page.
 *
 * Must run in the artist site context. No-ops when the artist has no Link
 * Page or when the page already matches.
 *
 * @param int $artist_id Artist profile ID.
 * @return bool|WP_Error True on success or no-op.
 */
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- WordPress plugin idiom: guard class plus procedural hook wiring in one module.
function ec_artist_push_link_page_identity( $artist_id ) {
	$artist_id = absint( $artist_id );
	if ( ! $artist_id || 'artist_profile' !== get_post_type( $artist_id ) ) {
		return new WP_Error( 'invalid_artist_profile', 'Invalid artist profile ID for identity push.' );
	}
	if ( ArtistDataSyncManager::is_syncing() ) {
		return true;
	}
	if ( ! function_exists( 'ec_read_link_page_persistence' ) ) {
		return new WP_Error( 'link_pages_runtime_unavailable', 'The Link Pages runtime is not loaded.' );
	}
	$link_page_id = (int) ec_get_link_page_for_artist( $artist_id );
	if ( ! $link_page_id ) {
		return true;
	}

	$artist   = get_post( $artist_id );
	$permalink = get_permalink( $artist_id );
	$identity  = array(
		'display_title'      => $artist ? $artist->post_title : '',
		'profile_image_id'   => (int) get_post_thumbnail_id( $artist_id ),
		'schema_entity_type' => 'MusicGroup',
		'schema_entity_url'  => $permalink ? (string) $permalink : '',
	);

	$current = ec_read_link_page_persistence( $link_page_id );
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	$changes = array();
	if ( ! $current['display_title_is_owned'] || $current['display_title'] !== $identity['display_title'] ) {
		$changes['display_title'] = $identity['display_title'];
	}
	if ( (int) $current['profile_image_id'] !== $identity['profile_image_id'] ) {
		$changes['profile_image_id'] = $identity['profile_image_id'];
	}
	// Schema entity fields exist from Link Pages 0.6; older runtimes omit
	// them from persistence, so only push when the runtime reports them.
	if ( isset( $current['schema_entity'] ) && is_array( $current['schema_entity'] ) ) {
		if ( ( $current['schema_entity']['type'] ?? '' ) !== $identity['schema_entity_type'] ) {
			$changes['schema_entity_type'] = $identity['schema_entity_type'];
		}
		if ( ( $current['schema_entity']['url'] ?? '' ) !== $identity['schema_entity_url'] ) {
			$changes['schema_entity_url'] = $identity['schema_entity_url'];
		}
	}
	if ( ! $changes ) {
		return true;
	}

	ArtistDataSyncManager::start_sync();
	try {
		$saved = ec_artist_save_link_page_fields( $link_page_id, $changes );
	} finally {
		ArtistDataSyncManager::stop_sync();
	}
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	/**
	 * Fires after an artist's identity was pushed to its Link Page.
	 *
	 * @param int $artist_id Artist profile ID.
	 */
	do_action( 'ec_artist_platform_sync_complete', $artist_id );
	return true;
}

/**
 * Historical entry point: sync now means "push identity to the page".
 *
 * @param int $artist_id Artist profile ID.
 * @return bool|WP_Error
 */
function ec_handle_artist_platform_sync( $artist_id ) {
	return ec_artist_push_link_page_identity( $artist_id );
}

/**
 * Public API alias.
 *
 * @param int $artist_id Artist profile ID.
 * @return bool|WP_Error
 */
function ec_sync_artist_platform( $artist_id ) {
	return ec_artist_push_link_page_identity( $artist_id );
}

/**
 * Action handler for `ec_artist_platform_sync`.
 *
 * @param int $artist_id Artist profile ID.
 */
function ec_handle_sync_action( $artist_id ) {
	ec_artist_push_link_page_identity( $artist_id );
}
add_action( 'ec_artist_platform_sync', 'ec_handle_sync_action', 10, 1 );

/**
 * Push identity when the artist profile is saved (name changes).
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function ec_artist_push_identity_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'artist_profile' !== $post->post_type ) {
		return;
	}
	ec_artist_push_link_page_identity( $post_id );
}
add_action( 'save_post_artist_profile', 'ec_artist_push_identity_on_save', 20, 2 );

/**
 * Push identity when the artist profile image changes.
 *
 * @param mixed  $meta_id   Meta ID(s).
 * @param int    $object_id Post ID.
 * @param string $meta_key  Meta key.
 */
function ec_artist_push_identity_on_thumbnail_change( $meta_id, $object_id, $meta_key ) {
	unset( $meta_id );
	if ( '_thumbnail_id' !== $meta_key || 'artist_profile' !== get_post_type( $object_id ) ) {
		return;
	}
	ec_artist_push_link_page_identity( $object_id );
}
add_action( 'added_post_meta', 'ec_artist_push_identity_on_thumbnail_change', 10, 3 );
add_action( 'updated_post_meta', 'ec_artist_push_identity_on_thumbnail_change', 10, 3 );
add_action( 'deleted_post_meta', 'ec_artist_push_identity_on_thumbnail_change', 10, 3 );

/**
 * Seed identity onto a freshly created Link Page.
 *
 * @param int $link_page_id Link Page ID.
 * @param int $artist_id    Artist profile ID.
 */
function ec_artist_push_identity_on_link_page_created( $link_page_id, $artist_id ) {
	unset( $link_page_id );
	ec_artist_push_link_page_identity( $artist_id );
}
add_action( 'ec_link_page_created', 'ec_artist_push_identity_on_link_page_created', 20, 2 );
