<?php
/**
 * Plugin activation and setup logic.
 * Creates Login page with login-register block on all network sites.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How far back (in days) the welcome-email fallback looks for signups that
 * never finished onboarding.
 */
const EXTRACHILL_WELCOME_EMAIL_FALLBACK_WINDOW_DAYS = 7;

/**
 * Main activation handler.
 * Called via register_activation_hook.
 */
function extrachill_users_run_activation() {
	if ( ! is_multisite() ) {
		deactivate_plugins( plugin_basename( EXTRACHILL_USERS_PLUGIN_FILE ) );
		wp_die( 'Extra Chill Users plugin requires a WordPress multisite installation.' );
	}

	// The refresh-token table is owned by wp-native-auth as of the eu#76
	// consolidation (it installs {base_prefix}wp_native_auth_refresh_tokens via
	// its own activation hook). The legacy {base_prefix}extrachill_refresh_tokens
	// table is no longer created or used by this plugin.

	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/concert-tracking/db.php';
	if ( function_exists( 'extrachill_users_install_concert_tracking_table' ) ) {
		extrachill_users_install_concert_tracking_table();
	}

	update_site_option( 'extrachill_users_concert_tracking_table_created', 1 );

	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/concert-tracking/import-db.php';
	if ( function_exists( 'extrachill_users_install_concert_import_runs_table' ) ) {
		extrachill_users_install_concert_import_runs_table();
	}

	update_site_option( 'extrachill_users_concert_import_runs_table_created', 1 );

	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/notifications/db.php';
	$notifications_table_ready = false;
	if ( function_exists( 'extrachill_users_install_notifications_table' ) ) {
		$notifications_table_ready = extrachill_users_install_notifications_table();
	}

	if ( $notifications_table_ready ) {
		update_site_option(
			'extrachill_users_notifications_schema_version',
			defined( 'EXTRACHILL_USERS_NOTIFICATIONS_SCHEMA_VERSION' ) ? (string) EXTRACHILL_USERS_NOTIFICATIONS_SCHEMA_VERSION : '1'
		);
	}

	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/entity-subscriptions/db.php';
	extrachill_users_install_entity_subscriptions_table();
	update_site_option( 'extrachill_users_entity_subscriptions_schema_version', EXTRACHILL_USERS_ENTITY_SUBSCRIPTIONS_SCHEMA_VERSION );

	extrachill_users_create_login_pages_network();

	// Register extra_chill_team role on every site in the network and
	// run the one-time meta-to-role migration (#45 Phase 1). Setting
	// the migration version flag prevents the admin_init safety net
	// from re-running the migration on the next admin request.
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/team-members/role.php';
	ec_users_register_team_role_network_wide();
	ec_users_migrate_team_meta_to_role();
	update_site_option( 'extrachill_users_team_migration_version', EXTRACHILL_USERS_VERSION );

	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/artist-dispatch-role.php';
	ec_users_register_artist_dispatch_role_on_main();

	extrachill_welcome_email_fallback_maybe_schedule();
}

/**
 * Deactivation handler.
 * Unschedules cron events.
 */
function extrachill_users_run_deactivation() {
	$timestamp = wp_next_scheduled( 'extrachill_welcome_email_fallback' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'extrachill_welcome_email_fallback' );
	}

	// Unread-notification email digest channel (inc/notifications/email.php).
	if ( defined( 'EC_NOTIFICATIONS_EMAIL_CRON_HOOK' ) ) {
		wp_clear_scheduled_hook( EC_NOTIFICATIONS_EMAIL_CRON_HOOK );
	} else {
		wp_clear_scheduled_hook( 'ec_notifications_email_digest' );
	}
}

/**
 * Users the welcome-email fallback should target right now.
 *
 * Network-wide signups registered between EXTRACHILL_WELCOME_EMAIL_FALLBACK_WINDOW_DAYS
 * and one hour ago that have not completed onboarding and have not been sent
 * a welcome email.
 *
 * @return WP_User[]
 */
