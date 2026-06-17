<?php
/**
 * Team Member WP Role
 *
 * Phase 1 of Extra-Chill/extrachill-users#45.
 *
 * The `extra_chill_team` role IS the source of truth for team
 * membership. It is registered on every site in the network and
 * assigned directly (via add_role/remove_role on the team-management
 * UI in extrachill-admin-tools); there is no derivation, no
 * synchronization, no parallel state.
 *
 * Why a role and not a function-based gate:
 *   Native WP code paths (Gutenberg, REST permission callbacks, the
 *   media library, wp_handle_upload, third-party plugins) all check
 *   capabilities. A custom function check is invisible to them. By
 *   granting team members real WP capabilities via this role, every
 *   cap-aware surface automatically respects team membership without
 *   plugin-specific glue.
 *
 * Migration from the legacy meta system:
 *   Earlier versions of extrachill-users stored team membership in
 *   the `extrachill_team` + `extrachill_team_manual_override` user
 *   meta keys, with a derivation step computing effective status from
 *   those two values. That entire parallel state layer is retired.
 *   ec_users_migrate_team_meta_to_role() runs once at activation /
 *   first request after a version bump, converts the meta into role
 *   assignments, then deletes the meta keys. Idempotent on re-run
 *   because the second pass finds no meta to migrate.
 *
 * @package ExtraChill\Users
 * @since 0.11.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Role slug for the Extra Chill team WP role.
 */
const EC_USERS_TEAM_ROLE = 'extra_chill_team';

/**
 * Returns the capability set granted by the team role.
 *
 * Split into two groups:
 *
 *   1. Standard WP caps the team needs everywhere. These are the caps
 *      Gutenberg / REST / media library look for. Granting them via the
 *      role is what closes the silent-500 gap.
 *
 *   2. Custom EC team-only capabilities. These are the WP-native
 *      replacement for function-based access checks. Each one names a
 *      specific team-only surface (Studio, Roadie chat, Transcribe
 *      tokens, Events admin, the wp-admin admin bar).
 *
 * Explicitly NOT granted: manage_options, delete_others_posts,
 * edit_others_posts, edit_users, plugin/theme management. The team role
 * is for music journalists, not site administrators. Team members work
 * on their OWN content only — they can draft and edit their own posts
 * (published or not) but cannot edit other authors' posts. Editing
 * others' work is an editor/administrator concern.
 *
 * @return array<string,bool> Capability map suitable for add_role().
 */
function ec_users_get_team_role_caps() {
	return array(
		// --- Standard WP caps ---
		// Scoped to the member's OWN posts: edit_posts (own drafts) and
		// edit_published_posts (own published work). edit_others_posts is
		// deliberately NOT granted so team members cannot edit other
		// authors' content.
		'read'                 => true,
		'upload_files'         => true,
		'edit_posts'           => true,
		'edit_published_posts' => true,
		'delete_posts'         => true,

		// --- Custom EC team-only caps ---
		'access_studio'        => true,
		'access_roadie'        => true,
		'access_transcribe'    => true,
		'access_events_admin'  => true,
		'access_admin_bar'     => true,
		'submit_for_review'    => true,
	);
}

/**
 * Returns the human-readable label for the team role.
 *
 * @return string
 */
function ec_users_get_team_role_label() {
	return __( 'Extra Chill Team', 'extrachill-users' );
}

/**
 * Register the team role on the current site.
 *
 * Safe to call repeatedly: when the role already exists with the
 * current cap set the call is a single get_role() + array compare
 * (~2µs). When the cap set has drifted (e.g. a deploy added a new
 * capability), the role is removed and re-added with the fresh set.
 *
 * @return void
 */
function ec_users_register_team_role() {
	$caps = ec_users_get_team_role_caps();

	$existing = get_role( EC_USERS_TEAM_ROLE );
	if ( $existing instanceof WP_Role ) {
		$existing_caps = array_filter( $existing->capabilities );

		// Normalize for strict comparison: ksort both sides so key order
		// doesn't produce a false-positive diff.
		ksort( $existing_caps );
		$desired_caps = $caps;
		ksort( $desired_caps );

		if ( $existing_caps !== $desired_caps ) {
			remove_role( EC_USERS_TEAM_ROLE );
			add_role( EC_USERS_TEAM_ROLE, ec_users_get_team_role_label(), $caps );
		}
		return;
	}

	add_role( EC_USERS_TEAM_ROLE, ec_users_get_team_role_label(), $caps );
}

