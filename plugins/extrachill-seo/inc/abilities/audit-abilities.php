<?php
/**
 * Audit Ability Callbacks
 *
 * Execute callbacks for SEO audit abilities.
 *
 * @package ExtraChill\SEO\Abilities
 */

namespace ExtraChill\SEO\Abilities;

use function ExtraChill\SEO\Audit\ec_seo_run_full_audit;
use function ExtraChill\SEO\Audit\ec_seo_start_batch_audit;
use function ExtraChill\SEO\Audit\ec_seo_continue_batch_audit;
use function ExtraChill\SEO\Audit\ec_seo_get_audit_results;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute callback for run-seo-audit ability.
 *
 * @param array $input Input parameters.
 * @return array Audit results.
 */
function extrachill_seo_ability_run_audit( $input = array() ) {
	$mode    = isset( $input['mode'] ) ? $input['mode'] : 'full';
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$checks  = isset( $input['checks'] ) ? $input['checks'] : array();

	if ( 'batch' === $mode ) {
		$existing = ec_seo_get_audit_results();
		if ( 'in_progress' === $existing['status'] ) {
			$audit_data = ec_seo_continue_batch_audit();
		} else {
			$audit_data = ec_seo_start_batch_audit();
		}
	} else {
		$audit_data = ec_seo_run_full_audit();
	}

	$results = $audit_data['results'];

	if ( $blog_id > 0 ) {
		$filtered_results = array();
		foreach ( $results as $check_key => $check_data ) {
			if ( isset( $check_data['by_site'][ $blog_id ] ) ) {
				$filtered_results[ $check_key ] = array(
					'total'   => $check_data['by_site'][ $blog_id ]['count'],
					'by_site' => array(
						$blog_id => $check_data['by_site'][ $blog_id ],
					),
				);
			}
		}
		$results = $filtered_results;
	}

	if ( ! empty( $checks ) ) {
		$check_map = array(
			'excerpts'        => 'missing_excerpts',
			'alt_text'        => 'missing_alt_text',
			'featured_images' => 'missing_featured',
			'broken_images'   => 'broken_images',
			'broken_links'    => array( 'broken_internal_links', 'broken_external_links' ),
			'redirect_links'  => 'redirect_links',
		);

		$filtered_results = array();
		foreach ( $checks as $check ) {
			if ( isset( $check_map[ $check ] ) ) {
				$mapped = $check_map[ $check ];
				if ( is_array( $mapped ) ) {
					foreach ( $mapped as $key ) {
						if ( isset( $results[ $key ] ) ) {
							$filtered_results[ $key ] = $results[ $key ];
						}
					}
				} elseif ( isset( $results[ $mapped ] ) ) {
					$filtered_results[ $mapped ] = $results[ $mapped ];
				}
			}
		}
		$results = $filtered_results;
	}

	return array(
		'success' => true,
		'mode'    => $mode,
		'blog_id' => $blog_id,
		'status'  => $audit_data['status'],
		'results' => $results,
		'message' => sprintf(
			/* translators: %s: audit status */
			__( 'Audit %s.', 'extrachill-seo' ),
			$audit_data['status']
		),
	);
}

/**
 * Execute callback for get-seo-results ability.
 *
 * @param array $input Input parameters.
 * @return array Audit results.
 */
function extrachill_seo_ability_get_results( $input = array() ) {
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$check   = isset( $input['check'] ) ? $input['check'] : '';

	$audit_data = ec_seo_get_audit_results();
	$results    = $audit_data['results'];

	if ( $blog_id > 0 ) {
		$filtered_results = array();
		foreach ( $results as $check_key => $check_data ) {
			if ( isset( $check_data['by_site'][ $blog_id ] ) ) {
				$filtered_results[ $check_key ] = array(
					'total'   => $check_data['by_site'][ $blog_id ]['count'],
					'by_site' => array(
						$blog_id => $check_data['by_site'][ $blog_id ],
					),
				);
			}
		}
		$results = $filtered_results;
	}

	if ( ! empty( $check ) && isset( $results[ $check ] ) ) {
		$results = array( $check => $results[ $check ] );
	}

	$summary = array(
		'total_issues' => 0,
		'by_check'     => array(),
	);

	foreach ( $results as $check_key => $check_data ) {
		$total                             = isset( $check_data['total'] ) ? $check_data['total'] : 0;
		$summary['by_check'][ $check_key ] = $total;
		$summary['total_issues']          += $total;
	}

	$last_run = '';
	if ( $audit_data['timestamp'] > 0 ) {
		$last_run = gmdate( 'c', $audit_data['timestamp'] );
	}

	return array(
		'status'    => $audit_data['status'],
		'timestamp' => $audit_data['timestamp'],
		'last_run'  => $last_run,
		'results'   => $results,
		'summary'   => $summary,
	);
}
