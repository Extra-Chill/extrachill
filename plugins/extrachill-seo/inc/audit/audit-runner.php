<?php
/**
 * Audit Runner
 *
 * Orchestrates full and batch SEO audits across the multisite network.
 *
 * @package ExtraChill\SEO\Audit
 */

namespace ExtraChill\SEO\Audit;

use function ExtraChill\SEO\Audit\Checks\ec_seo_count_missing_excerpts;
use function ExtraChill\SEO\Audit\Checks\ec_seo_count_missing_alt_text;
use function ExtraChill\SEO\Audit\Checks\ec_seo_count_missing_featured;
use function ExtraChill\SEO\Audit\Checks\ec_seo_count_broken_images;
use function ExtraChill\SEO\Audit\Checks\ec_seo_count_broken_links;
use function ExtraChill\SEO\Audit\Checks\ec_seo_get_image_urls_to_check;
use function ExtraChill\SEO\Audit\Checks\ec_seo_get_link_urls_to_check;
use function ExtraChill\SEO\Audit\Checks\ec_seo_count_broken_featured_images;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EC_SEO_BATCH_SIZE = 50;

/**
 * Runs a full audit across all available sites.
 *
 * This is synchronous and may timeout on large networks.
 *
 * @return array Complete audit results.
 */
function ec_seo_run_full_audit() {
	$results  = ec_seo_get_empty_results();
	$blog_ids = ec_seo_get_available_blog_ids();

	foreach ( $blog_ids as $slug => $blog_id ) {
		try {
			switch_to_blog( $blog_id );
			$site_label = get_bloginfo( 'name' );

			$results['missing_excerpts']['by_site'][ $blog_id ] = array(
				'count' => ec_seo_count_missing_excerpts(),
				'label' => $site_label,
			);

			$results['missing_alt_text']['by_site'][ $blog_id ] = array(
				'count' => ec_seo_count_missing_alt_text(),
				'label' => $site_label,
			);

			$results['missing_featured']['by_site'][ $blog_id ] = array(
				'count' => ec_seo_count_missing_featured(),
				'label' => $site_label,
			);

			$results['broken_images']['by_site'][ $blog_id ] = array(
				'count' => ec_seo_count_broken_images(),
				'label' => $site_label,
			);

			$results['broken_internal_links']['by_site'][ $blog_id ] = array(
				'count' => ec_seo_count_broken_links( 'internal' ),
				'label' => $site_label,
			);

			$results['broken_external_links']['by_site'][ $blog_id ] = array(
				'count' => ec_seo_count_broken_links( 'external' ),
				'label' => $site_label,
			);
		} finally {
			restore_current_blog();
		}
	}

	ec_seo_calculate_totals( $results );

	$audit_data = array(
		'status'    => 'complete',
		'timestamp' => time(),
		'progress'  => array(),
		'results'   => $results,
	);

	ec_seo_save_audit_results( $audit_data );

	return $audit_data;
}

/**
 * Starts a new batch audit.
 *
 * Initializes progress and runs the first batch.
 *
 * @return array Audit data with progress information.
 */
function ec_seo_start_batch_audit() {
	$blog_ids = ec_seo_get_available_blog_ids();
	$checks   = ec_seo_get_check_order();

	$audit_data = array(
		'status'    => 'in_progress',
		'timestamp' => time(),
		'progress'  => array(
			'current_check_index' => 0,
			'current_site_index'  => 0,
			'sites'               => array_values( $blog_ids ),
			'site_slugs'          => array_keys( $blog_ids ),
			'checks'              => $checks,
			'urls_to_check'       => array(),
			'urls_checked'        => 0,
			'urls_total'          => 0,
		),
		'results'   => ec_seo_get_empty_results(),
	);

	ec_seo_save_audit_results( $audit_data );

	return ec_seo_continue_batch_audit();
}

/**
 * Continues a batch audit from where it left off.
 *
 * Processes fast checks completely, slow checks in batches of URLs.
 *
 * @return array Updated audit data with progress.
 */