/**
 * Register the team role on every site in the network.
 *
 * @return void
 */
function ec_users_register_team_role_network_wide() {
	$site_ids = ec_users_get_network_site_ids();

	foreach ( $site_ids as $blog_id ) {
		try {
			switch_to_blog( (int) $blog_id );
			ec_users_register_team_role();
		} finally {
			restore_current_blog();
		}
	}
}

/**
 * Resolve the list of blog IDs to operate on.
 *
 * Prefers ec_get_all_site_ids() (active sites only), falls back to
 * ec_get_blog_ids() (the static map), then WP core get_sites().
 *
 * @return int[]
 */
function ec_users_get_network_site_ids() {
	if ( function_exists( 'ec_get_all_site_ids' ) ) {
		return array_map( 'intval', ec_get_all_site_ids() );
	}

	if ( function_exists( 'ec_get_blog_ids' ) ) {
		return array_map( 'intval', array_values( ec_get_blog_ids() ) );
	}

	return array_map( 'intval', get_sites( array( 'fields' => 'ids' ) ) );
}

/**
 * Grant the team role to a user on every site in the network.
 *
 * Used by the team-management UI (extrachill-admin-tools) and the
 * one-time migration helper below. Pure write — no derivation, no
 * meta read.
 *
 * Super-admins are skipped — they already have all caps via the
 * multisite cap layer, and giving them an extra role on every
 * subsite is noise.
 *
 * @param int $user_id User ID.
 * @return int[] Blog IDs the role was newly added to (already-present sites omitted).
 */
function ec_users_grant_team_role( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return array();
	}

	if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
		return array();
	}

	$added = array();

	foreach ( ec_users_get_network_site_ids() as $blog_id ) {
		$blog_id = (int) $blog_id;
		try {
			switch_to_blog( $blog_id );

			// Ensure the role exists before assignment. WP_User::add_role
			// writes the role name into user meta without checking whether
			// the role is registered — skipping this call could create a
			// "ghost role" (assignment exists but no caps take effect) on
			// a freshly-created subsite that hasn't run init yet.
			ec_users_register_team_role();

			$user = new WP_User( $user_id );
			if ( ! $user->exists() ) {
				continue;
			}

			if ( ! in_array( EC_USERS_TEAM_ROLE, (array) $user->roles, true ) ) {
				$user->add_role( EC_USERS_TEAM_ROLE );
				$added[] = $blog_id;
			}
		} finally {
			restore_current_blog();
		}
	}

	// Emit team_member_added once per grant (not once per site) when the
	// role was newly added on at least one site. Timestamps the adoption
	// the legacy add_role() path never recorded (extrachill-users#127).
	if ( ! empty( $added ) && function_exists( 'ec_users_emit_team_experience_event' ) ) {
		ec_users_emit_team_experience_event(
			'team_member_added',
			$user_id,
			array( 'blog_ids' => $added )
		);
	}

	return $added;
}

/**
 * Revoke the team role from a user on every site in the network.
 *
 * Pure write — counterpart to ec_users_grant_team_role.
 *
 * @param int $user_id User ID.
 * @return int[] Blog IDs the role was removed from.
 */
function ec_users_revoke_team_role( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return array();
	}

	$removed = array();

	foreach ( ec_users_get_network_site_ids() as $blog_id ) {
		$blog_id = (int) $blog_id;
		try {
			switch_to_blog( $blog_id );

			$user = new WP_User( $user_id );
			if ( ! $user->exists() ) {
				continue;
			}

			if ( in_array( EC_USERS_TEAM_ROLE, (array) $user->roles, true ) ) {
				$user->remove_role( EC_USERS_TEAM_ROLE );
				$removed[] = $blog_id;
			}
		} finally {
			restore_current_blog();
		}
	}

	// Emit team_member_removed once per revoke (not once per site) when the
	// role was actually removed from at least one site (extrachill-users#127).
	if ( ! empty( $removed ) && function_exists( 'ec_users_emit_team_experience_event' ) ) {
		ec_users_emit_team_experience_event(
			'team_member_removed',
			$user_id,
			array( 'blog_ids' => $removed )
		);
	}

	return $removed;
}

/**
 * One-time migration: legacy meta → role assignments, then delete meta.
 *
 * Before this PR, team membership was stored in user_meta keys
 * `extrachill_team` (auto flag) and `extrachill_team_manual_override`
 * ('add' / 'remove'). The effective status was computed at read time.
 *
 * This migration:
 *   1. Computes effective status from the legacy meta exactly the way
 *      the old ec_is_team_member() did.
 *   2. Grants the team role on every site for users whose effective
 *      status is "team".
 *   3. Deletes both meta keys for every user that had them set.
 *
 * Idempotent on re-run: a second pass finds no users with the meta
 * (already deleted), so it's a no-op.
 *
 * @return array{granted: int, meta_deleted: int} Summary counts.
 */
