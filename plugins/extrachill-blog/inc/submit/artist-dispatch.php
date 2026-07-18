<?php
/**
 * Artist Dispatch submission pathway.
 *
 * WordPress owns posts, capabilities, autosaves, revisions, and previews.
 * Extra Chill Users owns access and represented-artist authorization. Blocks
 * Everywhere owns the canonical frontend editor shell.
 *
 * @package ExtraChillBlog
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_BLOG_DISPATCH_SOURCE_META       = '_ec_artist_dispatch_source';
const EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META    = '_ec_artist_dispatch_submitter';
const EXTRACHILL_BLOG_DISPATCH_ARTIST_META       = '_ec_artist_dispatch_artist';
const EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META    = '_ec_artist_dispatch_submitted_at';
const EXTRACHILL_BLOG_DISPATCH_TERMS_META        = '_ec_artist_dispatch_terms_version';
const EXTRACHILL_BLOG_DISPATCH_SOURCE            = 'artist-dispatch';
const EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION     = '2026-07-18';
const EXTRACHILL_BLOG_DISPATCH_CATEGORY_SLUG     = 'artist-dispatch';
const EXTRACHILL_BLOG_DISPATCH_MIN_BE_VERSION    = '3.6.0';
const EXTRACHILL_BLOG_DISPATCH_DEFAULT_DRAFT_MAX = 3;
const EXTRACHILL_BLOG_DISPATCH_DEFAULT_QUEUE_MAX = 1;
const EXTRACHILL_BLOG_DISPATCH_ROLE              = 'extra_chill_artist_dispatch_contributor';
const EXTRACHILL_BLOG_DISPATCH_CREATE_HEADER     = 'X-Extra-Chill-Artist-Dispatch';
const EXTRACHILL_BLOG_DISPATCH_PAGES_OPTION      = 'extrachill_blog_artist_dispatch_pages';

/** Feature-owned page definitions and exact stored sentinels. */
const EXTRACHILL_BLOG_DISPATCH_PAGES = array(
	'submit'            => array(
		'title'    => 'Artist Dispatch',
		'sentinel' => '<!-- extrachill-artist-dispatch -->',
	),
	'submit/guidelines' => array(
		'title'    => 'Artist Dispatch Guidelines',
		'sentinel' => '<!-- extrachill-artist-dispatch-guidelines -->',
	),
	'submit/write'      => array(
		'title'    => 'Write an Artist Dispatch',
		'sentinel' => '<!-- extrachill-artist-dispatch-editor -->',
	),
);

/**
 * Provision a native page without changing an existing page.
 *
 * @param string $path Path relative to the site root.
 * @param string $title Page title.
 * @param string $sentinel Stored content sentinel.
 * @param int    $parent_id Parent page ID.
 * @return int Page ID, or zero on failure.
 */
function extrachill_blog_provision_submit_page( $path, $title, $sentinel, $parent_id = 0 ) {
	$existing = get_page_by_path( $path, OBJECT, 'page' );
	if ( $existing ) {
		$owned = (array) get_option( EXTRACHILL_BLOG_DISPATCH_PAGES_OPTION, array() );
		return isset( $owned[ $path ] ) && (int) $owned[ $path ] === (int) $existing->ID && $sentinel === $existing->post_content
			? (int) $existing->ID
			: 0;
	}

	$page_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_name'    => basename( $path ),
			'post_content' => $sentinel,
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'post_parent'  => $parent_id,
		),
		true
	);

	if ( is_wp_error( $page_id ) || ! $page_id ) {
		return 0;
	}
	$owned          = (array) get_option( EXTRACHILL_BLOG_DISPATCH_PAGES_OPTION, array() );
	$owned[ $path ] = (int) $page_id;
	update_option( EXTRACHILL_BLOG_DISPATCH_PAGES_OPTION, $owned );
	return (int) $page_id;
}

/**
 * Provision /submit and its native child pages idempotently.
 */
function extrachill_blog_provision_submit_pages() {
	$submit_id = extrachill_blog_provision_submit_page(
		'submit',
		EXTRACHILL_BLOG_DISPATCH_PAGES['submit']['title'],
		EXTRACHILL_BLOG_DISPATCH_PAGES['submit']['sentinel']
	);
	if ( ! $submit_id ) {
		return false;
	}

	$guidelines_id = extrachill_blog_provision_submit_page(
		'submit/guidelines',
		EXTRACHILL_BLOG_DISPATCH_PAGES['submit/guidelines']['title'],
		EXTRACHILL_BLOG_DISPATCH_PAGES['submit/guidelines']['sentinel'],
		$submit_id
	);
	if ( ! $guidelines_id ) {
		return false;
	}
	$write_id = extrachill_blog_provision_submit_page(
		'submit/write',
		EXTRACHILL_BLOG_DISPATCH_PAGES['submit/write']['title'],
		EXTRACHILL_BLOG_DISPATCH_PAGES['submit/write']['sentinel'],
		$submit_id
	);
	return (bool) $write_id;
}

