<?php
/**
 * Base class for NetworkStats metric providers.
 *
 * Removes boilerplate (key/label/ttl storage) and supplies the cross-blog
 * counting primitives every core provider needs, so a concrete provider is
 * usually just a constructor and a value() method.
 *
 * Counting strategy (deliberate): metric values are derived from DIRECT
 * cross-blog database reads via switch_to_blog() — published post counts and
 * non-empty taxonomy terms. This is intentional: data-machine-events,
 * extrachill-artist-platform, and extrachill-news-wire are PER-SITE plugins,
 * so their PHP functions/abilities are NOT loaded in the PHP process of the
 * site rendering a landing page (switch_to_blog does not load another site's
 * plugin stack). The data, however, lives in the shared database and is
 * queryable from any blog context. Reading the DB directly is therefore both
 * correct from any origin site and plugin-independent. Providers that wrap an
 * EXISTING cached counter (online users, total members, community stats) do so
 * explicitly in their own value() and do not use these DB helpers.
 *
 * @package ExtraChillNetwork\NetworkStats
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats;

defined( 'ABSPATH' ) || exit;

/**
 * Convenience base implementing the boilerplate accessors.
 */
abstract class AbstractMetricProvider implements MetricProvider {

	/**
	 * Metric machine key.
	 *
	 * @var string
	 */
	protected string $key;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	protected string $label;

	/**
	 * Cache TTL in seconds.
	 *
	 * @var int
	 */
	protected int $ttl;

	/**
	 * Construct a metric provider.
	 *
	 * @param string $key   Stable machine key.
	 * @param string $label Human-readable label.
	 * @param int    $ttl   Cache lifetime in seconds.
	 */
	public function __construct( string $key, string $label, int $ttl ) {
		$this->key   = $key;
		$this->label = $label;
		$this->ttl   = $ttl;
	}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * {@inheritDoc}
	 */
	public function cache_ttl(): int {
		return $this->ttl;
	}

	/**
	 * Resolve a logical site key to a blog ID, or null if unknown.
	 *
	 * @param string $site_key Logical site key (e.g. "events").
	 * @return int|null Blog ID, or null when the site is unavailable.
	 */
	protected function resolve_blog_id( string $site_key ): ?int {
		if ( ! function_exists( 'ec_get_blog_id' ) ) {
			return null;
		}

		$blog_id = ec_get_blog_id( $site_key );

		return $blog_id ? (int) $blog_id : null;
	}

	/**
	 * Count published posts of a post type on another site.
	 *
	 * Returns null (NOT 0) when the site cannot be resolved, so the engine
	 * can honestly report "not available" instead of a fabricated zero. A
	 * genuine zero (site exists, no matching posts) returns 0.
	 *
	 * @param string $site_key  Logical site key.
	 * @param string $post_type Post type slug.
	 * @return int|null Published count, or null if the site is unavailable.
	 */
	protected function count_published_posts( string $site_key, string $post_type ): ?int {
		$blog_id = $this->resolve_blog_id( $site_key );
		if ( null === $blog_id ) {
			return null;
		}

		$switched = false;
		if ( (int) get_current_blog_id() !== $blog_id && function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			// Count straight from the posts table rather than wp_count_posts():
			// the target post type is registered by a PER-SITE plugin that is
			// not loaded in this process, so wp_count_posts() (which keys its
			// cache on a registered post type) returns zeros. The rows exist in
			// the shared DB and are countable directly.
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
					$post_type
				)
			);

			return (int) $count;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Count UPCOMING published events on another site.
	 *
	 * Reads the data-machine-events `datamachine_event_dates` table directly
	 * (it carries its own `post_status` and `start_datetime` columns), counting
	 * distinct events whose start is today or later — matching the calendar's
	 * own "upcoming" boundary (`current_time('Y-m-d') . ' 00:00:00'`, see
	 * data-machine-events CalendarAbilities). This is the count a calendar
	 * landing page actually wants — "how many shows are coming up" — not the
	 * all-time published total, which includes every past event ever scraped.
	 *
	 * Plugin-independent: the events post type / plugin is not loaded in this
	 * process, but the rows live in the shared DB and are queryable after
	 * switch_to_blog(). Returns null when the site cannot be resolved or the
	 * table is absent, so the engine reports "not available" rather than a
	 * fabricated zero. A genuine zero (no upcoming events) returns 0.
	 *
	 * @param string $site_key Logical site key (e.g. 'events').
	 * @return int|null Upcoming published event count, or null if unavailable.
	 */
	protected function count_upcoming_events( string $site_key ): ?int {
		$blog_id = $this->resolve_blog_id( $site_key );
		if ( null === $blog_id ) {
			return null;
		}

		$switched = false;
		if ( (int) get_current_blog_id() !== $blog_id && function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			global $wpdb;

			$table = $wpdb->prefix . 'datamachine_event_dates';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				// Table not present (events site unavailable / schema absent).
				return null;
			}

			// Match the calendar's "upcoming" boundary: start of today in the
			// site's local timezone (data-machine-events CalendarAbilities uses
			// current_time('Y-m-d') . ' 00:00:00').
			$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT post_id) FROM %i WHERE post_status = %s AND start_datetime >= %s',
					$table,
					'publish',
					$today_start
				)
			);

			return (int) $count;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Count non-empty terms of a taxonomy on another site.
	 *
	 * "Non-empty" means count > 0, matching how a landing page would describe
	 * "cities with events". Returns null when the site is unavailable.
	 *
	 * @param string $site_key Logical site key.
	 * @param string $taxonomy Taxonomy slug.
	 * @return int|null Non-empty term count, or null if the site is unavailable.
	 */
	protected function count_nonempty_terms( string $site_key, string $taxonomy ): ?int {
		$blog_id = $this->resolve_blog_id( $site_key );
		if ( null === $blog_id ) {
			return null;
		}

		$switched = false;
		if ( (int) get_current_blog_id() !== $blog_id && function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				// The taxonomy is registered by a per-site plugin that is not
				// loaded in this process. Count distinct non-empty terms
				// straight from the term tables instead.
				return $this->count_nonempty_terms_via_db( $taxonomy );
			}

			$count = wp_count_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
				)
			);

			return is_wp_error( $count ) ? null : (int) $count;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Count non-empty terms directly from the term tables.
	 *
	 * Fallback for taxonomies whose registering plugin is not loaded in the
	 * current PHP process (per-site plugins after switch_to_blog). Must be
	 * called in the correct blog context.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return int Non-empty term count.
	 */
	private function count_nonempty_terms_via_db( string $taxonomy ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND count > 0",
				$taxonomy
			)
		);

		return (int) $count;
	}
}
