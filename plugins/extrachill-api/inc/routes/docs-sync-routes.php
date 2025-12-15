<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __DIR__ ) . 'controllers/class-docs-sync-controller.php';

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_docs_sync_routes' );

function extrachill_api_register_docs_sync_routes() {
	register_rest_route(
		'extrachill/v1',
		'/sync/doc',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( 'ExtraChill_Docs_Sync_Controller', 'sync_doc' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'source_file'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'title'         => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'content'       => array(
					'required' => true,
					'type'     => 'string',
				),
				'platform_slug' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'slug'          => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_title',
				),
				'filesize'      => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'timestamp'     => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'force'         => array(
					'default' => false,
					'type'    => 'boolean',
				),
			),
		)
	);
}
