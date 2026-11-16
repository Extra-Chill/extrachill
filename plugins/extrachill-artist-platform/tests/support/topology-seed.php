<?php
/**
 * Shared Extra Chill network topology seed for the managed WP Codebox harness.
 *
 * Loaded twice: as a must-use plugin (tests/support/harness-topology), so the
 * canonical sites and the Link Page storage blog exist before WP Codebox's
 * plugin-activation stage, and from managed-preload.php at PHPUnit bootstrap.
 * Every step is idempotent.
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


if ( ! function_exists( 'ec_artist_platform_test_seed_topology' ) ) {
	/**
	 * Seed the canonical blogs 1/4/7.
	 *
	 * @return void
	 */
	function ec_artist_platform_test_seed_topology() {
		if ( ! is_multisite() ) {
			return;
		}
		ec_artist_platform_test_seed_site( 1, '', 'Extra Chill' );
		ec_artist_platform_test_seed_site( 4, 'artist', 'Extra Chill Artist Platform' );
		ec_artist_platform_test_seed_site( 7, 'events', 'Extra Chill Events' );
	}
}

if ( ! function_exists( 'ec_artist_platform_test_link_page_storage_blog_id' ) ) {
	/**
	 * Resolve the sandbox Link Page storage blog.
	 *
	 * extrachill-network's ec_link_page_storage_blog_id filter returns
	 * EC_BLOG_ID_LINK_PAGES (13), which the sandbox does not seed, so an
	 * unusable candidate falls back to the artist site (4) the fixtures
	 * assert. A test that injects its own existing storage blog keeps it.
	 *
	 * @param int $blog_id Candidate storage blog ID.
	 * @return int
	 */
	function ec_artist_platform_test_link_page_storage_blog_id( $blog_id ) {
		$blog_id = (int) $blog_id;
		if ( $blog_id > 0 && function_exists( 'get_site' ) && get_site( $blog_id ) ) {
			return $blog_id;
		}
		return 4;
	}
}

add_filter( 'ec_link_page_storage_blog_id', 'ec_artist_platform_test_link_page_storage_blog_id', 11 );
