<?php
/**
 * Link Page storage registration.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

defined( 'EC_LINK_PAGE_STORAGE_BLOG_OPTION' ) || define( 'EC_LINK_PAGE_STORAGE_BLOG_OPTION', 'ec_link_page_storage_blog_id' );

/** Validate a candidate canonical storage site. */
function ec_validate_link_page_storage_blog_id( $blog_id ) {
	$blog_id = max( 0, (int) $blog_id );
	$site    = $blog_id ? get_site( $blog_id ) : null;
	return $site && empty( $site->deleted ) && empty( $site->archived ) && empty( $site->spam ) ? $blog_id : 0;
}

/** Return the one canonical network blog that stores Link Pages. */
function ec_get_link_page_storage_blog_id() {
	$multisite = function_exists( 'is_multisite' ) && is_multisite();
	if ( ! $multisite ) {
		return ec_validate_link_page_storage_blog_id( (int) get_current_blog_id() );
	}
	if ( defined( 'EC_LINK_PAGE_STORAGE_BLOG_ID' ) ) {
		$explicit = ec_validate_link_page_storage_blog_id( (int) EC_LINK_PAGE_STORAGE_BLOG_ID );
		if ( $explicit ) {
			return $explicit;
		}
	}
	if ( function_exists( 'has_filter' ) && has_filter( 'ec_link_page_storage_blog_id' ) ) {
		$explicit = ec_validate_link_page_storage_blog_id( (int) apply_filters( 'ec_link_page_storage_blog_id', 0 ) );
		if ( $explicit ) {
			return $explicit;
		}
	}
	return ec_validate_link_page_storage_blog_id( (int) get_site_option( EC_LINK_PAGE_STORAGE_BLOG_OPTION, 0 ) );
}

/** Execute a storage callback on the canonical blog and restore the caller. */
function ec_with_link_page_storage_blog( $callback ) {
	if ( ! is_callable( $callback ) ) {
		return new WP_Error( 'invalid_link_page_storage_callback', 'The Link Page storage callback is invalid.' );
	}
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	$entry_blog_id = get_current_blog_id();
	$stack         = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched      = ! empty( $GLOBALS['switched'] );
	$result        = null;
	try {
		if ( $entry_blog_id !== $storage_blog_id && ( ! switch_to_blog( $storage_blog_id ) || get_current_blog_id() !== $storage_blog_id ) ) {
			$result = new WP_Error( 'link_page_storage_switch_failed', 'The canonical Link Page storage blog could not be entered.' );
		} else {
			$result = call_user_func( $callback, $storage_blog_id );
		}
	} catch ( Throwable $throwable ) {
		$result = new WP_Error( 'link_page_storage_callback_failed', 'The canonical Link Page storage operation failed.' );
	} finally {
		$restored = ec_restore_link_pages_site_context( $entry_blog_id, $stack, $switched );
	}
	return $restored ? $result : new WP_Error( 'link_page_storage_context_leak', 'The canonical Link Page storage context could not be restored.' );
}

/**
 * Register the existing Link Page storage type without changing its contract.
 *
 * @return void
 */
