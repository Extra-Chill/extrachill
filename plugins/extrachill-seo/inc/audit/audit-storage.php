<?php
/**
 * Audit Storage
 *
 * Handles storage and retrieval of SEO audit results via network site options.
 *
 * @package ExtraChill\SEO\Audit
 */

namespace ExtraChill\SEO\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EC_SEO_AUDIT_OPTION = 'extrachill_seo_audit_results';

/**
 * Gets current audit results from storage.
 *
 * @return array Audit results with status, timestamp, progress, and results.
 */
function ec_seo_get_audit_results() {
	$default = array(
		'status'    => 'none',
		'timestamp' => 0,
		'progress'  => array(),
		'results'   => ec_seo_get_empty_results(),
	);

	return get_site_option( EC_SEO_AUDIT_OPTION, $default );
}

/**
 * Saves audit results to storage.
 *
 * @param array $results Audit results to save.
 */
function ec_seo_save_audit_results( $results ) {
	update_site_option( EC_SEO_AUDIT_OPTION, $results );
}

/**
 * Returns empty results structure for initialization.
 *
 * @return array Empty results array with all metric keys.
 */
function ec_seo_get_empty_results() {
	return array(
		'missing_excerpts'       => array(
			'total'   => 0,
			'by_site' => array(),
		),
		'missing_alt_text'       => array(
			'total'   => 0,
			'by_site' => array(),
		),
		'missing_featured'       => array(
			'total'   => 0,
			'by_site' => array(),
		),
		'broken_images'          => array(
			'total'   => 0,
			'by_site' => array(),
		),
		'broken_internal_links'  => array(
			'total'   => 0,
			'by_site' => array(),
		),
		'broken_external_links'  => array(
			'total'   => 0,
			'by_site' => array(),
		),
		'redirect_links'         => array(
			'total'   => 0,
			'by_site' => array(),
		),
	);
}

/**
 * Returns the ordered list of audit checks.
 *
 * Fast checks (database queries) are first, slow checks (HTTP requests) are last.
 *
 * @return array Ordered array of check keys.
 */
function ec_seo_get_check_order() {
	return array(
		'missing_excerpts',
		'missing_alt_text',
		'missing_featured',
		'broken_images',
		'broken_internal_links',
		'broken_external_links',
		'redirect_links',
	);
}

/**
 * Checks if a check type requires HTTP requests (slow).
 *
 * @param string $check_key The check key to evaluate.
 * @return bool True if check is slow (HTTP-based), false otherwise.
 */
function ec_seo_is_slow_check( $check_key ) {
	$slow_checks = array(
		'broken_images',
		'broken_internal_links',
		'broken_external_links',
		'redirect_links',
	);

	return in_array( $check_key, $slow_checks, true );
}
