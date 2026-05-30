<?php
/**
 * Sitemap Health Guardrail
 *
 * Asserts that WordPress core sitemap sub-files for public custom post types
 * actually serve HTTP 200. Edge config (nginx) or other infrastructure can
 * silently start returning 404/410 for the core `wp-sitemap-*` namespace,
 * which removes large content surfaces from search discovery without any
 * WordPress-level signal. This guardrail catches that regression.
 *
 * Background: an over-broad nginx rule once returned `410 Gone` for the live
 * `wp-sitemap-posts-data_machine_events-[0-9]+.xml` and
 * `wp-sitemap-taxonomies-artist-[0-9]+.xml` sub-sitemaps, hiding ~78k event
 * posts from crawlers even though WordPress was generating them correctly.
 * See https://github.com/Extra-Chill/extrachill-seo/issues/16
 *
 * This check is intentionally edge-aware: it fetches the public URLs over HTTP
 * (not the internal WP sitemap objects) so it observes exactly what a crawler
 * would receive, including any nginx/CDN interception.
 *
 * @package ExtraChill\SEO\Core
 */

namespace ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron hook name for the scheduled sitemap health check.
 */
const EC_SEO_SITEMAP_HEALTH_CRON = 'ec_seo_sitemap_health_check';

/**
 * Option key storing the latest sitemap health result (network option).
 */
const EC_SEO_SITEMAP_HEALTH_OPTION = 'ec_seo_sitemap_health';

/**
 * Schedule the daily sitemap health check on load.
 *
 * Uses a network-wide single schedule (runs in the context of the main site
 * but iterates all sites inside the runner).
 */
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( EC_SEO_SITEMAP_HEALTH_CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', EC_SEO_SITEMAP_HEALTH_CRON );
		}
	}
);

add_action( EC_SEO_SITEMAP_HEALTH_CRON, __NAMESPACE__ . '\\ec_seo_run_scheduled_sitemap_health_check' );

/**
 * Scheduled entry point: run the network-wide check and persist results.
 *
 * On failure, logs the failing URLs and stores the result so the network
 * admin notice can surface it.
 *
 * @return void
 */
function ec_seo_run_scheduled_sitemap_health_check() {
	$result = ec_seo_check_sitemap_health();

	update_site_option( EC_SEO_SITEMAP_HEALTH_OPTION, $result );

	if ( empty( $result['healthy'] ) ) {
		$failing = wp_list_pluck( $result['failures'], 'url' );
		$log     = sprintf(
			'[extrachill-seo] Sitemap health check FAILED: %d sub-sitemap(s) not returning 200. Failing URLs: %s',
			count( $result['failures'] ),
			implode( ', ', array_slice( $failing, 0, 20 ) )
		);

		/**
		 * Fires when the scheduled sitemap health check detects failures.
		 *
		 * Allows monitoring integrations (homeboy, alerting, etc.) to react
		 * without this plugin taking a hard logging dependency.
		 *
		 * @param array  $result Full health result.
		 * @param string $log    Human-readable failure summary.
		 */
		do_action( 'extrachill_seo_sitemap_health_failed', $result, $log );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $log ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic only, gated behind WP_DEBUG.
		}
	}
}

/**
 * Run the sitemap health check across one or all sites.
 *
 * For each target site, this discovers the post-type and taxonomy sitemap
 * sub-files from the live sitemap index, samples the first and last page of
 * each subtype (the boundaries most likely to be affected by page-number
 * based edge rules), and asserts each sampled URL returns HTTP 200.
 *
 * @param array $args {
 *     Optional. Arguments.
 *
 *     @type int|null $blog_id Restrict to a single blog. Default null (all sites).
 * }
 * @return array {
 *     Health result.
 *
 *     @type bool   $healthy   True when every sampled sub-sitemap returned 200.
 *     @type int    $checked   Number of URLs sampled.
 *     @type array  $failures  List of failing entries (each: url, status, blog_id, label).
 *     @type array  $by_site   Per-site summary keyed by blog id.
 *     @type string $timestamp ISO-8601 timestamp of the run.
 * }
 */
function ec_seo_check_sitemap_health( $args = array() ) {
	$blog_id = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : null;

	$blog_ids = ec_seo_sitemap_health_target_blog_ids( $blog_id );

	$failures = array();
	$by_site  = array();
	$checked  = 0;

	foreach ( $blog_ids as $id ) {
		$switched = false;
		if ( is_multisite() && get_current_blog_id() !== $id ) {
			switch_to_blog( $id );
			$switched = true;
		}

		$label = get_bloginfo( 'name' );
		$urls  = ec_seo_collect_sitemap_sample_urls();

		$site_failures = array();
		foreach ( $urls as $url ) {
			++$checked;
			$status = ec_seo_fetch_status_code( $url );
			if ( 200 !== $status ) {
				$entry = array(
					'url'     => $url,
					'status'  => $status,
					'blog_id' => $id,
					'label'   => $label,
				);

				$failures[]      = $entry;
				$site_failures[] = $entry;
			}
		}

		$by_site[ $id ] = array(
			'label'    => $label,
			'sampled'  => count( $urls ),
			'failures' => count( $site_failures ),
		);

		if ( $switched ) {
			restore_current_blog();
		}
	}

	return array(
		'healthy'   => empty( $failures ),
		'checked'   => $checked,
		'failures'  => $failures,
		'by_site'   => $by_site,
		'timestamp' => gmdate( 'c' ),
	);
}

