<?php
/**
 * Cleanup Link Page Tables Ability
 *
 * One-time operator cleanup (#1775): drops empty Link Page analytics table
 * copies on non-storage blogs. Dry-run by default; refuses any copy with rows.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the cleanup-link-page-tables ability.
 */
function extrachill_analytics_register_cleanup_link_page_tables_ability() {
	wp_register_ability(
		'extrachill/cleanup-link-page-tables',
		array(
			'label'               => __( 'Cleanup Link Page Analytics Tables', 'extrachill-analytics' ),
			'description'         => __( 'Drops empty Link Page daily analytics tables on non-storage blogs. Dry-run by default; never drops a table with rows.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => __( 'If true (default), only report what would be dropped.', 'extrachill-analytics' ),
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Per-table report with would_drop/dropped/refused counts.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_cleanup_link_page_tables',
			'permission_callback' => function () {
				return current_user_can( 'manage_network_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);
}

/**
 * Execute callback for the cleanup-link-page-tables ability.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function extrachill_analytics_ability_cleanup_link_page_tables( $input = array() ) {
	$dry_run = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : true;
	return extrachill_analytics_link_page_cleanup_orphan_tables( $dry_run );
}