function ec_register_link_page_post_type() {
	if ( post_type_exists( EC_LINK_PAGE_POST_TYPE ) ) {
		return;
	}

	$labels = array(
		'name'                  => _x( 'Link Pages', 'Post Type General Name', 'extrachill-link-pages' ),
		'singular_name'         => _x( 'Link Page', 'Post Type Singular Name', 'extrachill-link-pages' ),
		'menu_name'             => __( 'Link Pages', 'extrachill-link-pages' ),
		'name_admin_bar'        => __( 'Link Page', 'extrachill-link-pages' ),
		'archives'              => __( 'Link Page Archives', 'extrachill-link-pages' ),
		'attributes'            => __( 'Link Page Attributes', 'extrachill-link-pages' ),
		'parent_item_colon'     => __( 'Parent Link Page:', 'extrachill-link-pages' ),
		'all_items'             => __( 'All Link Pages', 'extrachill-link-pages' ),
		'add_new_item'          => __( 'Add New Link Page', 'extrachill-link-pages' ),
		'add_new'               => __( 'Add New', 'extrachill-link-pages' ),
		'new_item'              => __( 'New Link Page', 'extrachill-link-pages' ),
		'edit_item'             => __( 'Edit Link Page', 'extrachill-link-pages' ),
		'update_item'           => __( 'Update Link Page', 'extrachill-link-pages' ),
		'view_item'             => __( 'View Link Page', 'extrachill-link-pages' ),
		'view_items'            => __( 'View Link Pages', 'extrachill-link-pages' ),
		'search_items'          => __( 'Search Link Page', 'extrachill-link-pages' ),
		'not_found'             => __( 'Not found', 'extrachill-link-pages' ),
		'not_found_in_trash'    => __( 'Not found in Trash', 'extrachill-link-pages' ),
		'featured_image'        => __( 'Featured Image', 'extrachill-link-pages' ),
		'set_featured_image'    => __( 'Set featured image', 'extrachill-link-pages' ),
		'remove_featured_image' => __( 'Remove featured image', 'extrachill-link-pages' ),
		'use_featured_image'    => __( 'Use as featured image', 'extrachill-link-pages' ),
		'insert_into_item'      => __( 'Insert into link page', 'extrachill-link-pages' ),
		'uploaded_to_this_item' => __( 'Uploaded to this link page', 'extrachill-link-pages' ),
		'items_list'            => __( 'Link Pages list', 'extrachill-link-pages' ),
		'items_list_navigation' => __( 'Link Pages list navigation', 'extrachill-link-pages' ),
		'filter_items_list'     => __( 'Filter link pages list', 'extrachill-link-pages' ),
	);

	$registered = register_post_type(
		EC_LINK_PAGE_POST_TYPE,
		array(
			'label'               => __( 'Link Page', 'extrachill-link-pages' ),
			'description'         => __( 'Custom Post Type for Link Pages', 'extrachill-link-pages' ),
			'labels'              => $labels,
			'supports'            => array( 'title', 'custom-fields', 'author' ),
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 6,
			'menu_icon'           => 'dashicons-admin-links',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'rewrite'             => array( 'slug' => 'link-page' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'show_in_rest'        => true,
		)
	);
	if ( ! is_wp_error( $registered ) && post_type_exists( EC_LINK_PAGE_POST_TYPE ) ) {
		$GLOBALS['ec_link_pages_owns_post_type'] = true;
	}
}

/**
 * Register storage during normal requests only when the runtime is valid.
 *
 * @return true|WP_Error
 */
function ec_register_link_page_post_type_if_ready() {
	$valid = ec_validate_link_pages_runtime();
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	if ( get_current_blog_id() !== ec_get_link_page_storage_blog_id() ) {
		return true;
	}
	ec_register_link_page_post_type();
	return true;
}

/**
 * Restore an exact multisite context snapshot.
 *
 * @param int   $blog_id  Entry blog ID.
 * @param array $stack    Entry switch stack.
 * @param bool  $switched Entry switched state.
 * @return bool
 */
function ec_restore_link_pages_site_context( $blog_id, $stack, $switched ) {
	$current_stack = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$attempts      = 0;
	$current_depth = count( $current_stack );
	$target_depth  = count( $stack );
	while ( $current_depth > $target_depth && $attempts < 100 ) {
		restore_current_blog();
		$current_stack = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
		$current_depth = count( $current_stack );
		++$attempts;
	}
	if ( get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
	}
	$GLOBALS['_wp_switched_stack'] = $stack;
	$GLOBALS['switched']           = $switched;
	return get_current_blog_id() === $blog_id && $GLOBALS['_wp_switched_stack'] === $stack && (bool) $GLOBALS['switched'] === $switched;
}

/**
 * Execute one callback in a site context and restore the caller exactly.
 *
 * @param int      $site_id  Site ID.
 * @param callable $callback Site callback.
 * @return true|WP_Error
 */
function ec_invoke_link_pages_site_callback( $site_id, $callback ) {
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	$result   = null;
	try {
		if ( $blog_id !== (int) $site_id && ( ! switch_to_blog( $site_id ) || get_current_blog_id() !== (int) $site_id ) ) {
			$result = new WP_Error( 'ec_link_pages_site_switch_failed', 'The Link Pages lifecycle could not enter a site context.', array( 'site_id' => (int) $site_id ) );
		} else {
			$result = call_user_func( $callback, (int) $site_id );
		}
	} catch ( Throwable $throwable ) {
		$result = new WP_Error( 'ec_link_pages_site_callback_failed', 'The Link Pages lifecycle failed in a site context.', array( 'site_id' => (int) $site_id ) );
	} finally {
		$restored = ec_restore_link_pages_site_context( $blog_id, $stack, $switched );
	}
	if ( ! $restored ) {
		return new WP_Error( 'ec_link_pages_site_context_leak', 'The Link Pages lifecycle could not restore its multisite context.', array( 'site_id' => (int) $site_id ) );
	}
	return is_wp_error( $result ) ? $result : true;
}

/**
 * Iterate site IDs in bounded pages.
 *
 * @param callable $callback Site callback.
 * @return true|WP_Error
 */
function ec_for_each_link_pages_site( $callback ) {
	$offset = 0;
	$limit  = 100;
	do {
		try {
			$site_ids = get_sites(
				array(
					'fields'        => 'ids',
					'number'        => $limit,
					'offset'        => $offset,
					'orderby'       => 'id',
					'order'         => 'ASC',
					'no_found_rows' => true,
				)
			);
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'ec_link_pages_site_query_failed', 'The Link Pages lifecycle could not enumerate network sites.' );
		}
		if ( ! is_array( $site_ids ) ) {
			return new WP_Error( 'ec_link_pages_site_query_failed', 'The Link Pages lifecycle received an invalid network site query result.' );
		}
		foreach ( $site_ids as $site_id ) {
			$result = ec_invoke_link_pages_site_callback( (int) $site_id, $callback );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$page_count = count( $site_ids );
		$offset    += $page_count;
	} while ( $page_count === $limit );
	return true;
}

/** Register storage and flush one site's rewrite rules. */
function ec_flush_link_pages_site() {
	if ( function_exists( 'did_action' ) && ! did_action( 'wp_loaded' ) ) {
		return new WP_Error( 'ec_link_pages_rewrite_flush_too_early', 'The Link Pages rewrite flush was requested before WordPress finished loading.' );
	}
	ec_register_link_page_post_type();
	if ( ! post_type_exists( EC_LINK_PAGE_POST_TYPE ) ) {
		return new WP_Error( 'ec_link_pages_post_type_registration_failed', 'The Link Page storage type could not be registered.' );
	}
	$result = flush_rewrite_rules();
	if ( function_exists( 'ec_schedule_link_page_expiration_cleanup' ) ) {
		ec_schedule_link_page_expiration_cleanup();
	}
	return is_wp_error( $result ) ? $result : true;
}

/**
 * Execute activation work without terminating the request.
 *
 * @param bool $network_wide Whether activation is network-wide.
 * @return true|WP_Error
 */
function ec_prepare_link_pages_activation( $network_wide = false ) {
	$valid = ec_validate_link_pages_runtime();
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( $network_wide ? 'link_page_network_storage_unconfigured' : 'link_page_storage_unavailable', $network_wide ? 'Network activation requires an explicit or previously persisted canonical Link Page storage blog.' : 'The canonical Link Page storage blog is unavailable.' );
	}
	$result = ec_invoke_link_pages_site_callback( $storage_blog_id, 'ec_flush_link_pages_site' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ! update_site_option( EC_LINK_PAGE_STORAGE_BLOG_OPTION, $storage_blog_id ) && (int) get_site_option( EC_LINK_PAGE_STORAGE_BLOG_OPTION, 0 ) !== $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_configuration_failed', 'The canonical Link Page storage configuration could not be persisted.' );
	}
	return true;
}

