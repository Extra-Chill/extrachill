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
