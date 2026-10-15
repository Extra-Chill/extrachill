<?php
/**
 * Shared PHPUnit fixture helpers.
 *
 * This is a support file, not a test case: it is required by the suites that
 * use it and matches neither `*Test.php` nor `test-*.php`.
 *
 * @package ExtraChill\Users
 */

if ( ! function_exists( 'ec_users_tests_register_abilities' ) ) {
	/**
	 * Run an ability registrar the way WordPress 6.9+ requires.
	 *
	 * The wp_register_ability() function only accepts registrations while
	 * `wp_abilities_api_init` is running; anywhere else it reports incorrect
	 * usage and registers nothing. Firing the action unfiltered is no better:
	 * every other registrar re-runs and core reports "already registered".
	 *
	 * Detaching the other callbacks first fires a real action with only the
	 * given registrar attached. WP_UnitTestCase backs up and restores hooks
	 * around each test, so the detachment does not leak. The abilities
	 * registry itself is NOT restored between tests, so callers own
	 * unregistering whatever they add (see ec_users_tests_restore_ability()).
	 *
	 * @param callable $registrar Callback that calls wp_register_ability().
	 */
	function ec_users_tests_register_abilities( callable $registrar ): void {
		remove_all_actions( 'wp_abilities_api_init' );
		add_action( 'wp_abilities_api_init', $registrar );
		do_action( 'wp_abilities_api_init' );
	}
}

if ( ! function_exists( 'ec_users_tests_swap_ability' ) ) {
	/**
	 * Replace an ability with a fixture for the duration of one test.
	 *
	 * Owner registrations (for example extrachill-analytics'
	 * track-analytics-event, activated as a validation dependency) are moved
	 * aside rather than reused, so a fixture's capture assertions do not
	 * depend on which plugins are active or which suite ran first. Pass the
	 * return value to ec_users_tests_restore_ability() in tearDown().
	 *
	 * @param string $name Ability name.
	 * @param array  $args wp_register_ability() arguments.
	 * @return WP_Ability|null The displaced owner registration, if any.
	 */
	function ec_users_tests_swap_ability( string $name, array $args ): ?WP_Ability {
		$displaced = wp_has_ability( $name ) ? wp_unregister_ability( $name ) : null;

		ec_users_tests_register_abilities(
			static function () use ( $name, $args ): void {
				wp_register_ability( $name, $args );
			}
		);

		return $displaced;
	}
}

if ( ! function_exists( 'ec_users_tests_restore_ability' ) ) {
	/**
	 * Remove a fixture ability and put the displaced owner object back.
	 *
	 * The registry is not reset between tests, so a displaced registration
	 * that is not restored stays missing for every later suite. Re-inserting
	 * the original WP_Ability object restores it exactly, without re-running
	 * its registrar outside `wp_abilities_api_init`.
	 *
	 * @param string          $name      Ability name.
	 * @param WP_Ability|null $displaced Value returned by ec_users_tests_swap_ability().
	 */
	function ec_users_tests_restore_ability( string $name, ?WP_Ability $displaced ): void {
		if ( wp_has_ability( $name ) ) {
			wp_unregister_ability( $name );
		}
		if ( null === $displaced ) {
			return;
		}

		// Private properties are reflectively accessible without setAccessible() on PHP 8.1+.
		$registry            = WP_Abilities_Registry::get_instance();
		$abilities           = new ReflectionProperty( WP_Abilities_Registry::class, 'registered_abilities' );
		$registered          = $abilities->getValue( $registry );
		$registered[ $name ] = $displaced;
		$abilities->setValue( $registry, $registered );
	}
}

if ( ! function_exists( 'ec_users_tests_ensure_site' ) ) {
	/**
	 * Make a site with an exact blog ID exist for the rest of the process.
	 *
	 * Suites that exercise the canonical blog-ID map (artist = 4, studio = 12,
	 * ...) need those exact IDs. Creating sites through the factory until the
	 * ID appears only works once: per-test rollback removes the rows, but
	 * InnoDB never rolls back AUTO_INCREMENT, so the next test overshoots the
	 * target. Call this from wpSetUpBeforeClass(), which runs outside the
	 * per-test transaction and is committed, and insert the exact ID instead
	 * of relying on the sequence.
	 *
	 * @param int $blog_id Required blog ID.
	 * @return bool Whether the site exists afterwards.
	 */
	function ec_users_tests_ensure_site( int $blog_id ): bool {
		global $wpdb;

		if ( $blog_id <= 0 || ! is_multisite() ) {
			return false;
		}
		if ( get_site( $blog_id ) ) {
			return true;
		}

		$network = get_network();
		$now     = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- wp_insert_site() cannot target an explicit blog ID.
		$inserted = $wpdb->insert(
			$wpdb->blogs,
			array(
				'blog_id'      => $blog_id,
				'site_id'      => (int) $network->id,
				'domain'       => $network->domain,
				'path'         => trailingslashit( $network->path ) . 'ec-users-site-' . $blog_id . '/',
				'registered'   => $now,
				'last_updated' => $now,
				'public'       => 1,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( 1 !== $inserted ) {
			return false;
		}

		clean_blog_cache( $blog_id );
		$site = get_site( $blog_id );
		if ( ! $site ) {
			return false;
		}

		// Mirror wp_insert_site(): listeners plus core's wp_initialize_site().
		$suppress = $wpdb->suppress_errors();
		do_action( 'wp_insert_site', $site );
		do_action( 'wp_initialize_site', $site, array( 'title' => 'Site ' . $blog_id ) );
		$wpdb->suppress_errors( $suppress );
		wp_installing( false );

		return (bool) get_site( $blog_id );
	}
}