/**
 * Run page provisioning once per plugin version.
 */
function extrachill_blog_maybe_provision_submit_pages() {
	if ( version_compare( get_option( 'extrachill_blog_submit_page_version', '0' ), EXTRACHILL_BLOG_VERSION, '<' ) ) {
		if ( extrachill_blog_provision_submit_pages() ) {
			update_option( 'extrachill_blog_submit_page_version', EXTRACHILL_BLOG_VERSION );
		}
	}
}
add_action( 'admin_init', 'extrachill_blog_maybe_provision_submit_pages' );

/**
 * Read the exact Extra Chill Users self-access contract.
 *
 * The contract returns status, artist_id, and eligibility. Eligibility carries
 * criteria, reasons, and the operator policy. Missing data fails closed.
 *
 * @return array|WP_Error Access state.
 */
function extrachill_blog_dispatch_access() {
	if ( ! is_user_logged_in() || ! function_exists( 'wp_get_ability' ) ) {
		return new WP_Error( 'artist_dispatch_logged_out', __( 'Please log in to continue.', 'extrachill-blog' ) );
	}

	$ability = wp_get_ability( 'extrachill/get-artist-dispatch-access' );
	if ( ! $ability ) {
		$result = new WP_Error( 'artist_dispatch_dependency_missing', __( 'Artist Dispatch access is temporarily unavailable.', 'extrachill-blog' ) );
	} else {
		$result = $ability->execute( array() );
	}
	$result = apply_filters( 'extrachill_blog_artist_dispatch_access_state', $result );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ! is_array( $result ) || ! isset( $result['status'], $result['eligibility']['criteria'], $result['eligibility']['policy']['pilot_enabled'] ) ) {
		return new WP_Error( 'artist_dispatch_invalid_contract', __( 'Artist Dispatch access is temporarily unavailable.', 'extrachill-blog' ) );
	}

	return $result;
}

/**
 * Determine whether the current user has approved native write access.
 *
 * @param array|WP_Error|null $access Optional resolved access state.
 * @return bool Whether access is approved.
 */