function ec_users_migrate_team_meta_to_role() {
	global $wpdb;

	$summary = array(
		'granted'      => 0,
		'meta_deleted' => 0,
	);

	// Find every user that has either of the legacy meta keys set.
	// We include 'remove' overrides explicitly so we delete their meta
	// even though they don't get the role — clean slate either way.
	$user_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-shot migration query.
		"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
		 WHERE meta_key IN ( 'extrachill_team', 'extrachill_team_manual_override' )"
	);

	foreach ( (array) $user_ids as $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			continue;
		}

		// Replicate the legacy effective-status semantics so we don't
		// change anyone's membership during the migration.
		$override    = get_user_meta( $user_id, 'extrachill_team_manual_override', true );
		$flag        = get_user_meta( $user_id, 'extrachill_team', true );
		$should_have = ( 'add' === $override )
			? true
			: ( ( 'remove' === $override )
				? false
				: ( '1' === (string) $flag ) );

		if ( $should_have ) {
			$added = ec_users_grant_team_role( $user_id );
			if ( ! empty( $added ) ) {
				++$summary['granted'];
			}
		}

		delete_user_meta( $user_id, 'extrachill_team' );
		delete_user_meta( $user_id, 'extrachill_team_manual_override' );
		++$summary['meta_deleted'];
	}

	return $summary;
}

/**
 * Register the role on a newly-initialized subsite.
 *
 * @param WP_Site $new_site The newly created site object.
 * @return void
 */
function ec_users_on_new_site_register_role( $new_site ) {
	try {
		switch_to_blog( (int) $new_site->blog_id );
		ec_users_register_team_role();
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp_initialize_site', 'ec_users_on_new_site_register_role', 200 );

/**
 * Idempotent safety net: ensure the role exists on the current site
 * with the current cap set.
 *
 * Runs early on every request via init at priority 5 (before any
 * other plugin's init handler that might cap-check). The function
 * self-debounces — when the role already exists with the desired
 * caps, the call is a single get_role() lookup (~2µs).
 *
 * @return void
 */
function ec_users_maybe_register_team_role() {
	ec_users_register_team_role();
}
add_action( 'init', 'ec_users_maybe_register_team_role', 5 );

/**
 * Run the one-time meta-to-role migration after a version bump.
 *
 * Activation hooks fire only on plugin enable, not on plugin update,
 * so we also fire from admin_init when the stored migration version
 * lags behind the plugin version. Gated on a site_option flag so the
 * migration runs at most once per version (a single get_site_option
 * call on steady-state requests).
 *
 * @return void
 */
function ec_users_maybe_run_team_migration() {
	$option_key       = 'extrachill_users_team_migration_version';
	$current_version  = defined( 'EXTRACHILL_USERS_VERSION' ) ? EXTRACHILL_USERS_VERSION : '0';
	$migrated_version = get_site_option( $option_key, '0' );

	if ( version_compare( $migrated_version, $current_version, '>=' ) ) {
		return;
	}

	ec_users_register_team_role_network_wide();
	ec_users_migrate_team_meta_to_role();

	update_site_option( $option_key, $current_version );
}
add_action( 'admin_init', 'ec_users_maybe_run_team_migration' );

/**
 * Hide the team role from the wp-admin user-edit single-role dropdown.
 *
 * The wp-admin user-edit page uses a single-role SELECT that calls
 * WP_User::set_role() on save — which REPLACES the user's roles. If
 * the team role were listed there, a super-admin saving the profile
 * (even just to update display name) could silently strip the team
 * role from a multi-role user.
 *
 * Team membership is managed exclusively via the team-management UI
 * in extrachill-admin-tools, which writes the role directly via
 * add_role/remove_role. Removing it from editable_roles makes the
 * wp-admin dropdown incapable of touching the team role at all.
 *
 * @param array<string,array> $roles Roles indexed by slug.
 * @return array<string,array> Filtered roles.
 */
function ec_users_hide_team_role_from_editable_roles( $roles ) {
	unset( $roles[ EC_USERS_TEAM_ROLE ] );
	return $roles;
}
add_filter( 'editable_roles', 'ec_users_hide_team_role_from_editable_roles' );