/**
 * Make rewrite rules aware of the storage type on activation.
 *
 * @param bool $network_wide Whether activation is network-wide.
 * @return void
 */
function ec_activate_link_pages( $network_wide = false ) {
	$result = ec_prepare_link_pages_activation( $network_wide );
	if ( is_wp_error( $result ) ) {
		ec_record_link_pages_runtime_error( $result );
		wp_die( esc_html( $result->get_error_message() ) );
	}
}

/**
 * Flush rewrite rules after deactivation on every affected site.
 *
 * @param bool $network_wide Whether deactivation is network-wide.
 * @return void
 */
function ec_deactivate_link_pages( $network_wide = false ) {
	unset( $network_wide );
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	$result          = $storage_blog_id ? ec_invoke_link_pages_site_callback( $storage_blog_id, 'ec_unregister_and_flush_link_pages_site' ) : new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	if ( is_wp_error( $result ) ) {
		ec_record_link_pages_runtime_error( $result );
	}
}

/*
 * Deactivation deliberately preserves EC_LINK_PAGE_STORAGE_BLOG_OPTION. The
 * rolling fallback may still own records on that site, and a later standalone
 * activation must rediscover the same canonical storage rather than fork it.
 */

/** Remove standalone-owned storage rewrites and flush the current site. */
function ec_unregister_and_flush_link_pages_site() {
	if ( function_exists( 'did_action' ) && ! did_action( 'wp_loaded' ) ) {
		return new WP_Error( 'ec_link_pages_rewrite_flush_too_early', 'The Link Pages rewrite flush was requested before WordPress finished loading.' );
	}
	if ( ! empty( $GLOBALS['ec_link_pages_owns_post_type'] ) && post_type_exists( EC_LINK_PAGE_POST_TYPE ) ) {
		$result = unregister_post_type( EC_LINK_PAGE_POST_TYPE );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'ec_link_pages_post_type_unregistration_failed', 'The Link Page storage type could not be unregistered.' );
		}
		$GLOBALS['ec_link_pages_owns_post_type'] = false;
	}
	if ( post_type_exists( EC_LINK_PAGE_POST_TYPE ) && ! empty( $GLOBALS['ec_link_pages_owns_post_type'] ) ) {
		return new WP_Error( 'ec_link_pages_post_type_unregistration_failed', 'The Link Page storage type remained registered after deactivation.' );
	}
	if ( function_exists( 'ec_unschedule_link_page_expiration_cleanup' ) ) {
		ec_unschedule_link_page_expiration_cleanup();
	}
	$result = flush_rewrite_rules();
	return is_wp_error( $result ) ? $result : true;
}

