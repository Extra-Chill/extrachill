<?php
/**
 * Redirect Abilities
 *
 * Registers abilities for managing redirect rules via the WordPress Abilities API.
 *
 * @package ExtraChill\SEO\Abilities
 * @since 0.9.0
 */

namespace ExtraChill\SEO\Abilities;

use ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register redirect management abilities.
 *
 * Called from SEO_Abilities::register_abilities().
 */
function register_redirect_abilities() {

	// Add a redirect rule.
	wp_register_ability(
		'extrachill-seo/add-redirect',
		array(
			'label'       => __( 'Add Redirect', 'extrachill-seo' ),
			'description' => __( 'Create a 301/302 redirect rule from one URL to another.', 'extrachill-seo' ),
			'category'    => 'extrachill-seo',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'from_url'    => array(
						'type'        => 'string',
						'description' => __( 'Source URL path to redirect from (e.g., /old-page).', 'extrachill-seo' ),
					),
					'to_url'      => array(
						'type'        => 'string',
						'description' => __( 'Destination URL or path to redirect to.', 'extrachill-seo' ),
					),
					'status_code' => array(
						'type'        => 'integer',
						'description' => __( 'HTTP status code (301 or 302).', 'extrachill-seo' ),
						'default'     => 301,
					),
					'note'        => array(
						'type'        => 'string',
						'description' => __( 'Optional note about why this redirect exists.', 'extrachill-seo' ),
						'default'     => '',
					),
				),
				'required' => array( 'from_url', 'to_url' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\execute_add_redirect',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta' => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	// Delete a redirect rule.
	wp_register_ability(
		'extrachill-seo/delete-redirect',
		array(
			'label'       => __( 'Delete Redirect', 'extrachill-seo' ),
			'description' => __( 'Remove a redirect rule by ID.', 'extrachill-seo' ),
			'category'    => 'extrachill-seo',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'Redirect rule ID to delete.', 'extrachill-seo' ),
					),
				),
				'required' => array( 'id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\execute_delete_redirect',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta' => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);

	// List redirect rules.
	wp_register_ability(
		'extrachill-seo/list-redirects',
		array(
			'label'       => __( 'List Redirects', 'extrachill-seo' ),
			'description' => __( 'Query redirect rules with optional filtering.', 'extrachill-seo' ),
			'category'    => 'extrachill-seo',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'search'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'active'      => array(
						'type'    => 'integer',
						'default' => -1,
					),
					'status_code' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'orderby'     => array(
						'type'    => 'string',
						'default' => 'created_at',
					),
					'order'       => array(
						'type'    => 'string',
						'default' => 'DESC',
					),
					'limit'       => array(
						'type'    => 'integer',
						'default' => 100,
					),
					'offset'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			),
			'output_schema' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'execute_callback'    => __NAMESPACE__ . '\\execute_list_redirects',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta' => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute add-redirect ability.
 */
function execute_add_redirect( $input ) {
	$id = Core\extrachill_seo_add_redirect(
		$input['from_url'],
		$input['to_url'],
		$input['status_code'] ?? 301,
		$input['note'] ?? '',
		'ability'
	);

	if ( false === $id ) {
		return array(
			'success' => false,
			'id'      => 0,
			'message' => 'Failed — redirect rule may already exist for this URL.',
		);
	}

	return array(
		'success' => true,
		'id'      => $id,
		'message' => sprintf( 'Redirect created: %s → %s', $input['from_url'], $input['to_url'] ),
	);
}

/**
 * Execute delete-redirect ability.
 */
function execute_delete_redirect( $input ) {
	$deleted = Core\extrachill_seo_delete_redirect( $input['id'] );

	return array(
		'success' => $deleted,
		'message' => $deleted ? 'Redirect deleted.' : 'Redirect not found.',
	);
}

/**
 * Execute list-redirects ability.
 */
function execute_list_redirects( $input ) {
	return Core\extrachill_seo_get_redirects( $input );
}