function extrachill_welcome_email_fallback_candidates() {
	$users = get_users(
		array(
			// Users are network-wide; do not limit to members of the current blog.
			'blog_id'    => 0,
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'   => 'onboarding_completed',
					'value' => '0',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => 'welcome_email_sent',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'welcome_email_sent',
						'value' => '0',
					),
				),
			),
			// Only recent signups: the lower bound keeps a re-enabled schedule from
			// mass-emailing long-dormant accounts (see #454).
			'date_query' => array(
				array(
					// user_registered is UTC; relative strings would be resolved in
					// site-local time, so pass explicit UTC bounds.
					'after'     => gmdate( 'Y-m-d H:i:s', time() - EXTRACHILL_WELCOME_EMAIL_FALLBACK_WINDOW_DAYS * DAY_IN_SECONDS ),
					'before'    => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					'inclusive' => true,
				),
			),
		)
	);

	return $users;
}

/**
 * Cron callback for sending welcome emails to users who never completed onboarding.
 *
 * Sends the onboarding-incomplete welcome email to each candidate from
 * extrachill_welcome_email_fallback_candidates().
 */
function extrachill_welcome_email_fallback_callback() {
	$users = extrachill_welcome_email_fallback_candidates();

	$ability = wp_get_ability( 'extrachill/send-welcome-email' );
	if ( ! $ability ) {
		return;
	}

	foreach ( $users as $user ) {
		$ability->execute(
			array(
				'user_id'    => $user->ID,
				'email_type' => 'onboarding_incomplete',
			)
		);
	}
}

add_action( 'extrachill_welcome_email_fallback', 'extrachill_welcome_email_fallback_callback' );
add_action( 'init', 'extrachill_welcome_email_fallback_maybe_schedule' );

/**
 * Ensure the hourly welcome-email fallback is scheduled on the main site.
 *
 * Runs on every `init`, not only on plugin activation, so the event comes
 * back if it ever drops off the cron array (#454). Users are network-wide
 * and the callback queries them network-wide, so exactly one site (the main
 * site) owns the schedule; any stray copy on another site is cleared so the
 * fallback cannot double-send.
 */
function extrachill_welcome_email_fallback_maybe_schedule() {
	if ( ! is_main_site() ) {
		if ( wp_next_scheduled( 'extrachill_welcome_email_fallback' ) ) {
			wp_clear_scheduled_hook( 'extrachill_welcome_email_fallback' );
		}
		return;
	}

	if ( ! wp_next_scheduled( 'extrachill_welcome_email_fallback' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'extrachill_welcome_email_fallback' );
	}
}

/**
 * Create login page on all network sites.
 *
 * Prefers ec_get_all_site_ids() for dynamic discovery of all active sites,
 * falling back to ec_get_blog_ids() if the multisite plugin is outdated.
 */
function extrachill_users_create_login_pages_network() {
	if ( function_exists( 'ec_get_all_site_ids' ) ) {
		$site_ids = ec_get_all_site_ids();
	} elseif ( function_exists( 'ec_get_blog_ids' ) ) {
		$site_ids = array_values( ec_get_blog_ids() );
	} else {
		return;
	}

	foreach ( $site_ids as $blog_id ) {
		$blog_id = (int) $blog_id;

		if ( ! get_blog_details( $blog_id ) ) {
			continue;
		}

		try {
			switch_to_blog( $blog_id );
			extrachill_users_create_login_page();
		} finally {
			restore_current_blog();
		}
	}
}

/**
 * Create login page with login-register block.
 * Skips if page already exists.
 */
function extrachill_users_create_login_page() {
	if ( get_page_by_path( 'login' ) ) {
		return;
	}

	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Login',
			'post_name'    => 'login',
			'post_content' => '<!-- wp:extrachill/login-register /-->',
			'post_status'  => 'publish',
		)
	);
}

