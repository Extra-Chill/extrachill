<?php
/**
 * Global Function Wrappers
 *
 * Provides global (non-namespaced) access to audit functions for REST API endpoints.
 *
 * @package ExtraChill\SEO\Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a full SEO audit across all available sites.
 *
 * @return array Audit results.
 */
function ec_seo_run_full_audit() {
	return \ExtraChill\SEO\Audit\ec_seo_run_full_audit();
}

/**
 * Starts a batch SEO audit.
 *
 * @return array Audit data with progress.
 */
function ec_seo_start_batch_audit() {
	return \ExtraChill\SEO\Audit\ec_seo_start_batch_audit();
}

/**
 * Continues a batch SEO audit from where it left off.
 *
 * @return array Updated audit data.
 */
function ec_seo_continue_batch_audit() {
	return \ExtraChill\SEO\Audit\ec_seo_continue_batch_audit();
}

/**
 * Gets current audit results from storage.
 *
 * @return array Audit results.
 */
function ec_seo_get_audit_results() {
	return \ExtraChill\SEO\Audit\ec_seo_get_audit_results();
}

/**
 * Gets posts missing excerpts with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_missing_excerpts( $limit = 50, $offset = 0 ) {
	return \ExtraChill\SEO\Audit\Checks\ec_seo_get_missing_excerpts( $limit, $offset );
}

/**
 * Gets images missing alt text with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_missing_alt_text( $limit = 50, $offset = 0 ) {
	return \ExtraChill\SEO\Audit\Checks\ec_seo_get_missing_alt_text( $limit, $offset );
}

/**
 * Gets posts missing featured images with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_missing_featured( $limit = 50, $offset = 0 ) {
	return \ExtraChill\SEO\Audit\Checks\ec_seo_get_missing_featured( $limit, $offset );
}

/**
 * Gets broken images with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_broken_images( $limit = 50, $offset = 0 ) {
	return \ExtraChill\SEO\Audit\Checks\ec_seo_get_broken_images( $limit, $offset );
}

/**
 * Gets broken internal links with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_broken_internal_links( $limit = 50, $offset = 0 ) {
	return \ExtraChill\SEO\Audit\Checks\ec_seo_get_broken_links( 'internal', $limit, $offset );
}

/**
 * Gets broken external links with pagination.
 *
 * @param int $limit  Number of items to return.
 * @param int $offset Offset for pagination.
 * @return array Array with 'total' count and 'items' array.
 */
function ec_seo_get_broken_external_links( $limit = 50, $offset = 0 ) {
	return \ExtraChill\SEO\Audit\Checks\ec_seo_get_broken_links( 'external', $limit, $offset );
}