function ec_seo_continue_batch_audit() {
	$audit_data = ec_seo_get_audit_results();

	if ( 'in_progress' !== $audit_data['status'] ) {
		return $audit_data;
	}

	$progress = &$audit_data['progress'];
	$results  = &$audit_data['results'];
	$checks   = $progress['checks'];
	$sites    = $progress['sites'];

	while ( $progress['current_check_index'] < count( $checks ) ) {
		$check_key = $checks[ $progress['current_check_index'] ];
		$is_slow   = ec_seo_is_slow_check( $check_key );

		if ( $is_slow ) {
			$batch_result = ec_seo_process_slow_check_batch( $audit_data, $check_key );

			if ( 'in_progress' === $batch_result['status'] ) {
				ec_seo_save_audit_results( $audit_data );
				return $audit_data;
			}
		} else {
			ec_seo_process_fast_check( $audit_data, $check_key );
		}

		++$progress['current_check_index'];
		$progress['current_site_index'] = 0;
		$progress['urls_to_check']      = array();
		$progress['urls_checked']       = 0;
		$progress['urls_total']         = 0;
	}

	ec_seo_calculate_totals( $results );

	$audit_data['status']    = 'complete';
	$audit_data['timestamp'] = time();
	$audit_data['progress']  = array();

	ec_seo_save_audit_results( $audit_data );

	return $audit_data;
}

/**
 * Processes a fast check (database query only) across all sites.
 *
 * @param array  $audit_data Reference to audit data.
 * @param string $check_key  The check to run.
 */
function ec_seo_process_fast_check( &$audit_data, $check_key ) {
	$progress = &$audit_data['progress'];
	$results  = &$audit_data['results'];
	$sites    = $progress['sites'];

	foreach ( $sites as $blog_id ) {
		try {
			switch_to_blog( $blog_id );
			$site_label = get_bloginfo( 'name' );
			$count      = 0;

			switch ( $check_key ) {
				case 'missing_excerpts':
					$count = ec_seo_count_missing_excerpts();
					break;
				case 'missing_alt_text':
					$count = ec_seo_count_missing_alt_text();
					break;
				case 'missing_featured':
					$count = ec_seo_count_missing_featured();
					break;
			}

			$results[ $check_key ]['by_site'][ $blog_id ] = array(
				'count' => $count,
				'label' => $site_label,
			);
		} finally {
			restore_current_blog();
		}
	}
}

/**
 * Processes a slow check (HTTP requests) in batches.
 *
 * @param array  $audit_data Reference to audit data.
 * @param string $check_key  The check to run.
 * @return array Status array with 'status' key.
 */
function ec_seo_process_slow_check_batch( &$audit_data, $check_key ) {
	$progress = &$audit_data['progress'];
	$results  = &$audit_data['results'];
	$sites    = $progress['sites'];

	if ( empty( $progress['urls_to_check'] ) && 0 === $progress['urls_checked'] ) {
		$all_urls = array();

		foreach ( $sites as $index => $blog_id ) {
			try {
				switch_to_blog( $blog_id );
				$site_label = get_bloginfo( 'name' );

				$results[ $check_key ]['by_site'][ $blog_id ] = array(
					'count' => 0,
					'label' => $site_label,
				);

				switch ( $check_key ) {
					case 'broken_images':
						$broken_featured = ec_seo_count_broken_featured_images();
						$results[ $check_key ]['by_site'][ $blog_id ]['count'] = $broken_featured;
						$site_urls = ec_seo_get_image_urls_to_check();
						break;
					case 'broken_internal_links':
						$site_urls = ec_seo_get_link_urls_to_check( 'internal' );
						break;
					case 'broken_external_links':
						$site_urls = ec_seo_get_link_urls_to_check( 'external' );
						break;
					default:
						$site_urls = array();
				}

				foreach ( $site_urls as $url ) {
					$all_urls[] = array(
						'url'     => $url,
						'blog_id' => $blog_id,
					);
				}
			} finally {
				restore_current_blog();
			}
		}

		$progress['urls_to_check'] = $all_urls;
		$progress['urls_total']    = count( $all_urls );
	}

	$batch     = array_splice( $progress['urls_to_check'], 0, EC_SEO_BATCH_SIZE );
	$processed = 0;

	foreach ( $batch as $url_data ) {
		$url     = $url_data['url'];
		$blog_id = $url_data['blog_id'];

		if ( ec_seo_url_is_broken( $url ) ) {
			++$results[ $check_key ]['by_site'][ $blog_id ]['count'];
		}

		++$processed;
	}

	$progress['urls_checked'] += $processed;

	if ( ! empty( $progress['urls_to_check'] ) ) {
		return array( 'status' => 'in_progress' );
	}

	return array( 'status' => 'complete' );
}

/**
 * Calculates totals for all metrics from per-site counts.
 *
 * @param array $results Reference to results array.
 */
function ec_seo_calculate_totals( &$results ) {
	foreach ( $results as $key => &$metric ) {
		if ( isset( $metric['by_site'] ) ) {
			$metric['total'] = array_sum( array_column( $metric['by_site'], 'count' ) );
		}
	}
}
