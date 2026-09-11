<?php
/**
 * Shared base for the managed-harness test suite.
 *
 * Replaces the deleted tests/bootstrap.php WordPress simulation. Tests run
 * against the real WordPress booted by the Homeboy managed harness (see
 * homeboy.json), with the real extrachill-network, extrachill-api,
 * extrachill-users, and agents-api plugins active. Fault injection uses real
 * WordPress filters instead of function shims.
 *
 * @package ExtraChillArtistPlatform
 *
 * phpcs:ignoreFile Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound -- shared fixture: one function helper plus two boundary subclasses and the base test case are intentionally co-located.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current database runtime is SQLite-backed.
 *
 * Mirrors the plugin's own runtime detection so tests can require (or skip)
 * database-specific behavior for exactly the environments where it runs.
 *
 * @return bool
 */
function ec_artist_platform_test_wpdb_is_sqlite(): bool {
	return (bool) preg_match( '/sqlite|pgsql|postgres/', strtolower( (string) get_class( $GLOBALS['wpdb'] ) ) );
}

if ( ! class_exists( 'EC_Test_FailingAdvisoryReleaseWpdb' ) ) {
	/**
	 * A wpdb boundary subclass whose advisory-lock reads can be forced to fail.
	 *
	 * Only GET_LOCK/RELEASE_LOCK reads are overridden; every other query runs
	 * on the real database connection. Instantiated only in suites backed by
	 * real MySQL advisory locks.
	 */
	class EC_Test_FailingAdvisoryReleaseWpdb extends wpdb {
		/**
		 * Whether the next advisory-lock read should fail with a database error.
		 *
		 * @var bool
		 */
		public $fail_next_advisory_lock = false;

		/**
		 * Result injected for the next advisory-lock read ('0' for contention).
		 *
		 * @var string|null
		 */
		public $injected_advisory_result = null;

		/**
		 * Intercept advisory-lock reads; delegate everything else.
		 *
		 * @param string|null $query Query.
		 * @param int         $x     Offset.
		 * @param int         $y     Column.
		 * @return mixed
		 */
		public function get_var( $query = null, $x = 0, $y = 0 ) {
			if ( is_string( $query ) && ( str_contains( $query, 'GET_LOCK' ) || str_contains( $query, 'RELEASE_LOCK' ) ) ) {
				if ( $this->fail_next_advisory_lock ) {
					$this->fail_next_advisory_lock  = false;
					$this->last_error               = 'MySQL advisory lock query failed.';
					$this->injected_advisory_result = null;
					return null;
				}
				if ( null !== $this->injected_advisory_result ) {
					$this->last_error               = '';
					$result                         = $this->injected_advisory_result;
					$this->injected_advisory_result = null;
					return $result;
				}
			}
			return parent::get_var( $query, $x, $y );
		}
	}
}