/** Return whether this exact plugin basename is network-active. */
function ec_link_pages_is_network_active() {
	$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
	return isset( $plugins[ EXTRACHILL_LINK_PAGES_PLUGIN_BASENAME ] );
}

/**
 * Establish rewrite rules after WordPress finishes initializing a new site.
 *
 * @param WP_Site $new_site New site.
 * @param array   $args     Initialization arguments.
 * @return void
 */
function ec_initialize_link_pages_site( $new_site, $args = array() ) {
	unset( $args );
	if ( ! ec_link_pages_is_network_active() ) {
		return;
	}
	$site_id = isset( $new_site->blog_id ) ? (int) $new_site->blog_id : (int) $new_site->id;
	if ( ec_get_link_page_storage_blog_id() !== $site_id ) {
		return;
	}
	if ( function_exists( 'did_action' ) && ! did_action( 'wp_loaded' ) ) {
		$GLOBALS['ec_link_pages_queued_site_flushes'][ $site_id ] = $site_id;
		return;
	}
	$result = ec_invoke_link_pages_site_callback( $site_id, 'ec_flush_link_pages_site' );
	if ( is_wp_error( $result ) ) {
		ec_record_link_pages_runtime_error( $result );
	}
}

/** Flush new sites that were initialized before WordPress finished loading. */
function ec_flush_queued_link_pages_sites() {
	$site_ids = array_values( $GLOBALS['ec_link_pages_queued_site_flushes'] ?? array() );
	unset( $GLOBALS['ec_link_pages_queued_site_flushes'] );
	foreach ( $site_ids as $site_id ) {
		$result = ec_invoke_link_pages_site_callback( $site_id, 'ec_flush_link_pages_site' );
		if ( is_wp_error( $result ) ) {
			ec_record_link_pages_runtime_error( $result );
			return $result;
		}
	}
	return true;
}