/**
 * Auto-create login page when a new site is added to the network.
 *
 * Hooks into wp_initialize_site (WordPress 5.1+) at high priority
 * so the site is fully initialized before we insert the page.
 *
 * @param WP_Site $new_site The newly created site object.
 */
function extrachill_users_on_new_site( $new_site ) {
	try {
		switch_to_blog( (int) $new_site->blog_id );
		extrachill_users_create_login_page();
		update_option( 'extrachill_users_login_page_created', 1 );
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp_initialize_site', 'extrachill_users_on_new_site', 200 );

/**
 * Fallback for sites that existed before the wp_initialize_site hook was added.
 * Runs on admin_init with site option flag to prevent repeated checks.
 */
function extrachill_users_maybe_create_login_page() {
	if ( get_option( 'extrachill_users_login_page_created' ) ) {
		return;
	}

	extrachill_users_create_login_page();
	update_option( 'extrachill_users_login_page_created', 1 );
}
add_action( 'admin_init', 'extrachill_users_maybe_create_login_page' );

/**
 * Ensure concert tracking table exists.
 * Fallback for existing installations where table was added after activation.
 */
function extrachill_users_maybe_create_concert_tracking_table() {
	if ( get_site_option( 'extrachill_users_concert_tracking_table_created' ) ) {
		return;
	}

	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/concert-tracking/db.php';
	if ( function_exists( 'extrachill_users_install_concert_tracking_table' ) ) {
		extrachill_users_install_concert_tracking_table();
	}

	update_site_option( 'extrachill_users_concert_tracking_table_created', 1 );
}
add_action( 'admin_init', 'extrachill_users_maybe_create_concert_tracking_table' );

/**
 * Ensure concert import runs table exists and is on the current schema version.
 *
 * Re-runs dbDelta whenever EXTRACHILL_USERS_CONCERT_IMPORT_RUNS_SCHEMA_VERSION
 * (defined in import-db.php) is bumped. dbDelta is the canonical add-column
 * upgrade path — adding `total_events_created` only needs the schema string
 * updated and the version bumped.
 */
function extrachill_users_maybe_create_concert_import_runs_table() {
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/concert-tracking/import-db.php';

	$current_version = defined( 'EXTRACHILL_USERS_CONCERT_IMPORT_RUNS_SCHEMA_VERSION' )
		? (string) EXTRACHILL_USERS_CONCERT_IMPORT_RUNS_SCHEMA_VERSION
		: '1';

	$installed_version = (string) get_site_option( 'extrachill_users_concert_import_runs_schema_version', '' );

	if ( $installed_version === $current_version ) {
		return;
	}

	if ( function_exists( 'extrachill_users_install_concert_import_runs_table' ) ) {
		extrachill_users_install_concert_import_runs_table();
	}

	update_site_option( 'extrachill_users_concert_import_runs_schema_version', $current_version );
	// Maintain the legacy flag so any external code that checks it keeps working.
	update_site_option( 'extrachill_users_concert_import_runs_table_created', 1 );
}
add_action( 'admin_init', 'extrachill_users_maybe_create_concert_import_runs_table' );

/**
 * Create onboarding page on community site only.
 * Runs on admin_init with site option flag to prevent repeated checks.
 */
function extrachill_users_maybe_create_onboarding_page() {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return;
	}

	$community_blog_id = ec_get_blog_id( 'community' );
	if ( get_current_blog_id() !== $community_blog_id ) {
		return;
	}

	if ( get_option( 'extrachill_users_onboarding_page_created' ) ) {
		return;
	}

	if ( ! get_page_by_path( 'onboarding' ) ) {
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Onboarding',
				'post_name'    => 'onboarding',
				'post_content' => '<!-- wp:extrachill/onboarding /-->',
				'post_status'  => 'publish',
			)
		);
	}

	update_option( 'extrachill_users_onboarding_page_created', 1 );
}
add_action( 'admin_init', 'extrachill_users_maybe_create_onboarding_page' );
