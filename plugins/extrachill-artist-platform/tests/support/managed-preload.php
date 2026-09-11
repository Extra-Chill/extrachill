<?php
/**
 * Managed-harness topology seed for the Extra Chill Artist Platform suite.
 *
 * Required during the WP Codebox managed PHPUnit bootstrap via the
 * `wp_codebox_phpunit_preload_files` homeboy setting. The sandbox boots a
 * multisite WordPress whose blogs table is empty, while every cross-site code
 * path in the plugin resolves canonical blog IDs through the real
 * extrachill-network primitives (EC_BLOG_ID_ARTIST = 4, EC_BLOG_ID_EVENTS = 7).
 * This seed creates those exact sites so switch_to_blog() operates on real
 * per-site tables instead of simulation.
 *
 * Runs once per PHPUnit process, before any test executes. Site rows created
 * here survive the per-test transaction rollback because they precede the
 * first transaction.
 *
 * @package ExtraChillArtistPlatform
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ec_artist_platform_test_seed_site' ) ) {
	/**
	 * Create one canonical network site row with real per-site tables.
	 *
	 * @param int    $blog_id Exact canonical blog ID (EC_BLOG_ID_*).
	 * @param string $slug    URL path segment for the site.
	 * @param string $title   Site title.
	 * @return void
	 */
	function ec_artist_platform_test_seed_site( $blog_id, $slug, $title ) {
		global $wpdb;

		$blog_id = (int) $blog_id;
		$exists  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id = %d", $blog_id ) );
		if ( $exists ) {
			return;
		}

		$now      = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			$wpdb->blogs,
			array(
				'blog_id'      => $blog_id,
				'site_id'      => 1,
				'domain'       => (string) parse_url( home_url(), PHP_URL_HOST ),
				'path'         => $slug ? '/' . trim( $slug, '/' ) . '/' : '/',
				'registered'   => $now,
				'last_updated' => $now,
				'public'       => 1,
				'archived'     => 0,
				'mature'       => 0,
				'spam'         => 0,
				'deleted'      => 0,
				'lang_id'      => 0,
			)
		);
		if ( ! $inserted ) {
			throw new RuntimeException( "Managed harness could not seed site {$blog_id}: " . $wpdb->last_error );
		}

		if ( 1 === $blog_id ) {
			// The network primary already has base-prefix tables; creating them
			// again trips install_blog()'s already-installed guard.
			clean_blog_cache( $blog_id );
			return;
		}

		$did_switch = get_current_blog_id() !== $blog_id;
		if ( $did_switch ) {
			switch_to_blog( $blog_id );
		}
		$previous_level = error_reporting( E_ALL & ~E_DEPRECATED );
		install_blog( $blog_id, $title );
		error_reporting( $previous_level );
		if ( $did_switch ) {
			restore_current_blog();
		}
		clean_blog_cache( $blog_id );
	}
}

ec_artist_platform_test_seed_site( 1, '', 'Extra Chill' );
ec_artist_platform_test_seed_site( 4, 'artist', 'Extra Chill Artist Platform' );
ec_artist_platform_test_seed_site( 7, 'events', 'Extra Chill Events' );