function extrachill_blog_dispatch_is_approved( $access = null ) {
	$access = null === $access ? extrachill_blog_dispatch_access() : $access;
	return ! is_wp_error( $access )
		&& ! empty( $access['eligibility']['policy']['pilot_enabled'] )
		&& 'approved' === $access['status']
		&& current_user_can( 'edit_posts' )
		&& current_user_can( 'submit_for_review' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- registered by the required Extra Chill Users dependency.
}

/**
 * Confirm the audited terms acceptance returned by Extra Chill Users.
 *
 * @param array|WP_Error $access Access state.
 * @return bool Whether current terms were accepted in the audited request.
 */
function extrachill_blog_dispatch_has_current_terms( $access ) {
	return ! is_wp_error( $access )
		&& ! empty( $access['terms_acknowledged'] )
		&& isset( $access['terms_version'] )
		&& EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION === $access['terms_version'];
}

/**
 * Confirm an artist ID against the Users ability response.
 *
 * @param int        $artist_id Artist profile ID.
 * @param array|null $access Access state.
 * @return bool Whether the current user may represent the artist.
 */
function extrachill_blog_dispatch_can_represent_artist( $artist_id, $access = null ) {
	$access = null === $access ? extrachill_blog_dispatch_access() : $access;
	if ( is_wp_error( $access ) || ! extrachill_blog_dispatch_is_approved( $access ) ) {
		return false;
	}

	$artist_id = absint( $artist_id );
	if ( 0 >= $artist_id || absint( isset( $access['artist_id'] ) ? $access['artist_id'] : 0 ) !== $artist_id ) {
		return false;
	}

	$artist_ids = isset( $access['eligibility']['criteria']['claimed_artist']['artist_ids'] )
		? array_map( 'absint', (array) $access['eligibility']['criteria']['claimed_artist']['artist_ids'] )
		: array();
	return in_array( $artist_id, $artist_ids, true );
}

/**
 * Check the deployed Blocks Everywhere dependency.
 *
 * @return bool Whether the canonical editor API is available.
 */
function extrachill_blog_dispatch_has_editor_dependency() {
	return class_exists( '\\Automattic\\Blocks_Everywhere\\Blocks_Everywhere' )
		&& version_compare( \Automattic\Blocks_Everywhere\Blocks_Everywhere::VERSION, EXTRACHILL_BLOG_DISPATCH_MIN_BE_VERSION, '>=' );
}

/**
 * Test whether a post belongs to this intake pathway.
 *
 * @param int $post_id Post ID.
 * @return bool Whether this is an Artist Dispatch.
 */
function extrachill_blog_is_artist_dispatch( $post_id ) {
	return EXTRACHILL_BLOG_DISPATCH_SOURCE === get_post_meta( $post_id, EXTRACHILL_BLOG_DISPATCH_SOURCE_META, true );
}

/**
 * Whether a user is an editorial reviewer.
 *
 * @param int $user_id User ID, or current user when omitted.
 * @return bool Whether the user can edit others' posts.
 */
function extrachill_blog_dispatch_is_editor( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	return $user_id > 0 && user_can( $user_id, 'edit_others_posts' );
}

/**
 * Whether edit_posts authority comes only from the dedicated Dispatch role.
 *
 * Users with an unrelated Author-style role retain their ordinary post lane;
 * marked Dispatch writes still require current product approval separately.
 *
 * @param int $user_id User ID, or current user when omitted.
 * @return bool Whether the product grant is the sole editing role.
 */
function extrachill_blog_dispatch_is_product_only_user( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$user    = get_userdata( $user_id );
	if ( ! $user || ! user_can( $user_id, 'submit_for_review' ) || extrachill_blog_dispatch_is_editor( $user_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- registered by Extra Chill Users.
		return false;
	}
	foreach ( (array) $user->roles as $role_name ) {
		if ( EXTRACHILL_BLOG_DISPATCH_ROLE === $role_name ) {
			continue;
		}
		$role = get_role( $role_name );
		if ( $role && ! empty( $role->capabilities['edit_posts'] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Get active post limits.
 *
 * @return array{draft:int,pending:int} Limits.
 */
function extrachill_blog_dispatch_limits() {
	return array(
		'draft'   => max( 1, (int) apply_filters( 'extrachill_blog_artist_dispatch_draft_limit', EXTRACHILL_BLOG_DISPATCH_DEFAULT_DRAFT_MAX ) ),
		'pending' => max( 1, (int) apply_filters( 'extrachill_blog_artist_dispatch_pending_limit', EXTRACHILL_BLOG_DISPATCH_DEFAULT_QUEUE_MAX ) ),
	);
}

/**
 * Count the current user's marked posts in one status.
 *
 * @param string $status Post status.
 * @param int    $exclude Optional post ID to exclude.
 * @return int Count.
 */
function extrachill_blog_dispatch_count( $status, $exclude = 0 ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => $status,
			'author'         => get_current_user_id(),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post__not_in'   => $exclude ? array( $exclude ) : array(),
			'meta_key'       => EXTRACHILL_BLOG_DISPATCH_SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded author/status provenance query.
			'meta_value'     => EXTRACHILL_BLOG_DISPATCH_SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded author/status provenance query.
			'no_found_rows'  => false,
		)
	);
	return (int) $query->found_posts;
}

/** Get the server-owned provenance keys. */
function extrachill_blog_dispatch_provenance_keys() {
	return array(
		EXTRACHILL_BLOG_DISPATCH_SOURCE_META,
		EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META,
		EXTRACHILL_BLOG_DISPATCH_ARTIST_META,
		EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META,
		EXTRACHILL_BLOG_DISPATCH_TERMS_META,
	);
}

/**
 * Register provenance without exposing it through public REST responses.
 */
function extrachill_blog_dispatch_register_meta() {
	$fields = array(
		EXTRACHILL_BLOG_DISPATCH_SOURCE_META    => 'string',
		EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META => 'integer',
		EXTRACHILL_BLOG_DISPATCH_ARTIST_META    => 'integer',
		EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META => 'string',
		EXTRACHILL_BLOG_DISPATCH_TERMS_META     => 'string',
	);

	foreach ( $fields as $key => $type ) {
		register_post_meta(
			'post',
			$key,
			array(
				'single'        => true,
				'type'          => $type,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);
	}
}
add_action( 'init', 'extrachill_blog_dispatch_register_meta' );

/**
 * Block direct provenance mutation; only this plugin's scoped writer may set it.
 *
 * @param mixed  $check Existing short-circuit value.
 * @param int    $object_id Post ID.
 * @param string $meta_key Meta key.
 * @return mixed False for unauthorized provenance writes, original value otherwise.
 */
function extrachill_blog_dispatch_guard_provenance_meta( $check, $object_id, $meta_key ) {
	if ( ! in_array( $meta_key, extrachill_blog_dispatch_provenance_keys(), true ) ) {
		return $check;
	}
	$allowed = isset( $GLOBALS['extrachill_blog_dispatch_meta_write'] )
		&& (int) $GLOBALS['extrachill_blog_dispatch_meta_write'] === (int) $object_id;
	return $allowed ? $check : false;
}
add_filter( 'add_post_metadata', 'extrachill_blog_dispatch_guard_provenance_meta', 10, 3 );
add_filter( 'update_post_metadata', 'extrachill_blog_dispatch_guard_provenance_meta', 10, 3 );
add_filter( 'delete_post_metadata', 'extrachill_blog_dispatch_guard_provenance_meta', 10, 3 );

/**
 * Write one immutable provenance value from the trusted server path.
 *
 * @param int    $post_id Post ID.
 * @param string $key Provenance key.
 * @param mixed  $value Value.
 */
function extrachill_blog_dispatch_set_provenance( $post_id, $key, $value ) {
	if ( ! in_array( $key, extrachill_blog_dispatch_provenance_keys(), true ) ) {
		return;
	}
	$GLOBALS['extrachill_blog_dispatch_meta_write'] = (int) $post_id;
	update_post_meta( $post_id, $key, $value );
	unset( $GLOBALS['extrachill_blog_dispatch_meta_write'] );
}

/**
 * Detect any attempt to send server-owned provenance through REST.
 *
 * @param WP_REST_Request $request Request.
 * @return bool Whether provenance was supplied.
 */
function extrachill_blog_dispatch_request_has_provenance( $request ) {
	$meta = $request->get_param( 'meta' );
	if ( ! is_array( $meta ) ) {
		return false;
	}
	return (bool) array_intersect( array_keys( $meta ), extrachill_blog_dispatch_provenance_keys() );
}

/**
 * Normalize a core REST raw-field envelope to a string.
 *
 * @param mixed $value Raw string or {raw: string} envelope.
 * @return string Normalized raw value.
 */
function extrachill_blog_dispatch_raw_value( $value ) {
	if ( is_array( $value ) && array_key_exists( 'raw', $value ) ) {
		$value = $value['raw'];
	} elseif ( is_object( $value ) && isset( $value->raw ) ) {
		$value = $value->raw;
	}
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * Detect the core autosaves controller while it delegates to the posts controller.
 *
 * @param WP_REST_Request $request Request.
 * @return bool Whether this is a core autosave write.
 */
function extrachill_blog_dispatch_is_autosave_request( $request ) {
	if ( false !== strpos( $request->get_route(), '/autosaves' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return true;
	}
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- core controller delegation has no public request marker.
		if ( isset( $frame['class'] ) && WP_REST_Autosaves_Controller::class === $frame['class'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Acquire an atomic, short per-user operation lock.
 *
 * WordPress add_option() is an atomic database insert even without persistent object
 * cache, so parallel tabs cannot both pass count+write.
 *
 * @param string $operation Operation name.
 * @param int    $user_id User ID.
 * @return array|WP_Error Owned lock token or conflict.
 */
function extrachill_blog_dispatch_acquire_lock( $operation, $user_id ) {
	$key     = 'ec_dispatch_lock_' . sanitize_key( $operation ) . '_' . absint( $user_id );
	$lock    = array(
		'token'   => wp_generate_uuid4(),
		'expires' => time() + 15,
		'key'     => $key,
	);
	$current = get_option( $key, array() );
	if ( empty( $current ) && add_option( $key, $lock, '', false ) ) {
		$GLOBALS['extrachill_blog_dispatch_locks'][ $key ] = $lock;
		return $lock;
	}
	if ( is_array( $current ) && ! empty( $current['expires'] ) && (int) $current['expires'] < time() ) {
		global $wpdb;
		$owned = false;
		if ( isset( $wpdb->options ) ) {
			$owned = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $lock ), $key, maybe_serialize( $current ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic compare-and-swap on one owned lock option.
			wp_cache_delete( $key, 'options' );
		} elseif ( get_option( $key ) === $current ) {
			update_option( $key, $lock, false );
			$owned = true;
		}
		if ( $owned ) {
			$GLOBALS['extrachill_blog_dispatch_locks'][ $key ] = $lock;
			return $lock;
		}
	}
	return new WP_Error( 'artist_dispatch_write_in_progress', __( 'Another Artist Dispatch request is already in progress.', 'extrachill-blog' ), array( 'status' => 409 ) );
}

/**
 * Release one atomic operation lock.
 *
 * @param array $lock Owned lock token.
 */
function extrachill_blog_dispatch_release_lock( $lock ) {
	if ( ! is_array( $lock ) || empty( $lock['key'] ) || empty( $lock['token'] ) ) {
		return;
	}
	$key     = $lock['key'];
	$current = get_option( $key, array() );
	if ( ! is_array( $current ) || ! hash_equals( (string) $lock['token'], (string) ( $current['token'] ?? '' ) ) ) {
		unset( $GLOBALS['extrachill_blog_dispatch_locks'][ $key ] );
		return;
	}
	global $wpdb;
	if ( isset( $wpdb->options ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize( $current ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- owner-token compare-and-delete.
		wp_cache_delete( $key, 'options' );
	} elseif ( get_option( $key ) === $current ) {
		delete_option( $key );
	}
	unset( $GLOBALS['extrachill_blog_dispatch_locks'][ $key ] );
}

/** Release request locks if core exits before an after-insert hook. */
function extrachill_blog_dispatch_release_request_locks() {
	foreach ( isset( $GLOBALS['extrachill_blog_dispatch_locks'] ) ? (array) $GLOBALS['extrachill_blog_dispatch_locks'] : array() as $lock ) {
		extrachill_blog_dispatch_release_lock( $lock );
	}
}
add_action( 'shutdown', 'extrachill_blog_dispatch_release_request_locks' );

/**
 * Authorize exactly one low-level post insertion after REST validation.
 *
 * @param int   $post_id Existing post ID, or zero for create.
 * @param array $context Trusted context.
 */
function extrachill_blog_dispatch_authorize_insert( $post_id, array $context ) {
	$context['post_id']                                 = absint( $post_id );
	$context['consumed']                                = false;
	$GLOBALS['extrachill_blog_dispatch_insert_context'] = $context;
}

/**
 * Constrain direct wp_insert_post/wp_update_post use for product-only users.
 *
 * Validated core REST writes install a one-use authorization context. wp-admin,
 * direct PHP writes, and a second insertion in the same request fail closed.
 *
 * @param bool  $maybe_empty Core empty-content verdict.
 * @param array $postarr Post data.
 * @return bool Whether insertion must stop.
 */
function extrachill_blog_dispatch_gate_low_level_insert( $maybe_empty, $postarr ) {
	if ( 'post' !== ( isset( $postarr['post_type'] ) ? $postarr['post_type'] : 'post' ) ) {
		return $maybe_empty;
	}
	$post_id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
	$context = isset( $GLOBALS['extrachill_blog_dispatch_insert_context'] ) ? $GLOBALS['extrachill_blog_dispatch_insert_context'] : array();
	if ( $post_id && extrachill_blog_is_artist_dispatch( $post_id ) && ! extrachill_blog_dispatch_is_editor() ) {
		if ( empty( $context ) || ! empty( $context['consumed'] ) || (int) $context['post_id'] !== $post_id ) {
			return true;
		}
		$GLOBALS['extrachill_blog_dispatch_insert_context']['consumed'] = true;
		return false;
	}
	if ( ! extrachill_blog_dispatch_is_product_only_user() ) {
		return $maybe_empty;
	}
	if ( empty( $context ) || ! empty( $context['consumed'] ) || (int) $context['post_id'] !== $post_id ) {
		return true;
	}
	$GLOBALS['extrachill_blog_dispatch_insert_context']['consumed'] = true;
	return false;
}
add_filter( 'wp_insert_post_empty_content', 'extrachill_blog_dispatch_gate_low_level_insert', 10, 2 );

/**
 * Recursively validate the text-first block and embed policy.
 *
 * @param array $blocks Parsed blocks.
 * @return true|WP_Error Validation result.
 */
function extrachill_blog_dispatch_validate_blocks( array $blocks ) {
	$allowed = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/separator', 'core/embed' );
	$hosts   = array( 'youtube.com', 'www.youtube.com', 'youtu.be', 'vimeo.com', 'www.vimeo.com', 'soundcloud.com', 'www.soundcloud.com', 'open.spotify.com', 'bandcamp.com' );

	foreach ( $blocks as $block ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
		if ( null === $name && '' !== trim( isset( $block['innerHTML'] ) ? $block['innerHTML'] : '' ) ) {
			return new WP_Error( 'artist_dispatch_unstructured_content', __( 'Artist Dispatch content must use supported blocks.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
		if ( null !== $name && ! in_array( $name, $allowed, true ) ) {
			return new WP_Error( 'artist_dispatch_disallowed_block', __( 'This draft contains a block that Artist Dispatch does not support.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
		if ( 'core/embed' === $name ) {
			$url             = isset( $block['attrs']['url'] ) ? esc_url_raw( $block['attrs']['url'] ) : '';
			$host            = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$host_is_allowed = in_array( $host, $hosts, true ) || ( strlen( $host ) > 9 && '.bandcamp.com' === substr( $host, -13 ) );
			if ( ! $url || ! $host_is_allowed ) {
				return new WP_Error( 'artist_dispatch_disallowed_embed', __( 'That embed provider is not supported.', 'extrachill-blog' ), array( 'status' => 400 ) );
			}
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$valid = extrachill_blog_dispatch_validate_blocks( $block['innerBlocks'] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
	}

	return true;
}

/**
 * Enforce Artist Dispatch policy through the native posts controller.
 *
 * @param stdClass        $prepared_post Prepared post.
 * @param WP_REST_Request $request REST request.
 * @return stdClass|WP_Error Prepared post or error.
 */
function extrachill_blog_dispatch_rest_pre_insert( $prepared_post, $request ) {
	$post_id     = absint( $request->get_param( 'id' ) );
	$creating    = 0 === $post_id;
	$marked      = $post_id > 0 && extrachill_blog_is_artist_dispatch( $post_id );
	$is_editor   = extrachill_blog_dispatch_is_editor();
	$is_create   = $creating && 'create' === $request->get_header( EXTRACHILL_BLOG_DISPATCH_CREATE_HEADER );
	$is_autosave = extrachill_blog_dispatch_is_autosave_request( $request );
	$is_autosave = (bool) apply_filters( 'extrachill_blog_artist_dispatch_is_autosave_request', $is_autosave, $request );

	if ( extrachill_blog_dispatch_request_has_provenance( $request ) ) {
		return new WP_Error( 'artist_dispatch_server_owned_provenance', __( 'Artist Dispatch provenance is server-owned.', 'extrachill-blog' ), array( 'status' => 400 ) );
	}
	if ( $creating && ! $is_create ) {
		return extrachill_blog_dispatch_is_product_only_user()
			? new WP_Error( 'artist_dispatch_creation_required', __( 'Use the Artist Dispatch dashboard to create a post.', 'extrachill-blog' ), array( 'status' => 403 ) )
			: $prepared_post;
	}
	if ( ! $creating && ! $marked ) {
		return extrachill_blog_dispatch_is_product_only_user()
			? new WP_Error( 'artist_dispatch_unmarked_forbidden', __( 'This account can edit only validated Artist Dispatches.', 'extrachill-blog' ), array( 'status' => 403 ) )
			: $prepared_post;
	}
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'artist_dispatch_forbidden', __( 'You cannot edit Artist Dispatches.', 'extrachill-blog' ), array( 'status' => 403 ) );
	}

	if ( $is_create ) {
		$access = extrachill_blog_dispatch_access();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$artist_id = absint( isset( $access['artist_id'] ) ? $access['artist_id'] : 0 );
		if ( $is_editor || ! extrachill_blog_dispatch_is_approved( $access ) || ! extrachill_blog_dispatch_has_current_terms( $access ) || ! extrachill_blog_dispatch_can_represent_artist( $artist_id, $access ) ) {
			return new WP_Error( 'artist_dispatch_invalid_access', __( 'Approved access, a represented artist, and current audited terms are required.', 'extrachill-blog' ), array( 'status' => 403 ) );
		}
		$lock = extrachill_blog_dispatch_acquire_lock( 'create', get_current_user_id() );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		if ( extrachill_blog_dispatch_count( 'draft' ) >= extrachill_blog_dispatch_limits()['draft'] ) {
			extrachill_blog_dispatch_release_lock( $lock );
			return new WP_Error( 'artist_dispatch_draft_limit', __( 'Finish or submit an existing draft before starting another.', 'extrachill-blog' ), array( 'status' => 429 ) );
		}
		$prepared_post->post_author = get_current_user_id();
		$prepared_post->post_status = 'draft';
		extrachill_blog_dispatch_authorize_insert(
			0,
			array(
				'creating'  => true,
				'artist_id' => $artist_id,
				'terms'     => $access['terms_version'],
				'lock'      => $lock,
			)
		);
		return $prepared_post;
	}

	if ( $is_editor ) {
		// Affiliation is required when the contributor enters pending review.
		// Editors own the final immediate or scheduled publication decision.
		return $prepared_post;
	}
	$access = extrachill_blog_dispatch_access();
	$post   = get_post( $post_id );
	if ( ! extrachill_blog_dispatch_is_approved( $access ) || ! extrachill_blog_dispatch_has_current_terms( $access ) || ! $post || get_current_user_id() !== (int) $post->post_author || 'draft' !== $post->post_status ) {
		return new WP_Error( 'artist_dispatch_locked', __( 'Only a currently approved submitter can edit their draft Artist Dispatch.', 'extrachill-blog' ), array( 'status' => 403 ) );
	}
	if ( ! extrachill_blog_dispatch_can_represent_artist( get_post_meta( $post_id, EXTRACHILL_BLOG_DISPATCH_ARTIST_META, true ), $access ) ) {
		return new WP_Error( 'artist_dispatch_artist_invalid', __( 'The represented artist relationship is no longer valid.', 'extrachill-blog' ), array( 'status' => 403 ) );
	}
	$status = isset( $prepared_post->post_status ) ? $prepared_post->post_status : $post->post_status;
	if ( ! in_array( $status, array( 'draft', 'pending' ), true ) ) {
		return new WP_Error( 'artist_dispatch_invalid_status', __( 'Artist Dispatches can only be saved or submitted for review.', 'extrachill-blog' ), array( 'status' => 403 ) );
	}
	if ( $is_autosave ) {
		$allowed_autosave = array( 'id', 'title', 'content', 'excerpt', 'meta', 'context', '_locale' );
		foreach ( array_keys( array_merge( $request->get_params(), $request->get_body_params() ) ) as $field ) {
			if ( ! in_array( $field, $allowed_autosave, true ) ) {
				return new WP_Error( 'artist_dispatch_forbidden_autosave_field', __( 'That field cannot be changed by an Artist Dispatch autosave.', 'extrachill-blog' ), array( 'status' => 400 ) );
			}
		}
	} else {
		foreach ( array( 'author', 'featured_media', 'slug', 'date', 'date_gmt', 'categories', 'tags', 'template', 'format', 'password', 'sticky' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				return new WP_Error( 'artist_dispatch_forbidden_field', __( 'That post field is managed by the editorial team.', 'extrachill-blog' ), array( 'status' => 400 ) );
			}
		}
	}
	$effective_content = $request->has_param( 'content' )
		? extrachill_blog_dispatch_raw_value( $request->get_param( 'content' ) )
		: $post->post_content;
	$valid             = extrachill_blog_dispatch_validate_blocks( parse_blocks( $effective_content ) );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$lock = '';
	if ( 'pending' === $status ) {
		$lock = extrachill_blog_dispatch_acquire_lock( 'pending', get_current_user_id() );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		if ( extrachill_blog_dispatch_count( 'pending', $post_id ) >= extrachill_blog_dispatch_limits()['pending'] ) {
			extrachill_blog_dispatch_release_lock( $lock );
			return new WP_Error( 'artist_dispatch_pending_limit', __( 'Wait for an editor to review an existing submission before sending another.', 'extrachill-blog' ), array( 'status' => 429 ) );
		}
	}
	extrachill_blog_dispatch_authorize_insert(
		$post_id,
		array(
			'creating' => false,
			'lock'     => $lock,
		)
	);
	return $prepared_post;
}
add_filter( 'rest_pre_insert_post', 'extrachill_blog_dispatch_rest_pre_insert', 10, 2 );

/**
 * Reject protected autosave fields before the parent posts schema can act on them.
 *
 * The autosaves controller delegates preparation to the posts controller, whose
 * broader schema includes fields that must never reach an unlocked author draft.
 * Content and access policy still run through rest_pre_insert_post.
 *
 * @param mixed           $result Pre-dispatch result.
 * @param WP_REST_Server  $server REST server.
 * @param WP_REST_Request $request Request.
 * @return mixed|WP_Error Original result or field error.
 */
function extrachill_blog_dispatch_guard_autosave_fields( $result, $server, $request ) {
	unset( $server );
	if ( null !== $result || ! preg_match( '#^/wp/v2/posts/(?P<id>\d+)/autosaves$#', $request->get_route(), $matches ) || ! extrachill_blog_is_artist_dispatch( absint( $matches['id'] ) ) ) {
		return $result;
	}
	$allowed = array( 'id', 'title', 'content', 'excerpt', 'meta', 'context', '_locale' );
	foreach ( array_keys( array_merge( $request->get_params(), $request->get_body_params() ) ) as $field ) {
		if ( ! in_array( $field, $allowed, true ) ) {
			return new WP_Error( 'artist_dispatch_forbidden_autosave_field', __( 'That field cannot be changed by an Artist Dispatch autosave.', 'extrachill-blog' ), array( 'status' => 400 ) );
		}
	}
	return $result;
}
add_filter( 'rest_pre_dispatch', 'extrachill_blog_dispatch_guard_autosave_fields', 10, 3 );

/**
 * Stamp trusted provenance after native draft creation.
 *
 * @param WP_Post         $post Inserted post.
 * @param WP_REST_Request $request REST request.
 * @param bool            $creating Whether the post was created.
 */
function extrachill_blog_dispatch_rest_after_insert( $post, $request, $creating ) {
	unset( $request );
	$context = isset( $GLOBALS['extrachill_blog_dispatch_insert_context'] ) ? $GLOBALS['extrachill_blog_dispatch_insert_context'] : array();
	if ( $creating && ! empty( $context['creating'] ) && ! empty( $context['consumed'] ) ) {
		extrachill_blog_dispatch_set_provenance( $post->ID, EXTRACHILL_BLOG_DISPATCH_SOURCE_META, EXTRACHILL_BLOG_DISPATCH_SOURCE );
		extrachill_blog_dispatch_set_provenance( $post->ID, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, get_current_user_id() );
		extrachill_blog_dispatch_set_provenance( $post->ID, EXTRACHILL_BLOG_DISPATCH_ARTIST_META, absint( $context['artist_id'] ) );
		extrachill_blog_dispatch_set_provenance( $post->ID, EXTRACHILL_BLOG_DISPATCH_TERMS_META, sanitize_text_field( $context['terms'] ) );
		extrachill_blog_dispatch_ensure_category( $post->ID );
		extrachill_blog_dispatch_emit_event( 'draft_created', $post->ID );
	}
	if ( ! empty( $context['lock'] ) ) {
		extrachill_blog_dispatch_release_lock( $context['lock'] );
	}
	unset( $GLOBALS['extrachill_blog_dispatch_insert_context'] );
}
add_action( 'rest_after_insert_post', 'extrachill_blog_dispatch_rest_after_insert', 10, 3 );

/**
 * Lock contributor access to pending and published Dispatches in native caps.
 *
 * @param string[] $caps Primitive capabilities.
 * @param string   $cap Requested capability.
 * @param int      $user_id User ID.
 * @param array    $args Capability arguments.
 * @return string[] Filtered capabilities.
 */
function extrachill_blog_dispatch_map_meta_cap( $caps, $cap, $user_id, $args ) {
	if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'read_post' ), true ) || empty( $args[0] ) ) {
		return $caps;
	}
	$post = get_post( absint( $args[0] ) );
	if ( ! $post || ! extrachill_blog_is_artist_dispatch( $post->ID ) || extrachill_blog_dispatch_is_editor( $user_id ) ) {
		return $caps;
	}
	$submitter = absint( get_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, true ) );
	if ( 'read_post' === $cap ) {
		return 'publish' === $post->post_status || $submitter === (int) $user_id ? $caps : array( 'do_not_allow' );
	}
	if ( $submitter !== (int) $user_id || 'draft' !== $post->post_status ) {
		return array( 'do_not_allow' );
	}
	if ( get_current_user_id() !== (int) $user_id || ! extrachill_blog_dispatch_is_approved() ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}
add_filter( 'map_meta_cap', 'extrachill_blog_dispatch_map_meta_cap', 10, 4 );

/**
 * Stamp the first review time and lifecycle analytics.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post Post.
 */
function extrachill_blog_dispatch_transition( $new_status, $old_status, $post ) {
	if ( ! $post instanceof WP_Post || ! extrachill_blog_is_artist_dispatch( $post->ID ) || $new_status === $old_status || get_post_status( $post->ID ) !== $new_status ) {
		return;
	}
	if ( 'pending' === $new_status && 'draft' === $old_status ) {
		if ( ! get_post_meta( $post->ID, EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META, true ) ) {
			extrachill_blog_dispatch_set_provenance( $post->ID, EXTRACHILL_BLOG_DISPATCH_SUBMITTED_META, current_time( 'mysql', true ) );
		}
		extrachill_blog_dispatch_emit_event( 'submitted', $post->ID );
	}
	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		extrachill_blog_dispatch_ensure_category( $post->ID );
		extrachill_blog_dispatch_emit_event( 'published', $post->ID );
	}
}
add_action( 'transition_post_status', 'extrachill_blog_dispatch_transition', 10, 3 );

/**
 * Resolve lifecycle event names at one replaceable analytics boundary.
 *
 * @param string $event Lifecycle key.
 * @return string Event name.
 */
function extrachill_blog_dispatch_event_name( $event ) {
	$events = array(
		'draft_created' => defined( 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_DRAFT_CREATED' ) ? EC_ANALYTICS_EVENT_ARTIST_DISPATCH_DRAFT_CREATED : '',
		'submitted'     => defined( 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_SUBMITTED' ) ? EC_ANALYTICS_EVENT_ARTIST_DISPATCH_SUBMITTED : '',
		'published'     => defined( 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_PUBLISHED' ) ? EC_ANALYTICS_EVENT_ARTIST_DISPATCH_PUBLISHED : '',
	);
	return isset( $events[ $event ] ) ? $events[ $event ] : '';
}

/**
 * Emit a bounded lifecycle event through the analytics ability.
 *
 * @param string $event Lifecycle key.
 * @param int    $post_id Optional post ID.
 */
function extrachill_blog_dispatch_emit_event( $event, $post_id = 0 ) {
	$name    = extrachill_blog_dispatch_event_name( $event );
	$ability = $name && function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/track-analytics-event' ) : null;
	if ( ! $ability ) {
		return;
	}
	$data = array(
		'post_id'           => absint( $post_id ),
		'submitter_user_id' => absint( get_post_meta( $post_id, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, true ) ),
		'artist_id'         => absint( get_post_meta( $post_id, EXTRACHILL_BLOG_DISPATCH_ARTIST_META, true ) ),
	);
	$ability->execute(
		array(
			'event_type' => $name,
			'event_data' => $data,
		)
	);
}

/**
 * Ensure the native Artist Dispatch category is assigned.
 *
 * @param int $post_id Post ID.
 */
function extrachill_blog_dispatch_ensure_category( $post_id ) {
	$term = get_term_by( 'slug', EXTRACHILL_BLOG_DISPATCH_CATEGORY_SLUG, 'category' );
	if ( ! $term ) {
		$created = wp_insert_term( __( 'Artist Dispatch', 'extrachill-blog' ), 'category', array( 'slug' => EXTRACHILL_BLOG_DISPATCH_CATEGORY_SLUG ) );
		if ( is_wp_error( $created ) ) {
			return;
		}
		$term_id = (int) $created['term_id'];
	} else {
		$term_id = (int) $term->term_id;
	}
	wp_set_post_categories( $post_id, array( $term_id ), true );
}

/**
 * Register the generic publication notification descriptor.
 */
function extrachill_blog_dispatch_register_notification() {
	if ( function_exists( 'ec_users_register_publish_notify_source' ) ) {
		ec_users_register_publish_notify_source(
			'artist-dispatch',
			array(
				'meta_key'       => EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- notification descriptor, not a query.
				'user_id_field'  => '',
				'type'           => 'artist_dispatch_published',
				/* translators: %s: published post title. */
				'title_template' => __( 'Your Artist Dispatch “%s” is live', 'extrachill-blog' ),
			)
		);
	}
}
add_action( 'init', 'extrachill_blog_dispatch_register_notification', 20 );

require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/presentation.php';