/**
 * Resolve which blog ids to check.
 *
 * @param int|null $blog_id Single blog id, or null for all.
 * @return int[] Blog ids.
 */
function ec_seo_sitemap_health_target_blog_ids( $blog_id = null ) {
	if ( null !== $blog_id ) {
		return array( (int) $blog_id );
	}

	if ( ! is_multisite() ) {
		return array( get_current_blog_id() );
	}

	$sites = get_sites(
		array(
			'number'   => 0,
			'archived' => 0,
			'deleted'  => 0,
			'spam'     => 0,
			'fields'   => 'ids',
		)
	);

	return array_map( 'intval', $sites );
}

/**
 * Collect a representative sample of sitemap sub-file URLs for the current site.
 *
 * Reads the live core sitemap index, then for every post-type and taxonomy
 * provider samples the first and last page (deduplicated). Sampling the
 * boundaries keeps the check cheap on large networks (e.g. 40 event pages)
 * while still catching page-number-scoped edge rules.
 *
 * @return string[] Absolute sub-sitemap URLs to verify.
 */
function ec_seo_collect_sitemap_sample_urls() {
	$urls = array();

	if ( ! function_exists( 'wp_get_sitemap_providers' ) ) {
		return $urls;
	}

	$providers = wp_get_sitemap_providers();

	foreach ( $providers as $provider ) {
		$subtypes = $provider->get_object_subtypes();

		// Providers with no subtypes (e.g. users) still expose a single sitemap.
		if ( empty( $subtypes ) ) {
			$subtypes = array( '' => (object) array( 'name' => '' ) );
		}

		foreach ( $subtypes as $subtype_object ) {
			$subtype_name = is_object( $subtype_object ) && isset( $subtype_object->name )
				? $subtype_object->name
				: (string) $subtype_object;

			$max_pages = (int) $provider->get_max_num_pages( $subtype_name );
			if ( $max_pages < 1 ) {
				continue;
			}

			$pages_to_check = array( 1 );
			if ( $max_pages > 1 ) {
				$pages_to_check[] = $max_pages;
			}

			foreach ( array_unique( $pages_to_check ) as $page ) {
				$url = $provider->get_sitemap_url( $subtype_name, $page );
				if ( $url ) {
					$urls[ $url ] = true;
				}
			}
		}
	}

	return array_keys( $urls );
}

/**
 * Fetch the HTTP status code for a URL as a crawler would observe it.
 *
 * Uses a GET (not HEAD) because some edge rules only trigger on the rendered
 * response, and follows no redirects so the raw edge status (e.g. 410) is seen.
 *
 * @param string $url URL to check.
 * @return int HTTP status code, or 0 on transport error.
 */
function ec_seo_fetch_status_code( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'ExtraChill-SEO-SitemapHealth/1.0',
			'headers'     => array( 'Accept' => 'application/xml,text/xml' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return 0;
	}

	return (int) wp_remote_retrieve_response_code( $response );
}

/**
 * Surface a network admin notice when the last stored check failed.
 *
 * @return void
 */
add_action(
	'network_admin_notices',
	function () {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$result = get_site_option( EC_SEO_SITEMAP_HEALTH_OPTION );
		if ( empty( $result ) || ! empty( $result['healthy'] ) ) {
			return;
		}

		$count = isset( $result['failures'] ) ? count( $result['failures'] ) : 0;
		$first = isset( $result['failures'][0] ) ? $result['failures'][0] : null;

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'Extra Chill SEO: sitemap health check failed.', 'extrachill-seo' );
		echo '</strong> ';
		printf(
			/* translators: %d: number of failing sitemap sub-files. */
			esc_html( _n( '%d sitemap sub-file is not returning HTTP 200.', '%d sitemap sub-files are not returning HTTP 200.', $count, 'extrachill-seo' ) ),
			(int) $count
		);
		if ( $first ) {
			echo ' ';
			printf(
				/* translators: 1: example failing URL, 2: HTTP status code. */
				esc_html__( 'Example: %1$s returned %2$d. Check edge/nginx rules against the core wp-sitemap namespace.', 'extrachill-seo' ),
				esc_html( $first['url'] ),
				(int) $first['status']
			);
		}
		echo '</p></div>';
	}
);

/**
 * Clean up the scheduled event on plugin deactivation.
 *
 * @return void
 */
function ec_seo_unschedule_sitemap_health_check() {
	$timestamp = wp_next_scheduled( EC_SEO_SITEMAP_HEALTH_CRON );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, EC_SEO_SITEMAP_HEALTH_CRON );
	}
}