if ( ! class_exists( 'EC_Test_SQLitePathWpdb' ) ) {
	/**
	 * A wpdb boundary subclass whose class name selects the plugin's portable
	 * fallback-lock path while every query still runs on the real database.
	 *
	 * The plugin chooses the option-based lock when the wpdb class name
	 * matches a non-MySQL engine. On a MySQL-backed suite this subclass
	 * selects that path deliberately; on the SQLite sandbox the runtime class
	 * already matches and tests skip this subclass entirely.
	 */
	class EC_Test_SQLitePathWpdb extends wpdb {
		/**
		 * Whether the next options-table INSERT must fail.
		 *
		 * @var bool
		 */
		public $fail_option_insert = false;

		/**
		 * Whether the next raw query must fail.
		 *
		 * @var bool
		 */
		public $fail_next_query = false;

		/**
		 * Intercept options-table writes; delegate everything else.
		 *
		 * @param string $query Query.
		 * @return mixed
		 */
		public function query( $query ) {
			if ( is_string( $query ) && $this->fail_option_insert && str_contains( $query, 'INSERT' ) && str_contains( $query, 'options' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LongFound -- deliberate containment probe.
				$this->last_error = 'Network lock option insert failed.';
				return false;
			}
			if ( is_string( $query ) && $this->fail_next_query ) {
				$this->fail_next_query = false;
				$this->last_error      = 'Network lock query failed.';
				return false;
			}
			return parent::query( $query );
		}
	}
}

/**
 * Base test case for the Extra Chill Artist Platform managed suite.
 */
abstract class EC_Artist_Platform_TestCase extends WP_UnitTestCase {
	/**
	 * Filters added by fault injection helpers, removed in tearDown.
	 *
	 * @var array<int,array{tag:string,callback:callable,priority:int}>
	 */
	protected $ec_injected_filters = array();

	/**
	 * Reset ambient state that plugin code persists in globals.
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		unset(
			$GLOBALS['ec_artist_binding_lock'],
			$GLOBALS['ec_artist_binding_lock_pending'],
			$GLOBALS['ec_artist_binding_delete_locks'],
			$GLOBALS['ec_artist_binding_deferred_locks'],
			$GLOBALS['ec_artist_binding_release_failure'],
			$GLOBALS['ec_artist_membership_locks'],
			$GLOBALS['ec_artist_membership_failure']
		);
	}

	/**
	 * Remove every injected filter so state never leaks between tests.
	 */
	protected function tearDown(): void {
		if ( null !== $this->ec_original_wpdb ) {
			$GLOBALS['wpdb']        = $this->ec_original_wpdb;
			$this->ec_original_wpdb = null;
		}
		$this->ec_clear_injected_filters();
		parent::tearDown();
	}

	/**
	 * Register an injected filter and track it for teardown.
	 *
	 * @param string   $tag      Filter tag.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	protected function ec_inject_filter( string $tag, callable $callback, int $priority = 10 ): void {
		add_filter( $tag, $callback, $priority, 10 );
		$this->ec_injected_filters[] = array(
			'tag'      => $tag,
			'callback' => $callback,
			'priority' => $priority,
		);
	}

	/**
	 * Remove all injected filters.
	 */
	protected function ec_clear_injected_filters(): void {
		foreach ( $this->ec_injected_filters as $injected ) {
			remove_filter( $injected['tag'], $injected['callback'], $injected['priority'] );
		}
		$this->ec_injected_filters = array();
	}

	/**
	 * Skip when the suite runs without real MySQL advisory locks.
	 *
	 * Canonical binding serialization requires GET_LOCK/RELEASE_LOCK. Those
	 * paths run against a MySQL-backed WordPress (artist-binding-mysql CI
	 * workflow); the SQLite sandbox reports them as skipped instead of failing
	 * them on an unsupported runtime.
	 */
	protected function ec_require_mysql_advisory_locks(): void {
		if ( ec_artist_platform_test_wpdb_is_sqlite() ) {
			$this->markTestSkipped( 'Requires MySQL advisory locks; exercised by the artist-binding-mysql CI workflow.' );
		}
	}

	/**
	 * Skip when the suite runs on MySQL (portable fallback-lock path).
	 */
	protected function ec_require_non_mysql_wpdb(): void {
		if ( ! ec_artist_platform_test_wpdb_is_sqlite() ) {
			$this->markTestSkipped( 'Requires a non-MySQL wpdb runtime for the portable fallback lock path.' );
		}
	}

	/**
	 * Swap the database boundary for one test and restore it on teardown.
	 *
	 * @param wpdb $instance Replacement boundary instance.
	 */
	protected function ec_swap_wpdb( wpdb $instance ): void {
		$this->ec_original_wpdb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']        = $instance;
	}

	/**
	 * The original wpdb swapped by ec_swap_wpdb(), if any.
	 *
	 * @var wpdb|null
	 */
	protected $ec_original_wpdb = null;

	/**
	 * Canonical artist blog ID.
	 */
	protected function artist_blog_id(): int {
		return (int) ec_get_blog_id( 'artist' );
	}

	/**
	 * Canonical main blog ID.
	 */
	protected function main_blog_id(): int {
		return (int) ec_get_blog_id( 'main' );
	}

	/**
	 * Create a published artist profile post on the artist blog.
	 *
	 * @param string $title Post title (slug derived from it).
	 * @param array  $args  Extra post args (post_name, post_status, ...).
	 * @return int Post ID on the artist blog.
	 */
	protected function create_artist_profile( string $title, array $args = array() ): int {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$post_id = (int) self::factory()->post->create(
				array_merge(
					array(
						'post_type'   => 'artist_profile',
						'post_status' => 'publish',
						'post_title'  => $title,
					),
					$args
				)
			);
		} finally {
			restore_current_blog();
		}
		return $post_id;
	}

	/**
	 * Create a main-site artist term, optionally bound to a profile.
	 *
	 * @param string   $slug       Term slug.
	 * @param int|null $profile_id Artist profile ID stored as reciprocal meta.
	 * @return int Term ID on the main blog.
	 */
	protected function create_artist_term( string $slug, ?int $profile_id = null ): int {
		switch_to_blog( $this->main_blog_id() );
		try {
			$existing = get_term_by( 'slug', $slug, 'artist' );
			if ( $existing ) {
				$term_id = (int) $existing->term_id;
			} else {
				$created = wp_insert_term( $slug, 'artist' );
				$term_id = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
			}
			if ( $term_id && null !== $profile_id ) {
				update_term_meta( $term_id, '_artist_profile_id', (int) $profile_id );
			}
		} finally {
			restore_current_blog();
		}
		return $term_id;
	}

	/**
	 * Create a fully reciprocal profile/term binding pair.
	 *
	 * @param string $title Profile and term title.
	 * @return array{profile_id:int,term_id:int}
	 */
	protected function create_bound_artist( string $title ): array {
		$profile_id = $this->create_artist_profile( $title );
		switch_to_blog( $this->artist_blog_id() );
		try {
			$slug = (string) get_post_field( 'post_name', $profile_id, 'db' );
		} finally {
			restore_current_blog();
		}
		$term_id = $this->create_artist_term( $slug, $profile_id );
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $profile_id, '_artist_term_id', $term_id );
		restore_current_blog();
		return array(
			'profile_id' => $profile_id,
			'term_id'    => $term_id,
		);
	}

	/**
	 * Create both sides of an artist membership relationship.
	 *
	 * @param int $user_id   User ID.
	 * @param int $artist_id Artist profile post ID.
	 */
	protected function create_artist_membership( int $user_id, int $artist_id ): void {
		switch_to_blog( $this->artist_blog_id() );
		$members   = get_post_meta( $artist_id, '_artist_member_ids', true );
		$members   = is_array( $members ) ? $members : array();
		$members[] = $user_id;
		update_post_meta( $artist_id, '_artist_member_ids', array_map( 'intval', array_unique( $members ) ) );
		restore_current_blog();

		$profiles   = get_user_meta( $user_id, '_artist_profile_ids', true );
		$profiles   = is_array( $profiles ) ? $profiles : array();
		$profiles[] = $artist_id;
		update_user_meta( $user_id, '_artist_profile_ids', array_map( 'intval', array_unique( $profiles ) ) );
	}

	/**
	 * Grant a user a role on the artist blog.
	 *
	 * Multisite roles are per-site; users created on the network primary have
	 * no role on the canonical artist site unless granted here.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role name.
	 */
	protected function ec_grant_artist_blog_role( int $user_id, string $role ): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$user = new WP_User( $user_id );
			$user->add_role( $role );
			clean_user_cache( $user_id );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Register a test ability through the real registry.
	 *
	 * Core rejects wp_register_ability() outside the wp_abilities_api_init
	 * action, so this temporarily detaches the boot-time registrars, re-fires
	 * the action with only the test registration attached, and restores the
	 * original registrars. The registry remains real throughout.
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability args.
	 */
	protected function ec_register_test_ability( string $name, array $args ): void {
		$registry = WP_Abilities_Registry::get_instance();
		if ( $registry->is_registered( $name ) ) {
			$registry->unregister( $name );
		}

		$saved_filter                                  = $GLOBALS['wp_filter']['wp_abilities_api_init'] ?? null;
		$GLOBALS['wp_filter']['wp_abilities_api_init'] = new WP_Hook();
		add_action(
			'wp_abilities_api_init',
			static function () use ( $name, $args ) {
				wp_register_ability( $name, $args );
			},
			10,
			0
		);
		do_action( 'wp_abilities_api_init' );
		if ( null === $saved_filter ) {
			unset( $GLOBALS['wp_filter']['wp_abilities_api_init'] );
		} else {
			$GLOBALS['wp_filter']['wp_abilities_api_init'] = $saved_filter;
		}
	}

	/**
	 * Create a user granted the network administration surface.
	 *
	 * @param string $scope 'superadmin' grants manage_network_options; 'admin' grants manage_options.
	 * @return int User ID.
	 */
	protected function create_admin_user( string $scope = 'admin' ): int {
		$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( 'superadmin' === $scope && is_multisite() ) {
			// Some sandbox network installs persist site_admins as a bare
			// string; normalize it so grant_super_admin() can append.
			$super_admins = get_site_option( 'site_admins', array( 'admin' ) );
			if ( is_string( $super_admins ) ) {
				$super_admins = array( $super_admins );
			}
			$super_admins = array_values( array_filter( array_map( 'strval', (array) $super_admins ) ) );
			update_site_option( 'site_admins', array() === $super_admins ? array( 'admin' ) : $super_admins ); // phpcs:ignore Universal.Operators.StrictComparisons.LongEqual -- deliberate array identity guard.
			grant_super_admin( $user_id );
		}
		return $user_id;
	}

	/**
	 * Register a meta-write short-circuit that fails the next N calls.
	 *
	 * @param string      $action 'update', 'add', or 'delete'.
	 * @param string      $type   Meta type: post, user, or term.
	 * @param int         $times  Number of failing calls.
	 * @param string|null $key    Restrict to one meta key.
	 * @return stdClass State holder with ->remaining.
	 */
	private function ec_fail_meta_writes( string $action, string $type, int $times, ?string $key ): stdClass {
		$state            = new stdClass();
		$state->remaining = $times;
		$state->key       = $key;

		$callback = function ( $check, $object_id, $meta_key ) use ( $state ) {
			if ( $state->remaining <= 0 ) {
				return $check;
			}
			if ( null !== $state->key && $state->key !== $meta_key ) {
				return $check;
			}
			--$state->remaining;
			return false;
		};
		$this->ec_inject_filter( "{$action}_{$type}_metadata", $callback, 100 );
		return $state;
	}

	/**
	 * Make the next N update_{$type}_metadata calls fail.
	 *
	 * @param string      $type  Meta type: post, user, or term.
	 * @param int         $times Number of failing calls.
	 * @param string|null $key   Restrict to one meta key.
	 */
	protected function fail_meta_updates( string $type, int $times = 1, ?string $key = null ): void {
		$this->ec_fail_meta_writes( 'update', $type, $times, $key );
	}

	/**
	 * Make the next N add_{$type}_metadata calls fail.
	 *
	 * @param string      $type  Meta type: post, user, or term.
	 * @param int         $times Number of failing calls.
	 * @param string|null $key   Restrict to one meta key.
	 */
	protected function fail_meta_adds( string $type, int $times = 1, ?string $key = null ): void {
		$this->ec_fail_meta_writes( 'add', $type, $times, $key );
	}

	/**
	 * Make the next N delete_{$type}_metadata calls fail.
	 *
	 * @param string      $type  Meta type: post, user, or term.
	 * @param int         $times Number of failing calls.
	 * @param string|null $key   Restrict to one meta key.
	 */
	protected function fail_meta_deletes( string $type, int $times = 1, ?string $key = null ): void {
		$this->ec_fail_meta_writes( 'delete', $type, $times, $key );
	}

	/**
	 * Make the next add_{$type}_metadata call report success without writing.
	 *
	 * Exercises verification-stage compensation: the write reports success but
	 * the value never lands.
	 *
	 * @param string      $type Meta type: post, user, or term.
	 * @param string|null $key  Restrict to one meta key.
	 */
	protected function succeed_meta_adds_without_writing( string $type, ?string $key = null ): void {
		$this->ec_short_circuit_meta_success( 'add', $type, $key );
	}

	/**
	 * Make the next delete_{$type}_metadata call report success without deleting.
	 *
	 * @param string      $type Meta type: post, user, or term.
	 * @param string|null $key  Restrict to one meta key.
	 */
	protected function succeed_meta_deletes_without_deleting( string $type, ?string $key = null ): void {
		$this->ec_short_circuit_meta_success( 'delete', $type, $key );
	}

	/**
	 * Register a short-circuit that reports success without mutating storage.
	 *
	 * @param string      $action 'add' or 'delete'.
	 * @param string      $type   Meta type.
	 * @param string|null $key    Restrict to one meta key.
	 */
	private function ec_short_circuit_meta_success( string $action, string $type, ?string $key ): void {
		$state      = new stdClass();
		$state->key = $key;

		$callback = function ( $check, $object_id, $meta_key ) use ( $state ) {
			if ( null !== $state->key && $state->key !== $meta_key ) {
				return $check;
			}
			return true;
		};
		$this->ec_inject_filter( "{$action}_{$type}_metadata", $callback, 100 );
	}

	/**
	 * Simulate a competing writer winning the next compare-and-swap.
	 *
	 * On the next update_{$type}_metadata call for the key, the conflicting
	 * value is committed to the real store first (the competing writer's
	 * state), then the swap reports false so the caller retries against the
	 * newly observed state.
	 *
	 * @param string $type              Meta type: post, user, or term.
	 * @param string $key               Meta key.
	 * @param mixed  $conflicting_value Value the competing writer commits.
	 */
	protected function conflict_next_meta_update( string $type, string $key, $conflicting_value ): void {
		$state           = new stdClass();
		$state->fired    = false;
		$state->key      = $key;
		$state->conflict = $conflicting_value;
		$state->type     = $type;

		$callback = function ( $check, $object_id, $meta_key ) use ( $state ) {
			if ( $state->fired || $state->key !== $meta_key ) {
				return $check;
			}
			$state->fired = true;
			// The competing writer commits before this swap fails.
			update_metadata( $state->type, $object_id, $meta_key, $state->conflict );
			return false;
		};
		$this->ec_inject_filter( "update_{$type}_metadata", $callback, 100 );
	}

	/**
	 * Make wp_delete_post fail after the binding's own veto hook has run.
	 */
	protected function fail_post_deletes(): void {
		$callback = static function ( $check ) {
			return false;
		};
		// Runs after the binding hook (PHP_INT_MAX, registered at load) because
		// same-priority filters execute in registration order.
		$this->ec_inject_filter( 'pre_delete_post', $callback, PHP_INT_MAX );
	}

	/**
	 * Record every get_terms query's variables and fail one call.
	 *
	 * @param int $fail_on_call 1-based call number that must fail (0 = never fail).
	 * @return stdClass State holder; read ->queries after the run.
	 */
	protected function track_term_queries_and_fail_on( int $fail_on_call ): stdClass {
		$state          = new stdClass();
		$state->calls   = 0;
		$state->fail_on = $fail_on_call;
		$state->queries = array();

		$callback = function ( $results, $term_query ) use ( $state ) {
			++$state->calls;
			$vars = $term_query->query_vars;
			unset( $vars['taxonomies'] );
			$state->queries[] = $vars;
			if ( $state->calls === $state->fail_on ) {
				return new WP_Error( 'ec_test_term_query_failed', 'Injected term query failure.' );
			}
			return $results;
		};
		$this->ec_inject_filter( 'terms_pre_query', $callback, 100 );
		return $state;
	}
}
